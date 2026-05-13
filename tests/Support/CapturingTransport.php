<?php

declare(strict_types=1);

namespace TotalCMS\Mcp\Tests\Support;

use Mcp\Server\Transport\InMemoryTransport;

/**
 * Test helper — drives canned MCP messages through the Server and captures
 * every response (whether emitted directly via send() or queued onto the
 * session and drained via getOutgoingMessages()).
 *
 * The bundled InMemoryTransport runs the input loop but doesn't surface the
 * queued responses to callers — the StreamableHttpTransport polls them per
 * request, which is what we mimic here for tests.
 */
class CapturingTransport extends InMemoryTransport
{
	/** @var list<array{data: string, context: array<string, mixed>}> */
	public array $sent = [];

	public function send(string $data, array $context): void
	{
		parent::send($data, $context);
		$this->sent[] = ['data' => $data, 'context' => $context];
	}

	public function listen(): mixed
	{
		$this->logger->info('CapturingTransport processing canned messages');

		$reflection = new \ReflectionClass(parent::class);
		$messagesProperty = $reflection->getProperty('messages');

		foreach ($messagesProperty->getValue($this) as $message) {
			$this->handleMessage($message, $this->sessionId);
			// Drain any session-queued responses produced by this message.
			foreach ($this->getOutgoingMessages($this->sessionId) as $outgoing) {
				$this->sent[] = [
					'data'    => $outgoing['message'],
					'context' => $outgoing['context'],
				];
			}
		}

		$this->handleSessionEnd($this->sessionId);
		$this->sessionId = null;

		return null;
	}

	/**
	 * Decoded JSON-RPC responses in send order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function decodedResponses(): array
	{
		$out = [];
		foreach ($this->sent as $row) {
			$decoded = json_decode($row['data'], true);
			if (is_array($decoded)) {
				$out[] = $decoded;
			}
		}
		return $out;
	}

	/**
	 * Return the decoded response with the given JSON-RPC id, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function responseFor(int $id): ?array
	{
		foreach ($this->decodedResponses() as $response) {
			if (($response['id'] ?? null) === $id) {
				return $response;
			}
		}
		return null;
	}
}
