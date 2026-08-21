<?php

declare(strict_types=1);

require __DIR__ . '/php_pdo_test_connection.php';

try {
    $pdo = createTestConnection(['sslmode' => 'require']);
    $serverSsl = $pdo->query('SHOW ssl')->fetchColumn();
    $result = ['status' => 'pass', 'sslmode' => 'require', 'server_ssl' => $serverSsl];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit((string) $serverSsl === 'on' ? 0 : 1);
} catch (PDOException $error) {
    echo json_encode([
        'status' => 'unsupported',
        'sslmode' => 'require',
        'sqlstate' => (string) $error->getCode(),
        'message' => $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(3);
}
