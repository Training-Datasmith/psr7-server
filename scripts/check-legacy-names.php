<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * @return array<string, true>
 */
function load_psr_interface_method_names(): array
{
    $names = [];
    $interfaces = [
        \Psr\Http\Message\MessageInterface::class,
        \Psr\Http\Message\RequestInterface::class,
        \Psr\Http\Message\ResponseInterface::class,
        \Psr\Http\Message\ServerRequestInterface::class,
        \Psr\Http\Message\StreamInterface::class,
        \Psr\Http\Message\UploadedFileInterface::class,
        \Psr\Http\Message\UriInterface::class,
        \Psr\Http\Message\RequestFactoryInterface::class,
        \Psr\Http\Message\ResponseFactoryInterface::class,
        \Psr\Http\Message\ServerRequestFactoryInterface::class,
        \Psr\Http\Message\StreamFactoryInterface::class,
        \Psr\Http\Message\UploadedFileFactoryInterface::class,
        \Psr\Http\Message\UriFactoryInterface::class,
    ];

    foreach ($interfaces as $interface) {
        if (!interface_exists($interface)) {
            continue;
        }
        $ref = new ReflectionClass($interface);
        foreach ($ref->getMethods() as $method) {
            $names[$method->getName()] = true;
        }
    }

    return $names;
}

/**
 * @return array<string, true> keys like "Message_Trait::getHeaders"
 */
function build_scoped_upstream_method_allowlist(string $root): array
{
    $autoload = $root . '/vendor/autoload.php';
    if (!is_readable($autoload)) {
        fwrite(STDERR, "check-legacy-names: run composer update first (missing vendor/autoload.php).\n");
        exit(1);
    }
    require_once $autoload;

    $psrMethods = load_psr_interface_method_names();
    $scoped = [];
    foreach ([$root . '/src', $root . '/tests'] as $scanDir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scanDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if ($code === false) {
                continue;
            }
            if (!preg_match('/namespace\s+([^;]+);/', $code, $nsMatch)) {
                continue;
            }
            $namespace = trim($nsMatch[1]);
            $typeName = extract_type_name($code);
            if ($typeName === null) {
                continue;
            }
            $fqcn = $namespace . '\\' . $typeName;
            if (!class_exists($fqcn) && !trait_exists($fqcn)) {
                continue;
            }
            $ref = new ReflectionClass($fqcn);
            foreach ($ref->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $fqcn) {
                    continue;
                }
                $methodName = $method->getName();
                if (!isset($psrMethods[$methodName])) {
                    if ($methodName === 'jsonSerialize' && $ref->implementsInterface(\JsonSerializable::class)) {
                        $scoped[$typeName . '::' . $methodName] = true;
                    }
                    continue;
                }
                if ($ref->isTrait()) {
                    $scoped[$typeName . '::' . $methodName] = true;
                    continue;
                }
                foreach ($ref->getInterfaces() as $interface) {
                    if ($interface->hasMethod($methodName)) {
                        $scoped[$typeName . '::' . $methodName] = true;
                        break;
                    }
                }
            }

        }
    }

    return $scoped;
}

/**
 * @return array<string, true> keys: path/relative::ClassName::methodName
 */
function load_site_method_allowlist(string $path, string $root): array
{
    $allowlist = [];
    $root = rtrim($root, '/') . '/';
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('::', $line);
        if (count($parts) !== 3) {
            fwrite(STDERR, "check-legacy-names: invalid site method allowlist entry: {$line}\n");
            exit(1);
        }
        $allowlist[implode('::', $parts)] = true;
    }

    return $allowlist;
}

function relative_repo_path(string $filePath, string $root): string
{
    $root = rtrim($root, '/') . '/';
    if (strpos($filePath, $root) === 0) {
        return substr($filePath, strlen($root));
    }

    return $filePath;
}

/**
 * @return array<string, list<string>>
 */
function load_parameter_allowlist(string $path): array
{
    $map = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('::', $line);
        if (count($parts) !== 3) {
            fwrite(STDERR, "check-legacy-names: invalid parameter allowlist entry: {$line}\n");
            exit(1);
        }
        [$class, $method, $parameter] = $parts;
        $key = $class . '::' . $method;
        if (!isset($map[$key])) {
            $map[$key] = [];
        }
        $map[$key][] = $parameter;
    }

    return $map;
}

