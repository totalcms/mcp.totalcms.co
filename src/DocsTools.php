<?php

declare(strict_types=1);

namespace TotalCMS\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP tools for querying Total CMS documentation.
 *
 * The index is a structured JSON array built by bin/build-index.php from
 * the markdown documentation in the Total CMS repository.
 */
class DocsTools
{
	/** @var array<string, mixed> */
	private array $index;

	/**
	 * @param array<string, mixed> $index The loaded documentation index
	 */
	public function __construct(array $index)
	{
		$this->index = $index;
	}

	/**
	 * Search across all Total CMS documentation. Use this for general questions
	 * about Total CMS features, configuration, or usage.
	 */
	#[McpTool(
		name: 'docs_search',
		description: 'Full-text search across all Total CMS documentation. Returns matching sections with context and source URLs.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function search(string $query): string
	{
		$query = strtolower(trim($query));
		if ($query === '') {
			return json_encode(['error' => 'Query cannot be empty'], JSON_THROW_ON_ERROR);
		}

		$terms = preg_split('/\s+/', $query);
		$results = [];

		foreach ($this->index['pages'] ?? [] as $page) {
			$score = $this->scorePage($page, $terms);
			if ($score > 0) {
				$result = [
					'title'   => $page['title'],
					'path'    => $page['path'],
					'url'     => $page['url'],
					'excerpt' => $this->extractContext($page['content'] ?? '', $terms),
					'score'   => $score,
				];

				if (isset($page['since'])) {
					$result['since'] = $page['since'];
				}

				$results[] = $result;
			}
		}

		usort($results, fn (array $a, array $b) => $b['score'] <=> $a['score']);
		$results = array_slice($results, 0, 10);

		if (empty($results)) {
			return json_encode(['message' => 'No results found for: ' . $query], JSON_THROW_ON_ERROR);
		}

		return json_encode(['results' => $results], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

	/**
	 * Look up a specific Twig function by name. Returns the function signature,
	 * parameters, return type, description, and usage examples.
	 */
	#[McpTool(
		name: 'docs_twig_function',
		description: 'Look up a Total CMS Twig function by name. Returns signature, parameters, return type, and examples. Example: docs_twig_function("cms.objects")',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function twigFunction(string $name): string
	{
		$name = strtolower(trim($name));

		// Search in twig_functions index
		foreach ($this->index['twig_functions'] ?? [] as $func) {
			if (strtolower($func['name']) === $name) {
				return json_encode($func, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Try partial match
		$matches = [];
		foreach ($this->index['twig_functions'] ?? [] as $func) {
			if (str_contains(strtolower($func['name']), $name)) {
				$matches[] = $func;
			}
		}

		if (!empty($matches)) {
			return json_encode([
				'message' => "No exact match for '{$name}'. Did you mean:",
				'matches' => $matches,
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		return json_encode([
			'error'     => "Twig function '{$name}' not found.",
			'available' => array_column($this->index['twig_functions'] ?? [], 'name'),
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

	/**
	 * Look up a specific Twig filter by name. Returns the filter signature,
	 * description, and usage examples.
	 */
	#[McpTool(
		name: 'docs_twig_filter',
		description: 'Look up a Total CMS Twig filter by name. Returns signature, description, and examples. Example: docs_twig_filter("dateFormat")',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function twigFilter(string $name): string
	{
		$name = strtolower(trim($name));

		foreach ($this->index['twig_filters'] ?? [] as $filter) {
			if (strtolower($filter['name']) === $name) {
				return json_encode($filter, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Try partial match
		$matches = [];
		foreach ($this->index['twig_filters'] ?? [] as $filter) {
			if (str_contains(strtolower($filter['name']), $name)) {
				$matches[] = $filter;
			}
		}

		if (!empty($matches)) {
			return json_encode([
				'message' => "No exact match for '{$name}'. Did you mean:",
				'matches' => $matches,
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		return json_encode([
			'error'     => "Twig filter '{$name}' not found.",
			'available' => array_column($this->index['twig_filters'] ?? [], 'name'),
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

	/**
	 * Look up a field type by name. Returns configuration options, schema settings,
	 * and usage examples.
	 */
	#[McpTool(
		name: 'docs_field_type',
		description: 'Look up a Total CMS field type by name. Returns configuration options, schema settings, and examples. Example: docs_field_type("image")',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function fieldType(string $name): string
	{
		$name = strtolower(trim($name));

		foreach ($this->index['field_types'] ?? [] as $field) {
			if (strtolower($field['name']) === $name) {
				return json_encode($field, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Try partial match
		$matches = [];
		foreach ($this->index['field_types'] ?? [] as $field) {
			if (str_contains(strtolower($field['name']), $name)) {
				$matches[] = ['name' => $field['name'], 'description' => $field['description'] ?? ''];
			}
		}

		if (!empty($matches)) {
			return json_encode([
				'message' => "No exact match for '{$name}'. Did you mean:",
				'matches' => $matches,
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		return json_encode([
			'error'     => "Field type '{$name}' not found.",
			'available' => array_column($this->index['field_types'] ?? [], 'name'),
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

	/**
	 * Look up a REST API endpoint. Returns the HTTP method, path, parameters,
	 * headers, and response shape.
	 */
	#[McpTool(
		name: 'docs_api_endpoint',
		description: 'Look up a Total CMS REST API endpoint. Returns method, path, parameters, and response shape. Example: docs_api_endpoint("GET", "/collections/{name}")',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function apiEndpoint(string $method, string $path): string
	{
		$method = strtoupper(trim($method));
		$path = strtolower(trim($path));

		foreach ($this->index['api_endpoints'] ?? [] as $endpoint) {
			$epMethod = strtoupper($endpoint['method'] ?? '');
			$epPath = strtolower($endpoint['path'] ?? '');
			if ($epMethod === $method && ($epPath === $path || str_contains($epPath, $path))) {
				return json_encode($endpoint, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Try matching just the path
		$matches = [];
		foreach ($this->index['api_endpoints'] ?? [] as $endpoint) {
			$epPath = strtolower($endpoint['path'] ?? '');
			if (str_contains($epPath, $path)) {
				$matches[] = $endpoint;
			}
		}

		if (!empty($matches)) {
			return json_encode([
				'message' => "No exact match for {$method} {$path}. Related endpoints:",
				'matches' => $matches,
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		return json_encode([
			'error'     => "API endpoint '{$method} {$path}' not found.",
			'available' => array_map(
				fn (array $e) => ($e['method'] ?? 'GET') . ' ' . ($e['path'] ?? ''),
				$this->index['api_endpoints'] ?? []
			),
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

	/**
	 * Look up a schema configuration option. Returns the option description,
	 * valid values, and defaults.
	 */
	#[McpTool(
		name: 'docs_schema_config',
		description: 'Look up a Total CMS schema or collection configuration option. Returns description, valid values, and defaults. Example: docs_schema_config("labelPlural")',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function schemaConfig(string $key): string
	{
		$key = strtolower(trim($key));

		foreach ($this->index['schema_config'] ?? [] as $config) {
			if (strtolower($config['key'] ?? '') === $key) {
				return json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Try partial match
		$matches = [];
		foreach ($this->index['schema_config'] ?? [] as $config) {
			if (str_contains(strtolower($config['key'] ?? ''), $key)) {
				$matches[] = ['key' => $config['key'], 'description' => $config['description'] ?? ''];
			}
		}

		if (!empty($matches)) {
			return json_encode([
				'message' => "No exact match for '{$key}'. Did you mean:",
				'matches' => $matches,
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		return json_encode([
			'error'     => "Schema config '{$key}' not found.",
			'available' => array_column($this->index['schema_config'] ?? [], 'key'),
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

	/**
	 * Look up a CLI command. Returns the command syntax, arguments, options, and usage examples.
	 */
	#[McpTool(
		name: 'docs_cli_command',
		description: 'Look up a Total CMS CLI command by name. Returns syntax, arguments, options, and usage examples. Example: docs_cli_command("collection:query")',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function cliCommand(string $name): string
	{
		$name = strtolower(trim($name));

		// Exact match
		foreach ($this->index['cli_commands'] ?? [] as $cmd) {
			if (strtolower($cmd['name']) === $name) {
				return json_encode($cmd, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Partial match
		$matches = [];
		foreach ($this->index['cli_commands'] ?? [] as $cmd) {
			if (str_contains(strtolower($cmd['name']), $name)) {
				$matches[] = ['name' => $cmd['name'], 'description' => $cmd['description'] ?? ''];
			}
		}

		if (!empty($matches)) {
			return json_encode([
				'message' => "No exact match for '{$name}'. Did you mean:",
				'matches' => $matches,
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		// List all available commands
		$available = array_map(
			fn (array $cmd): string => $cmd['name'],
			$this->index['cli_commands'] ?? []
		);

		return json_encode([
			'error'              => "CLI command '{$name}' not found.",
			'available_commands' => $available,
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

	// -------------------------------------------------------
	// Search helpers
	// -------------------------------------------------------

	/**
	 * Score a page against search terms.
	 *
	 * @param array<string, mixed> $page
	 * @param string[]             $terms
	 */
	private function scorePage(array $page, array $terms): int
	{
		$score = 0;
		$title = strtolower($page['title'] ?? '');
		$content = strtolower($page['content'] ?? '');
		$sections = strtolower(implode(' ', $page['sections'] ?? []));

		foreach ($terms as $term) {
			// Title match is worth more
			if (str_contains($title, $term)) {
				$score += 10;
			}
			// Section heading match
			if (str_contains($sections, $term)) {
				$score += 5;
			}
			// Content match
			if (str_contains($content, $term)) {
				$score += 1;
				// Boost for multiple occurrences
				$score += min(substr_count($content, $term), 5);
			}
		}

		return $score;
	}

	/**
	 * Extract a context snippet around the first occurrence of any search term.
	 *
	 * @param string[] $terms
	 */
	private function extractContext(string $content, array $terms): string
	{
		$lowerContent = strtolower($content);
		$bestPos = null;

		foreach ($terms as $term) {
			$pos = strpos($lowerContent, $term);
			if ($pos !== false && ($bestPos === null || $pos < $bestPos)) {
				$bestPos = $pos;
			}
		}

		if ($bestPos === null) {
			return substr($content, 0, 200) . '...';
		}

		$start = max(0, $bestPos - 80);
		$excerpt = substr($content, $start, 300);
		$prefix = $start > 0 ? '...' : '';
		$suffix = ($start + 300) < strlen($content) ? '...' : '';

		return $prefix . trim($excerpt) . $suffix;
	}

	/**
	 * Look up the Total CMS extension API. Query by method name, event name,
	 * permission, or manifest field. Returns detailed information about the
	 * extension system for building extensions.
	 */
	#[McpTool(
		name: 'docs_extension',
		description: 'Look up Total CMS extension API details: context methods, events, permissions, or manifest fields. Example: docs_extension("addTwigFunction") or docs_extension("object.created") or docs_extension("permissions")',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function extension(string $query): string
	{
		$query = strtolower(trim($query));
		$api = $this->index['extension_api'] ?? [];

		if ($api === []) {
			return json_encode(['error' => 'Extension API index not available. Rebuild the index.'], JSON_THROW_ON_ERROR);
		}

		$versionNote = $api['min_version'] ?? null;

		// Return full section if querying by category
		if ($query === 'methods' || $query === 'context' || $query === 'context_methods') {
			return json_encode(['min_version' => $versionNote, 'items' => $api['context_methods'] ?? []], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}
		if ($query === 'events') {
			return json_encode(['min_version' => $versionNote, 'items' => $api['events'] ?? []], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}
		if ($query === 'permissions') {
			return json_encode(['min_version' => $versionNote, 'items' => $api['permissions'] ?? []], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}
		if ($query === 'manifest') {
			return json_encode($api['manifest_fields'] ?? [], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}
		if ($query === 'editions') {
			return json_encode($api['editions'] ?? [], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		// Search context methods by name
		foreach ($api['context_methods'] ?? [] as $method) {
			if (strtolower($method['name']) === $query) {
				return json_encode([...$method, 'min_version' => $versionNote], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Search events by name
		foreach ($api['events'] ?? [] as $event) {
			if (strtolower($event['name']) === $query) {
				return json_encode([...$event, 'min_version' => $versionNote], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Search permissions by ID
		foreach ($api['permissions'] ?? [] as $perm) {
			if (strtolower($perm['id']) === $query) {
				return json_encode([...$perm, 'min_version' => $versionNote], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Search manifest fields
		foreach ($api['manifest_fields'] ?? [] as $field) {
			if (strtolower($field['field']) === $query) {
				return json_encode([...$field, 'min_version' => $versionNote], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Partial match across all sections
		$matches = [];
		foreach ($api['context_methods'] ?? [] as $method) {
			if (str_contains(strtolower($method['name']), $query) || str_contains(strtolower($method['description']), $query)) {
				$matches[] = ['type' => 'method', ...$method];
			}
		}
		foreach ($api['events'] ?? [] as $event) {
			if (str_contains(strtolower($event['name']), $query) || str_contains(strtolower($event['description']), $query)) {
				$matches[] = ['type' => 'event', ...$event];
			}
		}
		foreach ($api['permissions'] ?? [] as $perm) {
			if (str_contains(strtolower($perm['id']), $query) || str_contains(strtolower($perm['description']), $query)) {
				$matches[] = ['type' => 'permission', ...$perm];
			}
		}

		if (!empty($matches)) {
			return json_encode([
				'message' => "No exact match for '{$query}'. Related results:",
				'matches' => $matches,
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		return json_encode([
			'error' => "No extension API match for '{$query}'.",
			'hint'  => 'Try: "methods", "events", "permissions", "manifest", "editions", or a specific name like "addTwigFunction" or "object.created"',
			'url'   => $api['url'] ?? 'https://docs.totalcms.co/extensions/overview/',
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}
}
