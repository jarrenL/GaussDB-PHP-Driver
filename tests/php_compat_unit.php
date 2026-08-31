<?php

declare(strict_types=1);

use GaussDb\Compat\CompatibilityMode;
use GaussDb\Compat\ConnectionConfig;
use GaussDb\Compat\Statement;

require dirname(__DIR__) . '/src/autoload.php';

function checkUnit(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

checkUnit(CompatibilityMode::fromName('M') === CompatibilityMode::M, 'M alias failed');
checkUnit(CompatibilityMode::fromName('mysql') === CompatibilityMode::M, 'MYSQL alias failed');
checkUnit(CompatibilityMode::fromName('O') === CompatibilityMode::ORACLE, 'O alias failed');
checkUnit(CompatibilityMode::fromName('A') === CompatibilityMode::ORACLE, 'A alias failed');
checkUnit(CompatibilityMode::fromName('ORA') === CompatibilityMode::ORACLE, 'ORA alias failed');
checkUnit(CompatibilityMode::fromName('ORACLE') === CompatibilityMode::ORACLE, 'ORACLE alias failed');
checkUnit(CompatibilityMode::matchesDatabaseValue(CompatibilityMode::ORACLE, 'ORA'), 'ORA database match failed');
checkUnit(CompatibilityMode::matchesDatabaseValue(CompatibilityMode::M, 'MYSQL'), 'MYSQL database match failed');
checkUnit(CompatibilityMode::matchesDatabaseValue(CompatibilityMode::M, ' mysql '), 'Normalized MYSQL database match failed');
checkUnit(!CompatibilityMode::matchesDatabaseValue(CompatibilityMode::M, 'ORA'), 'Mode mismatch was accepted');

$config = new ConnectionConfig('db.example.com', 5432, 'app', 'user', 'secret', CompatibilityMode::M);
$oracleConfig = new ConnectionConfig('db.example.com', 5432, 'app', 'user', 'secret', 'ORACLE');
checkUnit($oracleConfig->mode === CompatibilityMode::ORACLE, 'ConnectionConfig mode normalization failed');
$dsn = $config->pdoDsn();
checkUnit(strncmp($dsn, 'odbc:', 5) === 0, 'ODBC DSN prefix is missing');
checkUnit(strpos($dsn, 'Driver={GaussDB Unicode}') !== false, 'Unicode driver is missing');
checkUnit(strpos($dsn, 'ConnSettings=set client_encoding=UTF8') !== false, 'UTF8 setting is missing');
checkUnit(strpos($dsn, 'BoolsAsChar=0') !== false, 'Boolean option is missing');
checkUnit(strpos($dsn, 'ByteaAsLongVarBinary=1') !== false, 'Binary option is missing');

$escapedConfig = new ConnectionConfig(
    'db.example.com',
    5432,
    'app',
    'user',
    'secret',
    CompatibilityMode::M,
    'Gauss}DB'
);
$escapedDsn = $escapedConfig->pdoDsn();
checkUnit(strpos($escapedDsn, 'Driver={Gauss}}DB}') !== false, 'ODBC driver escaping failed');

foreach (array('db;host', 'db{host', 'db}host', "db\0host") as $invalidHost) {
    try {
        (new ConnectionConfig($invalidHost, 5432, 'app', 'user', 'secret', CompatibilityMode::M))->pdoDsn();
        checkUnit(false, 'Unsafe ODBC host was accepted');
    } catch (InvalidArgumentException $error) {
        checkUnit(strpos($error->getMessage(), 'must not contain') !== false, 'Unexpected ODBC validation error');
    }
}
try {
    (new ConnectionConfig('db.example.com', 5432, 'app', 'user', 'secret', CompatibilityMode::M, "driver\0name"))->pdoDsn();
    checkUnit(false, 'Unsafe ODBC driver name was accepted');
} catch (InvalidArgumentException $error) {
    checkUnit(strpos($error->getMessage(), 'driver name') !== false, 'Unexpected ODBC driver validation error');
}

$statementClass = new ReflectionClass(Statement::class);
$statement = $statementClass->newInstanceWithoutConstructor();
$modeProperty = $statementClass->getProperty('mode');
$modeProperty->setAccessible(true);
$modeProperty->setValue($statement, CompatibilityMode::M);
$normalizeParameter = $statementClass->getMethod('normalizeParameter');
$normalizeParameter->setAccessible(true);
checkUnit($normalizeParameter->invoke($statement, 7) === [7, PDO::PARAM_INT], 'Integer binding normalization failed');
checkUnit($normalizeParameter->invoke($statement, null) === [null, PDO::PARAM_NULL], 'NULL binding normalization failed');

$toBoolean = $statementClass->getMethod('toBoolean');
$toBoolean->setAccessible(true);
checkUnit($toBoolean->invoke(null, 'True') === true, 'Mixed-case true normalization failed');
checkUnit($toBoolean->invoke(null, 'False') === false, 'Mixed-case false normalization failed');

$bindType = $statementClass->getMethod('bindValue')->getParameters()[2];
checkUnit($bindType->allowsNull() && $bindType->getDefaultValue() === null, 'bindValue default type must be inferred');
$fetchAllArguments = $statementClass->getMethod('fetchAll')->getParameters()[1];
checkUnit($fetchAllArguments->isVariadic(), 'fetchAll must forward optional PDO arguments');

echo "compat unit tests passed\n";
