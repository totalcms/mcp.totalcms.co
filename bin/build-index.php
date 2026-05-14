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
// Parse the docs-only portion of the index (no PHP-source reflection).
// This is the same function the hermetic fixture test exercises, so the
// build pipeline and tests stay in lockstep.
// -------------------------------------------------------
$docsIndex = assembleDocsOnlyIndex($docsDir);

echo "Found " . count($docsIndex['pages']) . " documentation files.\n";
echo "  Pages indexed: " . count($docsIndex['pages']) . "\n";
echo "  Twig filters: " . count($docsIndex['twig_filters']) . "\n";

// -------------------------------------------------------
// Layer reflected cms.* functions on top of the documented namespace functions
// from the docs pass. Reflection is the canonical existence source; docs supply
// example snippets where written.
// -------------------------------------------------------
require_once __DIR__ . '/reflect-twig-functions.php';

$reflectedNamespaceFns = reflectCmsTwigFunctions($totalcmsPath);
[$mergedNamespaceFns, $staleDocs] = mergeTwigFunctions(
	$reflectedNamespaceFns,
	$docsIndex['documented_namespace_functions'],
);
$twigFunctions = array_merge($docsIndex['twig_functions'], $mergedNamespaceFns);

echo "  Twig functions: " . count($twigFunctions)
	. " (cms.*: " . count($reflectedNamespaceFns) . " reflected, "
	. count($docsIndex['documented_namespace_functions']) . " documented";
if ($staleDocs !== []) {
	echo ", " . count($staleDocs) . " doc-only — possibly stale";
}
echo ")\n";
if ($staleDocs !== [] && count($staleDocs) <= 10) {
	foreach ($staleDocs as $name) {
		echo "    ⚠ doc-only: {$name}\n";
	}
}

echo "  Field types: " . count($docsIndex['field_types']) . "\n";
echo "  API endpoints: " . count($docsIndex['api_endpoints']) . "\n";
echo "  Schema config options: " . count($docsIndex['schema_config']) . "\n";
echo "  CLI commands: " . count($docsIndex['cli_commands']) . "\n";

// -------------------------------------------------------
// Build the final index — docs pass + reflection-driven extension/builder API
// -------------------------------------------------------
$extensionApi = buildExtensionApiReference($totalcmsPath);
$builderApi   = buildBuilderApiReference();

$index = [
	'version'        => '1.0.0',
	'built_at'       => date('c'),
	'pages'          => $docsIndex['pages'],
	'twig_functions' => $twigFunctions,
	'twig_filters'   => $docsIndex['twig_filters'],
	'field_types'    => $docsIndex['field_types'],
	'api_endpoints'  => $docsIndex['api_endpoints'],
	'schema_config'  => $docsIndex['schema_config'],
	'cli_commands'   => $docsIndex['cli_commands'],
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

// Also refuse to write an index with stale URL prefixes — catches hardcoded
// docs.totalcms.co URLs that point at old paths (pre-reorg /builder/, /api/,
// /advanced/, /property-settings/, etc.). See ALLOWED_DOCS_URL_PREFIXES in
// index-parsers.php.
$urlFailures = validateIndexUrls($index);
if ($urlFailures !== []) {
	fwrite(STDERR, "\nError: index contains URLs with stale top-level prefixes:\n");
	foreach ($urlFailures as $msg) {
		fwrite(STDERR, "  - {$msg}\n");
	}
	fwrite(STDERR, "\nThe index was NOT written. Update the hardcoded URL in the offending parser\n");
	fwrite(STDERR, "to use a current top-level docs folder.\n");
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
