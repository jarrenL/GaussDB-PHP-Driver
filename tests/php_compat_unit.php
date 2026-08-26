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
checkUnit(CompatibilityMode::matchesDatabaseValue(CompatibilityMode::ORACLE, 'ORA'), 'ORA database match failed');
checkUnit(!CompatibilityMode::matchesDatabaseValue(CompatibilityMode::M, 'ORA'), 'Mode mismatch was accepted');

$config = new ConnectionConfig('db.example.com', 5432, 'app', 'user', 'secret', CompatibilityMode::M);
$dsn = $config->pdoDsn();
checkUnit(strncmp($dsn, 'odbc:', 5) === 0, 'ODBC DSN prefix is missing');
checkUnit(strpos($dsn, 'Driver={GaussDB Unicode}') !== false, 'Unicode driver is missing');
checkUnit(strpos($dsn, 'ConnSettings=set client_encoding=UTF8') !== false, 'UTF8 setting is missing');
checkUnit(strpos($dsn, 'BoolsAsChar=0') !== false, 'Boolean option is missing');
checkUnit(strpos($dsn, 'ByteaAsLongVarBinary=1') !== false, 'Binary option is missing');

echo "compat unit tests passed\n";
