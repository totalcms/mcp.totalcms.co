<?php

declare(strict_types=1);

use TotalCMS\Mcp\Health;

it('returns ok with index metadata when the index exists', function (): void {
	$tmp = tempnam(sys_get_temp_dir(), 'mcp-idx-');
	file_put_contents($tmp, json_encode([
		'pages' => [['title' => 'a'], ['title' => 'b'], ['title' => 'c']],
	]));

	$status = Health::status($tmp);

	expect($status['ok'])->toBeTrue();
	expect($status['index_pages_count'])->toBe(3);
	expect($status['index_built_at'])->toBeString();
	expect($status['sdk_version'])->toBeString();
	expect($status['php_version'])->toBe(PHP_VERSION);

	unlink($tmp);
});

it('returns not-ok when the index is missing', function (): void {
	$status = Health::status('/tmp/definitely-not-here-' . uniqid());

	expect($status['ok'])->toBeFalse();
	expect($status['index_built_at'])->toBeNull();
	expect($status['index_pages_count'])->toBeNull();
});

it('tolerates a malformed index file', function (): void {
	$tmp = tempnam(sys_get_temp_dir(), 'mcp-idx-');
	file_put_contents($tmp, 'not json');

	$status = Health::status($tmp);

	expect($status['ok'])->toBeTrue(); // file exists; just unparseable
	expect($status['index_pages_count'])->toBeNull();

	unlink($tmp);
});
