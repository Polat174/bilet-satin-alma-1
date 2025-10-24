-- Schema Refactor: Fotoğraftaki yapıya uygun hale getirme
-- Bu migration mevcut verileri koruyarak yapıyı değiştirir

-- 1. User_Coupons tablosu oluştur (fotoğraftaki gibi)
CREATE TABLE IF NOT EXISTS user_coupons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  coupon_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE(user_id, coupon_id)
);

-- 2. Booked_Seats tablosu oluştur (fotoğraftaki gibi)
CREATE TABLE IF NOT EXISTS booked_seats (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL,
  seat_number INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  UNIQUE(ticket_id)
);

-- 3. Mevcut tickets tablosundan user_coupons'a veri aktar
INSERT INTO user_coupons (coupon_id, user_id, created_at)
SELECT DISTINCT coupon_id, user_id, purchased_at
FROM tickets
WHERE coupon_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM user_coupons uc 
    WHERE uc.user_id = tickets.user_id 
    AND uc.coupon_id = tickets.coupon_id
  );

-- 4. Mevcut tickets tablosundan booked_seats'e veri aktar
INSERT INTO booked_seats (ticket_id, seat_number, created_at)
SELECT id, seat_number, purchased_at
FROM tickets
WHERE NOT EXISTS (
  SELECT 1 FROM booked_seats bs WHERE bs.ticket_id = tickets.id
);

-- 5. Users tablosuna full_name ekle (first_name + last_name birleştir)
-- Önce kolonları ekle
ALTER TABLE users ADD COLUMN full_name TEXT;

-- Mevcut first_name ve last_name'den full_name oluştur
UPDATE users 
SET full_name = TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))
WHERE full_name IS NULL OR full_name = '';

-- Boş olanlar için email'den isim üret
UPDATE users 
SET full_name = SUBSTR(email, 1, INSTR(email, '@') - 1)
WHERE full_name IS NULL OR full_name = '' OR full_name = ' ';

-- 6. Users tablosuna balance ekle (credit_cents'den dönüştür)
ALTER TABLE users ADD COLUMN balance INTEGER DEFAULT 800;

-- Mevcut credit_cents'i balance'a aktar (kuruştan TL'ye)
UPDATE users SET balance = credit_cents / 100 WHERE credit_cents > 0;

-- 7. Users tablosuna password ekle (password_hash ile aynı)
ALTER TABLE users ADD COLUMN password TEXT;
UPDATE users SET password = password_hash WHERE password IS NULL;

-- 8. Trips tablosuna eksik alanları ekle
-- Fotoğrafta: company_id, destination_city, arrival_time, departure_time, departure_city, price, capacity
-- Bizde zaten var: company_id, origin (departure_city), destination (destination_city), 
--                 departure_at (departure_time), price_cents (price), seat_count (capacity)
-- Eksik: arrival_time

ALTER TABLE trips ADD COLUMN arrival_time TEXT;

-- Kalkış saatinden 4 saat sonra varış olarak hesapla
UPDATE trips 
SET arrival_time = datetime(departure_at, '+4 hours')
WHERE arrival_time IS NULL;

-- 9. Tickets tablosuna total_price ekle (price_paid_cents yerine TL cinsinden)
ALTER TABLE tickets ADD COLUMN total_price INTEGER;
UPDATE tickets SET total_price = price_paid_cents / 100 WHERE total_price IS NULL;

-- 10. Coupons tablosunda discount alanı ekle (percent ile aynı ama fotoğrafta REAL)
ALTER TABLE coupons ADD COLUMN discount REAL;
UPDATE coupons SET discount = CAST(percent AS REAL) / 100.0 WHERE discount IS NULL;

-- 11. Companies tablosuna logo_path ekle (fotoğrafta TEXT)
ALTER TABLE companies ADD COLUMN logo_path TEXT;

-- İndeksler oluştur (performans için)
CREATE INDEX IF NOT EXISTS idx_user_coupons_user ON user_coupons(user_id);
CREATE INDEX IF NOT EXISTS idx_user_coupons_coupon ON user_coupons(coupon_id);
CREATE INDEX IF NOT EXISTS idx_booked_seats_ticket ON booked_seats(ticket_id);
CREATE INDEX IF NOT EXISTS idx_booked_seats_seat ON booked_seats(seat_number);