function extract_class_name(string $code): ?string
{
    if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/m', $code, $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

function extract_type_name(string $code): ?string
{
    if (preg_match('/^\s*trait\s+([A-Za-z0-9_]+)/m', $code, $matches) === 1) {
        return $matches[1];
    }

    return extract_class_name($code);
}

function extends_test_case(string $code): bool
{
    return (bool) preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+[A-Za-z0-9_]+\s+extends\s+TestCase\b/m', $code);
}

function is_snake_case_test_method_name(string $name): bool
{
    return (bool) preg_match('/^test_[a-z0-9_]+$/', $name);
}

/**
 * @param array<string, true> $scopedMethodAllowlist
 */
function method_overrides_phpunit_testcase(?string $fqcn, string $methodName): bool
{
    if ($fqcn === null || $fqcn === '' || !class_exists($fqcn)) {
        return false;
    }
    try {
        $ref = new ReflectionClass($fqcn);
    } catch (ReflectionException $e) {
        return false;
    }
    if (!$ref->isSubclassOf(\PHPUnit\Framework\TestCase::class)) {
        return false;
    }
    if (!$ref->hasMethod($methodName)) {
        return false;
    }
    $method = $ref->getMethod($methodName);
    if ($method->getDeclaringClass()->getName() !== $fqcn) {
        return false;
    }

    return (new ReflectionClass(\PHPUnit\Framework\TestCase::class))->hasMethod($methodName);
}

function extract_fqcn_from_code(string $code): ?string
{
    if (!preg_match('/namespace\s+([^;]+);/', $code, $nsMatch)) {
        return null;
    }
    $typeName = extract_type_name($code);
    if ($typeName === null) {
        return null;
    }

    return trim($nsMatch[1]) . '\\' . $typeName;
}

function is_allowed_camelcase_method_declaration(
    string $methodName,
    string $filePath,
    string $root,
    bool $underSrc,
    bool $underTests,
    ?string $typeName,
    ?string $fqcn,
    bool $extendsTestCase,
    array $scopedMethodAllowlist,
    array $siteMethodAllowlist
): bool {
    if (!$underSrc && !$underTests) {
        return true;
    }
    if ($typeName !== null) {
        $rel = relative_repo_path($filePath, $root);
        $siteKey = $rel . '::' . $typeName . '::' . $methodName;
        if (isset($siteMethodAllowlist[$siteKey])) {
            return true;
        }
    }
    if ($underSrc && $typeName !== null) {
        return isset($scopedMethodAllowlist[$typeName . '::' . $methodName]);
    }
    if ($underTests) {
        if (strpos($methodName, 'test') === 0) {
            return is_snake_case_test_method_name($methodName);
        }
        if ($extendsTestCase && $fqcn !== null && method_overrides_phpunit_testcase($fqcn, $methodName)) {
            return true;
        }
        if ($typeName !== null && isset($scopedMethodAllowlist[$typeName . '::' . $methodName])) {
            return true;
        }

        return false;
    }

    return false;
}

function is_studly_snake_type_name(string $name): bool
{
    return (bool) preg_match('/^[A-Z][a-zA-Z0-9]*(_[A-Z][a-zA-Z0-9]*)*$/', $name);
}

function is_superglobal_identifier(string $name): bool
{
    if ($name === 'GLOBALS') {
        return true;
    }

    return (bool) preg_match('/^_[A-Z][A-Z0-9_]*$/', $name);
}

function has_repo_owned_hump(string $name): bool
{
    if ($name === '') {
        return false;
    }
    if (is_superglobal_identifier($name)) {
        return false;
    }
    if (is_studly_snake_type_name($name)) {
        return false;
    }
    if (preg_match('/_[A-Z]/', $name)) {
        return true;
    }

    return (bool) preg_match('/[a-z][A-Z]/', $name);
}

function is_repo_camelcase_identifier(string $name): bool
{
    return has_repo_owned_hump($name);
}

/**
 * @return list<string>
 */
function collect_docblock_hump_violations(string $filePath, string $code): array
{
    $violations = [];
    foreach (token_get_all($code) as $token) {
        if (!is_array($token) || $token[0] !== T_DOC_COMMENT) {
            continue;
        }
        $block = $token[1];
        if (preg_match_all('/@(?:var|param|return|method|property)\b[^@]*/s', $block, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $tag, $vars)) {
                    foreach ($vars[1] as $varName) {
                        if (has_repo_owned_hump($varName)) {
                            $violations[] = sprintf('%s: repo-owned camelCase docblock identifier $%s', $filePath, $varName);
                        }
                    }
                }
            }
        }
    }

    return $violations;
}

