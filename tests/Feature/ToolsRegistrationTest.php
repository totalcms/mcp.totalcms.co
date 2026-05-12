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

	expect(mcpToolNamesFromIndexPhp())->toBe($expected);
});

it('binds every registered tool handler to a DocsTools method', function (): void {
	$handlers     = handlerMethodNamesFromIndexPhp();
	$publicMethods = publicToolMethodNames();

	$missing = array_diff($handlers, $publicMethods);

	expect($missing)->toBe(
		[],
		'public/index.php references a $tools->method() that does not exist on DocsTools: '
		. implode(', ', $missing)
	);
});

it('registers a handler for every public method on DocsTools', function (): void {
	$publicMethods = publicToolMethodNames();
	$handlers     = handlerMethodNamesFromIndexPhp();

	$unregistered = array_diff($publicMethods, $handlers);

	expect($unregistered)->toBe(
		[],
		'DocsTools has public methods that no tool handler calls: '
		. implode(', ', $unregistered)
		. ' (either register the tool in public/index.php or make the method private/protected)'
	);
});
