<?php

declare(strict_types=1);

namespace TotalCMS\Mcp;

use Mcp\Server\NativeClock;
use Mcp\Server\Session\FileSessionStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Hardened session store that treats malformed session files as missing.
 *
 * The bundled FileSessionStore returns the raw bytes; Session::readData()
 * then json_decodes with JSON_THROW_ON_ERROR, which raises uncaught
 * exceptions for corrupt files. We validate JSON here and unlink+return
 * false on failure, which the SDK treats as "session expired/not found"
 * and surfaces as a normal 404 to the client.
 */
class SafeFileSessionStore extends FileSessionStore
{
	public function __construct(
		string $directory,
		int $ttl = 3600,
		?ClockInterface $clock = null,
		private readonly LoggerInterface $logger = new NullLogger(),
	) {
		parent::__construct($directory, $ttl, $clock ?? new NativeClock());
	}

	public function read(Uuid $id): string|false
	{
		$data = parent::read($id);

		if (false === $data) {
			return false;
		}

		// Validate JSON before handing back to Session::readData() which
		// would otherwise throw JsonException on corrupt content.
		json_decode($data, true);
		if (\JSON_ERROR_NONE !== json_last_error()) {
			$this->logger->warning('Discarding corrupt session file', [
				'session_id' => $id->toRfc4122(),
				'error'      => json_last_error_msg(),
			]);
			$this->destroy($id);
			return false;
		}

		return $data;
	}
}