/**
 * @param array<string, true> $scopedMethodAllowlist
 * @param array<string, list<string>> $parameterAllowlist
 * @return list<string>
 */
function collect_snake_case_violations(
    string $filePath,
    string $code,
    string $root,
    array $scopedMethodAllowlist,
    array $siteMethodAllowlist,
    array $parameterAllowlist
): array {
    $violations = [];
    $tokens = token_get_all($code);
    $expectFunctionName = false;
    $underSrc = strpos($filePath, '/src/') !== false;
    $underTests = strpos($filePath, '/tests/') !== false;
    $typeName = extract_type_name($code);
    $fqcn = extract_fqcn_from_code($code);
    $extendsTestCase = $underTests && extends_test_case($code);
    $currentFunction = null;
    /** @var array<string, true> */
    $scopedParameters = [];

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            if ($token === '{') {
                // keep scopedParameters for function body
            }
            if ($token === '(') {
                $expectFunctionName = false;
            }
            continue;
        }

        [$id, $text] = $token;

        if ($id === T_FUNCTION) {
            $expectFunctionName = true;
            $currentFunction = null;
            $scopedParameters = [];
            continue;
        }

        if ($expectFunctionName && $id === T_STRING) {
            $currentFunction = $text;
            if (is_repo_camelcase_identifier($text) && !is_allowed_camelcase_method_declaration(
                $text,
                $filePath,
                $root,
                $underSrc,
                $underTests,
                $typeName,
                $fqcn,
                $extendsTestCase,
                $scopedMethodAllowlist,
                $siteMethodAllowlist
            )) {
                $violations[] = sprintf('%s: repo-owned camelCase identifier %s', $filePath, $text);
            }
            if ($typeName !== null) {
                $scopeKey = $typeName . '::' . $currentFunction;
                $scopedParameters = [];
                foreach ($parameterAllowlist[$scopeKey] ?? [] as $parameter) {
                    $scopedParameters[$parameter] = true;
                }
            }
            $expectFunctionName = false;
            continue;
        }

        if ($expectFunctionName && $id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
            $expectFunctionName = false;
        }

        if ($id === T_VARIABLE) {
            $name = substr($text, 1);
            if (!is_repo_camelcase_identifier($name)) {
                continue;
            }
            if (isset($scopedParameters[$name])) {
                continue;
            }
            $violations[] = sprintf('%s: repo-owned camelCase identifier $%s', $filePath, $name);
        }
    }

    return array_merge($violations, collect_docblock_hump_violations($filePath, $code));
}

/**
 * @param array<string, true> $scopedMethodAllowlist
 * @param array<string, true> $siteMethodAllowlist
 * @param array<string, list<string>> $parameterAllowlist
 * @return list<string>
 */
function collect_snake_case_violations_tree(
    string $dir,
    string $root,
    array $scopedMethodAllowlist,
    array $siteMethodAllowlist,
    array $parameterAllowlist
): array
{
    $violations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }
        $violations = array_merge(
            $violations,
            collect_snake_case_violations(
                $file->getPathname(),
                $contents,
                $root,
                $scopedMethodAllowlist,
                $siteMethodAllowlist,
                $parameterAllowlist
            )
        );
    }

    return $violations;
}

$scopedMethodAllowlist = build_scoped_upstream_method_allowlist($root);
$siteMethodAllowlist = load_site_method_allowlist($root . '/scripts/upstream-method-allowlist.txt', $root);
$parameterAllowlist = load_parameter_allowlist($root . '/scripts/upstream-parameter-allowlist.txt');

$scanPaths = [
    $root . '/src',
    $root . '/tests',
    $root . '/composer.json',
    $root . '/phpunit.xml.dist',
    $root . '/README.md',
    $root . '/ARCHITECTURE.md',
];

$patterns = [
    '/\\bServerRequestCreator\\b/',
    '/\\bServerRequestCreatorInterface\\b/',
    '/\\bfromGlobals\\b/',
    '/\\bfromArrays\\b/',
    '/\\bgetHeadersFromServer\\b/',
    '/\\bcreateUriFromArray\\b/',
    '/\\bcreateUploadedFileFromSpec\\b/',
    '/\\bnormalizeNestedFileSpec\\b/',
    '/\\bclass\\s+ServerRequestCreatorTest\\b/',
    '/\\bNyholm\\\\Psr7\\\\UploadedFile\\b/',
    '/\\bNyholm\\\\Psr7\\\\Factory\\\\Psr17Factory\\b/',
    '/\\bfunction\\s+test[A-Z]/',
];

