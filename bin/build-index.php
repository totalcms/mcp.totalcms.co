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

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index-parsers.php';

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
$extensionApi = buildExtensionApiReference($totalcmsPath);
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

// Refuse to write a clearly-broken index — catches building against an old
// T3 source tree (no CLI / extensions / builder docs) and parser regressions
// that drop major chunks of content. See INDEX_MINIMUM_COUNTS in index-parsers.php.
$failures = validateIndexCounts($index);
if ($failures !== []) {
	fwrite(STDERR, "\nError: index failed minimum-count sanity check:\n");
	foreach ($failures as $msg) {
		fwrite(STDERR, "  - {$msg}\n");
	}
	fwrite(STDERR, "\nThe index was NOT written. Likely cause: built against a T3 source tree that\n");
	fwrite(STDERR, "predates the missing sections, or a parser is silently dropping content.\n");
	exit(1);
}

$json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_dir(dirname($outputFile))) {
	mkdir(dirname($outputFile), 0755, true);
}
file_put_contents($outputFile, $json);

$sizeKb = round(strlen($json) / 1024);
echo "\nIndex built successfully!\n";
echo "Output: {$outputFile} ({$sizeKb} KB)\n";
