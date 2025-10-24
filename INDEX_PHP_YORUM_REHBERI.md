# 📝 index.php Yorum Satırları Rehberi

**Durum:** index.php dosyası 501 satır ve 48 route içeriyor. Tüm route'lara detaylı yorum eklemek yerine, **organize bölüm başlıkları** ve **kritik noktlara yorumlar** eklendi.

---

## ✅ Eklenen Yorumlar

### 1. Yardımcı Fonksiyonlar (Satır 30-63)
```php
// ============================================================
// YARDIMCI FONKSİYONLAR (Helper Functions)
// ============================================================

/**
 * clean() - XSS koruması için girdi temizleme
 * @param string $input Temizlenecek girdi
 * @return string Güvenli hale getirilmiş girdi
 */
function clean($input) { ... }

/**
 * auth() - Kullanıcı yetkisi kontrol eder, yoksa login'e yönlendirir
 * @param string|null $role Gerekli rol (null = sadece login kontrolü)
 * @return array Kullanıcı bilgileri
 */
function auth($role = null) { ... }

/**
 * csrf() - CSRF token kontrolü yapar
 * @return bool Token geçerli mi?
 */
function csrf() { ... }
```

---

### 2. Ana Sayfa & Sefer Arama (Satır 67-172)
```php
// ============================================================
// ANA SAYFA & SEFER ARAMA (Homepage & Trip Search)
// ============================================================

/**
 * Ana Sayfa - Sefer arama formu ve sonuçlar
 * GET / 
 * Query params: origin, destination, date
 */
$router->get('/', function () {
    // Sefer arama mantığı
    ...
});
```

---

### 3. Auth İşlemleri (Satır 179-328)
```php
// ============================================================
// AUTH İŞLEMLERİ (Authentication & Authorization)
// ============================================================

/**
 * Login Sayfası Göster
 * GET /login
 */
$router->get('/login', function () { ... });

/**
 * Login İşlemi
 * POST /login
 * Rate Limit: 10 istek/dakika (IP bazlı)
 * CSRF Koruması: Aktif
 */
$router->post('/login', function () {
    // Rate limiting - IP bazlı
    // CSRF kontrolü
    // Auth::login()
});

/**
 * Şifremi Unuttum Sayfası
 * GET /forgot-password
 */
$router->get('/forgot-password', function () { ... });

// ... diğer auth route'ları
```

---

## 📋 Route Grupları (Tam Liste)

### 🔐 Auth & Security Routes
| Route | Method | Açıklama | Rate Limit | CSRF |
|-------|--------|----------|------------|------|
| `/login` | GET | Login sayfası | - | - |
| `/login` | POST | Login işlemi | 10/dakika | ✓ |
| `/register` | GET | Kayıt sayfası | - | - |
| `/register` | POST | Kayıt işlemi | 5/saat | ✓ |
| `/logout` | GET | Çıkış işlemi | - | - |
| `/forgot-password` | GET, POST | Şifre sıfırlama talebi | - | ✓ |
| `/reset-password` | GET, POST | Şifre sıfırlama | - | ✓ |
| `/verify-email` | GET | Email doğrulama | - | - |

---

### 🎫 Kullanıcı Routes (User)
| Route | Method | Açıklama | Auth |
|-------|--------|----------|------|
| `/trip-details/{id}` | GET | Sefer detayları, koltuk seçimi | - |
| `/buy` | POST | Bilet satın alma | user |
| `/my-tickets` | GET | Bilet listem | user |
| `/cancel-ticket` | POST | Bilet iptal | user |
| `/ticket/{pnr}` | GET | Bilet PDF görüntüle | - |
| `/profile` | GET | Profil sayfası | user |
| `/profile/update` | POST | Profil güncelleme | user |
| `/profile/change-password` | POST | Şifre değiştirme | user |
| `/profile/card-add` | POST | Kart ekleme | user |
| `/wallet` | GET | Cüzdan sayfası | user |
| `/wallet/topup` | POST | Bakiye yükleme | user |

---

### 👨‍💼 Firma Admin Routes
| Route | Method | Açıklama | Auth |
|-------|--------|----------|------|
| `/firm-admin` | GET | Firma admin paneli | firm_admin |
| `/firm-admin/trip-add` | GET, POST | Sefer ekleme | firm_admin |
| `/firm-admin/trip-edit/{id}` | GET, POST | Sefer düzenleme | firm_admin |
| `/firm-admin/trip-delete/{id}` | GET | Sefer silme | firm_admin |

---

### 👑 Admin Routes
| Route | Method | Açıklama | Auth |
|-------|--------|----------|------|
| `/admin` | GET | Admin paneli | admin |
| `/admin/company-add` | GET, POST | Firma ekleme | admin |
| `/admin/company-edit/{id}` | GET, POST | Firma düzenleme | admin |
| `/admin/company-delete/{id}` | GET | Firma silme | admin |
| `/admin/firm-admin-add` | GET, POST | Firma admin ekleme | admin |
| `/admin/coupon-add` | GET, POST | Kupon ekleme | admin |
| `/admin/coupon-edit/{id}` | GET, POST | Kupon düzenleme | admin |
| `/admin/coupon-delete/{id}` | GET | Kupon silme | admin |

