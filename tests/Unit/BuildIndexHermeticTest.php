<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bin/index-parsers.php';

/**
 * Hermetic build-index tests.
 *
 * Drive `assembleDocsOnlyIndex()` against a small fake docs tree under
 * tests/fixtures/build-index/docs/ and assert the resulting index. This is
 * the same function the production build-index.php calls, so any divergence
 * between parser behavior and the test's expectations fails CI here — no
 * need to wait for the live data/index.json to be rebuilt and inspected.
 *
 * What this catches that the live-index test doesn't:
 *   - Regressions when no data/index.json exists yet (fresh clone, CI).
 *   - Edge cases the production docs happen not to exercise (-options.md
 *     filtering, index.md homepage URL, single-section page).
 *   - Drift between the parsers and the URL composition logic.
 */

const FIXTURE_BUILD_INDEX = __DIR__ . '/../fixtures/build-index/docs';

it('produces a non-empty docs-only index from the fixture tree', function (): void {
	$index = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);

	expect($index)->toHaveKeys([
		'pages', 'twig_filters', 'twig_functions', 'documented_namespace_functions',
		'field_types', 'api_endpoints', 'schema_config', 'cli_commands',
	]);
	expect($index['pages'])->not->toBeEmpty();
});

it('builds page URLs with the new top-level layout', function (): void {
	$index = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);
	$urls  = array_map(fn ($p) => $p['url'], $index['pages']);

	expect($urls)->toContain('https://docs.totalcms.co/get-started/welcome/');
	expect($urls)->toContain('https://docs.totalcms.co/apis/rest-api/');
	expect($urls)->toContain('https://docs.totalcms.co/extensions/cli/');
	expect($urls)->toContain('https://docs.totalcms.co/site-builder/twig/');
});

it('serves the root index.md as the homepage at / (not /index/)', function (): void {
	$index = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);
	$urls  = array_map(fn ($p) => $p['url'], $index['pages']);

	expect($urls)->toContain('https://docs.totalcms.co/');
	expect($urls)->not->toContain('https://docs.totalcms.co/index/');
});

it('preserves frontmatter title, description, and since on pages', function (): void {
	$index = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);

	$welcome = null;
	foreach ($index['pages'] as $page) {
		if ($page['path'] === 'get-started/welcome') {
			$welcome = $page;
			break;
		}
	}

	expect($welcome)->not->toBeNull();
	expect($welcome['title'])->toBe('Welcome');
	expect($welcome['since'])->toBe('3.5.0');
	expect($welcome['sections'])->toContain('Quick start');
});

it('treats fields/*.md as field_types but excludes -options.md', function (): void {
	$index = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);
	$names = array_map(fn ($f) => $f['name'], $index['field_types']);

	expect($names)->toContain('styled-text');
	expect($names)->toContain('card');
	expect($names)->not->toContain('relational-options');
});

it('emits field_type URLs under /fields/ (never /property-settings/)', function (): void {
	$index = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);

	foreach ($index['field_types'] as $ft) {
		expect($ft['url'])->toStartWith('https://docs.totalcms.co/fields/')
			->and($ft['url'])->not->toContain('/property-settings/')
			->and($ft['url'])->not->toContain('/property-options/');
	}
});

it('parses API endpoints from apis/rest-api.md (not the old api/ path)', function (): void {
	$index = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);

	expect($index['api_endpoints'])->not->toBeEmpty();
});

it('parses CLI commands from extensions/cli.md', function (): void {
	$index = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);
	$names = array_map(fn ($c) => $c['name'] ?? '', $index['cli_commands']);

	expect($names)->toContain('collection:list');
	foreach ($index['cli_commands'] as $cmd) {
		expect($cmd['url'])->toStartWith('https://docs.totalcms.co/extensions/cli/')
			->and($cmd['url'])->not->toContain('/advanced/cli/');
	}
});

it('produces an index that passes validateIndexUrls (with extension/builder URLs added)', function (): void {
	$docs = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);

	// Simulate the final index shape — layered just like build-index.php does,
	// but with a stub extension_api so we cover that traversal path too.
	$index = array_merge($docs, [
		'extension_api' => [
			'bundled_extensions' => [
				'items' => [
					['name' => 'ab-split', 'url' => 'https://docs.totalcms.co/extensions/ab-split/'],
				],
			],
		],
		'builder_api' => [
			'docs' => [
				['label' => 'Builder Twig', 'url' => 'https://docs.totalcms.co/site-builder/twig/'],
			],
		],
	]);
	// extension/builder URLs must reference pages we know about
	$index['pages'][] = ['url' => 'https://docs.totalcms.co/extensions/ab-split/'];

	expect(validateIndexUrls($index))->toBe([]);
});

it('rejects an index seeded with stale URLs (regression guard)', function (): void {
	$docs = assembleDocsOnlyIndex(FIXTURE_BUILD_INDEX);
	$index = array_merge($docs, [
		'extension_api' => [
			'bundled_extensions' => [
				'items' => [
					// Pre-reorg shape — must be flagged.
					['name' => 'ab-split', 'url' => 'https://docs.totalcms.co/extensions/bundled/ab-split/'],
				],
			],
		],
		'builder_api' => [
			'docs' => [
				// Pre-reorg shape — must be flagged.
				['label' => 'Builder Twig', 'url' => 'https://docs.totalcms.co/twig/builder/'],
			],
		],
	]);

	$failures = validateIndexUrls($index);

	expect($failures)->not->toBeEmpty();
	expect(implode("\n", $failures))->toContain('extensions/bundled/ab-split');
	expect(implode("\n", $failures))->toContain('twig/builder');
});
