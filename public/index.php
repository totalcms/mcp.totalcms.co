<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Schema\ToolAnnotations;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use TotalCMS\Mcp\DocsTools;
use TotalCMS\Mcp\Health;
use TotalCMS\Mcp\SafeFileSessionStore;

require_once __DIR__ . '/../vendor/autoload.php';

// Health check — returns server status for uptime monitoring
if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/health')) {
	$status = Health::status(__DIR__ . '/../data/index.json');
	http_response_code($status['ok'] ? 200 : 503);
	header('Content-Type: application/json');
	echo json_encode($status, JSON_PRETTY_PRINT);
	exit;
}

// Redirect browsers to the docs
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
	header('Location: https://docs.totalcms.co/advanced/ai-integration/', true, 302);
	exit;
}

// Set up rotating logger (one file per day, keep 14 days)
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
	@mkdir($logDir, 0o755, true);
}
$logHandler = new RotatingFileHandler($logDir . '/mcp.log', 14, Logger::INFO);
$logHandler->setFormatter(new LineFormatter(null, null, true, true));
$logger = new Logger('mcp');
$logger->pushHandler($logHandler);

// Load the documentation index (APCu-cached, invalidated by file mtime)
$indexPath = __DIR__ . '/../data/index.json';
if (!file_exists($indexPath)) {
	http_response_code(500);
	echo json_encode(['error' => 'Documentation index not built. Run: php bin/build-index.php']);
	exit(1);
}

$index = null;
if (function_exists('apcu_fetch') && apcu_enabled()) {
	$cacheKey = 'tcms_mcp_index:' . filemtime($indexPath);
	$cached = apcu_fetch($cacheKey, $hit);
	if ($hit) {
		$index = $cached;
	} else {
		$index = json_decode(file_get_contents($indexPath), true);
		apcu_store($cacheKey, $index, 3600);
	}
} else {
	$index = json_decode(file_get_contents($indexPath), true);
}

$tools = new DocsTools($index);
$readOnly = new ToolAnnotations(readOnlyHint: true);

// Build the MCP server with manually registered tools
$builder = Server::builder()
	->setServerInfo(
		name: 'Total CMS Docs',
		version: '1.0.0',
		description: 'Total CMS documentation server — look up Twig functions, filters, field types, API endpoints, and more.',
	)
	->setInstructions(
		'This server provides documentation for Total CMS, a flat-file PHP content management system. '
		. 'Use docs_search for general queries. Use the specific lookup tools (docs_twig_function, docs_twig_filter, etc.) '
		. 'when you know exactly what you are looking for.'
	)
	->setSession(new SafeFileSessionStore(__DIR__ . '/../data/sessions', logger: $logger))
	->setLogger($logger);

// Register tools as closures bound to the DocsTools instance
$builder->addTool(
	handler: fn (string $query) => $tools->search($query),
	name: 'docs_search',
	description: 'Full-text search across all Total CMS documentation. Returns matching sections with context and source URLs.',
	annotations: $readOnly,
);

$builder->addTool(
	handler: fn (string $name) => $tools->twigFunction($name),
	name: 'docs_twig_function',
	description: 'Look up a Total CMS Twig function by name. Returns signature, parameters, return type, and examples. Example: docs_twig_function("cms.collection.objects")',
	annotations: $readOnly,
);

$builder->addTool(
	handler: fn (string $name) => $tools->twigFilter($name),
	name: 'docs_twig_filter',
	description: 'Look up a Total CMS Twig filter by name. Returns signature, description, and examples. Example: docs_twig_filter("humanize")',
	annotations: $readOnly,
);

$builder->addTool(
	handler: fn (string $name) => $tools->fieldType($name),
	name: 'docs_field_type',
	description: 'Look up a Total CMS field type by name. Returns configuration options, schema settings, and examples. Example: docs_field_type("image")',
	annotations: $readOnly,
);

$builder->addTool(
	handler: fn (string $method, string $path) => $tools->apiEndpoint($method, $path),
	name: 'docs_api_endpoint',
	description: 'Look up a Total CMS REST API endpoint. Returns method, path, parameters, and response shape. Example: docs_api_endpoint("GET", "/collections/{name}")',
	annotations: $readOnly,
);

$builder->addTool(
	handler: fn (string $key) => $tools->schemaConfig($key),
	name: 'docs_schema_config',
	description: 'Look up a Total CMS schema or collection configuration option. Returns description, valid values, and defaults. Example: docs_schema_config("labelPlural")',
	annotations: $readOnly,
);

$builder->addTool(
	handler: fn (string $name) => $tools->cliCommand($name),
	name: 'docs_cli_command',
	description: 'Look up a Total CMS CLI command. Returns syntax, flags, and usage. Note: CLI is currently in development.',
	annotations: $readOnly,
);

$builder->addTool(
	handler: fn (string $query) => $tools->extension($query),
	name: 'docs_extension',
	description: 'Look up Total CMS extension API details: context methods, events, permissions, manifest fields, or bundled extensions. Example: docs_extension("addTwigFunction"), docs_extension("object.created"), docs_extension("permissions"), docs_extension("bundled"), docs_extension("totalcms/ab-split"), or docs_extension("geo-redirect")',
	annotations: $readOnly,
);

$builder->addTool(
	handler: fn (string $query) => $tools->builder($query),
	name: 'docs_builder',
	description: 'Look up Total CMS Site Builder details: page schema, directory structure, twig functions, asset pipeline, starter templates, CLI commands, route patterns, and page features (middleware). Examples: docs_builder("overview"), docs_builder("schema"), docs_builder("twig"), docs_builder("features"), docs_builder("starters"), docs_builder("cms.builder.nav"), docs_builder("ab-split")',
	annotations: $readOnly,
);

$server = $builder->build();

// Log MCP initialize requests so we can see which clients are connecting
$rawBody = file_get_contents('php://input');
if ($rawBody !== false && str_contains($rawBody, '"initialize"')) {
	$payload = json_decode($rawBody, true);
	$clientInfo = $payload['params']['clientInfo'] ?? [];
	$logger->info('MCP initialize', [
		'client'  => $clientInfo['name'] ?? 'unknown',
		'version' => $clientInfo['version'] ?? '',
		'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
	]);
}

// Create PSR-7 request from PHP globals (reusing the raw body we already read)
$request = ServerRequest::fromGlobals();
if ($rawBody !== false && $rawBody !== '') {
	$request = $request->withBody(\GuzzleHttp\Psr7\Utils::streamFor($rawBody));
}

// Run transport
$transport = new StreamableHttpTransport($request);
$response = $server->run($transport);

// Emit response
(new SapiEmitter())->emit($response);
