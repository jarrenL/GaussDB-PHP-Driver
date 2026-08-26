<?php

declare(strict_types=1);

$driver = getenv('GAUSS_TEST_DRIVER') ?: 'pgsql';
$user = getenv('GAUSS_USER') ?: 'gauss_php_test';
$badPassword = getenv('GAUSS_BAD_PASSWORD');
if ($badPassword === false || $badPassword === '') {
    fwrite(STDERR, "GAUSS_BAD_PASSWORD is required; use one controlled attempt to avoid account lockout\n");
    exit(2);
}

if ($driver === 'odbc') {
    $connectionString = getenv('GAUSS_ODBC_CONNECTION_STRING');
    $dsn = ($connectionString !== false && $connectionString !== '')
        ? "odbc:{$connectionString}"
        : 'odbc:' . (getenv('GAUSS_ODBC_DSN') ?: 'GaussDB');
} else {
    $host = getenv('GAUSS_HOST') ?: 'gaussdb';
    $port = getenv('GAUSS_PORT') ?: '5432';
    $database = getenv('GAUSS_DATABASE') ?: 'gdbdrv_m_test';
    $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
}

try {
    new PDO($dsn, $user, $badPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo json_encode(['status' => 'fail', 'message' => 'Invalid credentials unexpectedly connected']), PHP_EOL;
    exit(1);
} catch (PDOException $error) {
    $message = str_replace($badPassword, '<redacted>', $error->getMessage());
    echo json_encode([
        'status' => 'pass',
        'pdo_driver' => $driver,
        'sqlstate' => (string) $error->getCode(),
        'message' => $message,
        'password_leaked' => str_contains($error->getMessage(), $badPassword),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(str_contains($error->getMessage(), $badPassword) ? 1 : 0);
}
