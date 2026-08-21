<?php

declare(strict_types=1);

require __DIR__ . '/php_pdo_test_connection.php';

$pdo = createTestConnection();
$table = 'php_text_threshold_' . bin2hex(random_bytes(6));
$sizes = [32 * 1024, 65535, 65536, 96 * 1024, 128 * 1024, 256 * 1024, 512 * 1024];
$results = [];

try {
    $pdo->exec("CREATE TABLE {$table} (id BIGINT PRIMARY KEY, payload TEXT)");
    $insert = $pdo->prepare("INSERT INTO {$table} (id, payload) VALUES (?, ?)");
    $select = $pdo->prepare("SELECT payload FROM {$table} WHERE id = ?");
    foreach ($sizes as $targetBytes) {
        $value = str_repeat('中', intdiv($targetBytes, 3)) . str_repeat('A', $targetBytes % 3);
        try {
            $insert->execute([$targetBytes, $value]);
            $select->execute([$targetBytes]);
            $actual = $select->fetchColumn();
            $results[] = [
                'requested_bytes' => $targetBytes,
                'bytes' => strlen($value),
                'status' => $actual === $value ? 'pass' : 'mismatch',
                'returned_bytes' => is_string($actual) ? strlen($actual) : null,
            ];
        } catch (PDOException $error) {
            $results[] = [
                'requested_bytes' => $targetBytes,
                'bytes' => strlen($value),
                'status' => 'rejected',
                'sqlstate' => (string) $error->getCode(),
                'message' => $error->getMessage(),
            ];
        }
    }
} finally {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}

echo json_encode(['sizes' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
