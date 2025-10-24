<?php
declare(strict_types=1);

namespace App;

// Session güvenliği
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '0'); // HTTPS kullanıyorsanız 1 yapın
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');

session_start();

// Session hijacking koruması
if (!isset($_SESSION['user_agent'])) {
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
} else {
    if ($_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '') ||
        $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        session_unset();
        session_destroy();
        session_start();
    }
}

class Config
{
    public static function dbPath(): string
    {
        $path = __DIR__ . '/../storage/database.sqlite';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        if (!file_exists($path)) {
            touch($path);
        }
        return $path;
    }
}

class DB
{
    private static ?\PDO $pdo = null;

    public static function conn(): \PDO
    {
        if (self::$pdo === null) {
            $dsn = 'sqlite:' . Config::dbPath();
            self::$pdo = new \PDO($dsn);
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return self::$pdo;
    }
}


