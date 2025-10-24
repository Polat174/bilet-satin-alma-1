<?php
declare(strict_types=1);

namespace App;

class Tickets
{
    public static function getTrip(int $tripId): ?array
    {
        $stmt = DB::conn()->prepare('SELECT t.*, c.name AS company_name FROM trips t JOIN companies c ON c.id=t.company_id WHERE t.id = ?');
        $stmt->execute([$tripId]);
        $trip = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $trip ?: null;
    }

    /** @return array<int,int> seat numbers */
    public static function getOccupiedSeats(int $tripId): array
    {
        $stmt = DB::conn()->prepare("SELECT seat_number FROM tickets WHERE trip_id = ? AND status = 'active'");
        $stmt->execute([$tripId]);
        return array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'seat_number'));
    }

    public static function findCoupon(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        $stmt = DB::conn()->prepare('SELECT * FROM coupons WHERE code = ?');
        $stmt->execute([$code]);
        $c = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$c) return null;
        $now = new \DateTimeImmutable('now');
        if ((int)$c['used_count'] >= (int)$c['usage_limit']) return null;
        if ($now > new \DateTimeImmutable($c['expires_at'])) return null;
        return $c;
    }

    /**
     * @return array{ok:bool,msg?:string,ticket_id?:int,paid_cents?:int}
     */
    public static function purchase(int $userId, int $tripId, int $seatNumber, ?string $couponCode): array
    {
        $pdo = DB::conn();
        $trip = self::getTrip($tripId);
        if (!$trip) return ['ok' => false, 'msg' => 'Sefer bulunamadı'];
        if ($seatNumber < 1 || $seatNumber > (int)$trip['seat_count']) return ['ok' => false, 'msg' => 'Geçersiz koltuk'];
        $occupied = self::getOccupiedSeats($tripId);
        if (in_array($seatNumber, $occupied, true)) return ['ok' => false, 'msg' => 'Koltuk dolu'];

        $coupon = self::findCoupon($couponCode);
        $priceCents = (int)$trip['price_cents'];
        if ($coupon) {
            $discount = (int)floor($priceCents * ((int)$coupon['percent']) / 100);
            $priceCents = max(0, $priceCents - $discount);
        }

        $pdo->beginTransaction();
        try {
            // lock user row
            $stmt = $pdo->prepare('SELECT credit_cents FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$user) throw new \RuntimeException('Kullanıcı yok');
            if ((int)$user['credit_cents'] < $priceCents) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Yetersiz bakiye'];
            }

            // ensure seat still free
            $check = $pdo->prepare("SELECT COUNT(1) FROM tickets WHERE trip_id=? AND seat_number=? AND status='active'");
            $check->execute([$tripId, $seatNumber]);
            if ((int)$check->fetchColumn() > 0) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Koltuk dolu'];
            }

            // charge credit
            $upd = $pdo->prepare('UPDATE users SET credit_cents = credit_cents - :p WHERE id = :u');
            $upd->execute([':p' => $priceCents, ':u' => $userId]);

            // create ticket
            $pnr = bin2hex(random_bytes(8));
            $ins = $pdo->prepare('INSERT INTO tickets(user_id, trip_id, seat_number, price_paid_cents, coupon_id, status, purchased_at, pnr) VALUES(?,?,?,?,?,\'active\',?,?)');
            $couponId = $coupon ? (int)$coupon['id'] : null;
            $ins->execute([$userId, $tripId, $seatNumber, $priceCents, $couponId, date('c'), $pnr]);
            $ticketId = (int)$pdo->lastInsertId();

            // Log wallet transaction (charge)
            $meta = json_encode(['ticket_id' => $ticketId, 'trip_id' => $tripId, 'coupon_id' => $couponId]);
            $pdo->prepare('INSERT INTO wallet_transactions(user_id, type, amount_cents, meta, created_at) VALUES(?,?,?,?,?)')
                ->execute([$userId, 'charge', -$priceCents, $meta, date('c')]);

            // update coupon usage
            if ($coupon) {
                $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')->execute([$coupon['id']]);
            }

            $pdo->commit();
            return ['ok' => true, 'ticket_id' => $ticketId, 'paid_cents' => $priceCents];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Satın alma hatası'];
        }
    }

    public static function getTicket(int $ticketId): ?array
    {
        $sql = 'SELECT tk.*, u.email, u.id AS user_id, t.origin, t.destination, t.departure_at, t.company_id, t.price_cents, c.name AS company_name
                FROM tickets tk
                JOIN users u ON u.id = tk.user_id
                JOIN trips t ON t.id = tk.trip_id
                JOIN companies c ON c.id = t.company_id
                WHERE tk.id = ?';
        $stmt = DB::conn()->prepare($sql);
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getTicketByPnr(string $pnr, int $userId): ?array
    {
        $sql = 'SELECT tk.*, u.email, u.id AS user_id, t.origin, t.destination, t.departure_at, t.company_id, t.price_cents, c.name AS company_name
                FROM tickets tk
                JOIN users u ON u.id = tk.user_id
                JOIN trips t ON t.id = tk.trip_id
                JOIN companies c ON c.id = t.company_id
                WHERE tk.pnr = ? AND tk.user_id = ?';
        $stmt = DB::conn()->prepare($sql);
        $stmt->execute([$pnr, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}


