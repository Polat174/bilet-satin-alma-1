# 📝 Schema Refactor Yorum Satırları Raporu

**Tarih:** 21 Ekim 2025  
**Amaç:** Fotoğraftaki şemaya uygun olarak yapılan değişikliklere detaylı yorum satırları eklemek

---

## ✅ Eklenen Yorum Satırları

### 1️⃣ **src/Ticket.php** (3 Alan Güncellendi)

#### **Kupon Kontrolü (Satır 79-87)**
```php
// Kullanıcı bu kuponu daha önce kullanmış mı kontrol et
// YENİ: user_coupons tablosundan kontrol (fotoğraftaki yapıya uygun)
// ESKİ: tickets tablosundan kontrol ediliyordu
// AVANTAJ: Normalize yapı, daha hızlı sorgu, tek kupon kullanımı garantisi
$stmt = $pdo->prepare("SELECT COUNT(*) FROM user_coupons WHERE user_id = ? AND coupon_id = ?");
```

**Açıklama:**
- Kupon kontrolünün artık `user_coupons` tablosundan yapıldığı belirtildi
- Eski yöntem (tickets) ile karşılaştırma yapıldı
- Normalize yapının avantajları açıklandı

---

#### **Kupon Kaydetme (Satır 151-161)**
```php
// 9. Kupon kullanıldıysa kullanım sayısını artır ve user_coupons tablosuna kaydet
if ($pricing['coupon_id']) {
    // Kupon kullanım sayısını artır (mevcut yapı - coupons tablosu)
    $pdo->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")->execute(...);
    
    // YENİ: User_Coupons tablosuna kaydet (fotoğraftaki normalize yapı)
    // Bu sayede kullanıcı-kupon ilişkisi ayrı bir tabloda tutuluyor
    // AVANTAJ: "1 kullanıcı = 1 kupon" kuralı tablo seviyesinde garanti edilir (UNIQUE constraint)
    $pdo->prepare("INSERT INTO user_coupons(coupon_id, user_id, created_at) VALUES(?, ?, ?)")
        ->execute(...);
}
```

**Açıklama:**
- `user_coupons` tablosunun ne için kullanıldığı açıklandı
- UNIQUE constraint'in 1 kupon = 1 kullanıcı garantisini nasıl sağladığı belirtildi

---

#### **Bilet Oluşturma ve Booked_Seats (Satır 163-182)**
```php
// 10. Bileti oluştur ve veritabanına kaydet
// YENİ: total_price kolonu eklendi (TL cinsinden fiyat - fotoğraftaki yapı için)
// NOT: Hem price_paid_cents (kuruş) hem total_price (TL) tutuluyor (geriye uyumluluk)
$stmt = $pdo->prepare("INSERT INTO tickets(..., total_price) VALUES(...)");
$totalPriceTL = (int)round($pricing['price'] / 100); // Kuruştan TL'ye çevir (100 kuruş = 1 TL)

// 11. Ticket ID'yi al ve Booked_Seats tablosuna kaydet
// YENİ: Booked_Seats tablosu (fotoğraftaki normalize yapı)
// Rezerve edilen koltuklar ayrı bir tabloda tutuluyor
// AVANTAJ: Koltuk sorgularında performans artışı, daha temiz veri yapısı
$pdo->prepare("INSERT INTO booked_seats(ticket_id, seat_number, created_at) VALUES(?, ?, ?)")
    ->execute(...);

// 12. Cüzdan işlem geçmişine kaydet (para çıkışı)
// Wallet transactions tablosuna charge (ücret kesimi) kaydı ekle
```

**Açıklama:**
- `total_price` kolonunun ne işe yaradığı ve nasıl hesaplandığı açıklandı
- Geriye uyumluluk için iki fiyat alanının (kuruş ve TL) tutulduğu belirtildi
- `booked_seats` tablosunun performans avantajları açıklandı

---

### 2️⃣ **src/Auth.php** (2 Alan Güncellendi)

#### **Kayıt İşlemi (Satır 76-99)**
```php
// YENİ: full_name oluştur (fotoğraftaki yapı için)
// İsim ve soyisim birleştirilerek tam isim oluşturuluyor
$fullName = trim($firstName . ' ' . $lastName);
$hashedPassword = self::hashPassword($password);

// YENİ KOLONLAR:
// - full_name: İsim + Soyisim birleşik (fotoğraftaki yapı)
// - password: password_hash ile aynı (fotoğraftaki yapı - geriye uyumluluk)
// - balance: Başlangıç bakiyesi 800 TL (fotoğraftaki yapı)
// ESKİ KOLONLAR: email, password_hash, first_name, last_name, birth_date, gender, role, credit_cents
$stmt = $pdo->prepare('INSERT INTO users(..., full_name, balance, password, ...) VALUES(...)');
```

**Açıklama:**
- Yeni eklenen kolonlar (full_name, balance, password) listelendi
- Her birinin amacı açıklandı
- Eski kolonlarla karşılaştırma yapıldı

---

