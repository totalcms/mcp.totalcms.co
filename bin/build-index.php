#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build the MCP documentation index from Total CMS source docs.
 *
 * Usage: php bin/build-index.php [/path/to/totalcms]
 * Output: data/index.json
 *
 * Parses resources/docs/ markdown files into a structured index with:
 * - pages: Full-text searchable page content
 * - twig_functions: Parsed function signatures from Twig docs
 * - twig_filters: Parsed filter signatures
 * - field_types: Field type configurations from property-settings docs
 * - api_endpoints: REST API endpoint definitions
 * - schema_config: Schema/collection configuration options
 */

$totalcmsPath = $argv[1] ?? '/Users/joeworkman/Developer/totalcms';
$docsDir = $totalcmsPath . '/resources/docs';
$outputFile = __DIR__ . '/../data/index.json';

if (!is_dir($docsDir)) {
	echo "Error: Docs directory not found at {$docsDir}\n";
	echo "Usage: php bin/build-index.php [/path/to/totalcms]\n";
	exit(1);
}

echo "Building MCP documentation index...\n";
echo "Source: {$docsDir}\n\n";

// -------------------------------------------------------
// Collect all markdown files
// -------------------------------------------------------
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docsDir));
$files = [];
foreach ($iterator as $file) {
	if ($file->isFile() && $file->getExtension() === 'md') {
		$relativePath = str_replace($docsDir . '/', '', $file->getPathname());
		// Skip internal docs
		if (str_starts_with($relativePath, 'internal/')) {
			continue;
		}
		$files[$relativePath] = $file->getPathname();
	}
}

echo "Found " . count($files) . " documentation files.\n";

// -------------------------------------------------------
// Parse all pages for full-text search
// -------------------------------------------------------
$pages = [];
foreach ($files as $relativePath => $fullPath) {
	$content = file_get_contents($fullPath);
	$frontmatter = parseFrontmatter($content);
	$body = removeFrontmatter($content);
	$path = str_replace('.md', '', $relativePath);

	// Extract sections (H2 headings)
	$sections = [];
	if (preg_match_all('/^##\s+(.+)$/m', $body, $matches)) {
		$sections = $matches[1];
	}

	// Clean content for searching
	$searchContent = cleanForSearch($body);

	$pages[] = [
		'title'    => $frontmatter['title'] ?? extractH1($body) ?? basename($path),
		'path'     => $path,
		'url'      => 'https://docs.totalcms.co/' . str_replace('.md', '/', $relativePath),
		'sections' => $sections,
		'content'  => $searchContent,
	];
}

echo "  Pages indexed: " . count($pages) . "\n";

// -------------------------------------------------------
// Parse Twig filters from filters.md
// -------------------------------------------------------
$twigFilters = [];
$filtersFile = $docsDir . '/twig/filters.md';
if (file_exists($filtersFile)) {
	$content = file_get_contents($filtersFile);
	$twigFilters = parseFilterSignatures($content);
}
echo "  Twig filters: " . count($twigFilters) . "\n";

// -------------------------------------------------------
// Parse Twig functions from multiple files
// -------------------------------------------------------
$twigFunctions = [];

// functions.md has standalone functions
$functionsFile = $docsDir . '/twig/functions.md';
if (file_exists($functionsFile)) {
	$content = file_get_contents($functionsFile);
	$twigFunctions = array_merge($twigFunctions, parseFunctionSignatures($content));
}

// Parse cms.* namespace functions from various files
$twigNamespaceFiles = [
	'twig/collections.md',
	'twig/data.md',
	'twig/media.md',
	'twig/imageworks.md',
	'twig/variables.md',
	'twig/totalcms.md',
	'twig/auth.md',
	'twig/forms.md',
	'twig/forms/builder.md',
	'twig/forms/overview.md',
	'twig/render.md',
	'twig/views.md',
	'twig/locale.md',
	'twig/localization.md',
	'twig/load-more.md',
	'twig/utils.md',
];

foreach ($twigNamespaceFiles as $relPath) {
	$filePath = $docsDir . '/' . $relPath;
	if (file_exists($filePath)) {
		$content = file_get_contents($filePath);
		$twigFunctions = array_merge($twigFunctions, parseNamespaceFunctions($content, $relPath));
	}
}

echo "  Twig functions: " . count($twigFunctions) . "\n";

