<?php

declare(strict_types=1);

namespace TotalCMS\Mcp;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the configured MCP Server with all DocsTools registered as tools.
 *
 * Extracted from public/index.php so tests can construct a Server without
 * triggering the request-handling exit() in the entry point. The exact same
 * function powers production — index.php just adds the HTTP transport on top.
 */
class McpServerFactory
{
	/**
	 * @param array<string, mixed> $index    The loaded documentation index
	 * @param SessionStoreInterface $sessionStore Session backend (FileSession or InMemory in tests)
	 */
	public static function build(
		array $index,
		SessionStoreInterface $sessionStore,
		LoggerInterface $logger = new NullLogger(),
	): Server {
		$tools    = new DocsTools($index);
		$readOnly = new ToolAnnotations(readOnlyHint: true);

		$builder = Server::builder()
			->setServerInfo(
				name: 'Total CMS Docs',
				version: '1.0.0',
				description: 'Total CMS documentation server — look up Twig functions, filters, field types, API endpoints, and more.',
			)
			->setInstructions(
				'This server provides documentation for Total CMS, a flat-file PHP content management system. '
				. 'Use docs_search for general queries. Use the specific lookup tools (docs_twig_function, docs_twig_filter, etc.) '
				. 'when you know exactly what you are looking for.'
			)
			->setSession($sessionStore)
			->setLogger($logger);

		$builder->addTool(
			handler: fn (string $query) => $tools->search($query),
			name: 'docs_search',
			description: 'Full-text search across all Total CMS documentation. Returns matching sections with context and source URLs.',
			annotations: $readOnly,
		);
		$builder->addTool(
			handler: fn (string $name) => $tools->twigFunction($name),
			name: 'docs_twig_function',
			description: 'Look up a Total CMS Twig function by name. Returns signature, parameters, return type, and examples. Example: docs_twig_function("cms.collection.objects")',
			annotations: $readOnly,
		);
		$builder->addTool(
			handler: fn (string $name) => $tools->twigFilter($name),
			name: 'docs_twig_filter',
			description: 'Look up a Total CMS Twig filter by name. Returns signature, description, and examples. Example: docs_twig_filter("humanize")',
			annotations: $readOnly,
		);
		$builder->addTool(
			handler: fn (string $name) => $tools->fieldType($name),
			name: 'docs_field_type',
			description: 'Look up a Total CMS field type by name. Returns configuration options, schema settings, and examples. Example: docs_field_type("image")',
			annotations: $readOnly,
		);
		$builder->addTool(
			handler: fn (string $method, string $path) => $tools->apiEndpoint($method, $path),
			name: 'docs_api_endpoint',
			description: 'Look up a Total CMS REST API endpoint. Returns method, path, parameters, and response shape. Example: docs_api_endpoint("GET", "/collections/{name}")',
			annotations: $readOnly,
		);
		$builder->addTool(
			handler: fn (string $key) => $tools->schemaConfig($key),
			name: 'docs_schema_config',
			description: 'Look up a Total CMS schema or collection configuration option. Returns description, valid values, and defaults. Example: docs_schema_config("labelPlural")',
			annotations: $readOnly,
		);
		$builder->addTool(
			handler: fn (string $name) => $tools->cliCommand($name),
			name: 'docs_cli_command',
			description: 'Look up a Total CMS CLI command. Returns syntax, flags, and usage. Note: CLI is currently in development.',
			annotations: $readOnly,
		);
		$builder->addTool(
			handler: fn (string $query) => $tools->extension($query),
			name: 'docs_extension',
			description: 'Look up Total CMS extension API details: context methods, events, permissions, manifest fields, or bundled extensions. Example: docs_extension("addTwigFunction"), docs_extension("object.created"), docs_extension("permissions"), docs_extension("bundled"), docs_extension("totalcms/ab-split"), or docs_extension("geo-redirect")',
			annotations: $readOnly,
		);
		$builder->addTool(
			handler: fn (string $query) => $tools->builder($query),
			name: 'docs_builder',
			description: 'Look up Total CMS Site Builder details: page schema, directory structure, twig functions, asset pipeline, starter templates, CLI commands, route patterns, and page features (middleware). Examples: docs_builder("overview"), docs_builder("schema"), docs_builder("twig"), docs_builder("features"), docs_builder("starters"), docs_builder("cms.builder.nav"), docs_builder("ab-split")',
			annotations: $readOnly,
		);

		return $builder->build();
	}
}
