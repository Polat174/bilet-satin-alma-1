<?php
declare(strict_types=1);

namespace App;

/**
 * Bilet İşlemleri Sınıfı
 * 
 * Bilet satın alma, iptal etme ve listeleme işlemlerini yönetir.
 * Koltuk kontrolü, cinsiyet bazlı oturma kısıtlaması ve kupon uygulaması içerir.
 */
class Ticket
{
    /**
     * Cinsiyet bazlı yan koltuk kontrolü
     * 
     * 2+2 koltuk düzeninde yan yana farklı cinsiyetten yolcuların
     * oturmasını engeller. Tek numaralı koltuklar sol, çift numaralılar sağ taraftadır.
     * 
     * @param \PDO $pdo Veritabanı bağlantısı
     * @param int $tripId Sefer ID
     * @param int $seatNumber Seçilen koltuk numarası
     * @param string $userGender Kullanıcının cinsiyeti (male/female)
     * @param int $seatCount Toplam koltuk sayısı
     * @return string|null Cinsiyet uyumsuzluğu varsa hata mesajı, yoksa null
     */
    private static function checkGenderConflict(\PDO $pdo, int $tripId, int $seatNumber, string $userGender, int $seatCount): ?string
    {
        // Yan koltuk numarasını hesapla (2+2 düzen: tek->çift, çift->tek)
        $adjacentSeat = ($seatNumber % 2 == 1) ? $seatNumber + 1 : $seatNumber - 1;
        
        // Yan koltuk geçerli aralıkta mı kontrol et
        if ($adjacentSeat < 1 || $adjacentSeat > $seatCount) {
            return null; // Kenarda oturuyor, yan koltuk yok
        }

        // Yan koltukta oturan yolcunun cinsiyetini bul
        $stmt = $pdo->prepare("SELECT u.gender FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.trip_id = ? AND t.seat_number = ? AND t.status = 'active'");
        $stmt->execute([$tripId, $adjacentSeat]);
        $adjacentGender = $stmt->fetchColumn();

        // Yan koltukta biri varsa ve cinsiyeti farklıysa hata ver
        if ($adjacentGender && $adjacentGender !== $userGender) {
            $texts = ['male' => 'erkek', 'female' => 'kadın'];
            return "Bu koltuğun yanında {$texts[$adjacentGender]} yolcu oturuyor. Lütfen başka bir koltuk seçin.";
        }
        
        return null; // Sorun yok
    }

    /**
     * Kupon indirimini hesapla
     * 
     * Kullanıcının girdiği kupon kodunu kontrol eder ve geçerliyse
     * indirimli fiyatı hesaplar. Her kullanıcı bir kuponu sadece 1 kez kullanabilir.
     * 
     * @param \PDO $pdo Veritabanı bağlantısı
     * @param int|null $couponId Kupon ID (null ise kupon kullanılmıyor)
     * @param int $userId Kullanıcı ID
     * @param int $basePrice Orijinal fiyat (kuruş cinsinden)
     * @return array ['price' => indirimli fiyat, 'coupon_id' => kupon ID veya null]
     * @throws \Exception Kupon daha önce kullanılmışsa
     */
    private static function applyCoupon(\PDO $pdo, ?int $couponId, int $userId, int $basePrice): array
    {
        // Kupon kullanılmıyorsa orijinal fiyatı döndür
        if (!$couponId) {
            return ['price' => $basePrice, 'coupon_id' => null];
        }

        // Kuponu veritabanından bul
        $coupon = $pdo->query("SELECT * FROM coupons WHERE id = " . (int)$couponId)->fetch(\PDO::FETCH_ASSOC);
        
        // Kupon geçersizse veya süresi dolmuşsa kupon uygulanmaz
        if (!$coupon || $coupon['used_count'] >= $coupon['usage_limit'] || strtotime($coupon['expires_at']) <= time()) {
            return ['price' => $basePrice, 'coupon_id' => null];
        }

        // Kullanıcı bu kuponu daha önce kullanmış mı kontrol et
        // YENİ: user_coupons tablosundan kontrol (fotoğraftaki yapıya uygun)
        // ESKİ: tickets tablosundan kontrol ediliyordu
        // AVANTAJ: Normalize yapı, daha hızlı sorgu, tek kupon kullanımı garantisi
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_coupons WHERE user_id = ? AND coupon_id = ?");
        $stmt->execute([$userId, $couponId]);
        if ($stmt->fetchColumn() > 0) {
            throw new \Exception('Bu kuponu daha önce kullandınız');
        }

        // İndirimli fiyatı hesapla (örn: %20 indirim)
        $discountedPrice = (int)round($basePrice * (100 - (int)$coupon['percent']) / 100);
        return ['price' => $discountedPrice, 'coupon_id' => $couponId];
    }

