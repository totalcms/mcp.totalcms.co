<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Transport\StreamableHttpTransport;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use TotalCMS\Mcp\Health;
use TotalCMS\Mcp\McpServerFactory;
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

// Redirect browsers to the docs — but let MCP clients through.
// MCP's Streamable HTTP transport opens its SSE stream with GET + Accept: text/event-stream,
// so we only redirect when the Accept header looks like a plain browser visit.
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$isMcpRequest = str_contains($accept, 'application/json') || str_contains($accept, 'text/event-stream');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$isMcpRequest) {
	header('Location: https://docs.totalcms.co/extensions/ai-integration/', true, 302);
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

$sessionStore = new SafeFileSessionStore(__DIR__ . '/../data/sessions', logger: $logger);
$server       = McpServerFactory::build($index, $sessionStore, $logger);

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
$response  = $server->run($transport);

// Emit response
(new SapiEmitter())->emit($response);