function scan_file(string $path, array $patterns): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        return ["Could not read {$path}"];
    }
    $hits = [];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $contents)) {
            $hits[] = "{$path}: matched forbidden pattern {$pattern}";
        }
    }

    return $hits;
}

function scan_tree(string $dir, array $patterns): array
{
    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $hits = array_merge($hits, scan_file($file->getPathname(), $patterns));
    }

    return $hits;
}

/**
 * @return list<string>
 */
function class_file_mismatches_for(string $phpPath, string $contents): array
{
    $basename = basename($phpPath, '.php');
    $mismatches = [];

    if (preg_match_all(
        '/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)\s+extends\s+TestCase/m',
        $contents,
        $testMatches,
        PREG_SET_ORDER
    )) {
        foreach ($testMatches as $match) {
            $declared = $match[1];
            if ($declared !== $basename) {
                $mismatches[] = sprintf(
                    '%s: TestCase class %s must match file basename %s',
                    $phpPath,
                    $declared,
                    $basename
                );
            }
        }
    }

    $underSrc = strpos($phpPath, '/src/') !== false || strpos($phpPath, '\\src\\') !== false;
    if ($underSrc && preg_match_all(
        '/^\s*(?:final\s+|abstract\s+)?(?:class|trait|interface)\s+([A-Za-z0-9_]+)/m',
        $contents,
        $srcMatches,
        PREG_SET_ORDER
    )) {
        foreach ($srcMatches as $match) {
            $declared = $match[1];
            if ($declared !== $basename) {
                $mismatches[] = sprintf(
                    '%s: declared %s must match file basename %s',
                    $phpPath,
                    $declared,
                    $basename
                );
            }
        }
    }

    return $mismatches;
}

/**
 * @return list<string>
 */
function class_file_mismatches(string $phpPath): array
{
    $contents = file_get_contents($phpPath);
    if ($contents === false) {
        return ["Could not read {$phpPath}"];
    }

    return class_file_mismatches_for($phpPath, $contents);
}

/**
 * @return list<string>
 */
