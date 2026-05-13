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
