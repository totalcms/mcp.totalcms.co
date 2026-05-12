<?php

declare(strict_types=1);

/**
 * Decode a tool's JSON string return value back to an array.
 */
function decode(string $json): array
{
	$decoded = json_decode($json, true);
	expect($decoded)->toBeArray();
	return $decoded;
}

// ----------------------------------------------------------------
// docs_search
// ----------------------------------------------------------------

describe('search', function (): void {
	it('returns ranked results for a matching query', function (): void {
		$result = decode(docsTools()->search('blog'));
		expect($result['results'])->toBeArray();
		expect($result['results'][0]['title'])->toBe('Blog Collection');
	});

	it('returns no-results message for an unknown query', function (): void {
		$result = decode(docsTools()->search('xylophonical-marshmallow'));
		expect($result)->toHaveKey('message');
		expect($result['message'])->toContain('No results found');
	});

	it('rejects empty queries', function (): void {
		$result = decode(docsTools()->search(''));
		expect($result['error'])->toBe('Query cannot be empty');
	});

	it('promotes the since field into search results when present', function (): void {
		$result = decode(docsTools()->search('a/b split'));
		expect($result['results'][0]['since'] ?? null)->toBe('3.5.0');
	});

	it('handles UTF-8 content without crashing (em-dash regression)', function (): void {
		// fixture content contains "—" — would crash before mb_substr fix
		$result = decode(docsTools()->search('alternate'));
		expect($result['results'][0]['excerpt'])->toBeString();
	});
});

// ----------------------------------------------------------------
// docs_twig_function
// ----------------------------------------------------------------

describe('twigFunction', function (): void {
	it('returns the function on exact match', function (): void {
		$result = decode(docsTools()->twigFunction('cms.collection.objects'));
		expect($result['name'])->toBe('cms.collection.objects');
		expect($result['signature'])->toContain('array');
	});

	it('returns did-you-mean candidates on partial match', function (): void {
		$result = decode(docsTools()->twigFunction('collection'));
		expect($result['message'])->toContain('Did you mean');
		expect($result['matches'][0]['name'])->toBe('cms.collection.objects');
	});

	it('returns an error and available list on miss', function (): void {
		$result = decode(docsTools()->twigFunction('totally.not.real'));
		expect($result['error'])->toContain("not found");
		expect($result['available'])->toContain('cms.collection.objects');
	});
});

// ----------------------------------------------------------------
// docs_twig_filter
// ----------------------------------------------------------------

describe('twigFilter', function (): void {
	it('returns the filter on exact match', function (): void {
		$result = decode(docsTools()->twigFilter('humanize'));
		expect($result['name'])->toBe('humanize');
	});

	it('returns error on miss', function (): void {
		$result = decode(docsTools()->twigFilter('nope'));
		expect($result['error'])->toContain('not found');
	});
});

// ----------------------------------------------------------------
// docs_field_type
// ----------------------------------------------------------------

describe('fieldType', function (): void {
	it('returns the field type on exact match', function (): void {
		$result = decode(docsTools()->fieldType('image'));
		expect($result['name'])->toBe('image');
	});

	it('returns error on miss', function (): void {
		$result = decode(docsTools()->fieldType('nonexistent'));
		expect($result['error'])->toContain('not found');
	});
});

// ----------------------------------------------------------------
// docs_api_endpoint
// ----------------------------------------------------------------

describe('apiEndpoint', function (): void {
	it('returns the endpoint on exact match', function (): void {
		$result = decode(docsTools()->apiEndpoint('GET', '/collections/{name}'));
		expect($result['method'])->toBe('GET');
		expect($result['path'])->toBe('/collections/{name}');
	});

	it('returns error on miss', function (): void {
		$result = decode(docsTools()->apiEndpoint('GET', '/nowhere'));
		expect($result)->toHaveKey('error');
	});
});

// ----------------------------------------------------------------
// docs_schema_config
// ----------------------------------------------------------------

describe('schemaConfig', function (): void {
	it('returns the config on exact match', function (): void {
		$result = decode(docsTools()->schemaConfig('labelPlural'));
		expect($result['key'])->toBe('labelPlural');
	});

	it('returns error on miss', function (): void {
		$result = decode(docsTools()->schemaConfig('nope'));
		expect($result['error'])->toContain('not found');
	});
});

