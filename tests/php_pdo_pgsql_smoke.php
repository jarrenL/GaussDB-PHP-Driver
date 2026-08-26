<?php

declare(strict_types=1);

$host = getenv('GAUSS_HOST') ?: 'gaussdb';
$port = getenv('GAUSS_PORT') ?: '5432';
$database = getenv('GAUSS_DATABASE') ?: 'gdbdrv_m_test';
$user = getenv('GAUSS_USER') ?: 'gauss_php_test';
$password = getenv('GAUSS_PASSWORD');

if ($password === false || $password === '') {
    throw new RuntimeException('GAUSS_PASSWORD is required');
}

$pdo = new PDO(
    "pgsql:host={$host};port={$port};dbname={$database}",
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
    'client_version' => $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
];

$pdo->exec('DROP TABLE IF EXISTS php_driver_smoke');
$pdo->exec(<<<'SQL'
CREATE TABLE php_driver_smoke (
    id BIGINT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    amount DECIMAL(20, 4),
    enabled BOOLEAN,
    payload VARBINARY(128),
    created_at TIMESTAMP
)
SQL);

$insert = $pdo->prepare(<<<'SQL'
INSERT INTO php_driver_smoke
    (id, name, amount, enabled, payload, created_at)
VALUES
    (?, ?, ?, ?, ?, ?)
SQL);
$insert->bindValue(1, 1, PDO::PARAM_INT);
$insert->bindValue(2, '中文与 emoji 🚀', PDO::PARAM_STR);
$insert->bindValue(3, '1234567890123456.7890', PDO::PARAM_STR);
$insert->bindValue(4, 1, PDO::PARAM_INT);
$insert->bindValue(5, "binary\x00data", PDO::PARAM_STR);
$insert->bindValue(6, '2026-08-05 12:34:56.123456', PDO::PARAM_STR);
$insert->execute();

$select = $pdo->prepare('SELECT * FROM php_driver_smoke WHERE id = ?');
$select->execute([1]);
$result['native_prepare_row'] = $select->fetch();
$result['column_meta'] = [];
for ($i = 0; $i < $select->columnCount(); $i++) {
    $result['column_meta'][] = $select->getColumnMeta($i);
}

$pdo->beginTransaction();
$pdo->exec("INSERT INTO php_driver_smoke VALUES (2, 'rollback', 2.0000, false, NULL, NULL)");
$pdo->rollBack();
$result['rows_after_rollback'] = (int) $pdo->query('SELECT COUNT(*) FROM php_driver_smoke')->fetchColumn();

$result['m_mode_queries'] = [];
foreach ([
    'database' => 'SELECT DATABASE()',
    'sql_mode' => 'SELECT @@sql_mode',
] as $name => $sql) {
    try {
        $result['m_mode_queries'][$name] = [
            'ok' => true,
            'value' => $pdo->query($sql)->fetchColumn(),
        ];
    } catch (PDOException $exception) {
        $result['m_mode_queries'][$name] = [
            'ok' => false,
            'sqlstate' => $exception->getCode(),
            'message' => $exception->getMessage(),
        ];
    }
}

$pdo->exec('DROP TABLE php_driver_smoke');

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
