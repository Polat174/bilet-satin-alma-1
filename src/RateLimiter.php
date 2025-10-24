<?php
declare(strict_types=1);

namespace App;

class RateLimiter
{
    private static array $requests = [];
    
    /**
     * Rate limiting kontrolü yapar
     * @param string $key Unique identifier (örn: IP adresi, user ID)
     * @param int $maxRequests Maksimum istek sayısı
     * @param int $windowSeconds Zaman penceresi (saniye)
     * @return bool True ise izin verilir, false ise limit aşılmış
     */
    public static function check(string $key, int $maxRequests = 10, int $windowSeconds = 60): bool
    {
        $now = time();
        $windowStart = $now - $windowSeconds;
        
        // Eski istekleri temizle
        if (isset(self::$requests[$key])) {
            self::$requests[$key] = array_filter(
                self::$requests[$key],
                fn($timestamp) => $timestamp > $windowStart
            );
        } else {
            self::$requests[$key] = [];
        }
        
        // Limit kontrolü
        if (count(self::$requests[$key]) >= $maxRequests) {
            return false;
        }
        
        // Yeni isteği kaydet
        self::$requests[$key][] = $now;
        return true;
    }
    
    /**
     * Veritabanı tabanlı rate limiting (kalıcı)
     */
    public static function checkDB(string $key, int $maxRequests = 10, int $windowSeconds = 60): bool
    {
        $pdo = DB::conn();
        $now = date('c');
        $windowStart = date('c', time() - $windowSeconds);
        
        // rate_limits tablosu yoksa oluştur
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT NOT NULL,
                timestamp TEXT NOT NULL
            )
        ");
        
        // Eski kayıtları temizle
        $pdo->prepare('DELETE FROM rate_limits WHERE timestamp < ?')->execute([$windowStart]);
        
        // Mevcut istek sayısını kontrol et
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE key = ? AND timestamp >= ?');
        $stmt->execute([$key, $windowStart]);
        $count = (int)$stmt->fetchColumn();
        
        if ($count >= $maxRequests) {
            return false;
        }
        
        // Yeni isteği kaydet
        $pdo->prepare('INSERT INTO rate_limits(key, timestamp) VALUES(?, ?)')->execute([$key, $now]);
        return true;
    }
    
    /**
     * IP adresini al
     */
    public static function getClientIP(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Rate limit aşıldığında gösterilecek yanıt
     */
    public static function limitExceededResponse(): void
    {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode([
            'error' => 'Çok fazla istek gönderdiniz. Lütfen biraz bekleyip tekrar deneyin.',
            'retry_after' => 60
        ]);
        exit;
    }
}

