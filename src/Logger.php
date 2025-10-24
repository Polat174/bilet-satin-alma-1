<?php
declare(strict_types=1);

namespace App;

class Logger
{
    private static string $logDir = __DIR__ . '/../storage/logs/';
    
    /**
     * Log seviyelerine göre hata kaydı
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        try {
            // Log dizinini oluştur
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0777, true);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
            $logMessage = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;
            
            // Günlük dosyasına yaz
            $fileName = self::$logDir . date('Y-m-d') . '.log';
            file_put_contents($fileName, $logMessage, FILE_APPEND);
            
            // Veritabanına da kaydet
            self::logToDB($level, $message, $context);
        } catch (\Throwable $e) {
            // Loglama başarısız olursa sessizce devam et
            error_log('Logger failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Veritabanına log kaydı
     */
    private static function logToDB(string $level, string $message, array $context): void
    {
        try {
            $pdo = DB::conn();
            
            // logs tablosunu oluştur (yoksa)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    level TEXT NOT NULL,
                    message TEXT NOT NULL,
                    context TEXT,
                    user_id INTEGER,
                    ip_address TEXT,
                    user_agent TEXT,
                    created_at TEXT NOT NULL
                )
            ");
            
            $stmt = $pdo->prepare('
                INSERT INTO logs(level, message, context, user_id, ip_address, user_agent, created_at) 
                VALUES(?, ?, ?, ?, ?, ?, ?)
            ');
            
            $userId = $_SESSION['user']['id'] ?? null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;
            
            $stmt->execute([
                $level,
                $message,
                $contextJson,
                $userId,
                $ip,
                $userAgent,
                date('c')
            ]);
        } catch (\Throwable $e) {
            // DB loglama başarısız olursa dosyaya yaz
            error_log('DB Logger failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Helper metodları
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }
    
    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }
    
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }
    
    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }
    
    /**
     * Security olaylarını logla
     */
    public static function security(string $event, array $context = []): void
    {
        self::log('SECURITY', $event, $context);
    }
}

