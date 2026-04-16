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

$totalcmsPath = $argv[1] ?? '';
if ($totalcmsPath === '') {
	fwrite(STDERR, "Usage: php bin/build-index.php /path/to/totalcms\n");
	exit(1);
}
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
$extensionApi = buildExtensionApiReference();

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
	'extension_api'  => $extensionApi,
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
	// Commands introduced in specific versions (all unlisted commands are 3.2.0+)
	$versionMap = [
		'extension:list'    => '3.3.0',
		'extension:enable'  => '3.3.0',
		'extension:disable' => '3.3.0',
		'extension:remove'  => '3.3.0',
	];

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

		$cmd = [
			'name'        => $name,
			'description' => $description,
			'arguments'   => $arguments,
			'options'     => $options,
			'examples'    => $examples,
			'url'         => 'https://docs.totalcms.co/advanced/cli/',
		];

		if (isset($versionMap[$name])) {
			$cmd['min_version'] = $versionMap[$name];
		}

		$commands[] = $cmd;
	}

	return $commands;
}

/**
 * Build a structured reference of the extension API.
 * This is hand-maintained (not parsed from docs) since it represents the code contract.
 *
 * @return array<string, mixed>
 */
function buildExtensionApiReference(): array
{
	return [
		'min_version' => '3.3.0',
		'note' => 'The extension system requires Total CMS 3.3.0 or later. It is not available in earlier versions.',
		'context_methods' => [
			[
				'name'        => 'extensionId',
				'signature'   => 'extensionId(): string',
				'description' => 'Get the extension ID (e.g. "vendor/extension-name")',
				'phase'       => 'both',
			],
			[
				'name'        => 'extensionPath',
				'signature'   => 'extensionPath(): string',
				'description' => 'Get the absolute filesystem path to the extension directory',
				'phase'       => 'both',
			],
			[
				'name'        => 'manifest',
				'signature'   => 'manifest(): ExtensionManifest',
				'description' => 'Get the parsed extension manifest',
				'phase'       => 'both',
			],
			[
				'name'        => 'settings',
				'signature'   => 'settings(): array',
				'description' => 'Get all extension settings from tcms-data',
				'phase'       => 'both',
				'permission'  => 'settings:read',
			],
			[
				'name'        => 'setting',
				'signature'   => 'setting(string $key, mixed $default = null): mixed',
				'description' => 'Get a single extension setting value',
				'phase'       => 'both',
				'permission'  => 'settings:read',
			],
			[
				'name'        => 'get',
				'signature'   => 'get(string $serviceId): mixed',
				'description' => 'Resolve a service from the DI container. Only use in boot(), not register().',
				'phase'       => 'boot',
			],
			[
				'name'        => 'has',
				'signature'   => 'has(string $serviceId): bool',
				'description' => 'Check if a service exists in the DI container',
				'phase'       => 'boot',
			],
			[
				'name'        => 'installSchema',
				'signature'   => 'installSchema(array $schemaData): void',
				'description' => 'Install a user-editable schema into tcms-data/.schemas/. Skips if schema already exists. Pro+ only.',
				'phase'       => 'boot',
			],
			[
				'name'        => 'addTwigFunction',
				'signature'   => 'addTwigFunction(TwigFunction $function): void',
				'description' => 'Register a custom Twig function available in all templates',
				'phase'       => 'register',
				'permission'  => 'twig:functions',
			],
			[
				'name'        => 'addTwigFilter',
				'signature'   => 'addTwigFilter(TwigFilter $filter): void',
				'description' => 'Register a custom Twig filter',
				'phase'       => 'register',
				'permission'  => 'twig:filters',
			],
			[
				'name'        => 'addTwigGlobal',
				'signature'   => 'addTwigGlobal(string $name, mixed $value): void',
				'description' => 'Register a Twig global variable',
				'phase'       => 'register',
				'permission'  => 'twig:globals',
			],
			[
				'name'        => 'addCommand',
				'signature'   => 'addCommand(Command $command): void',
				'description' => 'Register a CLI command. Name must be namespaced (e.g. "vendor:command").',
				'phase'       => 'register',
				'permission'  => 'cli:commands',
			],
			[
				'name'        => 'addRoutes',
				'signature'   => 'addRoutes(callable $registrar): void',
				'description' => 'Register routes under /ext/{vendor}/{name}/. Callable receives RouteCollectorProxy.',
				'phase'       => 'register',
				'permission'  => 'routes:api or routes:admin',
			],
			[
				'name'        => 'addAdminNavItem',
				'signature'   => 'addAdminNavItem(AdminNavItem $item): void',
				'description' => 'Add a navigation item to the admin sidebar',
				'phase'       => 'register',
				'permission'  => 'admin:nav',
			],
			[
				'name'        => 'addDashboardWidget',
				'signature'   => 'addDashboardWidget(DashboardWidget $widget): void',
				'description' => 'Add a widget to the admin dashboard',
				'phase'       => 'register',
				'permission'  => 'admin:widgets',
			],
			[
				'name'        => 'addFieldType',
				'signature'   => 'addFieldType(string $typeName, string $fqcn): void',
				'description' => 'Register a custom field type. Class must extend FormField.',
				'phase'       => 'register',
				'permission'  => 'fields:register',
			],
			[
				'name'        => 'addEventListener',
				'signature'   => 'addEventListener(string $eventName, callable $listener, int $priority = 0): void',
				'description' => 'Subscribe to a content event. Lower priority = earlier execution.',
				'phase'       => 'register',
				'permission'  => 'events:listen',
			],
			[
				'name'        => 'addContainerDefinition',
				'signature'   => 'addContainerDefinition(string $id, callable $factory): void',
				'description' => 'Register a service in the DI container',
				'phase'       => 'register',
				'permission'  => 'container:definitions',
			],
		],
		'events' => [
			[
				'name'        => 'object.created',
				'description' => 'Fired after a new object is saved',
				'payload'     => ['collection' => 'string', 'id' => 'string'],
			],
			[
				'name'        => 'object.updated',
				'description' => 'Fired after an existing object is updated',
				'payload'     => ['collection' => 'string', 'id' => 'string'],
			],
			[
				'name'        => 'object.deleted',
				'description' => 'Fired after an object is deleted',
				'payload'     => ['collection' => 'string', 'id' => 'string'],
			],
			[
				'name'        => 'schema.saved',
				'description' => 'Fired after a schema is created or updated',
				'payload'     => ['schema' => 'string'],
			],
		],
		'permissions' => [
			['id' => 'twig:functions',        'description' => 'Register custom Twig functions'],
			['id' => 'twig:filters',          'description' => 'Register custom Twig filters'],
			['id' => 'twig:globals',          'description' => 'Register Twig global variables'],
			['id' => 'cli:commands',          'description' => 'Register CLI commands'],
			['id' => 'routes:api',            'description' => 'Register REST API endpoints'],
			['id' => 'routes:admin',          'description' => 'Register admin pages'],
			['id' => 'admin:nav',             'description' => 'Add items to admin navigation'],
			['id' => 'admin:widgets',         'description' => 'Add dashboard widgets'],
			['id' => 'events:listen',         'description' => 'Subscribe to content events'],
			['id' => 'fields:register',       'description' => 'Register custom field types'],
			['id' => 'settings:read',         'description' => 'Read extension settings'],
			['id' => 'settings:write',        'description' => 'Write extension settings'],
			['id' => 'container:definitions', 'description' => 'Register DI container services'],
		],
		'manifest_fields' => [
			['field' => 'id',              'required' => true,  'description' => 'Unique ID in vendor/name format'],
			['field' => 'name',            'required' => true,  'description' => 'Human-readable name'],
			['field' => 'version',         'required' => true,  'description' => 'Semver version (e.g. 1.0.0)'],
			['field' => 'description',     'required' => false, 'description' => 'Short description'],
			['field' => 'requires',        'required' => false, 'description' => 'Version constraints (totalcms, php, extensions)'],
			['field' => 'permissions',     'required' => false, 'description' => 'Array of permission identifiers'],
			['field' => 'min_edition',     'required' => false, 'description' => 'Minimum edition: lite, standard, or pro'],
			['field' => 'entrypoint',      'required' => false, 'description' => 'Path to ExtensionInterface class (default: Extension.php)'],
			['field' => 'settings_schema', 'required' => false, 'description' => 'Path to settings JSON Schema file'],
			['field' => 'author',          'required' => false, 'description' => 'Author object with name and url'],
			['field' => 'license',         'required' => false, 'description' => 'License identifier (default: proprietary)'],
		],
		'editions' => [
			['edition' => 'lite',     'level' => 1, 'description' => 'Basic edition, available to all'],
			['edition' => 'standard', 'level' => 2, 'description' => 'Standard features including custom collections'],
			['edition' => 'pro',      'level' => 3, 'description' => 'Full features including custom schemas and extensions schemas'],
		],
		'starter_repo' => 'https://github.com/totalcms/extension-starter',
		'url' => 'https://docs.totalcms.co/extensions/overview/',
	];
}
