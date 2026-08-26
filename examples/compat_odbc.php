<?php

declare(strict_types=1);

use GaussDb\Compat\BinaryValue;
use GaussDb\Compat\CompatibilityMode;
use GaussDb\Compat\ConnectionConfig;
use GaussDb\Compat\Driver;
use GaussDb\Compat\ResultType;

require dirname(__DIR__) . '/src/autoload.php';

$password = getenv('GAUSS_PASSWORD');
if ($password === false || $password === '') {
    throw new RuntimeException('GAUSS_PASSWORD is required');
}

$connection = Driver::connect(new ConnectionConfig(
    getenv('GAUSS_HOST') ?: '127.0.0.1',
    (int) (getenv('GAUSS_PORT') ?: 5432),
    getenv('GAUSS_DATABASE') ?: 'gdbdrv_m_test',
    getenv('GAUSS_USER') ?: 'gauss_php_test',
    $password,
    CompatibilityMode::fromName(getenv('GAUSS_MODE') ?: 'M'),
    getenv('GAUSS_ODBC_DRIVER') ?: 'GaussDB Unicode'
));

$connection->exec('DROP TABLE IF EXISTS php_compat_example');
$connection->exec('CREATE TABLE php_compat_example (id BIGINT PRIMARY KEY, enabled BOOLEAN, payload VARBINARY(64))');
$connection->execute(
    'INSERT INTO php_compat_example (id, enabled, payload) VALUES (?, ?, ?)',
    [1, true, new BinaryValue("A\x00B\xFF")]
);
$row = $connection->execute(
    'SELECT enabled, payload FROM php_compat_example WHERE id = ?',
    [1],
    ['enabled' => ResultType::BOOLEAN, 'payload' => ResultType::BINARY_HEX]
)->fetch();
$connection->exec('DROP TABLE php_compat_example');

echo json_encode([
    'enabled' => $row['enabled'],
    'payload_hex' => bin2hex($row['payload']),
], JSON_PRETTY_PRINT), PHP_EOL;
