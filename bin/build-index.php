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

	$page = [
		'title'    => $frontmatter['title'] ?? extractH1($body) ?? basename($path),
		'path'     => $path,
		'url'      => 'https://docs.totalcms.co/' . str_replace('.md', '/', $relativePath),
		'sections' => $sections,
		'content'  => $searchContent,
	];

	if (isset($frontmatter['since'])) {
		$page['since'] = $frontmatter['since'];
	}

	$pages[] = $page;
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
	'twig/builder.md',
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

// Also parse schema-level settings from schemas/reference.md
$schemaRefFile = $docsDir . '/schemas/reference.md';
if (file_exists($schemaRefFile)) {
	$content = file_get_contents($schemaRefFile);
	$schemaRefConfigs = parseSchemaConfig($content);
	// Override URL to point to the schema reference page
	foreach ($schemaRefConfigs as &$cfg) {
		$cfg['url'] = 'https://docs.totalcms.co/schemas/reference/';
	}
	unset($cfg);
	$schemaConfig = array_merge($schemaConfig, $schemaRefConfigs);
}
echo "  Schema config options: " . count($schemaConfig) . "\n";

// -------------------------------------------------------
// Parse CLI commands from cli/commands.md
// -------------------------------------------------------
$cliCommands = [];
$cliSourceFiles = [
	'advanced/cli.md',
	'builder/cli.md',
];
foreach ($cliSourceFiles as $relPath) {
	$filePath = $docsDir . '/' . $relPath;
	if (!file_exists($filePath)) {
		continue;
	}
	$content    = file_get_contents($filePath);
	$pageUrl    = 'https://docs.totalcms.co/' . str_replace('.md', '/', $relPath);
	$parsedCmds = parseCliCommands($content, $pageUrl);
	$cliCommands = array_merge($cliCommands, $parsedCmds);
}
echo "  CLI commands: " . count($cliCommands) . "\n";