function collect_class_file_mismatches(array $roots): array
{
    $violations = [];
    foreach ($roots as $path) {
        if (!is_dir($path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $violations = array_merge($violations, class_file_mismatches($file->getPathname()));
        }
    }

    return $violations;
}

$testFile = $root . '/tests/Server_Request_Creator_Test.php';
$testContents = file_get_contents($testFile);
if ($testContents === false) {
    fwrite(STDERR, "check-legacy-names: could not read Server_Request_Creator_Test.php for demo.\n");
    exit(1);
}
$demoContents = str_replace('class Server_Request_Creator_Test ', 'class ServerRequestCreatorTest ', $testContents);
$demoMismatches = class_file_mismatches_for($testFile, $demoContents);
if ($demoMismatches === []) {
    fwrite(STDERR, "check-legacy-names: class/file mismatch demo did not fail.\n");
    exit(1);
}
fwrite(STDOUT, "check-legacy-names: class/file mismatch demo failed for injected wrong class name.\n");

$demoCamelContents = preg_replace('/\$tmp_dir = /', '$fooBar = null; $tmp_dir = ', $testContents, 1);
if (!is_string($demoCamelContents) || $demoCamelContents === $testContents) {
    fwrite(STDERR, "check-legacy-names: camelCase identifier demo could not inject wrong variable name.\n");
    exit(1);
}
$demoSnakeViolations = collect_snake_case_violations(
    $testFile,
    $demoCamelContents,
    $root,
    $scopedMethodAllowlist,
    $siteMethodAllowlist,
    $parameterAllowlist
);
if ($demoSnakeViolations === []) {
    fwrite(STDERR, "check-legacy-names: camelCase identifier demo did not fail.\n");
    exit(1);
}
fwrite(STDOUT, "check-legacy-names: camelCase identifier demo failed for injected variable name.\n");

$demoHumpContents = preg_replace('/\$tmp_dir = /', '$foo_Bar = null; $tmp_dir = ', $testContents, 1);
if (!is_string($demoHumpContents) || $demoHumpContents === $testContents) {
    fwrite(STDERR, "check-legacy-names: underscore-hump demo could not inject \$foo_Bar.\n");
    exit(1);
}
$demoHumpViolations = collect_snake_case_violations(
    $testFile,
    $demoHumpContents,
    $root,
    $scopedMethodAllowlist,
    $siteMethodAllowlist,
    $parameterAllowlist
);
if ($demoHumpViolations === []) {
    fwrite(STDERR, "check-legacy-names: underscore-hump demo did not fail.\n");
    exit(1);
}
fwrite(STDOUT, "check-legacy-names: underscore-hump identifier demo failed for injected \$foo_Bar.\n");

$demoDocblockContents = preg_replace(
    '/(^\s*class Server_Request_Creator_Test\b)/m',
    '/** @var int $foo_Bar */' . "\n" . '$1',
    $testContents,
    1
);
if (!is_string($demoDocblockContents) || $demoDocblockContents === $testContents) {
    fwrite(STDERR, "check-legacy-names: docblock hump demo could not inject @var \$foo_Bar.\n");
    exit(1);
}
if (collect_docblock_hump_violations($testFile, $demoDocblockContents) === []) {
    fwrite(STDERR, "check-legacy-names: docblock hump demo did not fail.\n");
    exit(1);
}
fwrite(STDOUT, "check-legacy-names: docblock hump demo failed for injected @var \$foo_Bar.\n");

$creatorFile = $root . '/src/Server_Request_Creator.php';
$creatorContents = file_get_contents($creatorFile);
if ($creatorContents === false) {
    fwrite(STDERR, "check-legacy-names: could not read Server_Request_Creator.php for method demo.\n");
    exit(1);
}
$demoMethodContents = str_replace('private function normalize_files', 'private function normalizeFiles', $creatorContents, $count);
if ($count !== 1) {
    fwrite(STDERR, "check-legacy-names: method demo could not inject normalizeFiles.\n");
    exit(1);
}
$demoMethodViolations = collect_snake_case_violations(
    $creatorFile,
    $demoMethodContents,
    $root,
    $scopedMethodAllowlist,
    $siteMethodAllowlist,
    $parameterAllowlist
);
if ($demoMethodViolations === []) {
    fwrite(STDERR, "check-legacy-names: camelCase method demo did not fail.\n");
    exit(1);
}
fwrite(STDOUT, "check-legacy-names: scoped method demo failed for injected normalizeFiles.\n");

$demoTestFooContents = preg_replace(
    '/public function test_normalize_files\(/',
    'public function testFoo(',
    $testContents,
    1
);
if (!is_string($demoTestFooContents) || $demoTestFooContents === $testContents) {
    fwrite(STDERR, "check-legacy-names: testFoo demo could not inject wrong test method name.\n");
    exit(1);
}
$demoTestFooViolations = collect_snake_case_violations(
    $testFile,
    $demoTestFooContents,
    $root,
    $scopedMethodAllowlist,
    $siteMethodAllowlist,
    $parameterAllowlist
);
if ($demoTestFooViolations === []) {
    fwrite(STDERR, "check-legacy-names: testFoo demo did not fail.\n");
    exit(1);
}
fwrite(STDOUT, "check-legacy-names: test method demo failed for injected testFoo.\n");

$violations = [];
foreach ($scanPaths as $path) {
    if (is_dir($path)) {
        $violations = array_merge($violations, scan_tree($path, $patterns));
        continue;
    }
    if (is_file($path)) {
        $violations = array_merge($violations, scan_file($path, $patterns));
    }
}

$violations = array_merge($violations, collect_class_file_mismatches([$root . '/src', $root . '/tests']));
$violations = array_merge(
    $violations,
    collect_snake_case_violations_tree($root . '/src', $root, $scopedMethodAllowlist, $siteMethodAllowlist, $parameterAllowlist),
    collect_snake_case_violations_tree($root . '/tests', $root, $scopedMethodAllowlist, $siteMethodAllowlist, $parameterAllowlist)
);

fwrite(STDOUT, "check-legacy-names: matcher rejects Nyholm\\Psr7\\Factory\\Psr17Factory.\n");
fwrite(STDOUT, "check-legacy-names: matcher accepts Nyholm\\Psr7\\Factory\\Psr17_Factory.\n");

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "check-legacy-names: no pre-rename repo-owned names remain.\n");
fwrite(STDOUT, "check-legacy-names: all src and TestCase class names match their file basenames.\n");
fwrite(STDOUT, "check-legacy-names: no repo-owned camelCase identifiers outside scoped upstream allowlists.\n");
exit(0);
