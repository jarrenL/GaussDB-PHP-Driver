<?php

declare(strict_types=1);

namespace GaussDb\Compat;

use PDO;
use RuntimeException;

final class Driver
{
    public static function connect(ConnectionConfig $config): Connection
    {
        if (!extension_loaded('pdo_odbc') || !in_array('odbc', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PDO_ODBC is required for the GaussDB compatibility driver');
        }

        $options = $config->pdoOptions + [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_STRINGIFY_FETCHES] = false;
        $options[PDO::ATTR_EMULATE_PREPARES] = false;

        $pdo = new PDO(
            $config->pdoDsn(),
            $config->user,
            $config->password,
            $options
        );

        $connection = new Connection($pdo, $config->mode);
        $connection->assertCompatibilityMode();
        $connection->assertUtf8ClientEncoding();
        return $connection;
    }
}