// -------------------------------------------------------
// Build the final index
// -------------------------------------------------------
$extensionApi = buildExtensionApiReference();
$builderApi   = buildBuilderApiReference();

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
	'builder_api'    => $builderApi,
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

		// Skip headings that are markdown artifacts (e.g. "# Title")
		if (str_starts_with($key, '#')) {
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
function parseCliCommands(string $content, string $url = 'https://docs.totalcms.co/advanced/cli/'): array
{
	$commands = [];
	$frontmatter = parseFrontmatter($content);
	$pageSince = $frontmatter['since'] ?? null;
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
			'url'         => $url,
		];

		if ($pageSince !== null) {
			$cmd['since'] = $pageSince;
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
			],
			[
				'name'        => 'logger',
				'signature'   => 'logger(): \\Psr\\Log\\LoggerInterface',
				'description' => 'Get the shared extensions logger (writes to tcms-data/logs/extensions.log on the "extensions" channel). Prefix messages with the extension id so multi-extension logs remain readable.',
				'phase'       => 'both',
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
				'description' => 'Register authenticated API routes under /ext/{vendor}/{name}/. Callable receives RouteCollectorProxy.',
				'phase'       => 'register',
				'permission'  => 'routes:api',
			],
			[
				'name'        => 'addAdminRoutes',
				'signature'   => 'addAdminRoutes(callable $registrar): void',
				'description' => 'Register admin routes under /admin/ext/{vendor}/{name}/. Protected by admin auth middleware. Templates can extend admin-dashboard.twig.',
				'phase'       => 'register',
				'permission'  => 'routes:admin',
			],
			[
				'name'        => 'addPublicRoutes',
				'signature'   => 'addPublicRoutes(callable $registrar): void',
				'description' => 'Register unauthenticated public routes under /ext/{vendor}/{name}/. Use for webhooks, embeds, and endpoints accessible without credentials.',
				'phase'       => 'register',
				'permission'  => 'routes:public',
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
				'name'        => 'addAdminAsset',
				'signature'   => 'addAdminAsset(string $type, string $path): void',
				'description' => 'Load a CSS or JS file in the admin interface. Type is "css" or "js", path is relative to the extension\'s assets/ directory.',
				'phase'       => 'register',
				'permission'  => 'admin:assets',
			],
			[
				'name'        => 'addFieldType',
				'signature'   => 'addFieldType(string $typeName, string $fqcn, string $defaultType = \'string\'): void',
				'description' => 'Register a custom field type. Class must extend FormField. The optional $defaultType declares the default schema property type used when an author leaves the property\'s `type` blank (one of SchemaData::PROPERTY_TYPES, e.g. string, color, array).',
				'phase'       => 'register',
				'permission'  => 'fields',
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
				'permission'  => 'container',
			],
			[
				'name'        => 'addPageMiddleware',
				'signature'   => 'addPageMiddleware(string $name, string $middlewareClass): void',
				'description' => 'Register a page-features middleware. Class must implement TotalCMS\\Domain\\Builder\\PageMiddleware\\PageMiddlewareInterface. Name must be lowercase letters, digits, and hyphens (e.g. "geo-redirect", "rate-limit"). Once registered, the name appears in the page form\'s Features multiselect; admins opt-in per page.',
				'phase'       => 'register',
				'permission'  => 'page-middleware',
				'since'       => '3.5.0',
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
				'name'        => 'collection.created',
				'description' => 'Fired after a new collection is created',
				'payload'     => ['collection' => 'string'],
			],
			[
				'name'        => 'collection.updated',
				'description' => 'Fired after a collection is updated',
				'payload'     => ['collection' => 'string'],
			],
			[
				'name'        => 'collection.deleted',
				'description' => 'Fired after a collection is deleted',
				'payload'     => ['collection' => 'string'],
			],
			[
				'name'        => 'import.completed',
				'description' => 'Fired after a batch import (CSV, JSON, or URL) completes',
				'payload'     => ['collection' => 'string', 'count' => 'int'],
			],
			[
				'name'        => 'schema.saved',
				'description' => 'Fired after a schema is created or updated',
				'payload'     => ['schema' => 'string'],
			],
			[
				'name'        => 'schema.deleted',
				'description' => 'Fired after a schema is deleted',
				'payload'     => ['schema' => 'string'],
			],
			[
				'name'        => 'user.login',
				'description' => 'Fired after a user successfully logs in',
				'payload'     => ['user' => 'string'],
			],
			[
				'name'        => 'user.logout',
				'description' => 'Fired after a user logs out',
				'payload'     => ['user' => 'string'],
			],
			[
				'name'        => 'extension.enabled',
				'description' => 'Fired after an extension is enabled',
				'payload'     => ['id' => 'string'],
			],
			[
				'name'        => 'extension.disabled',
				'description' => 'Fired after an extension is disabled',
				'payload'     => ['id' => 'string'],
			],
			[
				'name'        => 'cache.cleared',
				'description' => 'Fired after the cache is cleared. Payload is the per-backend results map (SystemEventPayload).',
				'payload'     => ['data' => 'array'],
			],
			[
				'name'        => 'devmode.enabled',
				'description' => 'Fired when developer mode is enabled (cache disabled, debug output on). Payload includes the enabling user.',
				'payload'     => ['data' => 'array'],
			],
			[
				'name'        => 'devmode.disabled',
				'description' => 'Fired when developer mode is disabled. Empty SystemEventPayload.',
				'payload'     => ['data' => 'array'],
			],
		],
		'permissions' => [
			['id' => 'twig:functions', 'description' => 'Register custom Twig functions'],
			['id' => 'twig:filters',   'description' => 'Register custom Twig filters'],
			['id' => 'twig:globals',   'description' => 'Register Twig global variables'],
			['id' => 'cli:commands',   'description' => 'Register CLI commands'],
			['id' => 'routes:api',     'description' => 'Register authenticated API endpoints'],
			['id' => 'routes:admin',   'description' => 'Register admin pages'],
			['id' => 'routes:public',  'description' => 'Register unauthenticated public endpoints'],
			['id' => 'admin:nav',      'description' => 'Add items to admin navigation'],
			['id' => 'admin:widgets',  'description' => 'Add dashboard widgets'],
			['id' => 'admin:assets',   'description' => 'Load CSS/JS in the admin interface'],
			['id' => 'events:listen',  'description' => 'Subscribe to content events'],
			['id' => 'fields',         'description' => 'Register custom field types'],
			['id' => 'schemas',        'description' => 'Install user-editable schemas (Pro+ only)'],
			['id' => 'container',      'description' => 'Register DI container services'],
			['id' => 'page-middleware','description' => 'Register page-features middleware (since 3.5.0)'],
		],
		'manifest_fields' => [
			['field' => 'id',              'required' => true,  'description' => 'Unique ID in vendor/name format'],
			['field' => 'name',            'required' => true,  'description' => 'Human-readable name'],
			['field' => 'version',         'required' => true,  'description' => 'Semver version (e.g. 1.0.0)'],
			['field' => 'description',     'required' => false, 'description' => 'Short description'],
			['field' => 'requires',        'required' => false, 'description' => 'Version constraints (totalcms, php, extensions)'],
			['field' => 'min_edition',     'required' => false, 'description' => 'Minimum edition: lite, standard, or pro'],
			['field' => 'entrypoint',      'required' => false, 'description' => 'Path to ExtensionInterface class (default: Extension.php)'],
			['field' => 'settings_schema', 'required' => false, 'description' => 'Path to settings JSON Schema file'],
			['field' => 'author',          'required' => false, 'description' => 'Author object with name and url'],
			['field' => 'license',         'required' => false, 'description' => 'License identifier (default: proprietary)'],
			['field' => 'links',           'required' => false, 'description' => 'List of {label, url} card links shown in the admin extensions page'],
			['field' => 'icon',            'required' => false, 'description' => 'Path to icon image displayed in the admin extensions page'],
		],
		'editions' => [
			['edition' => 'lite',     'level' => 1, 'description' => 'Basic edition, available to all'],
			['edition' => 'standard', 'level' => 2, 'description' => 'Standard features including custom collections'],
			['edition' => 'pro',      'level' => 3, 'description' => 'Full features including custom schemas and extensions schemas'],
		],
		'starter_repo' => 'https://github.com/totalcms/extension-starter',
		'bundled_extensions' => [
			'since' => '3.5.0',
			'note' => 'Bundled extensions ship in the T3 package itself under resources/extensions/. They install automatically (no composer require, no upload), are disabled by default, and cannot be removed — only enabled/disabled. The manifest version field is ignored; they always report the running T3 version. A user-installed extension with the same ID under tcms-data/extensions/ shadows the bundled copy.',
			'install_path' => 'resources/extensions/{vendor}/{name}/',
			'override_path' => 'tcms-data/extensions/{vendor}/{name}/ (user-installed copy wins on ID collision)',
			'items' => [
				[
					'id'          => 'totalcms/ab-split',
					'name'        => 'A/B Split',
					'description' => 'Render an alternate page template at the same URL for a percentage of visitors. Sticky-bucketing via a 30-day cookie. Use for layout, copy, or CTA variations without changing the URL.',
					'page_feature' => 'ab-split',
					'page_data_keys' => [
						['key' => 'abTemplate', 'type' => 'string', 'description' => 'Path to variant-B template (e.g. pages/contact-b.twig). Required — empty or missing means the middleware no-ops and the page renders normally.'],
						['key' => 'abPercent',  'type' => 'int',    'default' => 50, 'description' => 'Percentage of visitors sent to variant B. Clamped to 0-100. Use 100 to force every visitor onto B for validation; 0 effectively disables the split.'],
					],
					'cookie' => ['name' => 'tcms_ab_<page-id>', 'value' => 'a or b', 'ttl' => '30 days', 'path' => '/', 'samesite' => 'Lax'],
					'limitations' => ['Two variants only (A/B, no multivariate)', 'Builder pages only — collection-URL matches not supported', 'No built-in analytics — read the cookie client-side and tag your analytics events'],
					'url' => 'https://docs.totalcms.co/extensions/bundled/ab-split/',
				],
				[
					'id'          => 'totalcms/geo-redirect',
					'name'        => 'Geo Redirect',
					'description' => 'Redirect visitors based on their country via 302. Reads country from CDN-injected request headers (no IP database). Includes loop prevention and a Vary header so CDN caches separate per-country variants.',
					'page_feature' => 'geo-redirect',
					'page_data_keys' => [
						['key' => 'geoRedirects', 'type' => 'object', 'description' => 'Map of ISO 3166-1 alpha-2 country code (e.g. "DE", "FR") to target URL. Use "*" as wildcard fallback for unlisted countries. Targets can be paths, absolute URLs, or include query strings.'],
					],
					'country_headers' => ['CF-IPCountry (Cloudflare)', 'X-Country-Code (generic / DIY proxies)', 'X-Vercel-IP-Country (Vercel)'],
					'response_headers' => ['Vary: CF-IPCountry, X-Country-Code, X-Vercel-IP-Country'],
					'limitations' => ['Country-level only, no city/region precision', 'Not a strong access-control mechanism — bypassable with VPN or header manipulation', 'Builder pages only — collection-URL matches not supported'],
					'url' => 'https://docs.totalcms.co/extensions/bundled/geo-redirect/',
				],
			],
			'cli' => [
				'tcms extension:list',
				'tcms extension:enable totalcms/<name>',
				'tcms extension:disable totalcms/<name>',
				'tcms extension:remove totalcms/<name>  # refuses with a friendly error pointing at disable',
			],
			'docs' => [
				['label' => 'Bundled Extensions Overview', 'url' => 'https://docs.totalcms.co/extensions/bundled/'],
				['label' => 'A/B Split',                   'url' => 'https://docs.totalcms.co/extensions/bundled/ab-split/'],
				['label' => 'Geo Redirect',                'url' => 'https://docs.totalcms.co/extensions/bundled/geo-redirect/'],
			],
		],
		'url' => 'https://docs.totalcms.co/extensions/overview/',
	];
}

