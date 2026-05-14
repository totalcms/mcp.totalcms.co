<?php

declare(strict_types=1);

/**
 * Source-level reflection of T3's TotalCMSTwigAdapter and its sub-adapters.
 *
 * Produces the canonical list of cms.* twig functions/properties by walking
 * the constructor of TotalCMSTwigAdapter (each public sub-adapter property
 * becomes a namespace like cms.collection) and reflecting each sub-adapter's
 * own public methods.
 *
 * Code is the source of truth for *what exists*. Docs (parsed separately)
 * are the source of truth for examples and richer prose — when reflection
 * and docs both have a method, the entry merges signature/description from
 * reflection with examples from docs.
 */

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use phpDocumentor\Reflection\DocBlockFactory;

require_once __DIR__ . '/reflect-extension-api.php';

/**
 * Methods on TotalCMSTwigAdapter that shouldn't be exposed as twig functions —
 * they're internal plumbing (request handling, asset collection, magic dispatch).
 */
const TWIG_SKIP_ROOT_METHODS = [
	'__construct',
	'__call',
	'log',                // Internal — twig templates shouldn't be logging.
	'addFrontendAssets',  // Setters used by extensions during boot, not by templates.
	'addAdminAssets',
];

/**
 * Property names on TotalCMSTwigAdapter that don't map to a sub-adapter we
 * want to reflect — they're scalar runtime values (env, base, etc.) rather
 * than namespaces with methods.
 */
const TWIG_SKIP_ROOT_PROPERTIES = [
	'env', 'base', 'api', 'dashboard', 'login', 'domain',
	'clearcache', 'version', 'currentUrl',
];

/**
 * Docs URL prefix for each sub-namespace. Not every adapter has a 1:1 doc
 * file; this map captures the conventions we use. Unknown namespaces fall
 * back to the generic /twig/ index.
 */
const TWIG_NAMESPACE_DOCS = [
	'form'       => 'https://docs.totalcms.co/forms/builder/',
	'license'    => 'https://docs.totalcms.co/twig/edition/',
	'edition'    => 'https://docs.totalcms.co/twig/edition/',
	'render'     => 'https://docs.totalcms.co/twig/render/',
	'view'       => 'https://docs.totalcms.co/twig/views/',
	'schema'     => 'https://docs.totalcms.co/schemas/twig/',
	'auth'       => 'https://docs.totalcms.co/auth/twig/',
	'data'       => 'https://docs.totalcms.co/twig/data/',
	'media'      => 'https://docs.totalcms.co/twig/media/',
	'collection' => 'https://docs.totalcms.co/twig/collections/',
	'admin'      => 'https://docs.totalcms.co/admin/twig/',
	'builder'    => 'https://docs.totalcms.co/site-builder/twig/',
	'locale'     => 'https://docs.totalcms.co/twig/locale/',
	'utils'      => 'https://docs.totalcms.co/twig/utils/',
];

/**
 * Reflect TotalCMSTwigAdapter and emit every public twig-callable method,
 * qualified with its cms.* namespace.
 *
 * @return list<array{name: string, signature: string, description: string, url: string}>
 */
