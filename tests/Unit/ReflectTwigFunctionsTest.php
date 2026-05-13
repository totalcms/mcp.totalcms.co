<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bin/reflect-twig-functions.php';

const TWIG_T3_PATH = __DIR__ . '/../fixtures/t3-twig';

beforeEach(function (): void {
	@mkdir(TWIG_T3_PATH . '/src/Domain/Twig/Adapter', 0o777, true);
});

afterEach(function (): void {
	$files = glob(TWIG_T3_PATH . '/src/Domain/Twig/Adapter/*.php') ?: [];
	foreach ($files as $f) {
		unlink($f);
	}
	@rmdir(TWIG_T3_PATH . '/src/Domain/Twig/Adapter');
	@rmdir(TWIG_T3_PATH . '/src/Domain/Twig');
	@rmdir(TWIG_T3_PATH . '/src/Domain');
	@rmdir(TWIG_T3_PATH . '/src');
	@rmdir(TWIG_T3_PATH);
});

function writeTwigFixture(string $rootBody, array $subAdapters = []): void
{
	$source = <<<PHP
<?php
namespace TotalCMS\\Domain\\Twig\\Adapter;

class TotalCMSTwigAdapter
{
	{$rootBody}
}
PHP;
	file_put_contents(TWIG_T3_PATH . '/src/Domain/Twig/Adapter/TotalCMSTwigAdapter.php', $source);

	foreach ($subAdapters as $className => $body) {
		$src = <<<PHP
<?php
namespace TotalCMS\\Domain\\Twig\\Adapter;

class {$className}
{
	{$body}
}
PHP;
		file_put_contents(TWIG_T3_PATH . '/src/Domain/Twig/Adapter/' . $className . '.php', $src);
	}
}

it('reflectCmsTwigFunctions extracts top-level cms.* methods', function (): void {
	writeTwigFixture(<<<'PHP'
	public function __construct() {}

	/** Get a config value. */
	public function config(string $key): mixed {}

	/** Internal — should be skipped via TWIG_SKIP_ROOT_METHODS. */
	public function log(string $message): void {}

	private function ignored(): void {}
	PHP);

	$fns = reflectCmsTwigFunctions(TWIG_T3_PATH);
	$names = array_column($fns, 'name');

	expect($names)->toBe(['cms.config']);
	expect($fns[0]['signature'])->toBe('config(string $key): mixed');
	expect($fns[0]['description'])->toBe('Get a config value.');
});

it('reflectCmsTwigFunctions walks each public sub-adapter property', function (): void {
	writeTwigFixture(
		rootBody: <<<'PHP'
		public function __construct(
			public CollectionTwigAdapter $collection,
			public DataTwigAdapter $data,
			private mixed $internal = null,
		) {}
		PHP,
		subAdapters: [
			'CollectionTwigAdapter' => <<<'PHP'
				/** Get all objects from a collection. */
				public function objects(string $collection): array {}

				/** Get one object. */
				public function object(string $collection, string $id): array {}

				private function helper(): void {}
			PHP,
			'DataTwigAdapter' => <<<'PHP'
				/** Hash a string. */
				public function hash(string $value): string {}
			PHP,
		],
	);

	$fns = reflectCmsTwigFunctions(TWIG_T3_PATH);
	$names = array_column($fns, 'name');

	expect($names)->toBe([
		'cms.collection.object',
		'cms.collection.objects',
		'cms.data.hash',
	]);
});

it('reflectCmsTwigFunctions skips scalar root properties like env / base', function (): void {
	writeTwigFixture(<<<'PHP'
	public string $env;
	public string $base;
	public function __construct(public CollectionTwigAdapter $collection) {}
	PHP, ['CollectionTwigAdapter' => 'public function list(): array {}']);

	$fns = reflectCmsTwigFunctions(TWIG_T3_PATH);

	// env and base should not produce 'cms.env.*' / 'cms.base.*' entries.
	expect(array_column($fns, 'name'))->toBe(['cms.collection.list']);
});

it('reflectCmsTwigFunctions maps namespaces to their docs URLs', function (): void {
	writeTwigFixture(
		rootBody: 'public function __construct(public CollectionTwigAdapter $collection) {}',
		subAdapters: ['CollectionTwigAdapter' => 'public function list(): array {}'],
	);

	$fns = reflectCmsTwigFunctions(TWIG_T3_PATH);

	expect($fns[0]['url'])->toBe('https://docs.totalcms.co/twig/collections/');
});

// ----------------------------------------------------------------
// mergeTwigFunctions
// ----------------------------------------------------------------

it('mergeTwigFunctions enriches reflected entries with doc examples', function (): void {
	$reflected = [
		['name' => 'cms.collection.objects', 'signature' => 'objects(string $c): array', 'description' => 'Get objects.', 'url' => 'https://docs.totalcms.co/twig/collections/'],
	];
	$documented = [
		['name' => 'cms.collection.objects', 'signature' => 'objects()', 'description' => 'Get objects.', 'examples' => ['{% set p = cms.collection.objects("blog") %}'], 'url' => 'https://docs.totalcms.co/twig/collections/'],
	];

	[$merged, $stale] = mergeTwigFunctions($reflected, $documented);

	expect($merged)->toHaveCount(1);
	expect($merged[0]['signature'])->toBe('objects(string $c): array'); // from reflection
	expect($merged[0]['examples'])->toBe(['{% set p = cms.collection.objects("blog") %}']); // from docs
	expect($stale)->toBe([]);
});

it('mergeTwigFunctions emits reflected-only entries with empty examples', function (): void {
	$reflected = [
		['name' => 'cms.admin.newThing', 'signature' => 'newThing(): array', 'description' => 'Brand new.', 'url' => 'https://docs.totalcms.co/twig/admin/'],
	];

	[$merged, $stale] = mergeTwigFunctions($reflected, []);

	expect($merged)->toHaveCount(1);
	expect($merged[0]['examples'])->toBe([]);
	expect($stale)->toBe([]);
});

it('mergeTwigFunctions flags doc-only entries as stale', function (): void {
	$reflected = [
		['name' => 'cms.collection.objects', 'signature' => 'objects()', 'description' => '', 'url' => ''],
	];
	$documented = [
		['name' => 'cms.collection.objects', 'signature' => '', 'description' => '', 'examples' => [], 'url' => ''],
		['name' => 'cms.collection.removedMethod', 'signature' => '', 'description' => '', 'examples' => [], 'url' => ''],
	];

	[$merged, $stale] = mergeTwigFunctions($reflected, $documented);

	expect(array_column($merged, 'name'))->toBe(['cms.collection.objects']);
	expect($stale)->toBe(['cms.collection.removedMethod']);
});
