<?php

declare(strict_types=1);

use Mcp\Server\Session\InMemorySessionStore;
use TotalCMS\Mcp\McpServerFactory;
use TotalCMS\Mcp\Tests\Support\CapturingTransport;

/**
 * Drive a real SDK Server through CapturingTransport with canned JSON-RPC
 * messages so we exercise the registration → tools/list → tools/call flow
 * end-to-end. The regex-based ToolsRegistrationTest covers the source, this
 * file covers the runtime contract.
 */

beforeEach(function (): void {
	$this->index  = require __DIR__ . '/../fixtures/index.php';
	$this->server = McpServerFactory::build($this->index, new InMemorySessionStore());
});

/**
 * Build an MCP `initialize` request body — the SDK requires it as the first
 * message in any session before tools/* can be called.
 */
function initializeMessage(int $id = 1): string
{
	return json_encode([
		'jsonrpc' => '2.0',
		'id'      => $id,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => [],
			'clientInfo'      => ['name' => 'pest', 'version' => '1.0'],
		],
	], JSON_THROW_ON_ERROR);
}

function jsonrpcMessage(int $id, string $method, array $params = []): string
{
	return json_encode([
		'jsonrpc' => '2.0',
		'id'      => $id,
		'method'  => $method,
		'params'  => $params,
	], JSON_THROW_ON_ERROR);
}

it('initialize succeeds and returns the server info we configured', function (): void {
	$transport = new CapturingTransport([initializeMessage(1)]);

	$this->server->run($transport);

	$response = $transport->responseFor(1);

	expect($response)->not->toBeNull();
	expect($response['result']['serverInfo']['name'])->toBe('Total CMS Docs');
	expect($response['result']['serverInfo']['version'])->toBe('1.0.0');
	expect($response['result']['capabilities'])->toHaveKey('tools');
});

it('tools/list returns every tool registered in McpServerFactory', function (): void {
	$transport = new CapturingTransport([
		initializeMessage(1),
		jsonrpcMessage(2, 'tools/list'),
	]);

	$this->server->run($transport);

	$response = $transport->responseFor(2);

	expect($response)->not->toBeNull();
	expect($response)->toHaveKey('result');
	expect($response['result'])->toHaveKey('tools');

	$names = array_column($response['result']['tools'], 'name');
	sort($names);

	expect($names)->toBe([
		'docs_api_endpoint',
		'docs_builder',
		'docs_cli_command',
		'docs_extension',
		'docs_field_type',
		'docs_schema_config',
		'docs_search',
		'docs_twig_filter',
		'docs_twig_function',
	]);
});

it('tools/list advertises descriptions and readOnlyHint annotations', function (): void {
	$transport = new CapturingTransport([
		initializeMessage(1),
		jsonrpcMessage(2, 'tools/list'),
	]);

	$this->server->run($transport);

	$tools = $transport->responseFor(2)['result']['tools'];

	$byName = [];
	foreach ($tools as $tool) {
		$byName[$tool['name']] = $tool;
	}

	expect($byName['docs_search']['description'])->toContain('Full-text search');
	expect($byName['docs_search']['annotations']['readOnlyHint'])->toBeTrue();
	expect($byName['docs_extension']['description'])->toContain('bundled');
});

it('tools/call dispatches to the bound DocsTools handler and returns its JSON', function (): void {
	$transport = new CapturingTransport([
		initializeMessage(1),
		jsonrpcMessage(2, 'tools/call', [
			'name'      => 'docs_search',
			'arguments' => ['query' => 'blog'],
		]),
	]);

	$this->server->run($transport);

	$response = $transport->responseFor(2);

	expect($response)->not->toBeNull();
	expect($response)->toHaveKey('result');

	// MCP wraps tool results as content blocks. The payload our DocsTools
	// methods return is a JSON string, so it surfaces as a text content block.
	$content = $response['result']['content'][0] ?? null;
	expect($content)->not->toBeNull();
	expect($content['type'])->toBe('text');

	$decoded = json_decode($content['text'], true);
	expect($decoded)->toBeArray();
	expect($decoded['results'][0]['title'])->toBe('Blog Collection');
});

it('tools/call returns an error for an unknown tool name', function (): void {
	$transport = new CapturingTransport([
		initializeMessage(1),
		jsonrpcMessage(2, 'tools/call', [
			'name'      => 'docs_does_not_exist',
			'arguments' => [],
		]),
	]);

	$this->server->run($transport);

	$response = $transport->responseFor(2);

	expect($response)->not->toBeNull();
	// MCP spec says unknown tool name should produce either a JSON-RPC error
	// or an isError content block — accept either since both signal failure
	// to the client.
	$hasError = isset($response['error'])
		|| ($response['result']['isError'] ?? false) === true;
	expect($hasError)->toBeTrue();
});
