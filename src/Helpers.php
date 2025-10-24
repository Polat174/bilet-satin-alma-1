<?php
declare(strict_types=1);

namespace App;

/**
 * Yardımcı Fonksiyonlar Sınıfı
 * 
 * Kod tekrarını azaltmak için sıkça kullanılan işlevleri içerir.
 * Yönlendirme, veri temizleme, formatlama gibi genel amaçlı fonksiyonlar.
 */
class Helpers
{
    /**
     * Yönlendirme helper
     * 
     * Kullanıcıyı belirtilen sayfaya yönlendirir ve script'i sonlandırır.
     * 
     * @param string $path Yönlendirilecek sayfa (örn: '/login')
     * @param array $params URL parametreleri (örn: ['error' => 'mesaj'])
     * @return never (script sonlanır)
     */
    public static function redirect(string $path, array $params = []): never
    {
        $query = !empty($params) ? '?' . http_build_query($params) : '';
        header('Location: ' . $path . $query);
        exit;
    }
    
    /**
     * Redirect with error message
     */
    public static function redirectWithError(string $path, string $error): never
    {
        self::redirect($path, ['error' => $error]);
    }
    
    /**
     * Redirect with success message
     */
    public static function redirectWithSuccess(string $path, string $msg): never
    {
        self::redirect($path, ['msg' => $msg]);
    }
    
    /**
     * Session'daki kullanıcı bakiyesini güncelle
     * 
     * Bilet satın alma veya iptal sonrası session'daki bakiye bilgisini
     * veritabanından güncel değerle senkronize eder.
     * 
     * @param int $userId Kullanıcı ID
     * @return void
     */
    public static function updateSessionCredit(int $userId): void
    {
        // Session'da kullanıcı yoksa veya farklı kullanıcıysa işlem yapma
        if (!isset($_SESSION['user']) || $_SESSION['user']['id'] !== $userId) {
            return;
        }
        
        // Veritabanından güncel bakiyeyi al
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT credit_cents FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $newCredit = (int)$stmt->fetchColumn();
        
        // Session'ı güncelle
        $_SESSION['user']['credit_cents'] = $newCredit;
    }
    
    /**
     * Require authentication with optional role check
     */
    public static function requireAuth(?string $role = null): array
    {
        $user = Auth::user();
        if (!$user || ($role && $user['role'] !== $role)) {
            self::redirect($user ? '/' : '/login');
        }
        return $user;
    }
    
    /**
     * Require CSRF token
     */
    public static function requireCsrf(string $redirectPath = '/'): void
    {
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            self::redirectWithError($redirectPath, 'Güvenlik hatası');
        }
    }
    
    /**
     * Get cleaned POST data
     */
    public static function cleanPost(string $key, string $default = ''): string
    {
        return htmlspecialchars(trim($_POST[$key] ?? $default), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Get cleaned GET data
     */
    public static function cleanGet(string $key, string $default = ''): string
    {
        return htmlspecialchars(trim($_GET[$key] ?? $default), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Format price in TL
     */
    public static function formatPrice(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' TL';
    }
    
    /**
     * Format date
     */
    public static function formatDate(string $datetime, string $format = 'd.m.Y H:i'): string
    {
        return (new \DateTimeImmutable($datetime))->format($format);
    }
}

