<?php

declare(strict_types=1);

use TotalCMS\Mcp\DocsTools;

/**
 * Build a DocsTools instance against the test fixture index.
 */
function docsTools(): DocsTools
{
	static $index;
	if ($index === null) {
		$index = require __DIR__ . '/fixtures/index.php';
	}
	return new DocsTools($index);
}

/**
 * Return the public method names on DocsTools that look like tool handlers
 * (i.e. anything except the constructor and private helpers).
 *
 * @return string[]
 */
function publicToolMethodNames(): array
{
	$reflection = new ReflectionClass(DocsTools::class);
	$names = [];
	foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
		if ($method->isConstructor()) {
			continue;
		}
		if ($method->getDeclaringClass()->getName() !== DocsTools::class) {
			continue;
		}
		$names[] = $method->getName();
	}
	sort($names);
	return $names;
}

/**
 * Return the tool names registered with $builder->addTool(name: '...') in
 * src/McpServerFactory.php.
 *
 * @return string[]
 */
function mcpToolNamesFromFactory(): array
{
	$source = file_get_contents(__DIR__ . '/../src/McpServerFactory.php');

	// Strip PHP comments via the tokenizer so commented-out addTool blocks
	// don't get picked up by the regex below.
	$stripped = '';
	foreach (PhpToken::tokenize($source) as $token) {
		if (in_array($token->id, [T_COMMENT, T_DOC_COMMENT], true)) {
			continue;
		}
		$stripped .= $token->text;
	}

	preg_match_all('/->addTool\b.*?name:\s*[\'"]([^\'"]+)[\'"]/s', $stripped, $matches);
	$names = $matches[1];
	sort($names);
	return $names;
}

/**
 * Return the handler method names referenced by $tools->methodName(...) inside
 * addTool() handler closures in src/McpServerFactory.php.
 *
 * @return string[]
 */
function handlerMethodNamesFromFactory(): array
{
	$source = file_get_contents(__DIR__ . '/../src/McpServerFactory.php');

	$stripped = '';
	foreach (PhpToken::tokenize($source) as $token) {
		if (in_array($token->id, [T_COMMENT, T_DOC_COMMENT], true)) {
			continue;
		}
		$stripped .= $token->text;
	}

	preg_match_all('/\$tools->(\w+)\s*\(/', $stripped, $matches);
	$names = array_values(array_unique($matches[1]));
	sort($names);
	return $names;
}