// -------------------------------------------------------
// Parse field types from property-settings docs
// -------------------------------------------------------
$fieldTypes = [];
$propSettingsDir = $docsDir . '/property-settings';
if (is_dir($propSettingsDir)) {
	$propFiles = glob($propSettingsDir . '/*.md');
	foreach ($propFiles as $propFile) {
		$content = file_get_contents($propFile);
		$frontmatter = parseFrontmatter($content);
		$body = removeFrontmatter($content);
		$fieldTypes[] = [
			'name'        => str_replace('.md', '', basename($propFile)),
			'title'       => $frontmatter['title'] ?? basename($propFile, '.md'),
			'description' => $frontmatter['description'] ?? '',
			'content'     => cleanForSearch($body),
			'url'         => 'https://docs.totalcms.co/property-settings/' . str_replace('.md', '/', basename($propFile)),
		];
	}
}

// Also parse schema types from schemas directory
$schemasDir = $docsDir . '/schemas';
if (is_dir($schemasDir)) {
	$schemaFiles = glob($schemasDir . '/*.md');
	foreach ($schemaFiles as $schemaFile) {
		$content = file_get_contents($schemaFile);
		$frontmatter = parseFrontmatter($content);
		$body = removeFrontmatter($content);
		$fieldTypes[] = [
			'name'        => str_replace('.md', '', basename($schemaFile)),
			'title'       => $frontmatter['title'] ?? basename($schemaFile, '.md'),
			'description' => $frontmatter['description'] ?? '',
			'content'     => cleanForSearch($body),
			'url'         => 'https://docs.totalcms.co/schemas/' . str_replace('.md', '/', basename($schemaFile)),
		];
	}
}
echo "  Field types: " . count($fieldTypes) . "\n";

// -------------------------------------------------------
// Parse REST API endpoints from rest-api.md
// -------------------------------------------------------
$apiEndpoints = [];
$apiFile = $docsDir . '/api/rest-api.md';
if (file_exists($apiFile)) {
	$content = file_get_contents($apiFile);
	$apiEndpoints = parseApiEndpoints($content);
}

// Also check index-filter.md for additional API docs
$indexFilterFile = $docsDir . '/api/index-filter.md';
if (file_exists($indexFilterFile)) {
	$content = file_get_contents($indexFilterFile);
	$apiEndpoints = array_merge($apiEndpoints, parseApiEndpoints($content));
}
echo "  API endpoints: " . count($apiEndpoints) . "\n";

// -------------------------------------------------------
// Parse schema/collection config options
// -------------------------------------------------------
$schemaConfig = [];
$settingsFile = $docsDir . '/collections/settings.md';
if (file_exists($settingsFile)) {
	$content = file_get_contents($settingsFile);
	$schemaConfig = parseSchemaConfig($content);
}
echo "  Schema config options: " . count($schemaConfig) . "\n";

// -------------------------------------------------------
// Parse CLI commands from cli/commands.md
// -------------------------------------------------------
$cliCommands = [];
$cliFile = $docsDir . '/advanced/cli.md';
if (file_exists($cliFile)) {
	$content = file_get_contents($cliFile);
	$cliCommands = parseCliCommands($content);
}
echo "  CLI commands: " . count($cliCommands) . "\n";

// -------------------------------------------------------
// Build the final index
// -------------------------------------------------------
$index = [
	'version'        => '1.0.0',
	'built_at'       => date('c'),
	'pages'          => $pages,
	'twig_functions' => $twigFunctions,
	'twig_filters'   => $twigFilters,
	'field_types'    => $fieldTypes,
	'api_endpoints'  => $apiEndpoints,
	'schema_config'  => $schemaConfig,
	'cli_commands'   => $cliCommands,
];

$json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_dir(dirname($outputFile))) {
	mkdir(dirname($outputFile), 0755, true);
}
file_put_contents($outputFile, $json);

$sizeKb = round(strlen($json) / 1024);
echo "\nIndex built successfully!\n";
echo "Output: {$outputFile} ({$sizeKb} KB)\n";

// =======================================================
// Parsing functions
// =======================================================

function parseFrontmatter(string $content): array
{
	if (preg_match('/^---\s*\n(.*?)\n---/s', $content, $match)) {
		$data = [];
		// Simple YAML key: "value" parser
		if (preg_match_all('/^(\w+):\s*"?([^"\n]*)"?\s*$/m', $match[1], $pairs, PREG_SET_ORDER)) {
			foreach ($pairs as $pair) {
				$data[$pair[1]] = $pair[2];
			}
		}
		return $data;
	}
	return [];
}

