<?php

declare(strict_types=1);

namespace TotalCMS\Mcp;

use Composer\InstalledVersions;

/**
 * Builds the JSON payload for the /health uptime-monitoring endpoint.
 */
class Health
{
	/**
	 * @return array{ok: bool, index_built_at: string|null, index_pages_count: int|null, sdk_version: string, php_version: string}
	 */
	public static function status(string $indexPath): array
	{
		$ok = is_file($indexPath);
		$mtime = $ok ? @filemtime($indexPath) : null;

		$pages = null;
		if ($ok) {
			$decoded = @json_decode((string) @file_get_contents($indexPath), true);
			if (is_array($decoded) && isset($decoded['pages']) && is_array($decoded['pages'])) {
				$pages = count($decoded['pages']);
			}
		}

		return [
			'ok'                => $ok,
			'index_built_at'    => ($mtime !== null && $mtime !== false) ? date('c', $mtime) : null,
			'index_pages_count' => $pages,
			'sdk_version'       => InstalledVersions::getPrettyVersion('mcp/sdk') ?? 'unknown',
			'php_version'       => PHP_VERSION,
		];
	}
}
