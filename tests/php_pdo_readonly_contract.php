<?php

declare(strict_types=1);

$driver = getenv('GAUSS_TEST_DRIVER') ?: 'pgsql';
$user = getenv('GAUSS_READONLY_USER');
$password = getenv('GAUSS_READONLY_PASSWORD');
if (!$user || !$password) {
    fwrite(STDERR, "GAUSS_READONLY_USER and GAUSS_READONLY_PASSWORD are required\n");
    exit(2);
}

if ($driver === 'odbc') {
    $connectionString = getenv('GAUSS_ODBC_CONNECTION_STRING');
    $dsn = $connectionString ? "odbc:{$connectionString}" : 'odbc:' . (getenv('GAUSS_ODBC_DSN') ?: 'GaussDB507');
} else {
    $host = getenv('GAUSS_HOST') ?: 'gaussdb-507';
    $port = getenv('GAUSS_PORT') ?: '5432';
    $database = getenv('GAUSS_DATABASE') ?: 'gdbdrv_m_test';
    $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
}

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$read = (int) $pdo->query('SELECT 1')->fetchColumn() === 1;
$table = 'php_readonly_must_not_create';
try {
    $pdo->exec("CREATE TABLE {$table} (id BIGINT)");
    $pdo->exec("DROP TABLE {$table}");
    $writeRejected = false;
    $sqlstate = null;
} catch (PDOException $error) {
    $writeRejected = true;
    $sqlstate = (string) $error->getCode();
}

$result = ['status' => $read && $writeRejected ? 'pass' : 'fail', 'select_allowed' => $read, 'ddl_rejected' => $writeRejected, 'sqlstate' => $sqlstate];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['status'] === 'pass' ? 0 : 1);