function removeFrontmatter(string $content): string
{
	return (string) preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
}

function extractH1(string $content): ?string
{
	if (preg_match('/^#\s+(.+)$/m', $content, $match)) {
		return trim($match[1]);
	}
	return null;
}

function cleanForSearch(string $body): string
{
	// Remove code blocks but keep content
	$clean = (string) preg_replace('/```\w*\n?/', '', $body);
	// Remove HTML tags
	$clean = strip_tags($clean);
	// Remove markdown formatting (preserve dots for cms.method names)
	$clean = (string) preg_replace('/[#*_`\[\]()]/', ' ', $clean);
	// Remove URLs
	$clean = (string) preg_replace('/https?:\/\/[^\s]+/', '', $clean);
	// Remove Twig delimiters
	$clean = (string) preg_replace('/\{\{|\}\}|\{%|%\}|\{#|#\}/', ' ', $clean);
	// Normalize whitespace
	$clean = (string) preg_replace('/\s+/', ' ', $clean);
	return strtolower(trim($clean));
}

/**
 * Parse filter signatures from filters.md.
 * Pattern: #### `filterName(params): returnType`
 *
 * @return array<int, array<string, mixed>>
 */
function parseFilterSignatures(string $content): array
{
	$filters = [];
	$body = removeFrontmatter($content);

	// Split by H4 headings that contain backtick signatures
	$parts = preg_split('/^####\s+/m', $body);

	foreach ($parts as $part) {
		$part = trim($part);
		if ($part === '' || !str_starts_with($part, '`')) {
			continue;
		}

		// Extract signature from first line
		if (!preg_match('/^`([^`]+)`\s*$/m', $part, $sigMatch)) {
			continue;
		}

		$signature = $sigMatch[1];

		// Parse name from signature (before the opening paren)
		if (preg_match('/^(\w+)/', $signature, $nameMatch)) {
			$name = $nameMatch[1];
		} else {
			continue;
		}

		// Get description (text after signature line, before first code block)
		$descriptionLines = [];
		$lines = explode("\n", $part);
		$pastSignature = false;
		foreach ($lines as $line) {
			if (!$pastSignature) {
				if (str_contains($line, '`' . $signature . '`') || str_starts_with($line, '`')) {
					$pastSignature = true;
				}
				continue;
			}
			$trimmed = trim($line);
			if ($trimmed === '' && !empty($descriptionLines)) {
				break;
			}
			if (str_starts_with($trimmed, '```')) {
				break;
			}
			if ($trimmed !== '') {
				$descriptionLines[] = $trimmed;
			}
		}
		$description = implode(' ', $descriptionLines);

		// Extract code examples
		$examples = extractCodeBlocks($part);

		$filters[] = [
			'name'        => $name,
			'signature'   => $signature,
			'description' => $description,
			'examples'    => $examples,
			'url'         => 'https://docs.totalcms.co/twig/filters/',
		];
	}

	return $filters;
}

/**
 * Parse standalone function signatures from functions.md.
 * Pattern: ### `functionName(params): returnType`
 *
 * @return array<int, array<string, mixed>>
 */
function parseFunctionSignatures(string $content): array
{
	$functions = [];
	$body = removeFrontmatter($content);

	// Split by H3 headings that contain backtick signatures
	$parts = preg_split('/^###\s+/m', $body);

	foreach ($parts as $part) {
		$part = trim($part);
		if ($part === '' || !str_starts_with($part, '`')) {
			continue;
		}

		if (!preg_match('/^`([^`]+)`/m', $part, $sigMatch)) {
			continue;
		}

		$signature = $sigMatch[1];

		if (preg_match('/^(\w+)/', $signature, $nameMatch)) {
			$name = $nameMatch[1];
		} else {
			continue;
		}

		$description = extractDescription($part);
		$examples = extractCodeBlocks($part);

		$functions[] = [
			'name'        => $name,
			'signature'   => $signature,
			'description' => $description,
			'examples'    => $examples,
			'url'         => 'https://docs.totalcms.co/twig/functions/',
		];
	}

	return $functions;
}

/**
 * Parse cms.* namespace functions from collection/data/media docs.
 * Pattern: ### methodName() or ## methodName()
 *
 * @return array<int, array<string, mixed>>
 */
