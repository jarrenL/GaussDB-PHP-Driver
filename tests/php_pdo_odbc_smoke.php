<?php

declare(strict_types=1);

$dsn = getenv('GAUSS_ODBC_DSN') ?: 'GaussDB';
$connectionString = getenv('GAUSS_ODBC_CONNECTION_STRING');
$pdoDsn = ($connectionString !== false && $connectionString !== '')
    ? "odbc:{$connectionString}"
    : "odbc:{$dsn}";
$user = getenv('GAUSS_USER') ?: 'gauss_php_test';
$password = getenv('GAUSS_PASSWORD');
if ($password === false || $password === '') {
    throw new RuntimeException('GAUSS_PASSWORD is required');
}

$pdo = new PDO(
    $pdoDsn,
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$result = [
    'php' => PHP_VERSION,
    'pdo_driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
];

$pdo->exec('DROP TABLE IF EXISTS php_odbc_smoke');
$pdo->exec(<<<'SQL'
CREATE TABLE php_odbc_smoke (
    id BIGINT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    amount DECIMAL(20, 4),
    enabled BOOLEAN,
    created_at TIMESTAMP
)
SQL);

$insert = $pdo->prepare(
    'INSERT INTO php_odbc_smoke (id, name, amount, enabled, created_at) VALUES (?, ?, ?, ?, ?)'
);
$insert->execute([1, 'Windows PDO ODBC 中文 🚀', '1234567890123456.7890', 1, '2026-08-05 12:34:56']);

$select = $pdo->prepare('SELECT * FROM php_odbc_smoke WHERE id = ?');
$select->execute([1]);
$result['row'] = $select->fetch();

$pdo->beginTransaction();
$pdo->exec("INSERT INTO php_odbc_smoke VALUES (2, 'rollback', 2.0000, 0, NULL)");
$pdo->rollBack();
$result['rows_after_rollback'] = (int) $pdo->query('SELECT COUNT(*) FROM php_odbc_smoke')->fetchColumn();

$pdo->exec('DROP TABLE php_odbc_smoke');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
