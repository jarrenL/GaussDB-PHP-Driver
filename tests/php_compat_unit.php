<?php

declare(strict_types=1);

use GaussDb\Compat\CompatibilityMode;
use GaussDb\Compat\ConnectionConfig;

require dirname(__DIR__) . '/src/autoload.php';

function checkUnit(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

checkUnit(CompatibilityMode::fromName('M') === CompatibilityMode::M, 'M alias failed');
checkUnit(CompatibilityMode::fromName('O') === CompatibilityMode::ORACLE, 'O alias failed');
checkUnit(CompatibilityMode::fromName('A') === CompatibilityMode::ORACLE, 'A alias failed');
checkUnit(CompatibilityMode::ORACLE->matchesDatabaseValue('ORA'), 'ORA database match failed');
checkUnit(!CompatibilityMode::M->matchesDatabaseValue('ORA'), 'Mode mismatch was accepted');

$config = new ConnectionConfig('db.example.com', 5432, 'app', 'user', 'secret', CompatibilityMode::M);
$dsn = $config->pdoDsn();
checkUnit(str_starts_with($dsn, 'odbc:'), 'ODBC DSN prefix is missing');
checkUnit(str_contains($dsn, 'Driver={GaussDB Unicode}'), 'Unicode driver is missing');
checkUnit(str_contains($dsn, 'ConnSettings=set client_encoding=UTF8'), 'UTF8 setting is missing');
checkUnit(str_contains($dsn, 'BoolsAsChar=0'), 'Boolean option is missing');
checkUnit(str_contains($dsn, 'ByteaAsLongVarBinary=1'), 'Binary option is missing');

echo "compat unit tests passed\n";