function parseNamespaceFunctions(string $content, string $sourceFile): array
{
	$functions = [];
	$body = removeFrontmatter($content);

	// Determine the namespace from the H1 heading (e.g., "# cms.collection")
	$namespace = '';
	if (preg_match('/^#\s+(cms\.\w+)/m', $body, $nsMatch)) {
		$namespace = $nsMatch[1];
	}

	// Determine URL from source file
	$urlPath = str_replace('.md', '/', $sourceFile);
	$url = 'https://docs.totalcms.co/' . $urlPath;

	// Split by H3 headings (method names)
	$parts = preg_split('/^###\s+/m', $body);

	foreach ($parts as $part) {
		$part = trim($part);
		if ($part === '') {
			continue;
		}

		// Get method name from first line
		$firstLine = strtok($part, "\n");
		if ($firstLine === false) {
			continue;
		}

		// Clean up the method name — remove backticks, trailing parens
		$methodName = trim($firstLine);
		$methodName = trim($methodName, '`');

		// Skip headings that don't look like method/function names
		if (str_contains($methodName, ' ') && !str_contains($methodName, '(')) {
			continue;
		}

		// Remove trailing () if present
		$cleanName = rtrim($methodName, '()');

		// Build full qualified name
		$fullName = $namespace ? $namespace . '.' . $cleanName : $cleanName;

		$description = extractDescription($part);
		$examples = extractCodeBlocks($part);

		if (!empty($description) || !empty($examples)) {
			$functions[] = [
				'name'        => $fullName,
				'signature'   => $methodName,
				'description' => $description,
				'examples'    => $examples,
				'url'         => $url,
			];
		}
	}

	return $functions;
}

/**
 * Parse REST API endpoints from rest-api.md.
 * Pattern: ```http\nMETHOD /path\n```
 *
 * @return array<int, array<string, mixed>>
 */
function parseApiEndpoints(string $content): array
{
	$endpoints = [];
	$body = removeFrontmatter($content);

	// Split into sections by H3 headings
	$sections = preg_split('/^###\s+/m', $body);

	foreach ($sections as $section) {
		$section = trim($section);
		if ($section === '') {
			continue;
		}

		$sectionTitle = strtok($section, "\n");

		// Find HTTP method/path blocks
		if (preg_match_all('/```http\s*\n\s*(GET|POST|PUT|PATCH|DELETE)\s+(\S+)\s*\n```/i', $section, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$method = strtoupper($match[1]);
				$path = $match[2];

				// Get description: text between heading and first code block
				$descText = '';
				if (preg_match('/^[^\n]+\n\n(.+?)(?:\n\n```|\n```)/s', $section, $descMatch)) {
					$candidate = trim($descMatch[1]);
					// Only use if it doesn't start with a code fence
					if (!str_starts_with($candidate, '```')) {
						$descText = (string) preg_replace('/\s+/', ' ', $candidate);
					}
				}
				$description = $descText ?: (string) $sectionTitle;

				// Look for parameters table
				$parameters = [];
				if (preg_match_all('/\|\s*`(\w+)`\s*\|\s*(\w+)\s*\|\s*([^|]+)\|/m', $section, $paramMatches, PREG_SET_ORDER)) {
					foreach ($paramMatches as $pm) {
						$parameters[] = [
							'name'        => $pm[1],
							'type'        => trim($pm[2]),
							'description' => trim($pm[3]),
						];
					}
				}

				// Check for edition info
				$edition = 'lite';
				if (preg_match('/pro\s+edition/i', $section)) {
					$edition = 'pro';
				} elseif (preg_match('/standard\s+edition/i', $section)) {
					$edition = 'standard';
				}

				$endpoints[] = [
					'method'      => $method,
					'path'        => $path,
					'title'       => $sectionTitle ?: '',
					'description' => $description,
					'parameters'  => $parameters,
					'edition'     => $edition,
					'url'         => 'https://docs.totalcms.co/api/rest-api/',
				];
			}
		}
	}

	return $endpoints;
}

/**
 * Parse schema/collection configuration options from settings.md.
 * Pattern: ### key\n\n**Type:** `type`
 *
 * @return array<int, array<string, mixed>>
 */
