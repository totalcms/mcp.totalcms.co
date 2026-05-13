<?php

declare(strict_types=1);

/**
 * Source-level reflection of T3's ExtensionContext class.
 *
 * Parses src/Domain/Extension/ExtensionContext.php via PhpParser to derive:
 * - context_methods: the public registration/lifecycle API
 * - permissions: the capabilityLabels() static method's return array
 *
 * Doesn't load T3 at runtime — it only reads the source file, so we don't
 * need T3's full Composer install (which can't even be resolved from outside
 * the root package because of VCS dependencies).
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use phpDocumentor\Reflection\DocBlockFactory;

/**
 * Methods we want to skip when emitting context_methods — internal collectors
 * used by ExtensionManager, not part of the extension-facing API.
 */
const REFLECTION_SKIP_METHODS = [
	'__construct',
	'getCapabilities',
	'capabilityLabels',
];

/**
 * Phase + permission mapping for each public method. Auto-derived data
 * (signature, description) doesn't tell us which lifecycle phase a method
 * belongs to or which permission it grants. This small table fills that gap.
 *
 * When a new public method is added to ExtensionContext, the build will
 * surface it with phase='register' and no permission unless an entry is
 * added here — the worst case is the AI agent gets a method listed without
 * its permission tag, not a missing method.
 */
const METHOD_METADATA = [
	// Identity / lifecycle (both phases)
	'extensionId'             => ['phase' => 'both',     'permission' => null],
	'extensionPath'           => ['phase' => 'both',     'permission' => null],
	'manifest'                => ['phase' => 'both',     'permission' => null],
	'settings'                => ['phase' => 'both',     'permission' => 'settings:read'],
	'setting'                 => ['phase' => 'both',     'permission' => null],
	'logger'                  => ['phase' => 'both',     'permission' => null],

	// Service resolution (boot only)
	'get'                     => ['phase' => 'boot',     'permission' => null],
	'has'                     => ['phase' => 'boot',     'permission' => null],
	'installSchema'           => ['phase' => 'boot',     'permission' => 'schemas'],

	// Registration (register only)
	'addTwigFunction'         => ['phase' => 'register', 'permission' => 'twig:functions'],
	'addTwigFilter'           => ['phase' => 'register', 'permission' => 'twig:filters'],
	'addTwigGlobal'           => ['phase' => 'register', 'permission' => 'twig:globals'],
	'addCommand'              => ['phase' => 'register', 'permission' => 'cli:commands'],
	'addRoutes'               => ['phase' => 'register', 'permission' => 'routes:api'],
	'addPublicRoutes'         => ['phase' => 'register', 'permission' => 'routes:public'],
	'addAdminRoutes'          => ['phase' => 'register', 'permission' => 'routes:admin'],
	'addAdminNavItem'         => ['phase' => 'register', 'permission' => 'admin:nav'],
	'addDashboardWidget'      => ['phase' => 'register', 'permission' => 'admin:widgets'],
	'addAdminAsset'           => ['phase' => 'register', 'permission' => 'admin:assets'],
	'addFrontendAsset'        => ['phase' => 'register', 'permission' => 'frontend:assets'],
	'addFieldType'            => ['phase' => 'register', 'permission' => 'fields'],
	'addEventListener'        => ['phase' => 'register', 'permission' => 'events:listen'],
	'addContainerDefinition'  => ['phase' => 'register', 'permission' => 'container'],
	'addPageMiddleware'       => ['phase' => 'register', 'permission' => 'page-middleware'],
];

/**
 * Reflect the public methods on T3's ExtensionContext class.
 *
 * @return list<array{name: string, signature: string, description: string, phase: string, permission: string|null}>
 */
function reflectContextMethods(string $totalcmsPath): array
{
	$file = $totalcmsPath . '/src/Domain/Extension/ExtensionContext.php';
	if (!is_file($file)) {
		throw new RuntimeException("ExtensionContext source not found at {$file}");
	}

	$classNode = parseClassFromFile($file, 'ExtensionContext');
	$docBlockFactory = DocBlockFactory::createInstance();
	$methods = [];

	foreach ($classNode->getMethods() as $method) {
		if (!$method->isPublic()) {
			continue;
		}
		$name = $method->name->toString();
		if (in_array($name, REFLECTION_SKIP_METHODS, true)) {
			continue;
		}
		if (str_starts_with($name, 'getRegistered')) {
			// Internal collectors used by ExtensionManager — not extension-facing.
			continue;
		}

		$signature   = formatMethodSignature($method);
		$description = extractMethodDescription($method, $docBlockFactory);
		$metadata    = METHOD_METADATA[$name] ?? ['phase' => 'register', 'permission' => null];

		$methods[] = [
			'name'        => $name,
			'signature'   => $signature,
			'description' => $description,
			'phase'       => $metadata['phase'],
			'permission'  => $metadata['permission'],
		];
	}

	return $methods;
}

/**
 * Reflect ExtensionContext::capabilityLabels() — extract the array literal
 * returned by the static method so we have an authoritative permissions list.
 *
 * @return list<array{id: string, description: string}>
 */
