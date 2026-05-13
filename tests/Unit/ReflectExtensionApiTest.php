<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bin/reflect-extension-api.php';

const T3_PATH = __DIR__ . '/../fixtures/t3';

beforeEach(function (): void {
	@mkdir(T3_PATH . '/src/Domain/Extension', 0o777, true);
});

afterEach(function (): void {
	$file = T3_PATH . '/src/Domain/Extension/ExtensionContext.php';
	if (is_file($file)) {
		unlink($file);
	}
	@rmdir(T3_PATH . '/src/Domain/Extension');
	@rmdir(T3_PATH . '/src/Domain');
	@rmdir(T3_PATH . '/src');
	@rmdir(T3_PATH);
});

function writeContextFixture(string $body): void
{
	$source = <<<PHP
<?php
namespace TotalCMS\\Domain\\Extension;

class ExtensionContext
{
	{$body}
}
PHP;
	file_put_contents(T3_PATH . '/src/Domain/Extension/ExtensionContext.php', $source);
}

it('reflectContextMethods extracts public methods with signatures and descriptions', function (): void {
	writeContextFixture(<<<'PHP'
	/**
	 * Get the extension ID.
	 */
	public function extensionId(): string {}

	/**
	 * Register a custom Twig function.
	 */
	public function addTwigFunction(\Twig\TwigFunction $function): void {}

	private function ignored(): void {}
	PHP);

	$methods = reflectContextMethods(T3_PATH);
	$names = array_column($methods, 'name');

	expect($names)->toBe(['extensionId', 'addTwigFunction']); // private is excluded

	$twig = $methods[1];
	expect($twig)->toMatchArray([
		'signature'  => 'addTwigFunction(Twig\TwigFunction $function): void',
		'phase'      => 'register',
		'permission' => 'twig:functions',
	]);
	expect($twig['description'])->toBe('Register a custom Twig function.');
});

it('reflectContextMethods skips getRegistered* and lifecycle internals', function (): void {
	writeContextFixture(<<<'PHP'
	public function __construct() {}
	public function addTwigFunction(): void {}
	public function getRegisteredTwigFunctions(): array {}
	public function getCapabilities(): array {}
	public static function capabilityLabels(): array {}
	PHP);

	$names = array_column(reflectContextMethods(T3_PATH), 'name');

	expect($names)->toBe(['addTwigFunction']);
});

it('reflectContextMethods defaults unknown methods to register phase with no permission', function (): void {
	writeContextFixture(<<<'PHP'
	public function someBrandNewThingy(): void {}
	PHP);

	$methods = reflectContextMethods(T3_PATH);

	expect($methods[0])->toMatchArray([
		'name'       => 'someBrandNewThingy',
		'phase'      => 'register',
		'permission' => null,
	]);
});

it('reflectContextMethods strips inline {@see} tags from descriptions', function (): void {
	writeContextFixture(<<<'PHP'
	/**
	 * Register a per-page middleware. The class must implement
	 * {@see \TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface}.
	 */
	public function addPageMiddleware(): void {}
	PHP);

	$methods = reflectContextMethods(T3_PATH);

	expect($methods[0]['description'])->not->toContain('{@see');
	expect($methods[0]['description'])->toContain('PageMiddlewareInterface');
});

it('reflectCapabilityLabels extracts the static method array', function (): void {
	writeContextFixture(<<<'PHP'
	public static function capabilityLabels(): array
	{
		return [
			'twig:functions' => 'Twig Functions',
			'cli:commands'   => 'CLI Commands',
			'fields'         => 'Custom Fields',
		];
	}
	PHP);

	$perms = reflectCapabilityLabels(T3_PATH);

	expect($perms)->toBe([
		['id' => 'twig:functions', 'description' => 'Twig Functions'],
		['id' => 'cli:commands',   'description' => 'CLI Commands'],
		['id' => 'fields',         'description' => 'Custom Fields'],
	]);
});

it('throws a clear error when ExtensionContext.php is missing', function (): void {
	expect(fn () => reflectContextMethods('/nonexistent/path'))
		->toThrow(RuntimeException::class, 'ExtensionContext source not found');
});

// ----------------------------------------------------------------
// reflectEditions
// ----------------------------------------------------------------

function writeEditionFixture(string $body): void
{
	@mkdir(T3_PATH . '/src/Domain/License/Data', 0o777, true);
	$source = <<<PHP
<?php
namespace TotalCMS\\Domain\\License\\Data;
enum Edition: string
{
	{$body}
}
PHP;
	file_put_contents(T3_PATH . '/src/Domain/License/Data/Edition.php', $source);
}

it('reflectEditions extracts cases and their level() match values', function (): void {
	writeEditionFixture(<<<'PHP'
	case LITE     = 'lite';
	case STANDARD = 'standard';
	case PRO      = 'pro';
	case UNKNOWN  = 'unknown';

	public function level(): int
	{
		return match ($this) {
			self::UNKNOWN  => 0,
			self::LITE     => 1,
			self::STANDARD => 2,
			self::PRO      => 3,
		};
	}
	PHP);

	$editions = reflectEditions(T3_PATH);

	expect($editions)->toHaveCount(4);
	expect(array_column($editions, 'edition'))->toBe(['lite', 'standard', 'pro', 'unknown']);

	$byEdition = array_column($editions, 'level', 'edition');
	expect($byEdition)->toBe(['lite' => 1, 'standard' => 2, 'pro' => 3, 'unknown' => 0]);

	unlink(T3_PATH . '/src/Domain/License/Data/Edition.php');
	@rmdir(T3_PATH . '/src/Domain/License/Data');
	@rmdir(T3_PATH . '/src/Domain/License');
});