function parseSchemaConfig(string $content): array
{
	$configs = [];
	$body = removeFrontmatter($content);

	// Split by H3 headings
	$sections = preg_split('/^###\s+/m', $body);

	foreach ($sections as $section) {
		$section = trim($section);
		if ($section === '') {
			continue;
		}

		$key = trim(strtok($section, "\n"));

		// Skip headings that look like prose rather than config keys
		if (str_contains($key, ' ') && strlen($key) > 30) {
			continue;
		}

		// Extract type
		$type = '';
		if (preg_match('/\*\*Type:\*\*\s*`([^`]+)`/', $section, $typeMatch)) {
			$type = $typeMatch[1];
		}

		// Extract required
		$required = false;
		if (preg_match('/\*\*Required:\*\*\s*(Yes|true)/i', $section)) {
			$required = true;
		}

		// Extract default
		$default = null;
		if (preg_match('/\*\*Default:\*\*\s*`([^`]*)`/', $section, $defMatch)) {
			$default = $defMatch[1];
		}

		$description = extractDescription($section);
		$examples = extractCodeBlocks($section);

		$configs[] = [
			'key'         => $key,
			'type'        => $type,
			'required'    => $required,
			'default'     => $default,
			'description' => $description,
			'examples'    => $examples,
			'url'         => 'https://docs.totalcms.co/collections/settings/',
		];
	}

	return $configs;
}

// =======================================================
// Helper functions
// =======================================================

/**
 * Extract the first paragraph of description text after the heading line.
 */
function extractDescription(string $section): string
{
	$lines = explode("\n", $section);
	$descLines = [];
	$started = false;

	foreach ($lines as $i => $line) {
		if ($i === 0) {
			continue; // Skip heading line
		}

		$trimmed = trim($line);

		// Skip empty lines before description starts
		if (!$started && $trimmed === '') {
			continue;
		}

		// Stop at code blocks, headings, tables, or blank lines after content
		if ($started && ($trimmed === '' || str_starts_with($trimmed, '```') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '|'))) {
			break;
		}

		// Skip metadata lines like **Type:** or **Required:**
		if (preg_match('/^\*\*\w+:\*\*/', $trimmed)) {
			if ($started) {
				break;
			}
			continue;
		}

		$started = true;
		$descLines[] = $trimmed;
	}

	return implode(' ', $descLines);
}

/**
 * Extract code blocks from a section.
 *
 * @return string[]
 */
function extractCodeBlocks(string $section): array
{
	$examples = [];
	if (preg_match_all('/```(\w*)\n(.*?)```/s', $section, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			$lang = $match[1] ?: 'text';
			$code = trim($match[2]);
			if ($code !== '') {
				$examples[] = $code;
			}
		}
	}
	// Limit to first 3 examples per entry
	return array_slice($examples, 0, 3);
}

/**
 * Parse CLI commands from cli/commands.md.
 * Pattern: ### `command:name`
 *
 * @return array<int, array<string, mixed>>
 */
function parseCliCommands(string $content): array
{
	$commands = [];
	$body = removeFrontmatter($content);

	// Split by H3 headings that contain backtick command names
	$parts = preg_split('/^###\s+/m', $body);

	foreach ($parts as $part) {
		$part = trim($part);
		if ($part === '' || !str_starts_with($part, '`')) {
			continue;
		}

		// Extract command name from backticks
		if (!preg_match('/^`([^`]+)`/', $part, $nameMatch)) {
			continue;
		}

		$name = $nameMatch[1];

		// Get description (first paragraph after the heading)
		$description = extractDescription($part);

		// Extract examples
		$examples = extractCodeBlocks($part);

		// Extract options table (rows where first column starts with --)
		$options = [];
		if (preg_match_all('/\|\s*`(--[^`]+)`\s*\|\s*([^|]+)\|/m', $part, $optMatches, PREG_SET_ORDER)) {
			foreach ($optMatches as $om) {
				$options[] = [
					'name'        => trim($om[1]),
					'description' => trim($om[2]),
				];
			}
		}

		// Extract arguments table (rows with Required Yes/No column)
		$arguments = [];
		if (preg_match_all('/\|\s*`(\w+)`\s*\|\s*(Yes|No)\s*\|\s*([^|]+)\|/m', $part, $argMatches, PREG_SET_ORDER)) {
			foreach ($argMatches as $am) {
				$arguments[] = [
					'name'        => trim($am[1]),
					'required'    => strtolower(trim($am[2])) === 'yes',
					'description' => trim($am[3]),
				];
			}
		}

		$commands[] = [
			'name'        => $name,
			'description' => $description,
			'arguments'   => $arguments,
			'options'     => $options,
			'examples'    => $examples,
			'url'         => 'https://docs.totalcms.co/advanced/cli/',
		];
	}

	return $commands;
}
