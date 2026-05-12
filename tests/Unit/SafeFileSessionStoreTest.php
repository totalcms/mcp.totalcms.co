<?php

declare(strict_types=1);

use Symfony\Component\Uid\UuidV4;
use TotalCMS\Mcp\SafeFileSessionStore;

beforeEach(function (): void {
	$this->dir = sys_get_temp_dir() . '/mcp-sessions-' . uniqid();
	mkdir($this->dir, 0o755, true);
	$this->store = new SafeFileSessionStore($this->dir);
});

afterEach(function (): void {
	if (isset($this->dir) && is_dir($this->dir)) {
		array_map('unlink', glob($this->dir . '/*') ?: []);
		rmdir($this->dir);
	}
});

it('returns valid JSON unchanged', function (): void {
	$id = new UuidV4();
	$payload = json_encode(['initialized' => true]);
	$this->store->write($id, $payload);

	expect($this->store->read($id))->toBe($payload);
});

it('discards a corrupt session file and returns false', function (): void {
	$id = new UuidV4();
	$path = $this->dir . '/' . $id->toRfc4122();
	file_put_contents($path, 'this is not json');

	expect($this->store->read($id))->toBeFalse();
	expect(file_exists($path))->toBeFalse(); // unlinked
});

it('returns false for non-existent sessions', function (): void {
	expect($this->store->read(new UuidV4()))->toBeFalse();
});

it('passes corruption events to the injected logger', function (): void {
	$messages = [];
	$logger = new class ($messages) extends \Psr\Log\AbstractLogger {
		public function __construct(private array &$messages) {}
		public function log($level, string|\Stringable $message, array $context = []): void
		{
			$this->messages[] = [$level, (string) $message, $context];
		}
	};

	$store = new SafeFileSessionStore($this->dir, logger: $logger);
	$id = new UuidV4();
	file_put_contents($this->dir . '/' . $id->toRfc4122(), '{broken');

	$store->read($id);

	expect($messages)->toHaveCount(1);
	expect($messages[0][1])->toBe('Discarding corrupt session file');
});
