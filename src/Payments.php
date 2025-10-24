<?php
declare(strict_types=1);

namespace App;

class Payments
{
    public static function maskPan(string $pan): string
    {
        $last4 = substr(preg_replace('/\D+/', '', $pan), -4);
        return '**** **** **** ' . $last4;
    }

    public static function detectBrand(string $pan): string
    {
        $digits = preg_replace('/\D+/', '', $pan);
        if (preg_match('/^4\d{12,18}$/', $digits)) return 'VISA';
        if (preg_match('/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))\d{14}$/', $digits)) return 'MASTERCARD';
        return 'CARD';
    }

    public static function addCard(int $userId, string $holder, string $pan, int $expMonth, int $expYear): bool|string
    {
        $brand = self::detectBrand($pan);
        $masked = self::maskPan($pan);
        $token = hash('sha256', $userId . '|' . $masked . '|' . $expMonth . '|' . $expYear);
        $stmt = DB::conn()->prepare('INSERT INTO payment_methods(user_id, brand, masked, token, holder_name, exp_month, exp_year, created_at) VALUES(?,?,?,?,?,?,?,?)');
        try {
            return $stmt->execute([$userId, $brand, $masked, $token, $holder, $expMonth, $expYear, date('c')]);
        } catch (\Throwable $e) {
            return 'Kart eklenemedi';
        }
    }

    public static function listCards(int $userId): array
    {
        $stmt = DB::conn()->prepare('SELECT * FROM payment_methods WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function topup(int $userId, int $amountCents, ?string $note = null): bool
    {
        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET credit_cents = credit_cents + :a WHERE id = :u')->execute([':a' => $amountCents, ':u' => $userId]);
            $pdo->prepare('INSERT INTO wallet_transactions(user_id, type, amount_cents, meta, created_at) VALUES(?,?,?,?,?)')
                ->execute([$userId, 'topup', $amountCents, $note, date('c')]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }
}