#### **Login İşlemi (Satır 113-139)**
```php
// YENİ: full_name ve balance kolonları da getiriliyor (fotoğraftaki yapı)
// ESKİ: Sadece temel kullanıcı bilgileri getiriliyordu
$stmt = $pdo->prepare('SELECT ..., full_name, balance, ... FROM users WHERE email = :e');

// Session'a kullanıcı bilgilerini kaydet
// YENİ ALANLAR: full_name (tam isim), balance (TL cinsinden bakiye)
// NOT: Hem credit_cents hem balance tutuluyor (geriye uyumluluk)
$_SESSION['user'] = [
    'credit_cents' => (int)$user['credit_cents'], // Kuruş cinsinden (mevcut sistem)
    'balance' => (int)($user['balance'] ?? 800), // TL cinsinden (fotoğraftaki yapı)
    'full_name' => $user['full_name'] ?? '', // Fotoğraftaki yapı için
    ...
];
```

**Açıklama:**
- Session'a eklenen yeni alanlar belirtildi
- Kuruş ve TL bakiyesi farkı açıklandı
- Geriye uyumluluk vurgulandı

---

### 3️⃣ **src/TripManager.php** (2 Metot Güncellendi)

#### **Sefer Oluşturma (Satır 48-60)**
```php
// YENİ: Varış saatini otomatik hesapla (fotoğraftaki yapı için)
// Kalkış saatinden 4 saat sonrası varsayılan varış saati olarak belirleniyor
// Örnek: Kalkış 10:00 ise Varış 14:00
$arrivalTime = date('c', strtotime($departureAt) + (4 * 3600)); // 3600 saniye = 1 saat, 4*3600 = 4 saat

// YENİ: arrival_time kolonu eklendi (fotoğraftaki yapı)
// ESKİ: Sadece departure_at vardı, varış saati tutulmuyordu
$stmt = DB::conn()->prepare('INSERT INTO trips(..., arrival_time, ...) VALUES(...)');
```

**Açıklama:**
- Varış saatinin nasıl hesaplandığı detaylı açıklandı
- Saniye hesaplaması (3600 = 1 saat) belirtildi
- Örnek verildi (10:00 → 14:00)

---

#### **Sefer Güncelleme (Satır 70-82)**
```php
// YENİ: Varış saatini otomatik güncelle (fotoğraftaki yapı için)
// Kalkış saati değiştiğinde varış saati de otomatik olarak yeniden hesaplanır
$arrivalTime = date('c', strtotime($departureAt) + (4 * 3600)); // 4 saat sonra

// YENİ: arrival_time da güncelleniyor (fotoğraftaki yapı)
// ESKİ: Sadece departure_at güncelleniyor, varış saati tutulmuyordu
$stmt = DB::conn()->prepare('UPDATE trips SET ..., arrival_time = ?, ... WHERE id = ?');
```

**Açıklama:**
- Güncelleme sırasında varış saatinin otomatik yeniden hesaplandığı belirtildi
- Eski sistemle karşılaştırma yapıldı

---

## 📊 Yorum İstatistikleri

| Dosya | Eklenen Yorum Satırı | Güncellenen Metot/Alan |
|-------|---------------------|------------------------|
| `Ticket.php` | +21 satır | 3 alan (kupon kontrolü, kupon kaydetme, bilet + booked_seats) |
| `Auth.php` | +17 satır | 2 metot (register, login) |
| `TripManager.php` | +12 satır | 2 metot (create, update) |
| **TOPLAM** | **+50 satır** | **7 alan/metot** |

---

## 🎯 Yorum Satırlarının Amaçları

### 1. **YENİ/ESKİ Karşılaştırması**
- Her değişiklik için eski durum açıklandı
- Farklar net bir şekilde belirtildi

### 2. **AVANTAJ Açıklamaları**
- Yeni yapının neden daha iyi olduğu açıklandı
- Performans artışları vurgulandı
- Normalize yapının faydaları belirtildi

### 3. **Geriye Uyumluluk Notları**
- Eski alanların korunduğu belirtildi
- İki yapının paralel çalıştığı vurgulandı

### 4. **Hesaplama Detayları**
- Matematiksel işlemler açıklandı (örn: kuruş → TL)
- Zaman hesaplamaları detaylandırıldı (örn: 3600 saniye = 1 saat)

### 5. **Fotoğraftaki Yapı Referansları**
- Her yeni alan için "fotoğraftaki yapı" ifadesi kullanıldı
- Hangi kolonun neden eklendiği açıklandı

---

## ✅ Sonuç

Tüm schema refactor değişikliklerine **50+ satır detaylı yorum** eklendi. Yorumlar:

- ✅ Türkçe ve anlaşılır
- ✅ Eski/yeni karşılaştırmaları içeriyor
- ✅ Avantajları açıklıyor
- ✅ Geriye uyumluluğu vurguluyor
- ✅ Hesaplamaları detaylandırıyor
- ✅ Fotoğraftaki yapıya referans veriyor

**Kodlar artık yeni geliştiriciler için çok daha anlaşılır hale geldi!** 🚀

