<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\ServerRequest;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Mcp\DocsTools;

// Load the documentation index
$indexPath = __DIR__ . '/../data/index.json';
if (!file_exists($indexPath)) {
	http_response_code(500);
	echo json_encode(['error' => 'Documentation index not built. Run: php bin/build-index.php']);
	exit(1);
}

$index = json_decode(file_get_contents($indexPath), true);
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
	->setSession(new FileSessionStore(__DIR__ . '/../data/sessions'));

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

$server = $builder->build();

// Log MCP connections (initialize requests only)
$rawBody = file_get_contents('php://input');
if ($rawBody !== false && str_contains($rawBody, '"initialize"')) {
	$payload = json_decode($rawBody, true);
	$clientInfo = $payload['params']['clientInfo'] ?? [];
	$logEntry = date('c')
		. "\t" . ($clientInfo['name'] ?? 'unknown')
		. "\t" . ($clientInfo['version'] ?? '')
		. "\t" . ($_SERVER['REMOTE_ADDR'] ?? '')
		. "\n";
	file_put_contents(__DIR__ . '/../logs/connections.log', $logEntry, FILE_APPEND | LOCK_EX);
}

// Create PSR-7 request from PHP globals
$request = ServerRequest::fromGlobals();

// Run transport
$transport = new StreamableHttpTransport($request);
$response = $server->run($transport);

// Emit response
(new SapiEmitter())->emit($response);