    /**
     * Bilet satın al (Ana işlem metodu)
     * 
     * Kullanıcı için bilet satın alır. Tüm kontrolleri yapar:
     * - Sefer ve kullanıcı kontrolü
     * - Koltuk müsaitliği
     * - Cinsiyet bazlı oturma kısıtlaması
     * - Kupon geçerliliği ve indirimi
     * - Bakiye kontrolü
     * - Transaction ile güvenli işlem
     * 
     * @param int $userId Kullanıcı ID
     * @param int $tripId Sefer ID
     * @param int $seatNumber Seçilen koltuk numarası
     * @param int|null $couponId Kupon ID (opsiyonel)
     * @return bool|string Başarılıysa true, hata varsa hata mesajı
     */
    public static function purchase(int $userId, int $tripId, int $seatNumber, ?int $couponId = null): bool|string
    {
        $pdo = DB::conn();
        $pdo->beginTransaction(); // Transaction başlat (rollback için)
        
        try {
            // 1. Sefer bilgisini al ve kontrol et
            $trip = $pdo->query("SELECT * FROM trips WHERE id = " . (int)$tripId)->fetch(\PDO::FETCH_ASSOC);
            if (!$trip) throw new \Exception('Sefer bulunamadı');
            
            // 2. Kullanıcı bilgisini al ve kontrol et
            $user = $pdo->query("SELECT id, credit_cents, gender FROM users WHERE id = " . (int)$userId)->fetch(\PDO::FETCH_ASSOC);
            if (!$user) throw new \Exception('Kullanıcı bulunamadı');

            // 3. Koltuk numarası geçerli mi kontrol et
            if ($seatNumber < 1 || $seatNumber > (int)$trip['seat_count']) {
                throw new \Exception('Geçersiz koltuk numarası');
            }

            // 4. Koltuk dolu mu kontrol et
            $stmt = $pdo->prepare("SELECT 1 FROM tickets WHERE trip_id = ? AND seat_number = ? AND status = 'active'");
            $stmt->execute([$tripId, $seatNumber]);
            if ($stmt->fetch()) throw new \Exception('Bu koltuk zaten alınmış');

            // 5. Cinsiyet bazlı yan koltuk kontrolü yap
            if ($user['gender'] && ($error = self::checkGenderConflict($pdo, $tripId, $seatNumber, $user['gender'], (int)$trip['seat_count']))) {
                throw new \Exception($error);
            }

            // 6. Kupon varsa uygula ve fiyatı hesapla
            $pricing = self::applyCoupon($pdo, $couponId, $userId, (int)$trip['price_cents']);
            
            // 7. Kullanıcının bakiyesi yeterli mi kontrol et
            if ((int)$user['credit_cents'] < $pricing['price']) {
                throw new \Exception('Yetersiz bakiye');
            }

            // 8. Kullanıcının bakiyesinden para düş
            $pdo->prepare("UPDATE users SET credit_cents = credit_cents - ? WHERE id = ?")->execute([$pricing['price'], $userId]);
            
            // 9. Kupon kullanıldıysa kullanım sayısını artır ve user_coupons tablosuna kaydet
            if ($pricing['coupon_id']) {
                // Kupon kullanım sayısını artır (mevcut yapı - coupons tablosu)
                $pdo->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")->execute([$pricing['coupon_id']]);
                
                // YENİ: User_Coupons tablosuna kaydet (fotoğraftaki normalize yapı)
                // Bu sayede kullanıcı-kupon ilişkisi ayrı bir tabloda tutuluyor
                // AVANTAJ: "1 kullanıcı = 1 kupon" kuralı tablo seviyesinde garanti edilir (UNIQUE constraint)
                $pdo->prepare("INSERT INTO user_coupons(coupon_id, user_id, created_at) VALUES(?, ?, ?)")
                    ->execute([$pricing['coupon_id'], $userId, date('c')]);
            }

            // 10. Bileti oluştur ve veritabanına kaydet
            // YENİ: total_price kolonu eklendi (TL cinsinden fiyat - fotoğraftaki yapı için)
            // NOT: Hem price_paid_cents (kuruş) hem total_price (TL) tutuluyor (geriye uyumluluk)
            $stmt = $pdo->prepare("INSERT INTO tickets(user_id, trip_id, seat_number, price_paid_cents, coupon_id, status, purchased_at, total_price) VALUES(?, ?, ?, ?, ?, 'active', ?, ?)");
            $totalPriceTL = (int)round($pricing['price'] / 100); // Kuruştan TL'ye çevir (100 kuruş = 1 TL)
            $stmt->execute([$userId, $tripId, $seatNumber, $pricing['price'], $pricing['coupon_id'], date('c'), $totalPriceTL]);

            // 11. Ticket ID'yi al ve Booked_Seats tablosuna kaydet
            // YENİ: Booked_Seats tablosu (fotoğraftaki normalize yapı)
            // Rezerve edilen koltuklar ayrı bir tabloda tutuluyor
            // AVANTAJ: Koltuk sorgularında performans artışı, daha temiz veri yapısı
            $ticketId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO booked_seats(ticket_id, seat_number, created_at) VALUES(?, ?, ?)")
                ->execute([$ticketId, $seatNumber, date('c')]);

            // 12. Cüzdan işlem geçmişine kaydet (para çıkışı)
            // Wallet transactions tablosuna charge (ücret kesimi) kaydı ekle
            $meta = json_encode(['ticket_id' => $ticketId, 'trip_id' => $tripId]);
            $pdo->prepare('INSERT INTO wallet_transactions(user_id, type, amount_cents, meta, created_at) VALUES(?, ?, ?, ?, ?)')
                ->execute([$userId, 'charge', -$pricing['price'], $meta, date('c')]);

            $pdo->commit(); // Tüm işlemler başarılı, kaydet
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            return $e->getMessage();
        }
    }

