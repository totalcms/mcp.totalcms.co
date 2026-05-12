<?php

declare(strict_types=1);

/**
 * Minimal docs index fixture for unit tests.
 *
 * Covers each top-level key consumed by DocsTools so every lookup path has
 * representative data without dragging in the full production index.
 */

return [
	'version'  => '1.0.0',
	'built_at' => '2026-05-12T00:00:00Z',
	'pages' => [
		[
			'title'    => 'Blog Collection',
			'path'     => 'collections/blog',
			'url'      => 'https://docs.totalcms.co/collections/blog/',
			'sections' => ['Configuration', 'Templates'],
			'content'  => 'the blog collection stores posts with title, body, and date — supports markdown rendering.',
		],
		[
			'title'    => 'A/B Split',
			'path'     => 'extensions/bundled/ab-split',
			'url'      => 'https://docs.totalcms.co/extensions/bundled/ab-split/',
			'sections' => ['Enabling', 'Per-page configuration'],
			'content'  => 'render an alternate page template at the same url for a percentage of visitors — sticky via cookie.',
			'since'    => '3.5.0',
		],
	],
	'twig_functions' => [
		[
			'name'        => 'cms.collection.objects',
			'signature'   => 'cms.collection.objects(string $collection): array',
			'description' => 'Returns all objects in a collection.',
			'examples'    => ['{% set posts = cms.collection.objects("blog") %}'],
			'url'         => 'https://docs.totalcms.co/twig/collections/',
		],
		[
			'name'        => 'cms.image',
			'signature'   => 'cms.image(string $id): array',
			'description' => 'Fetch an image object.',
			'examples'    => [],
			'url'         => 'https://docs.totalcms.co/twig/media/',
		],
	],
	'twig_filters' => [
		[
			'name'        => 'humanize',
			'signature'   => 'humanize(): string',
			'description' => 'Converts machine-style strings into human-readable form.',
			'examples'    => [],
			'url'         => 'https://docs.totalcms.co/twig/filters/',
		],
	],
	'field_types' => [
		[
			'name'        => 'image',
			'title'       => 'Image',
			'description' => 'Image upload property with processing.',
			'content'     => 'image content placeholder',
			'url'         => 'https://docs.totalcms.co/property-settings/image-gallery/',
		],
	],
	'api_endpoints' => [
		[
			'method'      => 'GET',
			'path'        => '/collections/{name}',
			'title'       => 'Get collection objects',
			'description' => 'Returns objects in the named collection.',
			'parameters'  => [],
			'edition'     => 'lite',
			'url'         => 'https://docs.totalcms.co/api/rest-api/',
		],
	],
	'schema_config' => [
		[
			'key'         => 'labelPlural',
			'type'        => 'string',
			'required'    => false,
			'default'     => null,
			'description' => 'The plural label used in admin UI.',
			'examples'    => [],
			'url'         => 'https://docs.totalcms.co/collections/settings/',
		],
	],
	'cli_commands' => [
		[
			'name'        => 'collection:query',
			'description' => 'Query collection objects from the CLI.',
			'arguments'   => [],
			'options'     => [],
			'examples'    => [],
			'url'         => 'https://docs.totalcms.co/advanced/cli/',
		],
	],
	'extension_api' => [
		'min_version' => '3.3.0',
		'context_methods' => [
			[
				'name'        => 'addTwigFunction',
				'signature'   => 'addTwigFunction(TwigFunction $function): void',
				'description' => 'Register a custom Twig function.',
				'phase'       => 'register',
				'permission'  => 'twig:functions',
			],
		],
		'events' => [
			[
				'name'        => 'object.created',
				'description' => 'Fired after a new object is saved.',
				'payload'     => ['collection' => 'string', 'id' => 'string'],
			],
		],
		'permissions' => [
			['id' => 'twig:functions', 'description' => 'Register custom Twig functions'],
		],
		'manifest_fields' => [
			['field' => 'id', 'required' => true, 'description' => 'Unique ID in vendor/name format'],
		],
		'editions' => [
			['edition' => 'lite', 'level' => 1, 'description' => 'Basic edition'],
		],
		'bundled_extensions' => [
			'since' => '3.5.0',
			'items' => [
				[
					'id'           => 'totalcms/ab-split',
					'name'         => 'A/B Split',
					'description'  => 'Render an alternate template for a percentage of visitors.',
					'page_feature' => 'ab-split',
					'url'          => 'https://docs.totalcms.co/extensions/bundled/ab-split/',
				],
				[
					'id'           => 'totalcms/geo-redirect',
					'name'         => 'Geo Redirect',
					'description'  => 'Redirect based on country.',
					'page_feature' => 'geo-redirect',
					'url'          => 'https://docs.totalcms.co/extensions/bundled/geo-redirect/',
				],
			],
		],
		'url' => 'https://docs.totalcms.co/extensions/overview/',
	],
	'builder_api' => [
		'min_version'      => '3.3.0',
		'pages_collection' => 'builder-pages',
		'page_schema' => [
			'id'     => 'builder-page',
			'fields' => [
				['name' => 'route',      'type' => 'text',          'description' => 'URL pattern'],
				['name' => 'middleware', 'type' => 'multicheckbox', 'description' => 'Page features to run before render'],
			],
		],
		'directory_structure' => [
			['path' => 'tcms-data/builder/layouts/', 'description' => 'Base layouts'],
		],
		'template_data' => [
			['name' => 'page', 'type' => 'object', 'description' => 'The full page object'],
		],
		'twig_functions' => [
			['name' => 'cms.builder.nav', 'signature' => 'nav(): array', 'description' => 'Top-level navigation pages'],
		],
		'cli_commands' => [
			['name' => 'builder:init', 'signature' => 'tcms builder:init [starter]', 'description' => 'Scaffold a builder site'],
		],
		'starters' => [
			['id' => 'blog', 'pages' => ['Home', 'Blog'], 'description' => 'Blog-focused site'],
		],
		'asset_config' => [],
		'route_patterns' => [
			['pattern' => '/about', 'type' => 'static', 'description' => 'Exact match'],
		],
		'collection_url_routing' => ['description' => 'Routes by collection url'],
		'page_features' => [
			'since' => '3.5.0',
			'built_in' => [
				['name' => 'auth', 'description' => 'Requires a logged-in visitor'],
			],
			'bundled_features' => [
				['name' => 'ab-split',     'extension' => 'totalcms/ab-split',     'description' => 'A/B render'],
				['name' => 'geo-redirect', 'extension' => 'totalcms/geo-redirect', 'description' => 'Geo redirect'],
			],
		],
		'url' => 'https://docs.totalcms.co/builder/overview/',
	],
];
