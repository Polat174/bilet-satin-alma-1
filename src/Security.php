<?php
declare(strict_types=1);

namespace App;

class Security
{
    public static function ensureCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }
        return (string)$_SESSION['csrf_token'];
    }

    public static function validateCsrf(?string $token): bool
    {
        $sess = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && $token !== '' && hash_equals((string)$sess, (string)$token);
    }

    public static function csrfField(): string
    {
        $t = self::ensureCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t) . '" />';
    }
}