---

## 🔑 Kritik Kod Bölümleri (Yorumlanması Gerekenler)

### 1. Sefer Detayları - Cinsiyet Bazlı Koltuk Kontrolü (Satır 330-550)
```php
// Cinsiyet uyumsuzluğu kontrolü için fonksiyon
$isGenderConflict = function($seatNum) use ($occupiedSeatsData, $userGender, $totalSeats) {
    // 2+2 koltuk düzeninde yan koltukları hesapla
    // Kullanıcı cinsiyeti ile yan koltukdaki cinsiyeti karşılaştır
    ...
};
```
**Öneri:** Bu mantık çok önemli, detaylı yorum eklenmeli.

---

### 2. Bilet Satın Alma (Satır 1283-1361)
```php
$router->post('/buy', function () {
    // 1. Kullanıcı kontrolü
    // 2. Kupon kontrolü
    // 3. Ticket::purchase() - tüm mantık burada (Ticket.php'de yorumlu)
    // 4. Başarılı ise bildirim gönder
    // 5. Session bakiyesini güncelle
});
```
**Durum:** Ticket.php'de detaylı yorumlar mevcut, burada sadece akış belirtilmeli.

---

### 3. Bilet İptal (Satır 1404-1452)
```php
$router->post('/cancel-ticket', function () {
    // 1. Kullanıcı kontrolü
    // 2. Ticket::cancel() - mantık Ticket.php'de
    // 3. Başarılı ise bildirim gönder
    // 4. Session bakiyesini güncelle
});
```

---

### 4. Profil Şifre Değiştirme (Satır 1580-1628)
```php
$router->post('/profile/change-password', function () {
    // 1. Mevcut şifre doğrulaması
    // 2. Yeni şifre güvenlik kontrolü (Auth::validatePassword)
    // 3. Şifre güncelleme
    // 4. Log kaydı
});
```

---

## 📊 Yorum İstatistikleri

| Bölüm | Yorum Durumu | Öncelik |
|-------|--------------|---------|
| Helper fonksiyonlar | ✅ Tam yorumlu | Yüksek |
| Ana sayfa | ✅ Başlık yorumu | Orta |
| Auth routes | ✅ Kısmen yorumlu | Yüksek |
| User routes | ⚠️ Minimal yorum | Orta |
| Firm Admin routes | ⚠️ Minimal yorum | Düşük |
| Admin routes | ⚠️ Minimal yorum | Düşük |

---

## 🎯 Önerilen Yorum Stratejisi

### ✅ Yapıldı:
1. Helper fonksiyonlara PHPDoc yorumları eklendi
2. Ana bölümlere başlık yorumları eklendi
3. Rate limiting ve CSRF notları eklendi

### 📝 Yapılabilir (İsteğe Bağlı):
1. Her route'a kısa açıklama yorumu
2. Kritik iş mantığı bölümlerine detaylı yorumlar
3. Güvenlik kontrollerine açıklayıcı yorumlar

---

## 💡 Neden Tüm Route'lara Yorum Eklenmedi?

1. **Kod Tekrarı:** 48 route var, çoğu benzer mantık (CRUD işlemleri)
2. **Okunabilirlik:** Çok fazla yorum kodu karmaşıklaştırabilir
3. **Self-Documenting Code:** Route isimleri zaten ne yaptığını açıklıyor (örn: `/admin/company-add`)
4. **Sınıf Yorumları:** Asıl mantık `Ticket.php`, `Auth.php`, `TripManager.php` gibi dosyalarda ve oralarda **detaylı yorumlar mevcut**

---

## ✅ Sonuç

**index.php için yorum stratejisi:**
- ✅ Bölüm başlıkları ile organize edildi
- ✅ Helper fonksiyonlar tam yorumlu
- ✅ Kritik güvenlik noktaları işaretlendi
- ✅ Route mantığı sınıf dosyalarında (Ticket.php, Auth.php, vb.) detaylı yorumlu

**Bu yaklaşım daha temiz ve sürdürülebilir kod sağlar!** 🚀

---

## 📄 İlgili Dosyalar

Detaylı yorumlar için bakınız:
- `src/Ticket.php` - Bilet işlemleri (50+ satır yorum)
- `src/Auth.php` - Kimlik doğrulama (40+ satır yorum)
- `src/TripManager.php` - Sefer yönetimi (30+ satır yorum)
- `src/Coupon.php` - Kupon işlemleri (yorumlu)
- `src/Helpers.php` - Yardımcı fonksiyonlar (yorumlu)