/**
 * Build a structured reference of the Site Builder.
 * Hand-maintained — represents the user-facing contract for building sites.
 *
 * @return array<string, mixed>
 */
function buildBuilderApiReference(): array
{
	return [
		'min_version' => '3.3.0',
		'note' => 'The Site Builder requires Total CMS 3.3.0 or later. Pages are routed dynamically from the builder-pages collection — there is no generation or deployment step.',
		'overview' => 'The Site Builder lets you build a complete frontend website inside Total CMS. Pages are collection objects with URL routes and templates. A middleware-based router (PageRouterMiddleware) matches incoming URLs against page routes and renders templates dynamically. API routes (/api/*) and admin routes (/admin/*) take priority.',
		'pages_collection' => 'builder-pages',
		'page_schema' => [
			'id'          => 'builder-page',
			'description' => 'Schema used by every object in the builder-pages collection.',
			'fields' => [
				['name' => 'id',          'type' => 'slug',     'description' => 'Page identifier (auto-generated from title)'],
				['name' => 'title',       'type' => 'text',     'description' => 'Page title'],
				['name' => 'route',       'type' => 'text',     'description' => 'URL pattern. Static (e.g. "/about") or dynamic with {param} placeholders (e.g. "/products/{id}")'],
				['name' => 'template',    'type' => 'text',     'description' => 'Page template name from tcms-data/builder/pages/'],
				['name' => 'layout',      'type' => 'select',   'description' => 'Layout template from tcms-data/builder/layouts/ (extended via {% extends %})'],
				['name' => 'description', 'type' => 'textarea', 'description' => 'Meta description'],
				['name' => 'draft',       'type' => 'toggle',   'description' => 'When true, the page is excluded from routing entirely (cannot be visited)'],
				['name' => 'nav',         'type' => 'toggle',   'description' => 'Include in nav menus. Defaults to true. Distinct from draft — a published page can be hidden from menus.'],
				['name' => 'sort',        'type' => 'number',   'description' => 'Navigation sort order (lower = first)'],
				['name' => 'parent',      'type' => 'select',   'description' => 'Parent page ID for hierarchical menus and subnav()'],
				['name' => 'middleware',  'type' => 'multicheckbox', 'description' => 'Page features (middleware) to run before render. Picked from a registry of installed middleware names — admin sees this field as "Features". Built-in: auth. Extensions register more via addPageMiddleware(). Since 3.5.0.'],
				['name' => 'accessGroups','type' => 'multiselect',   'description' => 'When auth feature is enabled, restricts access to users in any of the listed groups (SuperAdmins always pass). Empty = any logged-in user passes.'],
				['name' => 'data',        'type' => 'json',     'description' => 'Free-form per-page configuration consumed by page features and templates (exposed as page.data.* in Twig).'],
			],
		],
		'directory_structure' => [
			['path' => 'tcms-data/builder/layouts/',  'description' => 'Base HTML layouts. Page templates extend these via {% extends "layouts/<name>.twig" %}.'],
			['path' => 'tcms-data/builder/pages/',    'description' => 'Page content templates. Multiple page objects can share the same template.'],
			['path' => 'tcms-data/builder/partials/', 'description' => 'Reusable fragments (nav, footer, cards). Included via {% include %}.'],
			['path' => 'tcms-data/builder/macros/',   'description' => 'Reusable Twig macros for repeated rendering patterns.'],
		],
		'template_data' => [
			['name' => 'page',   'type' => 'object', 'description' => 'The full page object (id, title, route, template, layout, description, sort, parent, etc.)'],
			['name' => 'params', 'type' => 'object', 'description' => 'URL parameters extracted from dynamic routes. For route /products/{id}, params.id holds the captured segment.'],
		],
		'twig_functions' => [
			['name' => 'cms.builder.nav',     'signature' => 'nav(string $collection = "builder-pages"): array',                  'description' => 'Top-level navigation pages (no parent). Auto-filters drafts and nav:false pages, sorted by sort field.'],
			['name' => 'cms.builder.subnav',  'signature' => 'subnav(string $parentId, string $collection = "builder-pages"): array', 'description' => 'Child pages of a specific parent.'],
			['name' => 'cms.builder.navTree', 'signature' => 'navTree(string $collection = "builder-pages"): array',              'description' => 'Full hierarchy as a nested tree. Each item gets a children array, recursively.'],
			['name' => 'cms.builder.asset',   'signature' => 'asset(string $path): string',                                       'description' => 'Resolve an asset path to a URL with cache busting (manifest hash if present, else ?v=mtime).'],
			['name' => 'cms.builder.css',     'signature' => 'css(string $path): string',                                         'description' => 'Output a <link rel="stylesheet"> tag for a CSS file.'],
			['name' => 'cms.builder.js',      'signature' => 'js(string $path, array $options = []): string',                     'description' => 'Output a <script> tag. Pass {module: true} to add type="module".'],
			['name' => 'cms.builder.preload', 'signature' => 'preload(string $path, string $as): string',                         'description' => 'Output a <link rel="preload"> tag. Auto-adds crossorigin for fonts. $as is one of: font, image, script, style, fetch.'],
		],
		'cli_commands' => [
			['name' => 'builder:init', 'signature' => 'tcms builder:init [starter] [--list] [--force] [--json]', 'description' => 'Scaffold a new site from a bundled starter template. Copies templates into tcms-data/builder/, creates the builder-pages collection, and seeds page objects from the starter manifest.'],
		],
		'starters' => [
			['id' => 'minimal',   'pages' => ['Home'],                                            'description' => 'Single page with clean layout'],
			['id' => 'business',  'pages' => ['Home', 'About', 'Services', 'Contact'],            'description' => 'Professional business site'],
			['id' => 'blog',      'pages' => ['Home', 'Blog', 'Blog Post', 'About'],              'description' => 'Blog-focused site with dynamic post routing'],
			['id' => 'portfolio', 'pages' => ['Home', 'Work', 'About', 'Contact'],                'description' => 'Portfolio site with project cards'],
		],
		'asset_config' => [
			'assets_path' => [
				'description' => 'Public assets directory relative to docroot. Configured in Admin > Settings > Builder.',
				'default'     => 'assets',
			],
			'manifest' => [
				'description' => 'For production builds with content-hashed filenames, output a manifest.json into the assets directory. Asset functions automatically resolve hashed filenames from the manifest. Without a manifest, asset URLs use ?v={mtime} for cache busting.',
				'file'        => 'manifest.json',
				'tools'       => ['vite', 'esbuild'],
			],
		],
		'route_patterns' => [
			['pattern' => '/about',                'type' => 'static',  'description' => 'Exact match. Visiting /about renders this page.'],
			['pattern' => '/products/{id}',        'type' => 'dynamic', 'description' => 'Single segment captured into params.id.'],
			['pattern' => '/blog/{category}/{slug}', 'type' => 'dynamic', 'description' => 'Multiple segments captured into params.category and params.slug.'],
			['pattern' => '/robots.txt',           'type' => 'file',    'description' => 'File-extension routes auto-pick the right Content-Type. Supports .txt, .xml, .rss, .json, .md, .css, .js, .csv, .svg.'],
		],
		'collection_url_routing' => [
			'description' => 'When a collection has a url field set (e.g., /blog with pretty URLs enabled), the middleware also matches collection object URLs. /blog/my-post → fetches the my-post object from blog and renders templates/blog.twig with the object as page.',
			'pairing'     => 'A common pattern is a builder list page (/blog → blog-index template) plus a builder detail page (/blog/{id} → blog-post template) with the collection URL set to /blog so objectUrl() generates matching URLs.',
		],
		'page_features' => [
			'since'       => '3.5.0',
			'description' => 'Named middleware that builder pages opt into via the page form\'s Features field (stored in the page\'s middleware field). Each runs before the template renders and can short-circuit the request with a response (auth redirect, 302, 429, etc.) or pass through. Features run in the order listed on the page; the first one to return a response wins.',
			'admin_field' => 'Features (multicheckbox, stored as the page object\'s middleware field)',
			'config_via'  => 'Per-page configuration goes in the page\'s data (JSON) field. Each feature reads its own keys from page.data.* — see individual feature docs.',
			'built_in' => [
				[
					'name'        => 'auth',
					'description' => 'Requires a logged-in visitor. Logged-out browsers get 302 redirected to /admin/login (with ?redirect= back). JSON requests get 401 {"error": "Authentication required"}. Optionally scoped to specific access groups via the page\'s accessGroups field — non-members get 403 (no login redirect since they\'re already in). SuperAdmins always pass.',
					'page_fields' => ['accessGroups'],
				],
			],
			'bundled_features' => [
				['name' => 'ab-split',     'extension' => 'totalcms/ab-split',     'since' => '3.5.0', 'description' => 'Render an alternate template for a percentage of visitors. Sticky via 30-day cookie. Configure via page.data.abTemplate and page.data.abPercent.', 'url' => 'https://docs.totalcms.co/extensions/bundled/ab-split/'],
				['name' => 'geo-redirect', 'extension' => 'totalcms/geo-redirect', 'since' => '3.5.0', 'description' => '302 visitors based on country detected from CDN-injected headers (Cloudflare, Vercel, generic). Configure via page.data.geoRedirects.', 'url' => 'https://docs.totalcms.co/extensions/bundled/geo-redirect/'],
			],
			'registering_from_extension' => [
				'method'          => '$context->addPageMiddleware(string $name, string $middlewareClass): void',
				'permission'      => 'page-middleware',
				'naming_rules'    => 'Lowercase letters, digits, and hyphens only (e.g. geo-redirect, rate-limit, staff-only). Names are a stable contract — once shipped, a rename breaks pages that reference them.',
				'class_contract'  => 'Must implement TotalCMS\\Domain\\Builder\\PageMiddleware\\PageMiddlewareInterface — handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface. Return null to proceed (let next middleware / page render); return a Response to short-circuit.',
			],
			'failure_modes' => [
				'Unknown name in a page\'s middleware list (typo or uninstalled extension): runner logs a warning, silently skips that name, chain continues — page still renders.',
				'Middleware throws an exception: runner returns a 500 response (fail-closed for security so auth/geo gates never silently let pages through).',
			],
			'scope' => 'Per-page middleware applies only to builder-page route matches. Collection-URL matches (e.g. /blog/my-post resolved against a collection\'s url field) do NOT currently support per-record middleware — apply your own auth/etc in the collection\'s template.',
		],
		'docs' => [
			['label' => 'Site Builder Overview',         'url' => 'https://docs.totalcms.co/builder/overview/'],
			['label' => 'Page Features (Middleware)',    'url' => 'https://docs.totalcms.co/builder/overview/#page-features-middleware'],
			['label' => 'Builder Admin UI',              'url' => 'https://docs.totalcms.co/builder/admin/'],
			['label' => 'Frontend Assets (Vite)',        'url' => 'https://docs.totalcms.co/builder/frontend/'],
			['label' => 'Builder CLI Commands',          'url' => 'https://docs.totalcms.co/builder/cli/'],
			['label' => 'Starter Templates',             'url' => 'https://docs.totalcms.co/builder/starters/'],
			['label' => 'Builder Twig Reference',        'url' => 'https://docs.totalcms.co/twig/builder/'],
			['label' => 'Bundled Extensions',            'url' => 'https://docs.totalcms.co/extensions/bundled/'],
		],
		'url' => 'https://docs.totalcms.co/builder/overview/',
	];
}
