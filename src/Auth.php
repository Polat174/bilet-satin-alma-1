<?php
declare(strict_types=1);

namespace App;

class Auth
{
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function validatePassword(string $password): bool|string
    {
        if (strlen($password) < 8) {
            return 'Şifre en az 8 karakter olmalıdır';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Şifre en az bir büyük harf içermelidir';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Şifre en az bir küçük harf içermelidir';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Şifre en az bir rakam içermelidir';
        }
        return true;
    }

    public static function register(
        string $email, 
        string $password, 
        string $firstName,
        string $lastName,
        string $birthDate,
        string $gender, 
        string $role = 'user'
    ): bool|string {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Geçersiz e-posta adresi';
        }
        
        // Şifre güvenlik kontrolü
        $passwordCheck = self::validatePassword($password);
        if ($passwordCheck !== true) {
            return $passwordCheck;
        }
        
        if (!in_array($role, ['user', 'firm_admin', 'admin'], true)) {
            return 'Geçersiz rol';
        }
        if (!in_array($gender, ['male', 'female'], true)) {
            return 'Geçersiz cinsiyet';
        }
        if (empty(trim($firstName)) || empty(trim($lastName))) {
            return 'İsim ve soyisim zorunludur';
        }
        // Doğum tarihi kontrolü
        $birthDateTime = \DateTime::createFromFormat('Y-m-d', $birthDate);
        if (!$birthDateTime || $birthDateTime->format('Y-m-d') !== $birthDate) {
            return 'Geçersiz doğum tarihi formatı';
        }
        $age = (new \DateTime())->diff($birthDateTime)->y;
        if ($age < 18) {
            return 'En az 18 yaşında olmalısınız';
        }
        
        $pdo = DB::conn();
        
        // YENİ: full_name oluştur (fotoğraftaki yapı için)
        // İsim ve soyisim birleştirilerek tam isim oluşturuluyor
        $fullName = trim($firstName . ' ' . $lastName);
        $hashedPassword = self::hashPassword($password);
        
        // YENİ KOLONLAR:
        // - full_name: İsim + Soyisim birleşik (fotoğraftaki yapı)
        // - password: password_hash ile aynı (fotoğraftaki yapı - geriye uyumluluk)
        // - balance: Başlangıç bakiyesi 800 TL (fotoğraftaki yapı)
        // ESKİ KOLONLAR: email, password_hash, first_name, last_name, birth_date, gender, role, credit_cents
        $stmt = $pdo->prepare('INSERT INTO users(email, password_hash, password, first_name, last_name, full_name, birth_date, gender, role, credit_cents, balance, created_at) VALUES(:e,:ph,:p,:fn,:ln,:fullname,:bd,:g,:r,0,800,:t)');
        try {
            return $stmt->execute([
                ':e' => $email,
                ':ph' => $hashedPassword,
                ':p' => $hashedPassword, // Fotoğraftaki yapı için password kolonu (hash ile aynı)
                ':fn' => trim($firstName),
                ':ln' => trim($lastName),
                ':fullname' => $fullName, // Fotoğraftaki yapı için full_name kolonu
                ':bd' => $birthDate,
                ':g' => $gender,
                ':r' => $role,
                ':t' => date('c'),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'Bu e-posta ile bir hesap zaten var';
            }
            return 'Kayıt hatası: ' . $e->getMessage();
        }
    }

    public static function login(string $email, string $password): bool|string
    {
        $email = trim(strtolower($email));
        $pdo = DB::conn();
        
        // YENİ: full_name ve balance kolonları da getiriliyor (fotoğraftaki yapı)
        // ESKİ: Sadece temel kullanıcı bilgileri getiriliyordu
        $stmt = $pdo->prepare('SELECT id, email, password_hash, role, credit_cents, balance, gender, first_name, last_name, full_name, birth_date FROM users WHERE email = :e');
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$user || !self::verifyPassword($password, $user['password_hash'])) {
            return 'E-posta veya şifre hatalı';
        }
        
