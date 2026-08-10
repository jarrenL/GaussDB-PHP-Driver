<?php

declare(strict_types=1);

$password = getenv('GAUSS_PASSWORD');
if ($password === false || $password === '') {
    throw new RuntimeException('GAUSS_PASSWORD is required');
}

$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv('GAUSS_HOST') ?: '127.0.0.1',
        getenv('GAUSS_PORT') ?: '5432',
        getenv('GAUSS_DATABASE') ?: 'gdbdrv_m_test'
    ),
    getenv('GAUSS_USER') ?: 'gauss_php_test',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$statement = $pdo->prepare('SELECT ? AS message');
$statement->execute(['GaussDB 507 M mode is reachable from PHP PDO']);

print_r($statement->fetch());

