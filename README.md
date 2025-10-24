# 🚌 Modern Otobüs Bilet Satın Alma Sistemi

Modern, güvenli ve kullanıcı dostu otobüs bilet satın alma platformu. PHP 8.2, SQLite ve Docker ile geliştirilmiştir.

## ✨ Özellikler

### 🎨 Modern Kullanıcı Arayüzü
- **3D Koltuk Tasarımı**: Gerçekçi otobüs koltuk görünümü
- **Responsive Tasarım**: Mobil ve masaüstü uyumlu
- **Animasyonlar**: Smooth geçişler ve hover efektleri
- **Modern Renkler**: Gradient ve gölge efektleri

### 🔐 Güvenlik Özellikleri
- **CSRF Koruması**: Form güvenliği
- **Rate Limiting**: Brute force saldırı koruması
- **Session Güvenliği**: Session hijacking koruması
- **Şifre Güvenliği**: Güçlü şifre politikaları
- **Input Validation**: Tüm girdi doğrulama

### 👥 Kullanıcı Yönetimi
- **Kayıt/Giriş Sistemi**: Güvenli kimlik doğrulama
- **Profil Yönetimi**: Kişisel bilgi güncelleme
- **Şifre Sıfırlama**: E-posta ile şifre sıfırlama
- **E-posta Doğrulama**: Hesap aktivasyonu

### 🎫 Bilet Sistemi
- **Dinamik Koltuk Haritası**: Gerçek zamanlı koltuk durumu
- **Cinsiyet Bazlı Koltuk**: Erkek-kadın yan yana oturma engeli
- **Kupon Sistemi**: İndirim kuponları
- **PDF Bilet**: Yazdırılabilir biletler
- **PNR Kodu**: Benzersiz bilet numarası

### 💳 Ödeme Sistemi
- **Cüzdan Sistemi**: Sanal cüzdan
- **Kart Yönetimi**: Güvenli kart bilgileri
- **Ödeme Geçmişi**: İşlem kayıtları

### 📱 Ek Özellikler
- **Şehir Otomatik Tamamlama**: Hızlı şehir seçimi
- **Bildirim Sistemi**: E-posta/SMS simülasyonu
- **Loglama**: Detaylı sistem logları
- **Hata Yönetimi**: Kullanıcı dostu hata mesajları

## 🚀 Kurulum

### Gereksinimler
- Docker & Docker Compose
- Git

### Adım Adım Kurulum

1. **Repository'yi klonlayın:**
```bash
git clone https://github.com/kullaniciadi/bilet-satin-alma.git
cd bilet-satin-alma
```

2. **Docker container'ları başlatın:**
```bash
docker compose up -d --build
```

3. **Veritabanını oluşturun:**
```bash
docker compose exec app php scripts/migrate.php
```

4. **Örnek verileri yükleyin:**
```bash
docker compose exec app php scripts/seed.php
```

5. **Uygulamayı açın:**
- **Ana Uygulama**: http://localhost:8080
- **Sistem Sağlığı**: http://localhost:8080/health

## 🏗️ Proje Yapısı

```
bilet-satin-alma/
├── public/                 # Web root
│   ├── index.php          # Ana uygulama
│   └── assets/            # CSS, JS, resimler
├── src/                   # PHP sınıfları
│   ├── Auth.php           # Kimlik doğrulama
│   ├── Ticket.php         # Bilet işlemleri
│   ├── TripManager.php    # Sefer yönetimi
│   └── ...
├── database/              # Veritabanı
│   └── migrations/        # Schema değişiklikleri
├── scripts/               # Yardımcı scriptler
└── docker-compose.yml     # Docker yapılandırması
```

## 🎯 Kullanım

### Kullanıcı İşlemleri
1. **Kayıt Ol**: Yeni hesap oluştur
2. **Giriş Yap**: Hesabına giriş yap
3. **Profil**: Kişisel bilgileri güncelle
4. **Bilet Al**: Sefer seç ve koltuk al

### Admin İşlemleri
- Sefer ekleme/düzenleme
- Kupon yönetimi
- Sistem logları

## 🔧 Geliştirme

### Yeni Özellik Ekleme
1. Yeni route'ları `public/index.php`'ye ekleyin
2. Gerekli sınıfları `src/` klasörüne ekleyin
3. Veritabanı değişiklikleri için migration oluşturun

### Veritabanı Migration
```bash
# Yeni migration oluştur
docker compose exec app php scripts/migrate.php
```

## 📊 Teknik Detaylar

- **Backend**: PHP 8.2
- **Veritabanı**: SQLite (PDO)
- **Frontend**: HTML5, CSS3, JavaScript
- **Container**: Docker
- **Web Server**: Apache
- **Güvenlik**: CSRF, Rate Limiting, Session Security

## 🤝 Katkıda Bulunma

1. Fork yapın
2. Feature branch oluşturun (`git checkout -b feature/amazing-feature`)
3. Commit yapın (`git commit -m 'Add amazing feature'`)
4. Push yapın (`git push origin feature/amazing-feature`)
5. Pull Request oluşturun

## 📝 Lisans

Bu proje MIT lisansı altında lisanslanmıştır.

## 📞 İletişim

Proje hakkında sorularınız için issue açabilir veya iletişime geçebilirsiniz.

---

**Not**: Bu proje eğitim amaçlı geliştirilmiştir. Üretim ortamında kullanmadan önce güvenlik testlerini yapın.


