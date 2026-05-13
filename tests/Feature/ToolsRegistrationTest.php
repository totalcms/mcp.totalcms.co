<?php

declare(strict_types=1);

it('exposes the expected MCP tool names', function (): void {
	$expected = [
		'docs_api_endpoint',
		'docs_builder',
		'docs_cli_command',
		'docs_extension',
		'docs_field_type',
		'docs_schema_config',
		'docs_search',
		'docs_twig_filter',
		'docs_twig_function',
	];

	expect(mcpToolNamesFromFactory())->toBe($expected);
});

it('binds every registered tool handler to a DocsTools method', function (): void {
	$handlers     = handlerMethodNamesFromFactory();
	$publicMethods = publicToolMethodNames();

	$missing = array_diff($handlers, $publicMethods);

	expect($missing)->toBe(
		[],
		'McpServerFactory references a $tools->method() that does not exist on DocsTools: '
		. implode(', ', $missing)
	);
});

it('registers a handler for every public method on DocsTools', function (): void {
	$publicMethods = publicToolMethodNames();
	$handlers     = handlerMethodNamesFromFactory();

	$unregistered = array_diff($publicMethods, $handlers);

	expect($unregistered)->toBe(
		[],
		'DocsTools has public methods that no tool handler calls: '
		. implode(', ', $unregistered)
		. ' (either register the tool in McpServerFactory or make the method private/protected)'
	);
});