// ----------------------------------------------------------------
// docs_cli_command
// ----------------------------------------------------------------

describe('cliCommand', function (): void {
	it('returns the command on exact match', function (): void {
		$result = decode(docsTools()->cliCommand('collection:query'));
		expect($result['name'])->toBe('collection:query');
	});

	it('returns error on miss', function (): void {
		$result = decode(docsTools()->cliCommand('nope:nope'));
		expect($result['error'])->toContain('not found');
	});
});

// ----------------------------------------------------------------
// docs_extension
// ----------------------------------------------------------------

describe('extension', function (): void {
	it('returns context_methods for "methods" category', function (): void {
		$result = decode(docsTools()->extension('methods'));
		expect($result['items'][0]['name'])->toBe('addTwigFunction');
	});

	it('returns events for "events" category', function (): void {
		$result = decode(docsTools()->extension('events'));
		expect($result['items'][0]['name'])->toBe('object.created');
	});

	it('returns permissions for "permissions" category', function (): void {
		$result = decode(docsTools()->extension('permissions'));
		expect($result['items'][0]['id'])->toBe('twig:functions');
	});

	it('returns bundled_extensions for "bundled" category', function (): void {
		$result = decode(docsTools()->extension('bundled'));
		expect($result['items'])->toHaveCount(2);
		expect($result['items'][0]['id'])->toBe('totalcms/ab-split');
	});

	it('finds a bundled extension by full id', function (): void {
		$result = decode(docsTools()->extension('totalcms/ab-split'));
		expect($result['type'])->toBe('bundled_extension');
		expect($result['id'])->toBe('totalcms/ab-split');
	});

	it('finds a bundled extension by feature name', function (): void {
		$result = decode(docsTools()->extension('geo-redirect'));
		expect($result['type'])->toBe('bundled_extension');
		expect($result['id'])->toBe('totalcms/geo-redirect');
	});

	it('finds a context method by name', function (): void {
		$result = decode(docsTools()->extension('addTwigFunction'));
		expect($result['name'])->toBe('addTwigFunction');
	});

	it('finds an event by name', function (): void {
		$result = decode(docsTools()->extension('object.created'));
		expect($result['name'])->toBe('object.created');
	});

	it('returns error and hint on miss', function (): void {
		$result = decode(docsTools()->extension('nope-nope-nope'));
		expect($result)->toHaveKey('error');
		expect($result['hint'])->toContain('bundled');
	});
});

// ----------------------------------------------------------------
// docs_builder
// ----------------------------------------------------------------

describe('builder', function (): void {
	it('returns the overview summary for "overview"', function (): void {
		$result = decode(docsTools()->builder('overview'));
		expect($result['pages_collection'])->toBe('builder-pages');
	});

	it('returns page_features for "features" category', function (): void {
		$result = decode(docsTools()->builder('features'));
		expect($result['page_features']['built_in'][0]['name'])->toBe('auth');
	});

	it('finds a built-in page feature by name', function (): void {
		$result = decode(docsTools()->builder('auth'));
		expect($result['type'])->toBe('page_feature');
		expect($result['source'])->toBe('built-in');
	});

	it('finds a bundled page feature by name', function (): void {
		$result = decode(docsTools()->builder('ab-split'));
		expect($result['type'])->toBe('page_feature');
		expect($result['source'])->toBe('bundled-extension');
		expect($result['extension'])->toBe('totalcms/ab-split');
	});

	it('finds a page schema field by name', function (): void {
		$result = decode(docsTools()->builder('middleware'));
		// "middleware" is also a category alias → returns page_features
		expect($result['page_features']['built_in'][0]['name'])->toBe('auth');
	});

	it('finds a twig function by name', function (): void {
		$result = decode(docsTools()->builder('cms.builder.nav'));
		expect($result['name'])->toBe('cms.builder.nav');
	});

	it('finds a starter by id', function (): void {
		$result = decode(docsTools()->builder('blog'));
		expect($result['id'])->toBe('blog');
		expect($result['cli'])->toBe('tcms builder:init blog');
	});

	it('returns error and hint on miss', function (): void {
		$result = decode(docsTools()->builder('xyz-not-real'));
		expect($result)->toHaveKey('error');
		expect($result['hint'])->toContain('features');
	});
});
