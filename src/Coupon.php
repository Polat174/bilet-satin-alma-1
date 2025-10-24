<?php
declare(strict_types=1);

namespace App;

/**
 * Kupon Yönetim Sınıfı
 * 
 * İndirim kuponlarının oluşturulması, güncellenmesi, silinmesi ve 
 * validasyonu için kullanılan sınıf. Admin panelinden kupon yönetimini sağlar.
 */
class Coupon
{
    /**
     * Kupon parametrelerini doğrula
     * 
     * Kupon oluştururken ve güncellerken tekrar eden validasyonları 
     * tek bir metodda toplar (DRY prensibi)
     * 
     * @param string $code Kupon kodu (örn: YILBASI2025)
     * @param int $percent İndirim yüzdesi (1-100 arası)
     * @param int $usageLimit Kullanım limiti (kaç kişi kullanabilir)
     * @param string $expiresAt Son kullanma tarihi
     * @return string|null Hata varsa mesaj, yoksa null
     */
    private static function validateParams(string $code, int $percent, int $usageLimit, string $expiresAt): ?string
    {
        // Kupon kodu boş olamaz
        if (empty(trim($code))) return 'Kupon kodu boş olamaz';
        
        // İndirim oranı 1-100 arasında olmalı
        if ($percent < 1 || $percent > 100) return 'İndirim oranı 1-100 arasında olmalıdır';
        
        // Kullanım limiti pozitif olmalı
        if ($usageLimit <= 0) return 'Kullanım limiti 0\'dan büyük olmalıdır';
        
        // Son kullanma tarihi gelecekte olmalı
        if (strtotime($expiresAt) <= time()) return 'Son kullanma tarihi gelecekte olmalıdır';
        
        return null;
    }

    /**
     * Yeni kupon oluştur
     * 
     * Admin panelinden yeni indirim kuponu oluşturur.
     * Kupon kodu otomatik olarak büyük harfe çevrilir (YILBASI2025).
     * 
     * @param string $code Kupon kodu
     * @param int $percent İndirim yüzdesi
     * @param int $usageLimit Kullanım limiti
     * @param string $expiresAt Son kullanma tarihi
     * @return bool|string Başarılıysa true, hata varsa hata mesajı
     */
    public static function create(string $code, int $percent, int $usageLimit, string $expiresAt): bool|string
    {
        // Kupon kodunu büyük harfe çevir (tutarlılık için)
        $code = trim(strtoupper($code));
        
        // Parametreleri doğrula
        if ($error = self::validateParams($code, $percent, $usageLimit, $expiresAt)) {
            return $error;
        }

        try {
            // Veritabanına kaydet
            $stmt = DB::conn()->prepare('INSERT INTO coupons(code, percent, usage_limit, expires_at, created_at) VALUES(?, ?, ?, ?, ?)');
            $stmt->execute([$code, $percent, $usageLimit, $expiresAt, date('c')]);
            return true;
        } catch (\PDOException $e) {
            // Unique constraint hatası (aynı kod varsa)
            return $e->getCode() === '23000' ? 'Bu kupon kodu zaten kullanılıyor' : 'Kupon oluşturma hatası';
        }
    }

    public static function update(int $id, string $code, int $percent, int $usageLimit, string $expiresAt): bool|string
    {
        $code = trim(strtoupper($code));
        if ($error = self::validateParams($code, $percent, $usageLimit, $expiresAt)) {
            return $error;
        }

        try {
            $stmt = DB::conn()->prepare('UPDATE coupons SET code = ?, percent = ?, usage_limit = ?, expires_at = ? WHERE id = ?');
            $stmt->execute([$code, $percent, $usageLimit, $expiresAt, $id]);
            return true;
        } catch (\PDOException $e) {
            return $e->getCode() === '23000' ? 'Bu kupon kodu zaten kullanılıyor' : 'Kupon güncelleme hatası';
        }
    }

    public static function delete(int $id): bool|string
    {
        try {
            DB::conn()->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]);
            return true;
        } catch (\PDOException $e) {
            return 'Kupon silme hatası';
        }
    }

    public static function list(): array
    {
        return DB::conn()->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getById(int $id): ?array
    {
        $stmt = DB::conn()->prepare('SELECT * FROM coupons WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function getByCode(string $code): ?array
    {
        $stmt = DB::conn()->prepare('SELECT * FROM coupons WHERE code = ?');
        $stmt->execute([trim(strtoupper($code))]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Kupon kodunu doğrula (bilet satın alırken kullanılır)
     * 
     * Kullanıcı bilet satın alırken kupon kodu girerse,
     * kuponun geçerli olup olmadığını kontrol eder.
     * 
     * @param string $code Kupon kodu
     * @return bool|string Geçerliyse true, değilse hata mesajı
     */
    public static function validate(string $code): bool|string
    {
        // Kuponu veritabanından bul
        $coupon = self::getByCode($code);
        if (!$coupon) return 'Geçersiz kupon kodu';
        
        // Kullanım limitini kontrol et
        if ($coupon['used_count'] >= $coupon['usage_limit']) return 'Kupon kullanım limiti dolmuş';
        
        // Son kullanma tarihini kontrol et
        if (strtotime($coupon['expires_at']) <= time()) return 'Kupon süresi dolmuş';
        
        return true;
    }
}
