<?php
declare(strict_types=1);

namespace App;

use App\Security;

class Views
{
    public static function layout(string $title, string $content): void
    {
        $user = Auth::user();
        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<link rel="stylesheet" href="/assets/style.css?v=7">';
        echo '</head><body>';
        echo '<div class="nav">';
        echo '<div class="brand"><a href="/" style="color:inherit;text-decoration:none">Bilet Platformu</a></div>';
        echo '<div class="actions">';
        if ($user) {
            echo '<a href="/">Ana Sayfa</a>';
            echo '<a href="/my-tickets">Biletlerim</a>';
            echo '<a href="/profile">Profil</a>';
            echo '<a href="/wallet">Cüzdan</a>';
            if ($user['role'] === 'admin') {
                echo '<a href="/admin">Admin Paneli</a>';
            }
            if ($user['role'] === 'firm_admin') {
                echo '<a href="/firm-admin">Firma Paneli</a>';
            }
            echo '<span class="muted">' . htmlspecialchars($user['email']) . ' · ' . htmlspecialchars($user['role']) . '</span>';
            echo '<a href="/logout">Çıkış</a>';
        } else {
            echo '<a href="/login">Giriş</a>';
            echo '<a href="/register">Kayıt Ol</a>';
        }
        echo '</div></div>';
        echo '<div class="container">';
        echo $content;
        echo '</div>';
        echo '<script src="/assets/autocomplete.js"></script>';
        echo '</body></html>';
    }

    public static function loginForm(string $error = ''): void
    {
        ob_start();
        echo '<div class="card">';
        echo '<h2>Giriş Yap</h2>';
        if ($error) {
            echo '<p class="error">' . htmlspecialchars($error) . '</p>';
        }
        echo '<form method="post" action="/login" class="mt-2">';
        echo Security::csrfField();
        echo '<label>E-posta</label><input name="email" type="email" required />';
        echo '<label class="mt-2">Şifre</label><input name="password" type="password" required />';
        echo '<div class="mt-3"><button type="submit">Giriş</button></div>';
        echo '<div class="mt-2"><a href="/forgot-password" class="muted">Şifremi Unuttum</a></div>';
        echo '</form>';
        echo '</div>';
        self::layout('Giriş', ob_get_clean() ?: '');
    }

    public static function forgotPasswordForm(string $message = '', string $type = 'error'): void
    {
        ob_start();
        echo '<div class="card">';
        echo '<h2>Şifremi Unuttum</h2>';
        echo '<p class="muted">E-posta adresinize şifre sıfırlama linki gönderilecektir.</p>';
        if ($message) {
            echo '<p class="' . ($type === 'success' ? 'success' : 'error') . '">' . htmlspecialchars($message) . '</p>';
        }
        echo '<form method="post" action="/forgot-password" class="mt-2">';
        echo Security::csrfField();
        echo '<label>E-posta</label><input name="email" type="email" required />';
        echo '<div class="mt-3"><button type="submit">Gönder</button></div>';
        echo '<div class="mt-2"><a href="/login">Giriş Sayfasına Dön</a></div>';
        echo '</form>';
        echo '</div>';
        self::layout('Şifremi Unuttum', ob_get_clean() ?: '');
    }

    public static function resetPasswordForm(string $token, string $error = ''): void
    {
        ob_start();
        echo '<div class="card">';
        echo '<h2>Yeni Şifre Belirle</h2>';
        if ($error) {
            echo '<p class="error">' . htmlspecialchars($error) . '</p>';
        }
        echo '<form method="post" action="/reset-password" class="mt-2">';
        echo Security::csrfField();
        echo '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '" />';
        echo '<label>Yeni Şifre</label><input name="password" type="password" minlength="8" required />';
        echo '<p class="muted" style="font-size:12px;margin-top:4px;">En az 8 karakter, 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.</p>';
        echo '<label class="mt-2">Şifre Tekrar</label><input name="password_confirm" type="password" minlength="8" required />';
        echo '<div class="mt-3"><button type="submit">Şifreyi Değiştir</button></div>';
        echo '</form>';
        echo '</div>';
        self::layout('Şifre Sıfırla', ob_get_clean() ?: '');
    }

    public static function registerForm(string $error = ''): void
    {
        ob_start();
        echo '<div class="card">';
        echo '<h2>Kayıt Ol</h2>';
        if ($error) {
            echo '<p class="error">' . htmlspecialchars($error) . '</p>';
        }
        echo '<form method="post" action="/register" class="mt-2">';
        echo Security::csrfField();
        
        echo '<div class="form-row">';
        echo '<div class="form-col"><label>Ad</label><input name="first_name" type="text" required /></div>';
        echo '<div class="form-col"><label>Soyad</label><input name="last_name" type="text" required /></div>';
        echo '</div>';
        
        echo '<label class="mt-2">E-posta</label><input name="email" type="email" required />';
        echo '<label class="mt-2">Şifre</label><input name="password" type="password" minlength="8" required />';
        echo '<p class="muted" style="font-size:12px;margin-top:4px;">En az 8 karakter, 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.</p>';
        
        echo '<div class="form-row mt-2">';
        echo '<div class="form-col"><label>Doğum Tarihi</label><input name="birth_date" type="date" max="' . date('Y-m-d', strtotime('-18 years')) . '" required /></div>';
        echo '<div class="form-col"><label>Cinsiyet</label><select name="gender" required>';
        echo '<option value="">Seçiniz</option>';
        echo '<option value="male">Erkek</option>';
        echo '<option value="female">Kadın</option>';
        echo '</select></div>';
        echo '</div>';
        
        echo '<div class="mt-3"><button type="submit">Kayıt Ol</button></div>';
        echo '</form>';
        echo '</div>';
        self::layout('Kayıt', ob_get_clean() ?: '');
    }
}