        // Session'a kullanıcı bilgilerini kaydet
        // YENİ ALANLAR: full_name (tam isim), balance (TL cinsinden bakiye)
        // NOT: Hem credit_cents hem balance tutuluyor (geriye uyumluluk)
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'credit_cents' => (int)$user['credit_cents'], // Kuruş cinsinden (mevcut sistem)
            'balance' => (int)($user['balance'] ?? 800), // TL cinsinden (fotoğraftaki yapı)
            'gender' => $user['gender'] ?? 'male',
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'full_name' => $user['full_name'] ?? '', // Fotoğraftaki yapı için
            'birth_date' => $user['birth_date'] ?? '',
        ];
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function isRole(string $role): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === $role;
    }

    /**
     * Email doğrulama token'ı oluştur ve gönder
     */
    public static function sendEmailVerification(string $email): bool|string
    {
        $pdo = DB::conn();
        
        // email_verifications tablosunu oluştur (yoksa)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS email_verifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                token TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                used INTEGER DEFAULT 0
            )
        ");
        
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('c', time() + 3600); // 1 saat geçerli
        
        try {
            $stmt = $pdo->prepare('INSERT INTO email_verifications(email, token, expires_at, created_at) VALUES(?, ?, ?, ?)');
            $stmt->execute([$email, $token, $expiresAt, date('c')]);
            
            // Email gönder
            Notification::emailVerification($email, $token);
            
            return true;
        } catch (\PDOException $e) {
            return 'Email doğrulama gönderilemedi';
        }
    }

    /**
     * Email doğrulama token'ını kontrol et ve aktive et
     */
    public static function verifyEmail(string $token): bool|string
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM email_verifications WHERE token = ? AND used = 0');
        $stmt->execute([$token]);
        $verification = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$verification) {
            return 'Geçersiz doğrulama linki';
        }
        
        if (strtotime($verification['expires_at']) < time()) {
            return 'Doğrulama linkinin süresi dolmuş';
        }
        
        // Kullanıcıyı aktive et (email_verified kolonu eklenebilir)
        $pdo->prepare('UPDATE email_verifications SET used = 1 WHERE id = ?')->execute([$verification['id']]);
        
        // users tablosunda email_verified alanı varsa güncelle
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0");
        $pdo->prepare('UPDATE users SET email_verified = 1 WHERE email = ?')->execute([$verification['email']]);
        
        return true;
    }

    /**
     * Şifre sıfırlama token'ı oluştur ve gönder
     */
    public static function sendPasswordReset(string $email): bool|string
    {
        $pdo = DB::conn();
        
        // Email kontrolü
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if (!$stmt->fetch()) {
            return 'Bu e-posta adresi kayıtlı değil';
        }
        
        // password_resets tablosunu oluştur (yoksa)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                token TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                used INTEGER DEFAULT 0
            )
        ");
        
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('c', time() + 3600); // 1 saat geçerli
        
        try {
            $stmt = $pdo->prepare('INSERT INTO password_resets(email, token, expires_at, created_at) VALUES(?, ?, ?, ?)');
            $stmt->execute([$email, $token, $expiresAt, date('c')]);
            
            // Email gönder
            Notification::passwordReset($email, $token);
            
            return true;
        } catch (\PDOException $e) {
            return 'Şifre sıfırlama linki gönderilemedi';
        }
    }

    /**
     * Şifre sıfırlama token'ını kontrol et ve şifreyi değiştir
     */
    public static function resetPassword(string $token, string $newPassword): bool|string
    {
        // Şifre kontrolü
        $passwordCheck = self::validatePassword($newPassword);
        if ($passwordCheck !== true) {
            return $passwordCheck;
        }
        
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token = ? AND used = 0');
        $stmt->execute([$token]);
        $reset = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$reset) {
            return 'Geçersiz şifre sıfırlama linki';
        }
        
        if (strtotime($reset['expires_at']) < time()) {
            return 'Şifre sıfırlama linkinin süresi dolmuş';
        }
        
        // Şifreyi güncelle
        $hashedPassword = self::hashPassword($newPassword);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')->execute([$hashedPassword, $reset['email']]);
        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')->execute([$reset['id']]);
        
        return true;
    }

    public static function updateProfile(int $userId, array $data): bool|string
    {
        $allowedFields = ['first_name', 'last_name', 'birth_date', 'gender'];
        $updates = [];
        $params = [':id' => $userId];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $value = trim($data[$field]);
                
                // Validasyon
                if ($field === 'gender' && !in_array($value, ['male', 'female'], true)) {
                    return 'Geçersiz cinsiyet';
                }
                if (($field === 'first_name' || $field === 'last_name') && empty($value)) {
                    return ucfirst(str_replace('_', ' ', $field)) . ' boş olamaz';
                }
                if ($field === 'birth_date') {
                    $birthDateTime = \DateTime::createFromFormat('Y-m-d', $value);
                    if (!$birthDateTime || $birthDateTime->format('Y-m-d') !== $value) {
                        return 'Geçersiz doğum tarihi formatı';
                    }
                    $age = (new \DateTime())->diff($birthDateTime)->y;
                    if ($age < 18) {
                        return 'En az 18 yaşında olmalısınız';
                    }
                }
                
                $updates[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }
        
        if (empty($updates)) {
            return 'Güncellenecek bilgi yok';
        }
        
        $pdo = DB::conn();
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        
        try {
            $stmt->execute($params);
            
            // Session'ı güncelle
            if (isset($_SESSION['user']) && $_SESSION['user']['id'] === $userId) {
                foreach ($allowedFields as $field) {
                    if (isset($data[$field])) {
                        $_SESSION['user'][$field] = trim($data[$field]);
                    }
                }
            }
            
            return true;
        } catch (\PDOException $e) {
            return 'Profil güncelleme hatası: ' . $e->getMessage();
        }
    }
}