function reflectCmsTwigFunctions(string $totalcmsPath): array
{
	$rootFile = $totalcmsPath . '/src/Domain/Twig/Adapter/TotalCMSTwigAdapter.php';
	if (!is_file($rootFile)) {
		throw new RuntimeException("TotalCMSTwigAdapter source not found at {$rootFile}");
	}

	$functions = [];

	// 1. Top-level cms.* methods (config, etc.)
	$rootClass = parseClassFromFile($rootFile, 'TotalCMSTwigAdapter');
	foreach (publicMethodsOf($rootClass, TWIG_SKIP_ROOT_METHODS) as $method) {
		$functions[] = formatTwigFunction(
			namespace: 'cms',
			method: $method,
			url: 'https://docs.totalcms.co/twig/totalcms/',
		);
	}

	// 2. cms.<namespace>.* methods from each sub-adapter property on the constructor.
	$ctor = constructorOf($rootClass);
	if ($ctor === null) {
		return $functions;
	}

	foreach ($ctor->params as $param) {
		if (($param->flags & Modifiers::PUBLIC) === 0) {
			continue; // Only public promoted properties.
		}
		$propName = $param->var->name;
		if (in_array($propName, TWIG_SKIP_ROOT_PROPERTIES, true)) {
			continue;
		}
		if (!$param->type instanceof Node\Name && !$param->type instanceof Node\Identifier) {
			continue;
		}
		$adapterClass = $param->type instanceof Node\Name ? $param->type->toString() : $param->type->name;
		$adapterShort = basename(str_replace('\\', '/', $adapterClass));

		$adapterFile = $totalcmsPath . '/src/Domain/Twig/Adapter/' . $adapterShort . '.php';
		if (!is_file($adapterFile)) {
			// Sub-adapter from a different namespace (e.g. TotalFormFactory under Admin/).
			$adapterFile = findClassFile($totalcmsPath . '/src', $adapterShort);
		}
		if ($adapterFile === null || !is_file($adapterFile)) {
			continue;
		}

		$adapterClassNode = parseClassFromFile($adapterFile, $adapterShort);
		$url = TWIG_NAMESPACE_DOCS[$propName] ?? 'https://docs.totalcms.co/twig/';

		foreach (publicMethodsOf($adapterClassNode, ['__construct', '__call']) as $method) {
			$functions[] = formatTwigFunction(
				namespace: 'cms.' . $propName,
				method: $method,
				url: $url,
			);
		}
	}

	// Sort for stable output.
	usort($functions, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

	return $functions;
}

/**
 * @param list<string> $skip
 * @return list<Node\Stmt\ClassMethod>
 */
function publicMethodsOf(Node\Stmt\Class_ $class, array $skip): array
{
	$methods = [];
	foreach ($class->getMethods() as $method) {
		if (!$method->isPublic() || $method->isStatic()) {
			continue;
		}
		if (in_array($method->name->toString(), $skip, true)) {
			continue;
		}
		$methods[] = $method;
	}
	return $methods;
}

function constructorOf(Node\Stmt\Class_ $class): ?Node\Stmt\ClassMethod
{
	foreach ($class->getMethods() as $method) {
		if ($method->name->toString() === '__construct') {
			return $method;
		}
	}
	return null;
}

/**
 * @return array{name: string, signature: string, description: string, url: string}
 */
function formatTwigFunction(string $namespace, Node\Stmt\ClassMethod $method, string $url): array
{
	$shortName = $method->name->toString();
	return [
		'name'        => $namespace . '.' . $shortName,
		'signature'   => formatMethodSignature($method),
		'description' => extractMethodDescription($method, DocBlockFactory::createInstance()),
		'url'         => $url,
	];
}

/**
 * Recursively search src/ for a file whose class name matches $shortName.
 * Used for adapter types that live outside src/Domain/Twig/Adapter/
 * (e.g. TotalFormFactory under Admin/).
 */
function findClassFile(string $searchDir, string $shortName): ?string
{
	$target = $shortName . '.php';
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($searchDir));
	foreach ($iterator as $file) {
		if ($file->isFile() && $file->getFilename() === $target) {
			return $file->getPathname();
		}
	}
	return null;
}

/**
 * Merge reflection-derived twig functions with docs-parsed entries.
 *
 * Reflection is the canonical existence check (code IS the truth about
 * what twig can call). Docs supply richer descriptions and example
 * snippets where they're written. Doc-only entries (i.e. methods that
 * exist in markdown but not in code) are returned separately as a drift
 * report — they're stale documentation.
 *
 * @param list<array{name:string,signature:string,description:string,url:string}> $reflected
 * @param list<array{name:string,signature:string,description:string,examples:string[],url:string}> $documented
 * @return array{0: list<array<string,mixed>>, 1: list<string>} [merged entries, names of stale doc-only entries]
 */
function mergeTwigFunctions(array $reflected, array $documented): array
{
	$docsByName = [];
	foreach ($documented as $entry) {
		$docsByName[$entry['name']] = $entry;
	}

	$merged = [];
	foreach ($reflected as $entry) {
		$doc = $docsByName[$entry['name']] ?? null;
		if ($doc !== null) {
			$merged[] = [
				'name'        => $entry['name'],
				'signature'   => $entry['signature'],
				// Prefer docs description if reflection's docblock summary is empty.
				'description' => $entry['description'] !== '' ? $entry['description'] : ($doc['description'] ?? ''),
				'examples'    => $doc['examples'] ?? [],
				'url'         => $doc['url'] ?? $entry['url'],
			];
			unset($docsByName[$entry['name']]);
		} else {
			$merged[] = [
				'name'        => $entry['name'],
				'signature'   => $entry['signature'],
				'description' => $entry['description'],
				'examples'    => [],
				'url'         => $entry['url'],
			];
		}
	}

	// Anything left in $docsByName is documented but doesn't exist in code → stale.
	$stale = array_values(array_keys($docsByName));
	sort($stale);

	return [$merged, $stale];
}
