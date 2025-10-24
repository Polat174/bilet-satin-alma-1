<?php
declare(strict_types=1);

namespace App;

/**
 * Sefer Yönetim Sınıfı
 * 
 * Otobüs seferlerinin oluşturulması, güncellenmesi, silinmesi ve 
 * listelenmesi için kullanılan sınıf. Firma adminleri tarafından kullanılır.
 */
class TripManager
{
    /**
     * Sefer parametrelerini doğrula
     * 
     * Sefer oluştururken ve güncellerken tekrar eden validasyonları 
     * tek bir metodda toplar (DRY prensibi)
     * 
     * @param string $origin Kalkış şehri
     * @param string $destination Varış şehri
     * @param string $departureAt Kalkış tarihi/saati
     * @param int $priceCents Fiyat (kuruş cinsinden)
     * @param int $seatCount Koltuk sayısı
     * @return string|null Hata varsa mesaj, yoksa null
     */
    private static function validateParams(string $origin, string $destination, string $departureAt, int $priceCents, int $seatCount): ?string
    {
        // Şehir isimleri boş olamaz
        if (empty(trim($origin)) || empty(trim($destination))) return 'Kalkış ve varış şehirleri boş olamaz';
        
        // Fiyat ve koltuk sayısı pozitif olmalı
        if ($priceCents <= 0 || $seatCount <= 0) return 'Fiyat ve koltuk sayısı 0\'dan büyük olmalıdır';
        
        // Kalkış tarihi gelecekte olmalı (geçmiş sefer oluşturulamaz)
        if (strtotime($departureAt) <= time()) return 'Kalkış tarihi gelecekte olmalıdır';
        
        return null;
    }

    public static function create(int $companyId, string $origin, string $destination, string $departureAt, int $priceCents, int $seatCount): bool|string
    {
        if ($error = self::validateParams($origin, $destination, $departureAt, $priceCents, $seatCount)) {
            return $error;
        }

        try {
            // YENİ: Varış saatini otomatik hesapla (fotoğraftaki yapı için)
            // Kalkış saatinden 4 saat sonrası varsayılan varış saati olarak belirleniyor
            // Örnek: Kalkış 10:00 ise Varış 14:00
            $arrivalTime = date('c', strtotime($departureAt) + (4 * 3600)); // 3600 saniye = 1 saat, 4*3600 = 4 saat
            
            // YENİ: arrival_time kolonu eklendi (fotoğraftaki yapı)
            // ESKİ: Sadece departure_at vardı, varış saati tutulmuyordu
            $stmt = DB::conn()->prepare('INSERT INTO trips(company_id, origin, destination, departure_at, arrival_time, price_cents, seat_count, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$companyId, trim($origin), trim($destination), $departureAt, $arrivalTime, $priceCents, $seatCount, date('c')]);
            return true;
        } catch (\PDOException $e) {
            return 'Sefer oluşturma hatası';
        }
    }

    public static function update(int $id, string $origin, string $destination, string $departureAt, int $priceCents, int $seatCount): bool|string
    {
        if ($error = self::validateParams($origin, $destination, $departureAt, $priceCents, $seatCount)) {
            return $error;
        }

        try {
            // YENİ: Varış saatini otomatik güncelle (fotoğraftaki yapı için)
            // Kalkış saati değiştiğinde varış saati de otomatik olarak yeniden hesaplanır
            $arrivalTime = date('c', strtotime($departureAt) + (4 * 3600)); // 4 saat sonra
            
            // YENİ: arrival_time da güncelleniyor (fotoğraftaki yapı)
            // ESKİ: Sadece departure_at güncelleniyor, varış saati tutulmuyordu
            $stmt = DB::conn()->prepare('UPDATE trips SET origin = ?, destination = ?, departure_at = ?, arrival_time = ?, price_cents = ?, seat_count = ? WHERE id = ?');
            $stmt->execute([trim($origin), trim($destination), $departureAt, $arrivalTime, $priceCents, $seatCount, $id]);
            return true;
        } catch (\PDOException $e) {
            return 'Sefer güncelleme hatası';
        }
    }

    public static function delete(int $id): bool|string
    {
        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE trip_id = ? AND status = "active"');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                return 'Bu sefere ait aktif biletler var, silinemez';
            }
            $pdo->prepare('DELETE FROM trips WHERE id = ?')->execute([$id]);
            return true;
        } catch (\PDOException $e) {
            return 'Sefer silme hatası';
        }
    }

    public static function listByCompany(int $companyId): array
    {
        $stmt = DB::conn()->prepare('SELECT * FROM trips WHERE company_id = ? ORDER BY departure_at DESC');
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getById(int $id): ?array
    {
        $stmt = DB::conn()->prepare('SELECT * FROM trips WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Boş koltukları listele
     * 
     * Belirli bir seferdeki boş (satılmamış) koltukların numaralarını döndürür.
     * Koltuk haritası gösterimi için kullanılır.
     * 
     * @param int $tripId Sefer ID
     * @return array Boş koltuk numaraları dizisi (örn: [1, 3, 5, 8])
     */
    public static function getAvailableSeats(int $tripId): array
    {
        // Seferi bul
        if (!$trip = self::getById($tripId)) {
            return []; // Sefer bulunamadı
        }

        // Dolu koltukları bul
        $stmt = DB::conn()->prepare('SELECT seat_number FROM tickets WHERE trip_id = ? AND status = "active"');
        $stmt->execute([$tripId]);
        $takenSeats = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'seat_number');

        // Tüm koltuk numaralarından dolu olanları çıkar, boş olanlar kalsın
        return array_values(array_diff(range(1, $trip['seat_count']), $takenSeats));
    }
}
