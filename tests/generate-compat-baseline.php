<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tests/generate-compat-baseline.php RESULT_DIRECTORY OUTPUT_JSON\n");
    exit(2);
}

$inputRoot = realpath($argv[1]);
if ($inputRoot === false || !is_dir($inputRoot)) {
    fwrite(STDERR, "Result directory not found: {$argv[1]}\n");
    exit(2);
}

$outputPath = $argv[2];
$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDirectory}\n");
    exit(2);
}

$targets = array();
$sourceFiles = array();
$targetKeys = array();
$scenarioNames = null;
$testsPerTarget = null;
$totalPass = 0;
$totalFail = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($inputRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read result: {$path}");
    }
    $result = json_decode($contents, true);
    if (!is_array($result)) {
        throw new RuntimeException("Invalid JSON result: {$path}: " . json_last_error_msg());
    }

    // Ignore historical PDO_PGSQL and special-contract results in a mixed result tree.
    // Files claiming the current contract must still identify the ODBC delivery path.
    if (($result['contract'] ?? null) !== 'gaussdb-php-compat-v1') {
        continue;
    }
    if (($result['driver'] ?? null) !== 'odbc') {
        throw new RuntimeException("Compatibility result is not an ODBC result: {$path}");
    }
    if (!isset($result['php'], $result['architecture'], $result['mode'], $result['summary'], $result['tests'])) {
        throw new RuntimeException("Compatibility result is missing required fields: {$path}");
    }
    if (!isset($result['os']) || !is_string($result['os']) || $result['os'] === '') {
        throw new RuntimeException("Compatibility result is missing os: {$path}");
    }
    if (!is_array($result['summary']) || !is_array($result['tests'])) {
        throw new RuntimeException("Compatibility result has invalid summary/tests: {$path}");
    }

    $pass = isset($result['summary']['pass']) ? (int) $result['summary']['pass'] : -1;
    $fail = isset($result['summary']['fail']) ? (int) $result['summary']['fail'] : -1;
    if ($pass < 0 || $fail < 0 || $pass + $fail !== count($result['tests'])) {
        throw new RuntimeException("Compatibility result summary does not match tests: {$path}");
    }

    $currentScenarios = array();
    $countedPass = 0;
    $countedFail = 0;
    foreach ($result['tests'] as $test) {
        if (!is_array($test) || !isset($test['name'], $test['status']) || !is_string($test['name'])) {
            throw new RuntimeException("Compatibility result has invalid test entry: {$path}");
        }
        if ($test['status'] === 'pass') {
            ++$countedPass;
        } elseif ($test['status'] === 'fail') {
            ++$countedFail;
        } else {
            throw new RuntimeException("Compatibility result has unknown test status: {$path}");
        }
        $currentScenarios[] = $test['name'];
    }
    if ($countedPass !== $pass || $countedFail !== $fail) {
        throw new RuntimeException("Compatibility result status counts do not match summary: {$path}");
    }

    if ($scenarioNames === null) {
        $scenarioNames = $currentScenarios;
        $testsPerTarget = count($currentScenarios);
    } elseif ($scenarioNames !== $currentScenarios) {
        throw new RuntimeException("Compatibility targets do not contain the same ordered scenarios: {$path}");
    }

    $php = (string) $result['php'];
    $os = (string) $result['os'];
    $architecture = (string) $result['architecture'];
    $mode = \GaussDb\Compat\CompatibilityMode::fromName((string) $result['mode']);
    $targetKey = implode('|', array($php, $os, $architecture, $mode));
    if (isset($targetKeys[$targetKey])) {
        throw new RuntimeException("Duplicate compatibility target {$targetKey}: {$path}");
    }
    $targetKeys[$targetKey] = true;

    $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($inputRoot) + 1));
    $sourceFiles[] = $relativePath;
    $targets[] = array(
        'php' => $php,
        'os' => $os,
        'architecture' => $architecture,
        'mode' => $mode,
        'pass' => $pass,
        'fail' => $fail,
        'source' => $relativePath
    );
    $totalPass += $pass;
    $totalFail += $fail;
}

if (!$targets) {
    fwrite(STDERR, "No compatibility contract results found in {$inputRoot}\n");
    exit(2);
}

usort($targets, static function (array $left, array $right): int {
    $versionOrder = version_compare($left['php'], $right['php']);
    if ($versionOrder !== 0) {
        return $versionOrder;
    }
    return strcmp(
        implode('|', array($left['os'], $left['architecture'], $left['mode'])),
        implode('|', array($right['os'], $right['architecture'], $right['mode']))
    );
});
sort($sourceFiles, SORT_STRING);

$phpVersions = array_values(array_unique(array_map(static function (array $target): string {
    return $target['php'];
}, $targets)));
usort($phpVersions, 'version_compare');

$contractPath = dirname(__DIR__) . '/tests/php_compat_integration.php';
$baseline = array(
    'date' => gmdate('Y-m-d'),
    'server' => getenv('GAUSS_BASELINE_SERVER') ?: 'GaussDB test instance',
    'driver' => 'version-matched GaussDB Unicode ODBC',
    'php_versions' => $phpVersions,
    'contract' => 'tests/php_compat_integration.php',
    'generator' => 'tests/generate-compat-baseline.php',
    'contract_sha256' => hash_file('sha256', $contractPath),
    'tests_per_target' => $testsPerTarget,
    'scenarios' => $scenarioNames,
    'source_files' => $sourceFiles,
    'targets' => $targets,
    'summary' => array('pass' => $totalPass, 'fail' => $totalFail)
);

$encoded = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encoded === false || file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
    throw new RuntimeException("Unable to write baseline: {$outputPath}");
}

echo "Generated {$outputPath}: " . count($targets) . " targets, {$totalPass} pass, {$totalFail} fail\n";