// ----------------------------------------------------------------
// parseEventsFromDocs
// ----------------------------------------------------------------

it('parseEventsFromDocs extracts event name, description, and payload from H3 sections', function (): void {
	@mkdir(T3_PATH . '/resources/docs/extensions', 0o777, true);
	file_put_contents(T3_PATH . '/resources/docs/extensions/events.md', <<<'MD'
---
title: "Events"
---

## Available Events

### `object.created`

Fired after a new object is saved to a collection.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection name |
| `id` | `string` | Object ID |

### `user.login`

Fired after a user successfully logs in.

| Key | Type | Description |
|---|---|---|
| `user` | `string` | User ID |
MD);

	$events = parseEventsFromDocs(T3_PATH);

	expect($events)->toHaveCount(2);
	expect($events[0])->toMatchArray([
		'name'        => 'object.created',
		'description' => 'Fired after a new object is saved to a collection.',
		'payload'     => ['collection' => 'string', 'id' => 'string'],
	]);
	expect($events[1]['name'])->toBe('user.login');
	expect($events[1]['payload'])->toBe(['user' => 'string']);

	unlink(T3_PATH . '/resources/docs/extensions/events.md');
	@rmdir(T3_PATH . '/resources/docs/extensions');
	@rmdir(T3_PATH . '/resources/docs');
	@rmdir(T3_PATH . '/resources');
});

it('parseEventsFromDocs returns empty when events.md is missing', function (): void {
	expect(parseEventsFromDocs('/nonexistent'))->toBe([]);
});

// ----------------------------------------------------------------
// reflectManifestFields
// ----------------------------------------------------------------

it('reflectManifestFields converts constructor params to snake_case JSON keys', function (): void {
	@mkdir(T3_PATH . '/src/Domain/Extension/Data', 0o777, true);
	file_put_contents(T3_PATH . '/src/Domain/Extension/Data/ExtensionManifest.php', <<<'PHP'
<?php
namespace TotalCMS\Domain\Extension\Data;

final readonly class ExtensionManifest
{
	/**
	 * @param string  $id          e.g. "vendor/extension-name"
	 * @param string  $name        Human-readable name
	 * @param string  $version     Semver version
	 * @param ?string $settingsSchema  Path to settings schema
	 * @param bool    $bundled     Set by ExtensionDiscovery
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $version,
		public ?string $settingsSchema = null,
		public bool $bundled = false,
	) {}
}
PHP);

	$fields = reflectManifestFields(T3_PATH);

	expect(array_column($fields, 'field'))->toBe(['id', 'name', 'version', 'settings_schema']);
	expect($fields[0])->toMatchArray(['field' => 'id', 'required' => true]);
	expect($fields[3])->toMatchArray(['field' => 'settings_schema', 'required' => false]);
	// bundled is filtered out (set by discovery, not the JSON)

	unlink(T3_PATH . '/src/Domain/Extension/Data/ExtensionManifest.php');
	@rmdir(T3_PATH . '/src/Domain/Extension/Data');
});

// ----------------------------------------------------------------
// reflectBundledExtensions
// ----------------------------------------------------------------

it('reflectBundledExtensions reads each extension.json under resources/extensions', function (): void {
	@mkdir(T3_PATH . '/resources/extensions/totalcms/ab-split', 0o777, true);
	@mkdir(T3_PATH . '/resources/extensions/totalcms/geo-redirect', 0o777, true);

	file_put_contents(T3_PATH . '/resources/extensions/totalcms/ab-split/extension.json', json_encode([
		'id'          => 'totalcms/ab-split',
		'name'        => 'A/B Split',
		'description' => 'Split-test pages',
		'version'     => '1.0.0',
	]));
	file_put_contents(T3_PATH . '/resources/extensions/totalcms/geo-redirect/extension.json', json_encode([
		'id'          => 'totalcms/geo-redirect',
		'name'        => 'Geo Redirect',
		'description' => 'Redirect by country',
		'version'     => '1.0.0',
	]));

	$bundled = reflectBundledExtensions(T3_PATH);

	expect($bundled)->toHaveCount(2);
	expect($bundled[0])->toMatchArray([
		'id'   => 'totalcms/ab-split',
		'name' => 'A/B Split',
		'url'  => 'https://docs.totalcms.co/extensions/bundled/ab-split/',
	]);

	// cleanup
	foreach (glob(T3_PATH . '/resources/extensions/totalcms/*/extension.json') as $f) {
		unlink($f);
	}
	@rmdir(T3_PATH . '/resources/extensions/totalcms/ab-split');
	@rmdir(T3_PATH . '/resources/extensions/totalcms/geo-redirect');
	@rmdir(T3_PATH . '/resources/extensions/totalcms');
	@rmdir(T3_PATH . '/resources/extensions');
	@rmdir(T3_PATH . '/resources');
});

it('reflectBundledExtensions returns empty when the directory is missing', function (): void {
	expect(reflectBundledExtensions('/nonexistent'))->toBe([]);
});