    /**
     * Kullanıcının biletlerini listele
     */
    public static function listByUser(int $userId): array
    {
        $stmt = DB::conn()->prepare("
            SELECT t.*, tr.origin, tr.destination, tr.departure_at, tr.price_cents, tr.company_id, c.name AS company_name 
            FROM tickets t 
            JOIN trips tr ON tr.id = t.trip_id 
            JOIN companies c ON c.id = tr.company_id 
            WHERE t.user_id = ? 
            ORDER BY t.purchased_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Bileti iptal et
     * 
     * Kullanıcının aktif biletini iptal eder ve parasını iade eder.
     * Kalkışa 1 saatten az kala iptal edilemez (sistem kuralı).
     * 
     * @param int $ticketId Bilet ID
     * @param int $userId Kullanıcı ID (güvenlik için)
     * @return bool|string Başarılıysa true, hata varsa hata mesajı
     */
    public static function cancel(int $ticketId, int $userId): bool|string
    {
        $pdo = DB::conn();
        $pdo->beginTransaction(); // Transaction başlat
        
        try {
            // 1. Bileti bul ve kalkış saatini kontrol et
            $stmt = $pdo->prepare("SELECT t.*, tr.departure_at FROM tickets t JOIN trips tr ON tr.id = t.trip_id WHERE t.id = ? AND t.user_id = ? AND t.status = 'active'");
            $stmt->execute([$ticketId, $userId]);
            $ticket = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // Bilet bulunamadı veya başka kullanıcının bileti
            if (!$ticket) throw new \Exception('Bilet bulunamadı veya iptal edilemez');
            
            // 2. Kalkışa 1 saatten az kaldıysa iptal edilemez
            if (strtotime($ticket['departure_at']) - time() < 3600) {
                throw new \Exception('Kalkışa 1 saatten az kaldı, iptal edilemez');
            }

            // 3. Bilet durumunu iptal edildi olarak güncelle
            $pdo->prepare("UPDATE tickets SET status = 'cancelled', cancelled_at = ? WHERE id = ?")->execute([date('c'), $ticketId]);
            
            // 4. Kullanıcıya parasını iade et (bakiyeye ekle)
            $pdo->prepare("UPDATE users SET credit_cents = credit_cents + ? WHERE id = ?")->execute([$ticket['price_paid_cents'], $userId]);

            // 5. Cüzdan işlem geçmişine kaydet (para girişi)
            $meta = json_encode(['ticket_id' => $ticketId, 'trip_id' => $ticket['trip_id']]);
            $pdo->prepare('INSERT INTO wallet_transactions(user_id, type, amount_cents, meta, created_at) VALUES(?, ?, ?, ?, ?)')
                ->execute([$userId, 'refund', (int)$ticket['price_paid_cents'], $meta, date('c')]);

            $pdo->commit(); // Tüm işlemler başarılı, kaydet
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            return $e->getMessage();
        }
    }
}
