<?php

declare(strict_types=1);

function createTestConnection(array $dsnOptions = []): PDO
{
    $driver = getenv('GAUSS_TEST_DRIVER') ?: 'pgsql';
    $user = getenv('GAUSS_USER') ?: 'gauss_php_test';
    $password = getenv('GAUSS_PASSWORD');
    if ($password === false || $password === '') {
        throw new RuntimeException('GAUSS_PASSWORD is required');
    }
    if ($driver === 'odbc') {
        $connectionString = getenv('GAUSS_ODBC_CONNECTION_STRING');
        $dsn = $connectionString ? "odbc:{$connectionString}" : 'odbc:' . (getenv('GAUSS_ODBC_DSN') ?: 'GaussDB507');
    } else {
        $host = getenv('GAUSS_HOST') ?: 'gaussdb-507';
        $port = getenv('GAUSS_PORT') ?: '5432';
        $database = getenv('GAUSS_DATABASE') ?: 'gdbdrv_m_test';
        $options = $dsnOptions ? ';' . http_build_query($dsnOptions, '', ';') : '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}{$options}";
    }
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
