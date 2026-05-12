<?php

declare(strict_types=1);

namespace TotalCMS\Mcp;

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
		$lowerContent = mb_strtolower($content);
		$bestPos = null;

		foreach ($terms as $term) {
			$pos = mb_strpos($lowerContent, $term);
			if ($pos !== false && ($bestPos === null || $pos < $bestPos)) {
				$bestPos = $pos;
			}
		}

		if ($bestPos === null) {
			return mb_substr($content, 0, 200) . '...';
		}

		$start = max(0, $bestPos - 80);
		$excerpt = mb_substr($content, $start, 300);
		$prefix = $start > 0 ? '...' : '';
		$suffix = ($start + 300) < mb_strlen($content) ? '...' : '';

		return $prefix . trim($excerpt) . $suffix;
	}

	/**
	 * Look up the Total CMS extension API. Query by method name, event name,
	 * permission, or manifest field. Returns detailed information about the
	 * extension system for building extensions.
	 */
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
		if ($query === 'bundled' || $query === 'bundled_extensions') {
			return json_encode($api['bundled_extensions'] ?? [], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		// Specific bundled extension lookup — by full id (totalcms/ab-split),
		// short id (ab-split), or page-feature name (also ab-split / geo-redirect).
		foreach ($api['bundled_extensions']['items'] ?? [] as $item) {
			$id = strtolower($item['id'] ?? '');
			$shortId = strtolower(basename($item['id'] ?? ''));
			$feature = strtolower($item['page_feature'] ?? '');
			if ($id === $query || $shortId === $query || $feature === $query) {
				return json_encode([
					'type'        => 'bundled_extension',
					...$item,
					'min_version' => $api['bundled_extensions']['since'] ?? $versionNote,
				], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
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
		foreach ($api['bundled_extensions']['items'] ?? [] as $item) {
			$haystack = strtolower(($item['id'] ?? '') . ' ' . ($item['name'] ?? '') . ' ' . ($item['description'] ?? '') . ' ' . ($item['page_feature'] ?? ''));
			if (str_contains($haystack, $query)) {
				$matches[] = ['type' => 'bundled_extension', ...$item];
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
			'hint'  => 'Try: "methods", "events", "permissions", "manifest", "editions", "bundled", or a specific name like "addTwigFunction", "object.created", or "totalcms/ab-split"',
			'url'   => $api['url'] ?? 'https://docs.totalcms.co/extensions/overview/',
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}

	/**
	 * Look up the Total CMS Site Builder reference. Use this when helping users
	 * build site templates, page routes, navigation menus, or asset pipelines.
	 *
	 * Use category queries to get a coherent slice of the builder API:
	 * - "overview"  — what the Site Builder is and how routing works
	 * - "schema"    — the builder-page schema fields (use when creating page objects)
	 * - "templates" — directory structure (layouts/, pages/, partials/, macros/) and template_data variables
	 * - "twig"      — cms.builder.* twig functions (nav, asset, css, js, etc.)
	 * - "assets"    — asset path config and Vite/esbuild manifest convention
	 * - "starters"  — bundled starter templates (minimal, business, blog, portfolio)
	 * - "cli"       — builder:init scaffolding command
	 * - "routes"    — static and dynamic route patterns, and collection URL routing
	 * - "features"  — page features / middleware system (built-in auth + bundled features like ab-split, geo-redirect)
	 *
	 * Or query by a specific identifier — a twig function name (e.g. "cms.builder.nav"),
	 * a starter id (e.g. "blog"), a page schema field (e.g. "route", "middleware"),
	 * a CLI command, or a page-feature name (e.g. "auth", "ab-split", "geo-redirect").
	 */
	public function builder(string $query): string
	{
		$query = strtolower(trim($query));
		$api   = $this->index['builder_api'] ?? [];

		if ($api === []) {
			return json_encode(['error' => 'Site Builder API index not available. Rebuild the index.'], JSON_THROW_ON_ERROR);
		}

		$versionNote = $api['min_version'] ?? null;

		// Empty query → return the high-level summary
		if ($query === '' || $query === 'overview' || $query === 'help') {
			return json_encode([
				'min_version'       => $versionNote,
				'note'              => $api['note'] ?? '',
				'overview'          => $api['overview'] ?? '',
				'pages_collection'  => $api['pages_collection'] ?? '',
				'docs'              => $api['docs'] ?? [],
				'next_steps'        => 'Query a category for details: schema, templates, twig, assets, starters, cli, routes',
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		// Category queries
		$categories = [
			'schema'         => ['page_schema'],
			'page'           => ['page_schema'],
			'pages'          => ['page_schema', 'pages_collection'],
			'templates'      => ['directory_structure', 'template_data'],
			'directory'      => ['directory_structure'],
			'data'           => ['template_data'],
			'twig'           => ['twig_functions'],
			'functions'      => ['twig_functions'],
			'assets'         => ['asset_config'],
			'asset'          => ['asset_config'],
			'starters'       => ['starters'],
			'starter'        => ['starters'],
			'cli'            => ['cli_commands'],
			'commands'       => ['cli_commands'],
			'routes'         => ['route_patterns', 'collection_url_routing'],
			'routing'        => ['route_patterns', 'collection_url_routing'],
			'features'       => ['page_features'],
			'page_features'  => ['page_features'],
			'middleware'     => ['page_features'],
		];

		if (isset($categories[$query])) {
			$result = ['min_version' => $versionNote];
			foreach ($categories[$query] as $key) {
				if (isset($api[$key])) {
					$result[$key] = $api[$key];
				}
			}
			return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		// Specific lookups: twig function by name
		foreach ($api['twig_functions'] ?? [] as $fn) {
			if (strtolower($fn['name']) === $query) {
				return json_encode([...$fn, 'min_version' => $versionNote], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Page schema field by name
		foreach ($api['page_schema']['fields'] ?? [] as $field) {
			if (strtolower($field['name']) === $query) {
				return json_encode([
					'type'              => 'page_schema_field',
					'field'             => $field,
					'pages_collection'  => $api['pages_collection'] ?? '',
					'min_version'       => $versionNote,
				], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Starter by id
		foreach ($api['starters'] ?? [] as $starter) {
			if (strtolower($starter['id']) === $query) {
				return json_encode([...$starter, 'cli' => 'tcms builder:init ' . $starter['id'], 'min_version' => $versionNote], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// CLI command by name
		foreach ($api['cli_commands'] ?? [] as $cmd) {
			if (strtolower($cmd['name']) === $query) {
				return json_encode([...$cmd, 'min_version' => $versionNote], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Page feature by name (built-in or bundled)
		foreach ($api['page_features']['built_in'] ?? [] as $feature) {
			if (strtolower($feature['name']) === $query) {
				return json_encode([
					'type'        => 'page_feature',
					'source'      => 'built-in',
					...$feature,
					'min_version' => $versionNote,
				], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}
		foreach ($api['page_features']['bundled_features'] ?? [] as $feature) {
			if (strtolower($feature['name']) === $query) {
				return json_encode([
					'type'   => 'page_feature',
					'source' => 'bundled-extension',
					...$feature,
				], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
			}
		}

		// Partial matches across functions, fields, starters, page features
		$matches = [];
		foreach ($api['twig_functions'] ?? [] as $fn) {
			if (str_contains(strtolower($fn['name']), $query) || str_contains(strtolower($fn['description']), $query)) {
				$matches[] = ['type' => 'twig_function', ...$fn];
			}
		}
		foreach ($api['page_schema']['fields'] ?? [] as $field) {
			if (str_contains(strtolower($field['name']), $query) || str_contains(strtolower($field['description']), $query)) {
				$matches[] = ['type' => 'page_schema_field', ...$field];
			}
		}
		foreach ($api['starters'] ?? [] as $starter) {
			if (str_contains(strtolower($starter['id']), $query) || str_contains(strtolower($starter['description']), $query)) {
				$matches[] = ['type' => 'starter', ...$starter];
			}
		}
		foreach ($api['page_features']['built_in'] ?? [] as $feature) {
			if (str_contains(strtolower($feature['name']), $query) || str_contains(strtolower($feature['description']), $query)) {
				$matches[] = ['type' => 'page_feature', 'source' => 'built-in', ...$feature];
			}
		}
		foreach ($api['page_features']['bundled_features'] ?? [] as $feature) {
			if (str_contains(strtolower($feature['name']), $query) || str_contains(strtolower($feature['description']), $query)) {
				$matches[] = ['type' => 'page_feature', 'source' => 'bundled-extension', ...$feature];
			}
		}

		if (!empty($matches)) {
			return json_encode([
				'message' => "No exact match for '{$query}'. Related results:",
				'matches' => $matches,
			], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		}

		return json_encode([
			'error' => "No Site Builder match for '{$query}'.",
			'hint'  => 'Try a category — "overview", "schema", "templates", "twig", "assets", "starters", "cli", "routes", or "features" — or a specific name like "cms.builder.nav", "route", "blog", "auth", "ab-split", or "geo-redirect".',
			'url'   => $api['url'] ?? 'https://docs.totalcms.co/builder/overview/',
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
	}
}
