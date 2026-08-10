<?php

declare(strict_types=1);

$database = getenv('GAUSS_DATABASE');
$expectedMode = getenv('GAUSS_EXPECTED_MODE');
$password = getenv('GAUSS_PASSWORD');
if (!$database || !$expectedMode || !$password) {
    fwrite(STDERR, "GAUSS_DATABASE, GAUSS_EXPECTED_MODE and GAUSS_PASSWORD are required\n");
    exit(2);
}

$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv('GAUSS_HOST') ?: 'gaussdb-507',
        getenv('GAUSS_PORT') ?: '5432',
        $database
    ),
    getenv('GAUSS_USER') ?: 'gauss_php_test',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$results = [];
$requiredFailures = 0;
$run = static function (string $name, bool $required, callable $test) use (&$results, &$requiredFailures): void {
    try {
        $detail = $test();
        $results[] = ['name' => $name, 'required' => $required, 'status' => 'pass', 'detail' => $detail];
    } catch (Throwable $error) {
        if ($required) {
            $requiredFailures++;
        }
        $results[] = [
            'name' => $name,
            'required' => $required,
            'status' => $required ? 'fail' : 'compatibility-fail',
            'detail' => ['sqlstate' => (string) $error->getCode(), 'message' => $error->getMessage()],
        ];
    }
};
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$actualMode = (string) $pdo->query(
    "SELECT datcompatibility FROM pg_database WHERE datname = current_database()"
)->fetchColumn();

$run('mode is correctly detected', true, static function () use ($actualMode, $expectedMode, $expect): array {
    $expect($actualMode === $expectedMode, "Expected mode {$expectedMode}, got {$actualMode}");
    return ['datcompatibility' => $actualMode];
});

$table = 'php_mode_contract';
try {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");

    $run('common DDL and CRUD', true, static function () use ($pdo, $table, $expect): array {
        $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, name VARCHAR(128), amount DECIMAL(12,2))");
        $insert = $pdo->prepare("INSERT INTO {$table} (id, name, amount) VALUES (?, ?, ?)");
        $insert->execute([1, 'common', '1234567890.12']);
        $row = $pdo->query("SELECT * FROM {$table} WHERE id = 1")->fetch();
        $expect((string) $row['id'] === '1', 'Inserted row was not returned');
        $expect((string) $row['amount'] === '1234567890.12', 'DECIMAL value changed');
        return $row;
    });

    $run('prepared statement resists injection value', true, static function () use ($pdo, $table, $expect): void {
        $value = "x'); DROP TABLE {$table}; --";
        $statement = $pdo->prepare("INSERT INTO {$table} (id, name) VALUES (?, ?)");
        $statement->execute([2, $value]);
        $actual = $pdo->query("SELECT name FROM {$table} WHERE id = 2")->fetchColumn();
        $expect($actual === $value, 'Prepared value was changed or interpreted as SQL');
    });

    $run('transaction rollback', true, static function () use ($pdo, $table, $expect): void {
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO {$table} (id, name) VALUES (3, 'rollback')");
        $pdo->rollBack();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE id = 3")->fetchColumn();
        $expect($count === 0, 'Rollback did not remove uncommitted row');
    });

    $run('duplicate key SQLSTATE and recovery', true, static function () use ($pdo, $table, $expect): array {
        try {
            $pdo->exec("INSERT INTO {$table} (id, name) VALUES (1, 'duplicate')");
            throw new RuntimeException('Duplicate key unexpectedly succeeded');
        } catch (PDOException $error) {
            $state = (string) $error->getCode();
            $expect(strlen($state) === 5, 'No five-character SQLSTATE returned');
        }
        $expect((int) $pdo->query('SELECT 1')->fetchColumn() === 1, 'Connection did not recover after error');
        return ['sqlstate' => $state];
    });

    if ($actualMode === 'ORA') {
        $run('ORA NVL function', true, static function () use ($pdo, $expect): void {
            $expect($pdo->query("SELECT NVL(NULL, 'fallback')")->fetchColumn() === 'fallback', 'NVL result is incorrect');
        });
        $run('ORA empty string is NULL', false, static function () use ($pdo, $expect): void {
            $expect((int) $pdo->query("SELECT CASE WHEN '' IS NULL THEN 1 ELSE 0 END")->fetchColumn() === 1, 'Empty string is not treated as NULL');
        });
    } elseif ($actualMode === 'MYSQL') {
        $run('MYSQL IFNULL function', true, static function () use ($pdo, $expect): void {
            $expect($pdo->query("SELECT IFNULL(NULL, 'fallback')")->fetchColumn() === 'fallback', 'IFNULL result is incorrect');
        });
        $run('MYSQL LIMIT syntax', true, static function () use ($pdo, $expect): void {
            $expect((int) $pdo->query('SELECT 1 AS value LIMIT 1')->fetchColumn() === 1, 'LIMIT result is incorrect');
        });
    } elseif ($actualMode === 'PG') {
        $run('PG double-colon cast', true, static function () use ($pdo, $expect): void {
            $expect((int) $pdo->query("SELECT '42'::INTEGER")->fetchColumn() === 42, 'PostgreSQL cast result is incorrect');
        });
        $run('PG generate_series', false, static function () use ($pdo, $expect): void {
            $expect($pdo->query('SELECT generate_series(1,3)')->fetchAll(PDO::FETCH_COLUMN) === [1, 2, 3], 'generate_series result is incorrect');
        });
    } elseif ($actualMode === 'M') {
        $run('M IFNULL function', true, static function () use ($pdo, $expect): void {
            $expect($pdo->query("SELECT IFNULL(NULL, 'fallback')")->fetchColumn() === 'fallback', 'IFNULL result is incorrect');
        });
        $run('M DATABASE function is callable', false, static function () use ($pdo, $expect): array {
            $value = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $expect($value !== false && $value !== null, 'DATABASE() returned no value');
            return ['value' => $value];
        });
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}

$summary = ['pass' => 0, 'fail' => 0, 'compatibility-fail' => 0];
foreach ($results as $result) {
    $summary[$result['status']]++;
}
echo json_encode([
    'database' => $database,
    'mode' => $actualMode,
    'php' => PHP_VERSION,
    'pdo_driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'summary' => $summary,
    'tests' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit($requiredFailures === 0 ? 0 : 1);