function reflectCapabilityLabels(string $totalcmsPath): array
{
	$file = $totalcmsPath . '/src/Domain/Extension/ExtensionContext.php';
	if (!is_file($file)) {
		throw new RuntimeException("ExtensionContext source not found at {$file}");
	}

	$classNode = parseClassFromFile($file, 'ExtensionContext');
	$labelsMethod = null;
	foreach ($classNode->getMethods() as $method) {
		if ($method->name->toString() === 'capabilityLabels') {
			$labelsMethod = $method;
			break;
		}
	}
	if ($labelsMethod === null) {
		throw new RuntimeException('capabilityLabels() not found on ExtensionContext');
	}

	$arrayNode = null;
	$finder = new NodeFinder();
	$return = $finder->findFirstInstanceOf((array) $labelsMethod->stmts, Node\Stmt\Return_::class);
	if ($return && $return->expr instanceof Node\Expr\Array_) {
		$arrayNode = $return->expr;
	}
	if ($arrayNode === null) {
		throw new RuntimeException('capabilityLabels() does not return an array literal we can parse');
	}

	$permissions = [];
	foreach ($arrayNode->items as $item) {
		if (!$item instanceof Node\Expr\ArrayItem) {
			continue;
		}
		if (!$item->key instanceof Node\Scalar\String_) {
			continue;
		}
		$permissions[] = [
			'id'          => $item->key->value,
			'description' => $item->value instanceof Node\Scalar\String_ ? $item->value->value : '',
		];
	}

	return $permissions;
}

/**
 * Parse a PHP file and find a specific class declaration in it.
 */
function parseClassFromFile(string $file, string $shortName): Node\Stmt\Class_
{
	$source = file_get_contents($file);
	$parser = (new ParserFactory())->createForHostVersion();
	$ast = $parser->parse($source) ?? [];

	$finder = new NodeFinder();
	$class = $finder->findFirst($ast, function (Node $node) use ($shortName) {
		return $node instanceof Node\Stmt\Class_
			&& $node->name !== null
			&& $node->name->toString() === $shortName;
	});

	if (!$class instanceof Node\Stmt\Class_) {
		throw new RuntimeException("Class {$shortName} not found in {$file}");
	}

	return $class;
}

/**
 * Build a human-readable signature like `addFoo(string $bar, int $baz = 0): void`.
 */
function formatMethodSignature(Node\Stmt\ClassMethod $method): string
{
	$params = [];
	foreach ($method->params as $param) {
		$type = $param->type ? renderType($param->type) . ' ' : '';
		$prefix = $param->byRef ? '&' : ($param->variadic ? '...' : '');
		$name = '$' . $param->var->name;
		$default = '';
		if ($param->default !== null) {
			$default = ' = ' . renderExpr($param->default);
		}
		$params[] = $type . $prefix . $name . $default;
	}

	$return = $method->returnType ? ': ' . renderType($method->returnType) : '';
	return $method->name->toString() . '(' . implode(', ', $params) . ')' . $return;
}

/**
 * Render a type AST node back to a readable string (best-effort).
 */
function renderType(Node $type): string
{
	if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
		return $type->toString();
	}
	if ($type instanceof Node\NullableType) {
		return '?' . renderType($type->type);
	}
	if ($type instanceof Node\UnionType) {
		return implode('|', array_map('renderType', $type->types));
	}
	if ($type instanceof Node\IntersectionType) {
		return implode('&', array_map('renderType', $type->types));
	}
	return 'mixed';
}

/**
 * Render an expression AST node (used for default param values).
 */
function renderExpr(Node\Expr $expr): string
{
	if ($expr instanceof Node\Scalar\String_) {
		return "'" . $expr->value . "'";
	}
	if ($expr instanceof Node\Scalar\Int_) {
		return (string) $expr->value;
	}
	if ($expr instanceof Node\Scalar\Float_) {
		return (string) $expr->value;
	}
	if ($expr instanceof Node\Expr\ConstFetch) {
		return $expr->name->toString();
	}
	if ($expr instanceof Node\Expr\Array_) {
		return '[]';
	}
	return '...';
}

/**
 * Pull a one-line description out of the method's docblock — uses summary
 * when present, falls back to the first sentence of the long description.
 */
function extractMethodDescription(Node\Stmt\ClassMethod $method, DocBlockFactory $factory): string
{
	$docComment = $method->getDocComment();
	if ($docComment === null) {
		return '';
	}

	try {
		$docBlock = $factory->create($docComment->getText());
	} catch (\Throwable) {
		return '';
	}

	$summary = trim($docBlock->getSummary());
	$description = trim((string) $docBlock->getDescription());

	// If summary is short, append the first paragraph of the long description
	// for context. Caps at the first ~250 chars so AI tooltip blurbs stay scannable.
	$blurb = $summary;
	if ($blurb !== '' && $description !== '' && mb_strlen($blurb) < 80) {
		$firstParagraph = explode("\n\n", $description)[0];
		$blurb .= ' ' . $firstParagraph;
	} elseif ($blurb === '' && $description !== '') {
		$blurb = explode("\n\n", $description)[0];
	}

	return cleanDocblockText($blurb);
}

/**
 * Strip inline phpDoc tags, normalize whitespace, and trim.
 */
function cleanDocblockText(string $text): string
{
	// {@see Foo\Bar::method()} -> Foo\Bar::method()
	$text = (string) preg_replace('/\{@(?:see|link)\s+([^}]+)\}/', '$1', $text);
	// Any other {@tag ...} -> drop entirely
	$text = (string) preg_replace('/\{@\w+\s*[^}]*\}/', '', $text);
	// Collapse runs of whitespace (including newlines) to a single space
	$text = (string) preg_replace('/\s+/', ' ', $text);
	return trim($text);
}
