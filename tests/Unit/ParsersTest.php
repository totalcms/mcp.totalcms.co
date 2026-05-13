<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bin/index-parsers.php';

const FIXTURE_DOCS = __DIR__ . '/../fixtures/docs';

it('parseFrontmatter extracts YAML frontmatter as an associative array', function (): void {
	$content = file_get_contents(FIXTURE_DOCS . '/advanced/cli.md');

	$result = parseFrontmatter($content);

	expect($result)->toMatchArray([
		'title'       => 'CLI Commands',
		'description' => 'Total CMS CLI reference.',
		'since'       => '3.3.0',
	]);
});

it('parseFrontmatter returns empty array when no frontmatter present', function (): void {
	expect(parseFrontmatter('no frontmatter here'))->toBe([]);
});

it('parseFrontmatter handles malformed YAML gracefully', function (): void {
	$content = "---\nthis: is: invalid: yaml\n---\nbody";
	expect(parseFrontmatter($content))->toBe([]);
});

it('parseFilterSignatures extracts name, signature, description, and url', function (): void {
	$content = file_get_contents(FIXTURE_DOCS . '/twig/filters.md');

	$filters = parseFilterSignatures($content);

	expect($filters)->toHaveCount(2);
	expect($filters[0])->toMatchArray([
		'name'      => 'humanize',
		'signature' => 'humanize(): string',
		'url'       => 'https://docs.totalcms.co/twig/filters/',
	]);
	expect($filters[0]['description'])->toContain('Converts a machine-style identifier');
	expect($filters[1]['name'])->toBe('dateFormat');
});

it('parseFunctionSignatures extracts standalone Twig functions', function (): void {
	$content = file_get_contents(FIXTURE_DOCS . '/twig/functions.md');

	$functions = parseFunctionSignatures($content);

	expect($functions)->toHaveCount(2);
	expect($functions[0]['name'])->toBe('cmsConfig');
	expect($functions[0]['signature'])->toContain('mixed');
	expect($functions[1]['name'])->toBe('imageUrl');
});

it('parseApiEndpoints extracts method/path pairs with descriptions', function (): void {
	$content = file_get_contents(FIXTURE_DOCS . '/api/rest-api.md');

	$endpoints = parseApiEndpoints($content);

	expect($endpoints)->toHaveCount(2);
	expect($endpoints[0])->toMatchArray([
		'method' => 'GET',
		'path'   => '/collections/{name}',
	]);
	expect($endpoints[1])->toMatchArray([
		'method' => 'POST',
		'path'   => '/collections/{name}/objects',
	]);
	expect($endpoints[1]['edition'])->toBe('pro'); // detected from "Pro edition" prose
});

it('parseSchemaConfig extracts key, type, default, and description', function (): void {
	$content = file_get_contents(FIXTURE_DOCS . '/collections/settings.md');

	$configs = parseSchemaConfig($content);

	expect($configs)->toHaveCount(2);
	expect($configs[0])->toMatchArray([
		'key'     => 'labelPlural',
		'type'    => 'string',
		'default' => 'null',
	]);
	expect($configs[0]['description'])->toContain('plural label shown in the admin UI');
});

it('parseCliCommands extracts name, arguments, options, and propagates since', function (): void {
	$content = file_get_contents(FIXTURE_DOCS . '/advanced/cli.md');

	$commands = parseCliCommands($content);

	expect($commands)->toHaveCount(2);
	expect($commands[0])->toMatchArray([
		'name'  => 'collection:list',
		'since' => '3.3.0', // propagated from page frontmatter
	]);
	expect($commands[0]['options'][0]['name'])->toBe('--json');

	expect($commands[1]['name'])->toBe('object:get');
	expect($commands[1]['arguments'])->toHaveCount(2);
	expect($commands[1]['arguments'][0]['name'])->toBe('collection');
	expect($commands[1]['arguments'][0]['required'])->toBeTrue();
});

it('extractCodeBlocks pulls fenced code samples', function (): void {
	$section = "header\n\n```php\necho 'a';\n```\n\n```twig\n{{ foo }}\n```";

	$blocks = extractCodeBlocks($section);

	expect($blocks)->toBe(["echo 'a';", "{{ foo }}"]);
});

it('cleanForSearch strips markdown and lowercases', function (): void {
	$clean = cleanForSearch("# Heading\n\n**Bold** and `code` here. <a href='x'>link</a>");

	expect($clean)->toContain('heading');
	expect($clean)->toContain('bold');
	expect($clean)->not->toContain('**');
	expect($clean)->not->toContain('<a');
});

it('extractH1 returns the first H1 heading', function (): void {
	expect(extractH1("# Hello\n## World"))->toBe('Hello');
	expect(extractH1("no heading"))->toBeNull();
});

it('validateIndexCounts passes a healthy index', function (): void {
	$index = [
		'pages'          => array_fill(0, 100, 'p'),
		'twig_functions' => array_fill(0, 140, 'f'),
		'twig_filters'   => array_fill(0, 80,  'f'),
		'field_types'    => array_fill(0, 20,  't'),
		'api_endpoints'  => array_fill(0, 30,  'e'),
		'schema_config'  => array_fill(0, 30,  'c'),
		'cli_commands'   => array_fill(0, 28,  'c'),
	];

	expect(validateIndexCounts($index))->toBe([]);
});

it('validateIndexCounts flags every undersized section', function (): void {
	$failures = validateIndexCounts([
		'pages'          => array_fill(0, 40, 'p'), // 80 needed
		'twig_functions' => array_fill(0, 90, 'f'), // 100 needed
		// other sections missing entirely
	]);

	expect($failures)->toContain('pages: 40 (expected >= 80)');
	expect($failures)->toContain('twig_functions: 90 (expected >= 100)');
	expect($failures)->toContain('twig_filters: 0 (expected >= 50)');
	expect($failures)->toContain('cli_commands: 0 (expected >= 20)');
});

it('validateIndexCounts mimics the pre-3.3 regression (no CLI / extensions docs)', function (): void {
	// Roughly what an index built against totalcms/cms 3.2.5 would look like.
	$index = [
		'pages'          => array_fill(0, 60, 'p'),
		'twig_functions' => array_fill(0, 80, 'f'),
		'twig_filters'   => array_fill(0, 60, 'f'),
		'field_types'    => array_fill(0, 18, 't'),
		'api_endpoints'  => array_fill(0, 25, 'e'),
		'schema_config'  => array_fill(0, 22, 'c'),
		'cli_commands'   => [],
	];

	$failures = validateIndexCounts($index);

	expect($failures)->not->toBeEmpty();
	expect(implode("\n", $failures))->toContain('cli_commands');
});

it('validateIndexCounts accepts custom thresholds for tests', function (): void {
	expect(validateIndexCounts(['pages' => array_fill(0, 5, 'p')], ['pages' => 3]))->toBe([]);
	expect(validateIndexCounts(['pages' => array_fill(0, 5, 'p')], ['pages' => 10]))
		->toBe(['pages: 5 (expected >= 10)']);
});
