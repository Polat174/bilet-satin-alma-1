<?php
declare(strict_types=1);

namespace App;

class Notification
{
    /**
     * Email bildirimi gönderir (simülasyon - gerçekte SMTP kullanılabilir)
     * @param string $to Email adresi
     * @param string $subject Konu
     * @param string $message Mesaj
     * @return bool
     */
    public static function sendEmail(string $to, string $subject, string $message): bool
    {
        // Gerçek uygulamada PHPMailer, Symfony Mailer vb. kullanılır
        // Şimdilik sadece loglayalım
        return self::log('email', $to, $subject, $message);
    }
    
    /**
     * SMS bildirimi gönderir (simülasyon)
     * @param string $phone Telefon numarası
     * @param string $message Mesaj
     * @return bool
     */
    public static function sendSMS(string $phone, string $message): bool
    {
        // Gerçek uygulamada Twilio, Nexmo vb. kullanılır
        return self::log('sms', $phone, '', $message);
    }
    
    /**
     * Bildirim kaydını veritabanına yazar
     */
    private static function log(string $type, string $recipient, string $subject, string $message): bool
    {
        try {
            $pdo = DB::conn();
            
            // notifications tablosunu oluştur (yoksa)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type TEXT NOT NULL,
                    recipient TEXT NOT NULL,
                    subject TEXT,
                    message TEXT NOT NULL,
                    sent_at TEXT NOT NULL,
                    status TEXT DEFAULT 'sent'
                )
            ");
            
            $stmt = $pdo->prepare('INSERT INTO notifications(type, recipient, subject, message, sent_at) VALUES(?,?,?,?,?)');
            $stmt->execute([$type, $recipient, $subject, $message, date('c')]);
            
            return true;
        } catch (\PDOException $e) {
            error_log('Notification error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Bilet satın alma bildirimi gönderir
     */
    public static function ticketPurchased(array $user, array $ticket, array $trip): bool
    {
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $subject = 'Bilet Satın Alma Onayı';
        $message = "Merhaba $name,\n\n";
        $message .= "Biletiniz başarıyla satın alındı.\n\n";
        $message .= "Sefer Detayları:\n";
        $message .= "Kalkış: " . $trip['origin'] . "\n";
        $message .= "Varış: " . $trip['destination'] . "\n";
        $message .= "Tarih: " . $trip['departure_at'] . "\n";
        $message .= "Koltuk: " . $ticket['seat_number'] . "\n";
        $message .= "PNR: " . ($ticket['pnr'] ?? 'N/A') . "\n\n";
        $message .= "İyi yolculuklar dileriz!";
        
        return self::sendEmail($user['email'], $subject, $message);
    }
    
    /**
     * Bilet iptal bildirimi gönderir
     */
    public static function ticketCancelled(array $user, array $ticket, array $trip): bool
    {
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $subject = 'Bilet İptal Onayı';
        $message = "Merhaba $name,\n\n";
        $message .= "Biletiniz başarıyla iptal edildi.\n\n";
        $message .= "İade Tutarı: " . number_format((int)$ticket['price_paid_cents'] / 100, 2, ',', '.') . " TL\n";
        $message .= "İade tutarı cüzdanınıza yatırılmıştır.\n\n";
        $message .= "Teşekkür ederiz.";
        
        return self::sendEmail($user['email'], $subject, $message);
    }
    
    /**
     * Email doğrulama maili gönderir
     */
    public static function emailVerification(string $email, string $token): bool
    {
        $verificationLink = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/verify-email?token=$token";
        
        $subject = 'Email Adresinizi Doğrulayın';
        $message = "Merhaba,\n\n";
        $message .= "Hesabınızı aktifleştirmek için aşağıdaki linke tıklayın:\n\n";
        $message .= $verificationLink . "\n\n";
        $message .= "Bu işlemi siz yapmadıysanız bu maili görmezden gelebilirsiniz.";
        
        return self::sendEmail($email, $subject, $message);
    }
    
    /**
     * Şifre sıfırlama maili gönderir
     */
    public static function passwordReset(string $email, string $token): bool
    {
        $resetLink = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/reset-password?token=$token";
        
        $subject = 'Şifre Sıfırlama Talebi';
        $message = "Merhaba,\n\n";
        $message .= "Şifrenizi sıfırlamak için aşağıdaki linke tıklayın:\n\n";
        $message .= $resetLink . "\n\n";
        $message .= "Bu link 1 saat geçerlidir.\n";
        $message .= "Bu işlemi siz yapmadıysanız bu maili görmezden gelebilirsiniz.";
        
        return self::sendEmail($email, $subject, $message);
    }
}

