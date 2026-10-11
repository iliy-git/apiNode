<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dbPath = Config::getDbPath();
            self::$instance = new PDO("sqlite:{$dbPath}", null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]);

            // Предотвращение блокировок базы X-UI
            self::$instance->exec("PRAGMA journal_mode = WAL;");
            self::$instance->exec("PRAGMA busy_timeout = 5000;");
        }

        return self::$instance;
    }
}
