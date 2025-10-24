<?php
declare(strict_types=1);

use App\Router;
use App\Views;
use App\Auth;
use App\Trips;
use App\Ticket;
use App\Company;
use App\TripManager;
use App\Coupon;
use App\PDFGenerator;
use App\Payments;
use App\DB;
use App\Security;
use App\Tickets;
use App\RateLimiter;
use App\Notification;
use App\Logger;
use App\Helpers;

// Güvenlik başlıkları
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

// ============================================================
// YARDIMCI FONKSİYONLAR (Helper Functions)
// ============================================================

/**
 * clean() - XSS koruması için girdi temizleme
 * @param string $input Temizlenecek girdi
 * @return string Güvenli hale getirilmiş girdi
 */
function clean($input) { 
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8'); 
}

/**
 * auth() - Kullanıcı yetkisi kontrol eder, yoksa login'e yönlendirir
 * @param string|null $role Gerekli rol (null = sadece login kontrolü)
 * @return array Kullanıcı bilgileri
 */
function auth($role = null) { 
    $user = Auth::user();
    if (!$user || ($role && $user['role'] !== $role)) {
        header('Location: /' . ($user ? '' : 'login'));
        exit;
    }
    return $user;
}

/**
 * csrf() - CSRF token kontrolü yapar
 * @return bool Token geçerli mi?
 */
function csrf() { 
    return Security::validateCsrf($_POST['csrf_token'] ?? ''); 
}

$router = new Router();

// ============================================================
// ANA SAYFA & SEFER ARAMA (Homepage & Trip Search)
// ============================================================

/**
 * Ana Sayfa - Sefer arama formu ve sonuçlar
 * GET / 
 * Query params: origin, destination, date
 */
$router->get('/', function () {
    $origin = clean($_GET['origin'] ?? '');
    $destination = clean($_GET['destination'] ?? '');
    $date = clean($_GET['date'] ?? '');

    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    if ($date === '') {
        // Find the next available trip date >= now
        $stmt = DB::conn()->query("SELECT substr(departure_at,1,10) AS d FROM trips WHERE departure_at >= datetime('now') GROUP BY d ORDER BY d ASC LIMIT 1");
        $next = (string)($stmt->fetchColumn() ?: $today);
        $dateInput = $next;      // shown in the input
        $dateFilter = $next;     // used for initial search so results appear
    } else {
        $dateInput = $date;
        $dateFilter = $date;
    }

    $trips = Trips::search(['origin' => $origin, 'destination' => $destination, 'date' => $dateFilter]);
    $user = Auth::user();

    ob_start();
    echo '<div class="card">';
    echo '<h1>Sefer Ara</h1>';
    echo '<p class="muted">Kalkış, varış ve tarih ile arama yap.</p>';
    echo '<form method="get" class="row">';
    $cities = file_get_contents(__DIR__ . '/assets/cities-tr.html');
    echo '<div><label>Kalkış</label><select name="origin">';
    echo '<option value="">Seçiniz</option>' . $cities . '</select></div>';
    echo '<div><label>Varış</label><select name="destination">';
    echo '<option value="">Seçiniz</option>' . $cities . '</select></div>';
    echo '<div><label>Tarih</label><input type="date" name="date" min="' . htmlspecialchars($today) . '" value="' . htmlspecialchars($dateInput) . '" /></div>';
    echo '<div class="mt-3"><button type="submit">Ara</button></div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="card mt-4">';
    echo '<h2>Sonuçlar</h2>';
    if (empty($trips)) {
        echo '<p class="muted">Kriterlere uygun sefer bulunamadı.</p>';
    } else {
        echo '<table class="mt-2">';
        echo '<thead><tr><th>Firma</th><th>Kalkış</th><th>Varış</th><th>Tarih/Saat</th><th>Fiyat</th><th>Koltuk</th>';
        $user = Auth::user();
        if ($user && $user['role'] === 'user') {
            echo '<th></th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($trips as $t) {
            $when = htmlspecialchars((new DateTimeImmutable($t['departure_at']))->format('d.m.Y H:i'));
            $price = number_format((int)$t['price_cents'] / 100, 2, ',', '.') . ' TL';
            echo '<tr>';
            echo '<td>' . htmlspecialchars($t['company_name']) . '</td>';
            echo '<td>' . htmlspecialchars($t['origin']) . '</td>';
            echo '<td>' . htmlspecialchars($t['destination']) . '</td>';
            echo '<td>' . $when . '</td>';
            echo '<td>' . $price . '</td>';
            echo '<td>' . (int)$t['seat_count'] . '</td>';
            if ($user && $user['role'] === 'user') {
                echo '<td>';
                echo '<a href="/trip-details/' . (int)$t['id'] . '" class="btn">Detaylar</a> ';
                echo '<form method="post" action="/buy" style="display:inline">';
                echo Security::csrfField();
                echo '<input type="hidden" name="trip_id" value="' . (int)$t['id'] . '" />';
                $availableSeats = App\TripManager::getAvailableSeats((int)$t['id']);
                $totalSeats = (int)$t['seat_count'];
                echo '<select name="seat_number" required>';
                echo '<option value="">Koltuk Seç</option>';
                for ($seat = 1; $seat <= $totalSeats; $seat++) {
                    $isAvailable = in_array($seat, $availableSeats);
                    $disabled = $isAvailable ? '' : ' disabled';
                    $label = $isAvailable ? "Koltuk $seat" : "Koltuk $seat (Dolu)";
                    echo '<option value="' . $seat . '"' . $disabled . '>' . $label . '</option>';
                }
                echo '</select>';
                echo '<input type="text" name="coupon_code" placeholder="Kupon Kodu" style="width:80px" />';
                echo '<button type="submit">Satın Al</button>';
                echo '</form>';
                echo '</td>';
            } else {
                echo '<td><a href="/trip-details/' . (int)$t['id'] . '" class="btn">Detaylar</a></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    if ($user && $user['role'] === 'user') {
        echo '<div class="mt-4">';
        echo '<a href="/my-tickets">Biletlerim</a> · ';
        echo '<a href="/profile">Profil</a> · ';
        echo '<a href="/wallet">Cüzdan</a>';
        echo '</div>';
    }

    Views::layout('Ana Sayfa', ob_get_clean() ?: '');
});

$router->get('/health', function () {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
});

// ============================================================
// AUTH İŞLEMLERİ (Authentication & Authorization)
// ============================================================

/**
 * Login Sayfası Göster
 * GET /login
 */
$router->get('/login', function () {
    $msg = Helpers::cleanGet('msg');
    $error = Helpers::cleanGet('error');
    
    $successMessages = [
        'password-reset-success' => 'Şifreniz başarıyla değiştirildi. Giriş yapabilirsiniz.',
        'email-verified' => 'E-posta adresiniz doğrulandı. Giriş yapabilirsiniz.'
    ];
    
    $success = $successMessages[$msg] ?? '';
    Views::loginForm($error, $success);
});

/**
 * Login İşlemi
 * POST /login
 * Rate Limit: 10 istek/dakika (IP bazlı)
 * CSRF Koruması: Aktif
 */
$router->post('/login', function () {
    // Rate limiting - IP bazlı (10 istek / dakika)
    $ip = RateLimiter::getClientIP();
    if (!RateLimiter::checkDB("login:$ip", 10, 60)) {
        Views::loginForm('Çok fazla giriş denemesi yaptınız. Lütfen 1 dakika bekleyin.');
        return;
    }
    
    if (!csrf()) { header('Location: /login?error=Güvenlik hatası'); return; }
    
    $email = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $result = Auth::login($email, $password);
    if ($result === true) {
        header('Location: /');
    } else {
        Views::loginForm(is_string($result) ? $result : 'Giriş başarısız');
    }
});

$router->get('/register', function () {
    Views::registerForm();
});

$router->post('/register', function () {
    // Rate limiting - IP bazlı (5 kayıt / saat)
    $ip = RateLimiter::getClientIP();
    if (!RateLimiter::checkDB("register:$ip", 5, 3600)) {
        Views::registerForm('Çok fazla kayıt denemesi yaptınız. Lütfen daha sonra tekrar deneyin.');
        return;
    }
    
    if (!csrf()) { header('Location: /register?error=Güvenlik hatası'); return; }
    
    $email = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $firstName = clean($_POST['first_name'] ?? '');
    $lastName = clean($_POST['last_name'] ?? '');
    $birthDate = clean($_POST['birth_date'] ?? '');
    $gender = clean($_POST['gender'] ?? '');
    
    $result = Auth::register($email, $password, $firstName, $lastName, $birthDate, $gender, 'user');
    if ($result === true) {
        Auth::login($email, $password);
        header('Location: /');
    } else {
        Views::registerForm(is_string($result) ? $result : 'Kayıt başarısız');
    }
});

$router->get('/logout', function () {
    Auth::logout();
    header('Location: /');
});

/**
 * Şifremi Unuttum Sayfası
 * GET /forgot-password
 */
$router->get('/forgot-password', function () {
    Views::forgotPasswordForm();
});

// Şifremi Unuttum - İşlem
$router->post('/forgot-password', function () {
    if (!csrf()) { header('Location: /forgot-password?error=Güvenlik hatası'); return; }
    
    $email = clean($_POST['email'] ?? '');
    $result = Auth::sendPasswordReset($email);
    
    if ($result === true) {
        Views::forgotPasswordForm('Şifre sıfırlama linki e-posta adresinize gönderildi.', 'success');
    } else {
        Views::forgotPasswordForm((string)$result, 'error');
    }
});

// Şifre Sıfırlama - Form
$router->get('/reset-password', function () {
    $token = clean($_GET['token'] ?? '');
    if (empty($token)) {
        header('Location: /login');
        return;
    }
    Views::resetPasswordForm($token);
});

// Şifre Sıfırlama - İşlem
$router->post('/reset-password', function () {
    if (!csrf()) { header('Location: /login?error=Güvenlik hatası'); return; }
    
    $token = clean($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    if ($password !== $passwordConfirm) {
        Views::resetPasswordForm($token, 'Şifreler eşleşmiyor');
        return;
    }
    
    $result = Auth::resetPassword($token, $password);
    
    if ($result === true) {
        header('Location: /login?msg=password-reset-success');
    } else {
        Views::resetPasswordForm($token, (string)$result);
    }
});

// Email Doğrulama
$router->get('/verify-email', function () {
    $token = clean($_GET['token'] ?? '');
    
    if (empty($token)) {
        header('Location: /');
        return;
    }
    
    $result = Auth::verifyEmail($token);
    
    if ($result === true) {
        header('Location: /login?msg=email-verified');
    } else {
        header('Location: /login?error=' . urlencode((string)$result));
    }
});

// Sefer detayları sayfası
$router->get('/trip-details/{id}', function ($id) {
    $trip = TripManager::getById((int)$id);
    if (!$trip) {
        header('Location: /?error=Sefer bulunamadı');
        return;
    }
    
    $company = Company::getById($trip['company_id']);
    $user = Auth::user();
    $availableSeats = TripManager::getAvailableSeats((int)$id);
    $totalSeats = (int)$trip['seat_count'];
    $occupiedSeats = $totalSeats - count($availableSeats);
    
    // Dolu koltukların cinsiyet bilgisini al
    $stmt = DB::conn()->prepare('SELECT seat_number, passenger_gender FROM tickets WHERE trip_id = ? AND status = "active"');
    $stmt->execute([(int)$id]);
    $occupiedSeatsData = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
    
    // Kullanıcının cinsiyetini al (giriş yapmışsa)
    $userGender = $user['gender'] ?? null;
    
    // Cinsiyet uyumsuzluğu kontrolü için fonksiyon
    $isGenderConflict = function($seatNum) use ($occupiedSeatsData, $userGender, $totalSeats) {
        if (!$userGender) return false;
        
        // Yan koltukları hesapla (2+2 düzen)
        $adjacentSeats = [];
        if ($seatNum % 2 == 1) {
            $adjacentSeats[] = $seatNum + 1; // Sağındaki
        } else {
            $adjacentSeats[] = $seatNum - 1; // Solundaki
        }
        
        foreach ($adjacentSeats as $adjSeat) {
            if ($adjSeat < 1 || $adjSeat > $totalSeats) continue;
            $adjGender = $occupiedSeatsData[$adjSeat] ?? null;
            if ($adjGender && $adjGender !== $userGender) {
                return true; // Cinsiyet uyumsuzluğu var
            }
        }
        return false;
    };
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Sefer Detayları</h2>';
    echo '<div class="trip-info">';
    echo '<div class="info-row">';
    echo '<span class="label">Firma:</span>';
    echo '<span class="value">' . htmlspecialchars($company['name']) . '</span>';
    echo '</div>';
    echo '<div class="info-row">';
    echo '<span class="label">Güzergah:</span>';
    echo '<span class="value">' . htmlspecialchars($trip['origin']) . ' → ' . htmlspecialchars($trip['destination']) . '</span>';
    echo '</div>';
    echo '<div class="info-row">';
    echo '<span class="label">Kalkış Tarihi:</span>';
    echo '<span class="value">' . htmlspecialchars((new DateTimeImmutable($trip['departure_at']))->format('d.m.Y H:i')) . '</span>';
    echo '</div>';
    echo '<div class="info-row">';
    echo '<span class="label">Fiyat:</span>';
    echo '<span class="value price">' . number_format((int)$trip['price_cents'] / 100, 2, ',', '.') . ' TL</span>';
    echo '</div>';
    echo '<div class="info-row">';
    echo '<span class="label">Koltuk Durumu:</span>';
    echo '<span class="value">' . $occupiedSeats . ' / ' . $totalSeats . ' dolu</span>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Koltuk haritası - Bilet.com tarzı (yan yana düzen)
    echo '<div class="card mt-4">';
    echo '<h3>Koltuk Seçimi</h3>';
    echo '<div class="bus-layout">';
    echo '<div class="bus-outline">';
    echo '<div class="bus-front">ÖN</div>';
    
    // Her sıra için yan yana koltuklar
    $rowsCount = (int)ceil($totalSeats / 4);
    for ($row = 1; $row <= $rowsCount; $row++) {
        echo '<div class="seat-row">';
        
        // Sol koltuklar (1-2)
        for ($col = 1; $col <= 2; $col++) {
            $seatNum = ($row - 1) * 4 + $col;
            if ($seatNum <= $totalSeats) {
                $isAvailable = in_array($seatNum, $availableSeats);
                $hasConflict = $isAvailable && $isGenderConflict($seatNum);
                
                if ($isAvailable) {
                    $seatClass = $hasConflict ? 'seat-available seat-gender-conflict' : 'seat-available';
                    $genderSymbol = '';
                } else {
                    $gender = $occupiedSeatsData[$seatNum] ?? null;
                    $seatClass = $gender === 'male' ? 'seat-occupied-male' : ($gender === 'female' ? 'seat-occupied-female' : 'seat-occupied');
                    $genderSymbol = $gender === 'male' ? '♂' : ($gender === 'female' ? '♀' : '');
                }
                echo '<div class="seat ' . $seatClass . '" data-seat="' . $seatNum . '" ' . ($hasConflict ? 'title="Yanında farklı cinsiyetten yolcu var"' : '') . '>';
                echo '<span class="seat-number">' . $seatNum . '</span>';
                if ($genderSymbol) echo '<span class="gender-symbol">' . $genderSymbol . '</span>';
                echo '</div>';
            } else {
                echo '<div class="seat-empty"></div>';
            }
        }
        
        // Koridor
        echo '<div class="aisle"></div>';
        
        // Sağ koltuklar (3-4)
        for ($col = 3; $col <= 4; $col++) {
            $seatNum = ($row - 1) * 4 + $col;
            if ($seatNum <= $totalSeats) {
                $isAvailable = in_array($seatNum, $availableSeats);
                $hasConflict = $isAvailable && $isGenderConflict($seatNum);
                
                if ($isAvailable) {
                    $seatClass = $hasConflict ? 'seat-available seat-gender-conflict' : 'seat-available';
                    $genderSymbol = '';
                } else {
                    $gender = $occupiedSeatsData[$seatNum] ?? null;
                    $seatClass = $gender === 'male' ? 'seat-occupied-male' : ($gender === 'female' ? 'seat-occupied-female' : 'seat-occupied');
                    $genderSymbol = $gender === 'male' ? '♂' : ($gender === 'female' ? '♀' : '');
                }
                echo '<div class="seat ' . $seatClass . '" data-seat="' . $seatNum . '" ' . ($hasConflict ? 'title="Yanında farklı cinsiyetten yolcu var"' : '') . '>';
                echo '<span class="seat-number">' . $seatNum . '</span>';
                if ($genderSymbol) echo '<span class="gender-symbol">' . $genderSymbol . '</span>';
                echo '</div>';
            } else {
                echo '<div class="seat-empty"></div>';
            }
        }
        
        echo '</div>'; // seat-row sonu
    }
    
    echo '<div class="bus-back">ARKA</div>';
    echo '</div>'; // bus-outline sonu
    echo '</div>'; // bus-layout sonu
    
    echo '<div class="seat-legend">';
    echo '<div class="legend-item"><div class="legend-color available"></div> Boş Koltuk</div>';
    echo '<div class="legend-item"><div class="legend-color occupied"></div> Dolu (Genel)</div>';
    echo '<div class="legend-item"><div class="legend-color male"></div> Dolu - Erkek ♂</div>';
    echo '<div class="legend-item"><div class="legend-color female"></div> Dolu - Kadın ♀</div>';
    echo '<div class="legend-item"><div class="legend-color selected"></div> Seçilen Koltuk</div>';
    echo '</div>';
    echo '</div>';
    
    // Bilet satın alma (sadece giriş yapmış kullanıcılar için)
    if ($user && $user['role'] === 'user') {
        echo '<div class="card mt-4">';
        echo '<h3>Bilet Satın Al</h3>';
        echo '<form method="post" action="/buy">';
        echo Security::csrfField();
        echo '<input type="hidden" name="trip_id" value="' . (int)$id . '" />';
        echo '<label>Koltuk Seçimi</label>';
        echo '<select name="seat_number" id="seat-select" required>';
        echo '<option value="">Koltuk Seçiniz</option>';
        foreach ($availableSeats as $seat) {
            echo '<option value="' . $seat . '">Koltuk ' . $seat . '</option>';
        }
        echo '</select>';
        echo '<p class="seat-hint">💡 Koltuk haritasından da seçebilirsiniz!</p>';
        echo '<label class="mt-2">Kupon Kodu (İsteğe bağlı)</label>';
        echo '<input type="text" name="coupon_code" placeholder="Kupon kodu girin" />';
        echo '<div class="mt-3">';
        echo '<button type="submit" class="btn-large">Bilet Satın Al</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    } else {
        echo '<div class="card mt-4">';
        echo '<div class="info-box">';
        echo '<p>Bilet satın almak için <a href="/login">giriş yapın</a> veya <a href="/register">kayıt olun</a>.</p>';
        echo '</div>';
        echo '</div>';
    }
    
    echo '<div class="mt-4">';
    echo '<a href="/" class="btn-secondary">← Ana Sayfaya Dön</a>';
    echo '</div>';
    
    // JavaScript for seat selection
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        const seatSelect = document.getElementById("seat-select");
        const seats = document.querySelectorAll(".seat-available");
        
        // Koltuk haritasından seçim
        seats.forEach(seat => {
            seat.addEventListener("click", function() {
                const seatNum = this.dataset.seat;
                seatSelect.value = seatNum;
                
                // Önceki seçimi temizle
                document.querySelectorAll(".seat-selected").forEach(s => {
                    s.classList.remove("seat-selected");
                    s.classList.add("seat-available");
                });
                
                // Yeni seçimi işaretle
                this.classList.remove("seat-available");
                this.classList.add("seat-selected");
            });
        });
        
        // Select\'ten seçim
        seatSelect.addEventListener("change", function() {
            const selectedSeat = this.value;
            
            // Önceki seçimi temizle
            document.querySelectorAll(".seat-selected").forEach(s => {
                s.classList.remove("seat-selected");
                s.classList.add("seat-available");
            });
            
            // Yeni seçimi işaretle
            if (selectedSeat) {
                const seatElement = document.querySelector(`[data-seat="${selectedSeat}"]`);
                if (seatElement) {
                    seatElement.classList.remove("seat-available");
                    seatElement.classList.add("seat-selected");
                }
            }
        });
    });
    </script>';
    
    Views::layout('Sefer Detayları', ob_get_clean() ?: '');
});

// Firma Admin Panel Routes
$router->get('/firm-admin', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    $company = Company::getUserCompany($user['id']);
    if (!$company) {
        echo 'Firma bilginiz bulunamadı';
        return;
    }
    $trips = TripManager::listByCompany($company['id']);
    
    // Hata/başarı mesajları
    $msg = $_GET['msg'] ?? '';
    $error = $_GET['error'] ?? '';
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Firma Paneli - ' . htmlspecialchars($company['name']) . '</h2>';
    if ($msg === 'success') {
        echo '<div class="success">İşlem başarıyla tamamlandı!</div>';
    }
    if ($error) {
        echo '<div class="error">Hata: ' . htmlspecialchars($error) . '</div>';
    }
    echo '<div class="actions">';
    echo '<a href="/firm-admin/trip-add" class="btn">Yeni Sefer Ekle</a>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="card mt-4">';
    echo '<h3>Seferlerim</h3>';
    if (empty($trips)) {
        echo '<p class="muted">Henüz sefer eklenmemiş.</p>';
    } else {
        echo '<table><thead><tr><th>Kalkış</th><th>Varış</th><th>Tarih/Saat</th><th>Fiyat</th><th>Koltuk</th><th>İşlemler</th></tr></thead><tbody>';
        foreach ($trips as $t) {
            $when = htmlspecialchars((new DateTimeImmutable($t['departure_at']))->format('d.m.Y H:i'));
            $price = number_format((int)$t['price_cents'] / 100, 2, ',', '.') . ' TL';
            echo '<tr>';
            echo '<td>' . htmlspecialchars($t['origin']) . '</td>';
            echo '<td>' . htmlspecialchars($t['destination']) . '</td>';
            echo '<td>' . $when . '</td>';
            echo '<td>' . $price . '</td>';
            echo '<td>' . (int)$t['seat_count'] . '</td>';
            echo '<td>';
            echo '<a href="/firm-admin/trip-edit/' . (int)$t['id'] . '">Düzenle</a> | ';
            echo '<a href="/firm-admin/trip-delete/' . (int)$t['id'] . '" onclick="return confirm(\'Emin misiniz?\')">Sil</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    
    Views::layout('Firma Paneli', ob_get_clean() ?: '');
});

$router->get('/firm-admin/trip-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    $company = Company::getUserCompany($user['id']);
    if (!$company) {
        echo 'Firma bilginiz bulunamadı';
        return;
    }
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Yeni Sefer Ekle</h2>';
    echo '<form method="post" action="/firm-admin/trip-add">';
    echo '<label>Kalkış Şehri</label><input name="origin" type="text" required />';
    echo '<label class="mt-2">Varış Şehri</label><input name="destination" type="text" required />';
    echo '<label class="mt-2">Kalkış Tarihi ve Saati</label><input name="departure_at" type="datetime-local" required />';
    echo '<label class="mt-2">Fiyat (Kuruş)</label><input name="price_cents" type="number" min="1" required />';
    echo '<label class="mt-2">Koltuk Sayısı</label><input name="seat_count" type="number" min="1" required />';
    echo '<div class="mt-3"><button type="submit">Ekle</button></div>';
    echo '</form>';
    echo '</div>';
    
    Views::layout('Yeni Sefer', ob_get_clean() ?: '');
});

$router->post('/firm-admin/trip-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    $company = Company::getUserCompany($user['id']);
    if (!$company) {
        header('Location: /firm-admin?error=Firma bilginiz bulunamadı');
        return;
    }
    
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $departureAt = $_POST['departure_at'] ?? '';
    $priceCents = (int)($_POST['price_cents'] ?? 0);
    $seatCount = (int)($_POST['seat_count'] ?? 0);
    
    $result = TripManager::create($company['id'], $origin, $destination, $departureAt, $priceCents, $seatCount);
    if ($result === true) {
        header('Location: /firm-admin?msg=success');
    } else {
        header('Location: /firm-admin?error=' . urlencode((string)$result));
    }
});

// Firma Admin Trip Edit/Delete
$router->get('/firm-admin/trip-edit/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    $company = Company::getUserCompany($user['id']);
    if (!$company) {
        header('Location: /firm-admin?error=Firma bilginiz bulunamadı');
        return;
    }
    
    $trip = TripManager::getById((int)$id);
    if (!$trip || $trip['company_id'] != $company['id']) {
        header('Location: /firm-admin?error=Sefer bulunamadı');
        return;
    }
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Sefer Düzenle</h2>';
    echo '<form method="post" action="/firm-admin/trip-edit/' . (int)$id . '">';
    echo Security::csrfField();
    echo '<label>Kalkış Şehri</label><input name="origin" type="text" value="' . htmlspecialchars($trip['origin']) . '" required />';
    echo '<label class="mt-2">Varış Şehri</label><input name="destination" type="text" value="' . htmlspecialchars($trip['destination']) . '" required />';
    echo '<label class="mt-2">Kalkış Tarihi ve Saati</label><input name="departure_at" type="datetime-local" value="' . htmlspecialchars(substr($trip['departure_at'], 0, 16)) . '" required />';
    echo '<label class="mt-2">Fiyat (Kuruş)</label><input name="price_cents" type="number" min="1" value="' . (int)$trip['price_cents'] . '" required />';
    echo '<label class="mt-2">Koltuk Sayısı</label><input name="seat_count" type="number" min="1" value="' . (int)$trip['seat_count'] . '" required />';
    echo '<div class="mt-3"><button type="submit">Güncelle</button></div>';
    echo '</form>';
    echo '</div>';
    
    Views::layout('Sefer Düzenle', ob_get_clean() ?: '');
});

$router->post('/firm-admin/trip-edit/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    $company = Company::getUserCompany($user['id']);
    if (!$company) {
        header('Location: /firm-admin?error=Firma bilginiz bulunamadı');
        return;
    }
    
    $trip = TripManager::getById((int)$id);
    if (!$trip || $trip['company_id'] != $company['id']) {
        header('Location: /firm-admin?error=Sefer bulunamadı');
        return;
    }
    
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $departureAt = $_POST['departure_at'] ?? '';
    $priceCents = (int)($_POST['price_cents'] ?? 0);
    $seatCount = (int)($_POST['seat_count'] ?? 0);
    
    $result = TripManager::update((int)$id, $origin, $destination, $departureAt, $priceCents, $seatCount);
    if ($result === true) {
        header('Location: /firm-admin?msg=success');
    } else {
        header('Location: /firm-admin?error=' . urlencode((string)$result));
    }
});

$router->get('/firm-admin/trip-delete/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    $company = Company::getUserCompany($user['id']);
    if (!$company) {
        header('Location: /firm-admin?error=Firma bilginiz bulunamadı');
        return;
    }
    
    $trip = TripManager::getById((int)$id);
    if (!$trip || $trip['company_id'] != $company['id']) {
        header('Location: /firm-admin?error=Sefer bulunamadı');
        return;
    }
    
    $result = TripManager::delete((int)$id);
    if ($result === true) {
        header('Location: /firm-admin?msg=success');
    } else {
        header('Location: /firm-admin?error=' . urlencode((string)$result));
    }
});

// Admin Panel Routes
$router->get('/admin', function () {
    $user = auth('admin');
    
    $companies = Company::list();
    $coupons = Coupon::list();
    
    // Hata/başarı mesajları
    $msg = $_GET['msg'] ?? '';
    $error = $_GET['error'] ?? '';
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Admin Paneli</h2>';
    if ($msg === 'success') {
        echo '<div class="success">İşlem başarıyla tamamlandı!</div>';
    }
    if ($error) {
        echo '<div class="error">Hata: ' . htmlspecialchars($error) . '</div>';
    }
    echo '<div class="actions">';
    echo '<a href="/admin/company-add" class="btn">Yeni Firma Ekle</a> ';
    echo '<a href="/admin/firm-admin-add" class="btn">Yeni Firma Admin Ekle</a> ';
    echo '<a href="/admin/coupon-add" class="btn">Yeni Kupon Ekle</a>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="card mt-4">';
    echo '<h3>Firmalar</h3>';
    if (empty($companies)) {
        echo '<p class="muted">Henüz firma eklenmemiş.</p>';
    } else {
        echo '<table><thead><tr><th>Firma Adı</th><th>İşlemler</th></tr></thead><tbody>';
        foreach ($companies as $c) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($c['name']) . '</td>';
            echo '<td>';
            echo '<a href="/admin/company-edit/' . (int)$c['id'] . '">Düzenle</a> | ';
            echo '<a href="/admin/company-delete/' . (int)$c['id'] . '" onclick="return confirm(\'Emin misiniz?\')">Sil</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    
    echo '<div class="card mt-4">';
    echo '<h3>Kuponlar</h3>';
    if (empty($coupons)) {
        echo '<p class="muted">Henüz kupon eklenmemiş.</p>';
    } else {
        echo '<table><thead><tr><th>Kod</th><th>İndirim %</th><th>Kullanım</th><th>Son Tarih</th><th>İşlemler</th></tr></thead><tbody>';
        foreach ($coupons as $c) {
            $expires = htmlspecialchars((new DateTimeImmutable($c['expires_at']))->format('d.m.Y H:i'));
            echo '<tr>';
            echo '<td>' . htmlspecialchars($c['code']) . '</td>';
            echo '<td>%' . (int)$c['percent'] . '</td>';
            echo '<td>' . (int)$c['used_count'] . '/' . (int)$c['usage_limit'] . '</td>';
            echo '<td>' . $expires . '</td>';
            echo '<td>';
            echo '<a href="/admin/coupon-edit/' . (int)$c['id'] . '">Düzenle</a> | ';
            echo '<a href="/admin/coupon-delete/' . (int)$c['id'] . '" onclick="return confirm(\'Emin misiniz?\')">Sil</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    
    Views::layout('Admin Paneli', ob_get_clean() ?: '');
});

// Admin Company Management
$router->get('/admin/company-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    
    $error = $_GET['error'] ?? '';
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Yeni Firma Ekle</h2>';
    if ($error) {
        echo '<div class="error">Hata: ' . htmlspecialchars($error) . '</div>';
    }
    echo '<form method="post" action="/admin/company-add">';
    echo Security::csrfField();
    echo '<label>Firma Adı</label>';
    echo '<input name="name" type="text" required minlength="2" maxlength="100" placeholder="Firma adını girin" />';
    echo '<div class="mt-3">';
    echo '<button type="submit">Ekle</button> ';
    echo '<a href="/admin" class="btn-secondary">İptal</a>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    
    Views::layout('Yeni Firma', ob_get_clean() ?: '');
});

$router->post('/admin/company-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    
    $name = trim($_POST['name'] ?? '');
    $result = Company::create($name);
    if ($result === true) {
        header('Location: /admin?msg=success');
    } else {
        header('Location: /admin?error=' . urlencode((string)$result));
    }
});

// Admin Company Edit/Delete
$router->get('/admin/company-edit/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    $company = Company::getById((int)$id);
    if (!$company) {
        header('Location: /admin?error=Firma bulunamadı');
        return;
    }
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Firma Düzenle</h2>';
    echo '<form method="post" action="/admin/company-edit/' . (int)$id . '">';
    echo Security::csrfField();
    echo '<label>Firma Adı</label><input name="name" type="text" value="' . htmlspecialchars($company['name']) . '" required />';
    echo '<div class="mt-3"><button type="submit">Güncelle</button></div>';
    echo '</form>';
    echo '</div>';
    
    Views::layout('Firma Düzenle', ob_get_clean() ?: '');
});

$router->post('/admin/company-edit/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    
    $name = trim($_POST['name'] ?? '');
    $result = Company::update((int)$id, $name);
    if ($result === true) {
        header('Location: /admin?msg=success');
    } else {
        header('Location: /admin?error=' . urlencode((string)$result));
    }
});

$router->get('/admin/company-delete/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    
    $result = Company::delete((int)$id);
    if ($result === true) {
        header('Location: /admin?msg=success');
    } else {
        header('Location: /admin?error=' . urlencode((string)$result));
    }
});

// Admin Firm Admin Management
$router->get('/admin/firm-admin-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    $companies = Company::list();
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Yeni Firma Admin Ekle</h2>';
    echo '<form method="post" action="/admin/firm-admin-add">';
    echo Security::csrfField();
    echo '<label>E-posta</label><input name="email" type="email" required />';
    echo '<label class="mt-2">Şifre</label><input name="password" type="password" minlength="6" required />';
    echo '<label class="mt-2">Firma</label><select name="company_id" required>';
    echo '<option value="">Seçiniz</option>';
    foreach ($companies as $c) {
        echo '<option value="' . (int)$c['id'] . '">' . htmlspecialchars($c['name']) . '</option>';
    }
    echo '</select>';
    echo '<div class="mt-3"><button type="submit">Ekle</button></div>';
    echo '</form>';
    echo '</div>';
    
    Views::layout('Yeni Firma Admin', ob_get_clean() ?: '');
});

$router->post('/admin/firm-admin-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $companyId = (int)($_POST['company_id'] ?? 0);
    
    if ($companyId <= 0) {
        header('Location: /admin/firm-admin-add?error=Geçersiz firma');
        return;
    }
    
    $result = Auth::register($email, $password, 'firm_admin');
    if ($result === true) {
        // Get the new user ID and assign to company
        $stmt = DB::conn()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $userId = (int)$stmt->fetchColumn();
        
        $stmt = DB::conn()->prepare('INSERT INTO firm_admin_companies(user_id, company_id) VALUES(?, ?)');
        $stmt->execute([$userId, $companyId]);
        
        header('Location: /admin?msg=success');
    } else {
        header('Location: /admin/firm-admin-add?error=' . urlencode((string)$result));
    }
});

// Admin Coupon Management
$router->get('/admin/coupon-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Yeni Kupon Ekle</h2>';
    echo '<form method="post" action="/admin/coupon-add">';
    echo Security::csrfField();
    echo '<label>Kupon Kodu</label><input name="code" type="text" required />';
    echo '<label class="mt-2">İndirim Oranı (%)</label><input name="percent" type="number" min="1" max="100" required />';
    echo '<label class="mt-2">Kullanım Limiti</label><input name="usage_limit" type="number" min="1" required />';
    echo '<label class="mt-2">Son Kullanma Tarihi</label><input name="expires_at" type="datetime-local" required />';
    echo '<div class="mt-3"><button type="submit">Ekle</button></div>';
    echo '</form>';
    echo '</div>';
    
    Views::layout('Yeni Kupon', ob_get_clean() ?: '');
});

$router->post('/admin/coupon-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    
    $code = trim($_POST['code'] ?? '');
    $percent = (int)($_POST['percent'] ?? 0);
    $usageLimit = (int)($_POST['usage_limit'] ?? 0);
    $expiresAt = $_POST['expires_at'] ?? '';
    
    $result = Coupon::create($code, $percent, $usageLimit, $expiresAt);
    if ($result === true) {
        header('Location: /admin?msg=success');
    } else {
        header('Location: /admin?error=' . urlencode((string)$result));
    }
});

$router->get('/admin/coupon-edit/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    $coupon = Coupon::getById((int)$id);
    if (!$coupon) {
        header('Location: /admin?error=Kupon bulunamadı');
        return;
    }
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Kupon Düzenle</h2>';
    echo '<form method="post" action="/admin/coupon-edit/' . (int)$id . '">';
    echo Security::csrfField();
    echo '<label>Kupon Kodu</label><input name="code" type="text" value="' . htmlspecialchars($coupon['code']) . '" required />';
    echo '<label class="mt-2">İndirim Oranı (%)</label><input name="percent" type="number" min="1" max="100" value="' . (int)$coupon['percent'] . '" required />';
    echo '<label class="mt-2">Kullanım Limiti</label><input name="usage_limit" type="number" min="1" value="' . (int)$coupon['usage_limit'] . '" required />';
    echo '<label class="mt-2">Son Kullanma Tarihi</label><input name="expires_at" type="datetime-local" value="' . htmlspecialchars(substr($coupon['expires_at'], 0, 16)) . '" required />';
    echo '<div class="mt-3"><button type="submit">Güncelle</button></div>';
    echo '</form>';
    echo '</div>';
    
    Views::layout('Kupon Düzenle', ob_get_clean() ?: '');
});

$router->post('/admin/coupon-edit/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    
    $code = trim($_POST['code'] ?? '');
    $percent = (int)($_POST['percent'] ?? 0);
    $usageLimit = (int)($_POST['usage_limit'] ?? 0);
    $expiresAt = $_POST['expires_at'] ?? '';
    
    $result = Coupon::update((int)$id, $code, $percent, $usageLimit, $expiresAt);
    if ($result === true) {
        header('Location: /admin?msg=success');
    } else {
        header('Location: /admin?error=' . urlencode((string)$result));
    }
});

$router->get('/admin/coupon-delete/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: /');
        return;
    }
    
    $result = Coupon::delete((int)$id);
    if ($result === true) {
        header('Location: /admin?msg=success');
    } else {
        header('Location: /admin?error=' . urlencode((string)$result));
    }
});

// Firma Admin Paneli
$router->get('/firm-admin', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    
    // Firma Admin'in firmasını bul
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT c.id, c.name FROM companies c JOIN firm_admin_companies fac ON c.id = fac.company_id WHERE fac.user_id = ?');
    $stmt->execute([$user['id']]);
    $company = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (!$company) {
        header('Location: /?error=Firma bilgisi bulunamadı');
        return;
    }
    
    // Firmaya ait seferleri listele
    $stmt = $pdo->prepare('SELECT * FROM trips WHERE company_id = ? ORDER BY departure_at DESC');
    $stmt->execute([$company['id']]);
    $trips = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Firma Paneli - ' . htmlspecialchars($company['name']) . '</h2>';
    echo '<p class="muted">Firmanıza ait seferleri yönetin.</p>';
    echo '<div class="mt-3"><a href="/firm-admin/trip-add" class="btn">Yeni Sefer Ekle</a></div>';
    echo '</div>';
    
    if (empty($trips)) {
        echo '<div class="card mt-4">';
        echo '<p class="muted">Henüz sefer eklenmemiş.</p>';
        echo '</div>';
    } else {
        echo '<div class="card mt-4">';
        echo '<h3>Seferler</h3>';
        echo '<table><thead><tr><th>Kalkış</th><th>Varış</th><th>Tarih/Saat</th><th>Fiyat</th><th>Koltuk</th><th>İşlemler</th></tr></thead><tbody>';
        foreach ($trips as $trip) {
            $when = htmlspecialchars((new DateTimeImmutable($trip['departure_at']))->format('d.m.Y H:i'));
            $price = number_format((int)$trip['price_cents'] / 100, 2, ',', '.') . ' TL';
            echo '<tr>';
            echo '<td>' . htmlspecialchars($trip['origin']) . '</td>';
            echo '<td>' . htmlspecialchars($trip['destination']) . '</td>';
            echo '<td>' . $when . '</td>';
            echo '<td>' . $price . '</td>';
            echo '<td>' . (int)$trip['seat_count'] . '</td>';
            echo '<td>';
            echo '<a href="/firm-admin/trip-edit/' . (int)$trip['id'] . '" class="btn-secondary">Düzenle</a> ';
            echo '<a href="/firm-admin/trip-delete/' . (int)$trip['id'] . '" class="btn" style="background:var(--danger)">Sil</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    
    Views::layout('Firma Paneli', ob_get_clean() ?: '');
});

// Firma Admin - Sefer Ekleme
$router->get('/firm-admin/trip-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Yeni Sefer Ekle</h2>';
    echo '<form method="post" action="/firm-admin/trip-add">';
    echo Security::csrfField();
    echo '<label>Kalkış Şehri</label><input name="origin" type="text" required />';
    echo '<label class="mt-2">Varış Şehri</label><input name="destination" type="text" required />';
    echo '<label class="mt-2">Kalkış Tarihi ve Saati</label><input name="departure_at" type="datetime-local" required />';
    echo '<label class="mt-2">Fiyat (TL)</label><input name="price" type="number" step="0.01" min="0" required />';
    echo '<label class="mt-2">Koltuk Sayısı</label><input name="seat_count" type="number" min="1" max="50" required />';
    echo '<div class="mt-3"><button type="submit">Ekle</button></div>';
    echo '</form>';
    echo '</div>';
    
    Views::layout('Yeni Sefer', ob_get_clean() ?: '');
});

$router->post('/firm-admin/trip-add', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    
    // Firma Admin'in firmasını bul
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT company_id FROM firm_admin_companies WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $companyId = $stmt->fetchColumn();
    
    if (!$companyId) {
        header('Location: /firm-admin?error=Firma bilgisi bulunamadı');
        return;
    }
    
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $departureAt = $_POST['departure_at'] ?? '';
    $price = (float)($_POST['price'] ?? 0);
    $seatCount = (int)($_POST['seat_count'] ?? 0);
    
    $result = TripManager::create($companyId, $origin, $destination, $departureAt, $price, $seatCount);
    if ($result === true) {
        header('Location: /firm-admin?msg=success');
    } else {
        header('Location: /firm-admin/trip-add?error=' . urlencode((string)$result));
    }
});

// Firma Admin - Sefer Düzenleme
$router->get('/firm-admin/trip-edit/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    
    // Firma Admin'in firmasını kontrol et
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT t.* FROM trips t JOIN firm_admin_companies fac ON t.company_id = fac.company_id WHERE t.id = ? AND fac.user_id = ?');
    $stmt->execute([$id, $user['id']]);
    $trip = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (!$trip) {
        header('Location: /firm-admin?error=Sefer bulunamadı');
        return;
    }
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Sefer Düzenle</h2>';
    echo '<form method="post" action="/firm-admin/trip-edit/' . (int)$id . '">';
    echo Security::csrfField();
    echo '<label>Kalkış Şehri</label><input name="origin" type="text" value="' . htmlspecialchars($trip['origin']) . '" required />';
    echo '<label class="mt-2">Varış Şehri</label><input name="destination" type="text" value="' . htmlspecialchars($trip['destination']) . '" required />';
    echo '<label class="mt-2">Kalkış Tarihi ve Saati</label><input name="departure_at" type="datetime-local" value="' . htmlspecialchars(substr($trip['departure_at'], 0, 16)) . '" required />';
    echo '<label class="mt-2">Fiyat (TL)</label><input name="price" type="number" step="0.01" min="0" value="' . number_format((int)$trip['price_cents'] / 100, 2, '.', '') . '" required />';
    echo '<label class="mt-2">Koltuk Sayısı</label><input name="seat_count" type="number" min="1" max="50" value="' . (int)$trip['seat_count'] . '" required />';
    echo '<div class="mt-3"><button type="submit">Güncelle</button></div>';
    echo '</form>';
    echo '</div>';
    
    Views::layout('Sefer Düzenle', ob_get_clean() ?: '');
});

$router->post('/firm-admin/trip-edit/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    
    // Firma Admin'in firmasını kontrol et
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT company_id FROM trips t JOIN firm_admin_companies fac ON t.company_id = fac.company_id WHERE t.id = ? AND fac.user_id = ?');
    $stmt->execute([$id, $user['id']]);
    $companyId = $stmt->fetchColumn();
    
    if (!$companyId) {
        header('Location: /firm-admin?error=Sefer bulunamadı');
        return;
    }
    
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $departureAt = $_POST['departure_at'] ?? '';
    $price = (float)($_POST['price'] ?? 0);
    $seatCount = (int)($_POST['seat_count'] ?? 0);
    
    $result = TripManager::update((int)$id, $companyId, $origin, $destination, $departureAt, $price, $seatCount);
    if ($result === true) {
        header('Location: /firm-admin?msg=success');
    } else {
        header('Location: /firm-admin/trip-edit/' . (int)$id . '?error=' . urlencode((string)$result));
    }
});

// Firma Admin - Sefer Silme
$router->get('/firm-admin/trip-delete/{id}', function ($id) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'firm_admin') {
        header('Location: /');
        return;
    }
    
    // Firma Admin'in firmasını kontrol et
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT company_id FROM trips t JOIN firm_admin_companies fac ON t.company_id = fac.company_id WHERE t.id = ? AND fac.user_id = ?');
    $stmt->execute([$id, $user['id']]);
    $companyId = $stmt->fetchColumn();
    
    if (!$companyId) {
        header('Location: /firm-admin?error=Sefer bulunamadı');
        return;
    }
    
    $result = TripManager::delete((int)$id);
    if ($result === true) {
        header('Location: /firm-admin?msg=success');
    } else {
        header('Location: /firm-admin?error=' . urlencode((string)$result));
    }
});

// ============================================================
// BİLET İŞLEMLERİ (Ticket Operations)
// ============================================================

/**
 * Bilet Satın Alma İşlemi
 * POST /buy
 * 
 * İşlem Akışı:
 * 1. Kullanıcı kontrolü (sadece user rolü)
 * 2. CSRF token kontrolü
 * 3. Kupon kodu kontrolü (varsa)
 * 4. Ticket::purchase() ile satın alma (detaylı mantık Ticket.php'de)
 * 5. Session bakiyesini güncelle
 * 6. Cinsiyet bilgisini kaydet
 * 7. Email/SMS bildirimi gönder
 * 
 * Güvenlik:
 * - Auth::user() kontrolü
 * - CSRF koruması
 * - Input sanitization
 * - Transaction (Ticket.php'de)
 */
$router->post('/buy', function () {
    $user = auth('user'); // Sadece login olan user'lar
    if (!csrf()) { header('Location: /?error=Güvenlik hatası'); return; }
    
    $tripId = (int)($_POST['trip_id'] ?? 0);
    $seatNumber = (int)($_POST['seat_number'] ?? 0);
    $couponCode = clean($_POST['coupon_code'] ?? '');
    
    // Kupon kodu kontrolü (opsiyonel)
    $couponId = null;
    if (!empty($couponCode)) {
        $coupon = Coupon::getByCode($couponCode);
        if ($coupon) {
            $validate = Coupon::validate($couponCode);
            if ($validate === true) {
                $couponId = $coupon['id'];
            } else {
                header('Location: /?error=' . urlencode((string)$validate));
                return;
            }
        } else {
            header('Location: /?error=Geçersiz kupon kodu');
            return;
        }
    }
    
    $result = Ticket::purchase($user['id'], $tripId, $seatNumber, $couponId);
    if ($result === true) {
        // Session'daki kullanıcı bilgilerini güncelle
        $pdo = App\DB::conn();
        $stmt = $pdo->prepare('SELECT credit_cents FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $newCredit = (int)$stmt->fetchColumn();
        $_SESSION['user']['credit_cents'] = $newCredit;
        
        // Kullanıcının cinsiyet bilgisini bilet ile kaydet
        if (isset($user['gender'])) {
            $stmt = $pdo->prepare('UPDATE tickets SET passenger_gender = ? WHERE user_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$user['gender'], $user['id']]);
        }
        
        // Bilet ve sefer bilgilerini al
        $stmt = $pdo->prepare('
            SELECT t.*, tr.origin, tr.destination, tr.departure_at 
            FROM tickets t 
            JOIN trips tr ON tr.id = t.trip_id 
            WHERE t.user_id = ? 
            ORDER BY t.id DESC 
            LIMIT 1
        ');
        $stmt->execute([$user['id']]);
        $ticketData = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Email/SMS bildirimi gönder
        if ($ticketData) {
            Notification::ticketPurchased($user, $ticketData, $ticketData);
            Logger::info('Bilet satın alındı', [
                'user_id' => $user['id'],
                'trip_id' => $tripId,
                'seat' => $seatNumber,
                'pnr' => $ticketData['pnr'] ?? ''
            ]);
        }
        
        $pnr = (string)($ticketData['pnr'] ?? '');
        if ($pnr !== '') {
            header('Location: /ticket/' . $pnr);
            return;
        }
        header('Location: /my-tickets?msg=success');
    } else {
        Logger::warning('Bilet satın alma başarısız', [
            'user_id' => $user['id'],
            'trip_id' => $tripId,
            'error' => $result
        ]);
        header('Location: /?error=' . urlencode((string)$result));
    }
});

// Kullanıcının biletlerini listele
$router->get('/my-tickets', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'user') {
        header('Location: /login');
        return;
    }
    $tickets = Ticket::listByUser($user['id']);
    ob_start();
    echo '<div class="card">';
    echo '<h2>Biletlerim</h2>';
    if (empty($tickets)) {
        echo '<p class="muted">Henüz biletiniz yok.</p>';
    } else {
        echo '<table><thead><tr><th>Firma</th><th>Kalkış</th><th>Varış</th><th>Tarih/Saat</th><th>Koltuk</th><th>Fiyat</th><th>Durum</th><th></th></tr></thead><tbody>';
        foreach ($tickets as $t) {
            $when = htmlspecialchars((new DateTimeImmutable($t['departure_at']))->format('d.m.Y H:i'));
            $price = number_format((int)$t['price_paid_cents'] / 100, 2, ',', '.') . ' TL';
            echo '<tr>';
            echo '<td>' . htmlspecialchars($t['company_name']) . '</td>';
            echo '<td>' . htmlspecialchars($t['origin']) . '</td>';
            echo '<td>' . htmlspecialchars($t['destination']) . '</td>';
            echo '<td>' . $when . '</td>';
            echo '<td>' . (int)$t['seat_number'] . '</td>';
            echo '<td>' . $price . '</td>';
            echo '<td>' . htmlspecialchars($t['status']) . '</td>';
            if ($t['status'] === 'active') {
                echo '<td>';
                echo '<form method="post" action="/cancel-ticket" style="display:inline">' . Security::csrfField() . '<input type="hidden" name="ticket_id" value="' . (int)$t['id'] . '" /><button type="submit">İptal Et</button></form>';
                echo ' | <a href="/ticket/' . (isset($t['pnr']) ? htmlspecialchars($t['pnr']) : (int)$t['id']) . '">PDF İndir</a>';
                echo '</td>';
            } else {
                echo '<td><a href="/ticket/' . (isset($t['pnr']) ? htmlspecialchars($t['pnr']) : (int)$t['id']) . '">PDF İndir</a></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    Views::layout('Biletlerim', ob_get_clean() ?: '');
});

// Bilet iptal işlemi
$router->post('/cancel-ticket', function () {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'user') {
        header('Location: /login');
        return;
    }
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    
    // İptal öncesi bilet bilgilerini al
    $pdo = App\DB::conn();
    $stmt = $pdo->prepare('
        SELECT t.*, tr.origin, tr.destination, tr.departure_at 
        FROM tickets t 
        JOIN trips tr ON tr.id = t.trip_id 
        WHERE t.id = ? AND t.user_id = ?
    ');
    $stmt->execute([$ticketId, $user['id']]);
    $ticketData = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    $result = Ticket::cancel($ticketId, $user['id']);
    if ($result === true) {
        // Session'daki kullanıcı bilgilerini güncelle
        $stmt = $pdo->prepare('SELECT credit_cents FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $newCredit = (int)$stmt->fetchColumn();
        $_SESSION['user']['credit_cents'] = $newCredit;
        
        // Email/SMS bildirimi gönder
        if ($ticketData) {
            Notification::ticketCancelled($user, $ticketData, $ticketData);
            Logger::info('Bilet iptal edildi', [
                'user_id' => $user['id'],
                'ticket_id' => $ticketId,
                'refund' => $ticketData['price_paid_cents'] ?? 0
            ]);
        }
        
        header('Location: /my-tickets?msg=cancel-success');
    } else {
        Logger::warning('Bilet iptal başarısız', [
            'user_id' => $user['id'],
            'ticket_id' => $ticketId,
            'error' => $result
        ]);
        header('Location: /my-tickets?error=' . urlencode((string)$result));
    }
});

// Profil: kullanıcı bilgileri ve kartlar
$router->get('/profile', function () {
    $user = Auth::user();
    if (!$user) { header('Location: /login'); return; }
    
    $error = $_GET['error'] ?? '';
    $msg = $_GET['msg'] ?? '';
    
    ob_start();
    
    // Profil Bilgileri
    echo '<div class="card">';
    echo '<h2>Profil Bilgileri</h2>';
    if ($error) echo '<p class="error">' . htmlspecialchars($error) . '</p>';
    if ($msg === 'profile-updated') echo '<p class="success">Profil bilgileriniz güncellendi</p>';
    if ($msg === 'password-changed') echo '<p class="success">Şifreniz başarıyla değiştirildi</p>';
    
    echo '<form method="post" action="/profile/update">';
    echo Security::csrfField();
    
    echo '<div class="form-row">';
    echo '<div class="form-col"><label>Ad</label><input name="first_name" type="text" value="' . htmlspecialchars($user['first_name'] ?? '') . '" required /></div>';
    echo '<div class="form-col"><label>Soyad</label><input name="last_name" type="text" value="' . htmlspecialchars($user['last_name'] ?? '') . '" required /></div>';
    echo '</div>';
    
    echo '<label class="mt-2">E-posta</label><input type="email" value="' . htmlspecialchars($user['email']) . '" disabled />';
    echo '<p class="muted" style="margin-top:5px; font-size:12px;">E-posta değiştirilemez</p>';
    
    echo '<div class="form-row mt-2">';
    echo '<div class="form-col"><label>Doğum Tarihi</label><input name="birth_date" type="date" value="' . htmlspecialchars($user['birth_date'] ?? '') . '" max="' . date('Y-m-d', strtotime('-18 years')) . '" required /></div>';
    echo '<div class="form-col"><label>Cinsiyet</label><select name="gender" required>';
    $selectedMale = ($user['gender'] ?? 'male') === 'male' ? ' selected' : '';
    $selectedFemale = ($user['gender'] ?? '') === 'female' ? ' selected' : '';
    echo '<option value="male"' . $selectedMale . '>Erkek</option>';
    echo '<option value="female"' . $selectedFemale . '>Kadın</option>';
    echo '</select></div>';
    echo '</div>';
    
    echo '<p class="muted mt-2">Rol: ' . htmlspecialchars($user['role']) . ' · Bakiye: ' . number_format(((int)$user['credit_cents']) / 100, 2, ',', '.') . ' TL</p>';
    
    echo '<div class="mt-3"><button type="submit">Profili Güncelle</button></div>';
    echo '</form>';
    echo '</div>';
    
    // Şifre Değiştirme
    echo '<div class="card mt-4">';
    echo '<h3>Şifre Değiştir</h3>';
    echo '<form method="post" action="/profile/change-password">';
    echo Security::csrfField();
    echo '<label>Mevcut Şifre</label><input name="current_password" type="password" required />';
    echo '<label class="mt-2">Yeni Şifre</label><input name="new_password" type="password" minlength="8" required />';
    echo '<p class="muted" style="font-size:12px;margin-top:4px;">En az 8 karakter, 1 büyük harf, 1 küçük harf ve 1 rakam içermelidir.</p>';
    echo '<label class="mt-2">Yeni Şifre Tekrar</label><input name="new_password_confirm" type="password" minlength="8" required />';
    echo '<div class="mt-3"><button type="submit">Şifreyi Değiştir</button></div>';
    echo '</form>';
    echo '</div>';

    // Kayıtlı Kartlar
    $cards = Payments::listCards($user['id']);
    echo '<div class="card mt-4">';
    echo '<h3>Kayıtlı Kartlar</h3>';
    if ($msg === 'card-added') echo '<p class="success">Kart başarıyla eklendi</p>';
    
    if (empty($cards)) {
        echo '<p class="muted">Kayıtlı kart yok.</p>';
    } else {
        echo '<table><thead><tr><th>Marka</th><th>Maskeli</th><th>Ad Soyad</th><th>SKT</th></tr></thead><tbody>';
        foreach ($cards as $c) {
            $exp = sprintf('%02d/%04d', (int)$c['exp_month'], (int)$c['exp_year']);
            echo '<tr><td>' . htmlspecialchars($c['brand']) . '</td><td>' . htmlspecialchars($c['masked']) . '</td><td>' . htmlspecialchars((string)$c['holder_name']) . '</td><td>' . $exp . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    // Kart Ekle
    echo '<div class="card mt-4">';
    echo '<h3>Kart Ekle</h3>';
    echo '<form method="post" action="/profile/card-add">';
    echo Security::csrfField();
    echo '<label>Kart Üzerindeki İsim</label><input name="holder" required />';
    echo '<label class="mt-2">Kart Numarası</label><input name="pan" required />';
    echo '<div class="row">';
    echo '<div><label class="mt-2">Ay</label><input type="number" name="exp_month" min="1" max="12" required /></div>';
    echo '<div><label class="mt-2">Yıl</label><input type="number" name="exp_year" min="' . date('Y') . '" max="' . (date('Y')+15) . '" required /></div>';
    echo '</div>';
    echo '<label class="mt-2">Güvenlik Kodu (CVC)</label><input name="cvc" type="password" minlength="3" maxlength="4" />';
    echo '<div class="mt-3"><button type="submit">Kaydet</button></div>';
    echo '</form>';
    echo '</div>';

    Views::layout('Profil', ob_get_clean() ?: '');
});

$router->post('/profile/update', function () {
    $user = Auth::user();
    if (!$user) { header('Location: /login'); return; }
    if (!csrf()) { header('Location: /profile?error=Güvenlik hatası'); return; }
    
    $data = [
        'first_name' => $_POST['first_name'] ?? '',
        'last_name' => $_POST['last_name'] ?? '',
        'birth_date' => $_POST['birth_date'] ?? '',
        'gender' => $_POST['gender'] ?? '',
    ];
    
    $result = Auth::updateProfile($user['id'], $data);
    if ($result === true) {
        header('Location: /profile?msg=profile-updated');
    } else {
        header('Location: /profile?error=' . urlencode((string)$result));
    }
});

$router->post('/profile/card-add', function () {
    $user = Auth::user();
    if (!$user) { header('Location: /login'); return; }
    if (!csrf()) { header('Location: /profile?error=Güvenlik hatası'); return; }
    
    $holder = trim($_POST['holder'] ?? '');
    $pan = trim($_POST['pan'] ?? '');
    $expMonth = (int)($_POST['exp_month'] ?? 0);
    $expYear = (int)($_POST['exp_year'] ?? 0);
    $ok = Payments::addCard($user['id'], $holder, $pan, $expMonth, $expYear);
    header('Location: /profile' . ($ok === true ? '?msg=card-added' : '?error=' . urlencode((string)$ok)));
});

// Şifre Değiştirme
$router->post('/profile/change-password', function () {
    $user = Auth::user();
    if (!$user) { header('Location: /login'); return; }
    if (!csrf()) { header('Location: /profile?error=Güvenlik hatası'); return; }
    
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $newPasswordConfirm = $_POST['new_password_confirm'] ?? '';
    
    // Şifrelerin eşleşmesi kontrolü
    if ($newPassword !== $newPasswordConfirm) {
        header('Location: /profile?error=' . urlencode('Yeni şifreler eşleşmiyor'));
        return;
    }
    
    // Mevcut şifre kontrolü
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $userHash = $stmt->fetchColumn();
    
    if (!Auth::verifyPassword($currentPassword, (string)$userHash)) {
        Logger::warning('Şifre değiştirme başarısız - yanlış mevcut şifre', ['user_id' => $user['id']]);
        header('Location: /profile?error=' . urlencode('Mevcut şifre hatalı'));
        return;
    }
    
    // Yeni şifre güvenlik kontrolü
    $passwordCheck = Auth::validatePassword($newPassword);
    if ($passwordCheck !== true) {
        header('Location: /profile?error=' . urlencode((string)$passwordCheck));
        return;
    }
    
    // Şifreyi güncelle
    $hashedPassword = Auth::hashPassword($newPassword);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    
    try {
        $stmt->execute([$hashedPassword, $user['id']]);
        Logger::info('Şifre değiştirildi', ['user_id' => $user['id']]);
        header('Location: /profile?msg=password-changed');
    } catch (\PDOException $e) {
        Logger::error('Şifre değiştirme hatası', ['user_id' => $user['id'], 'error' => $e->getMessage()]);
        header('Location: /profile?error=' . urlencode('Şifre değiştirilemedi'));
    }
});

// Cüzdan: bakiye yükleme
$router->get('/wallet', function () {
    $user = Auth::user();
    if (!$user) { header('Location: /login'); return; }
    
    // İşlem geçmişini al
    $stmt = DB::conn()->prepare('SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$user['id']]);
    $transactions = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    
    ob_start();
    echo '<div class="card">';
    echo '<h2>Cüzdan</h2>';
    echo '<p class="muted">Mevcut Bakiye: ' . number_format(((int)$user['credit_cents']) / 100, 2, ',', '.') . ' TL</p>';
    echo '</div>';

    echo '<div class="card mt-4">';
    echo '<h3>Bakiye Yükle</h3>';
    echo '<form method="post" action="/wallet/topup">';
    echo Security::csrfField();
    echo '<label>Tutar (TL)</label><input name="amount" type="number" min="1" step="1" required />';
    echo '<div class="mt-3"><button type="submit">Yükle</button></div>';
    echo '</form>';
    echo '<p class="muted mt-2">Not: Demo ortamı, gerçek ödeme alınmaz.</p>';
    echo '</div>';

    echo '<div class="card mt-4">';
    echo '<h3>İşlem Geçmişi</h3>';
    if (empty($transactions)) {
        echo '<p class="muted">Henüz işlem yok.</p>';
    } else {
        echo '<table><thead><tr><th>Tarih</th><th>İşlem</th><th>Tutar</th><th>Detay</th></tr></thead><tbody>';
        foreach ($transactions as $t) {
            $date = htmlspecialchars((new DateTimeImmutable($t['created_at']))->format('d.m.Y H:i'));
            $amount = number_format((int)$t['amount_cents'] / 100, 2, ',', '.') . ' TL';
            $type = match($t['type']) {
                'topup' => 'Bakiye Yükleme',
                'charge' => 'Bilet Satın Alma',
                'refund' => 'Bilet İptali',
                default => $t['type']
            };
            $meta = $t['meta'] ? json_decode($t['meta'], true) : [];
            $detail = '';
            if (isset($meta['ticket_id'])) {
                $detail = 'Bilet #' . $meta['ticket_id'];
            } elseif ($t['type'] === 'topup') {
                $detail = $t['meta'] ?: 'Manuel yükleme';
            }
            echo '<tr>';
            echo '<td>' . $date . '</td>';
            echo '<td>' . $type . '</td>';
            echo '<td>' . ($t['amount_cents'] > 0 ? '+' : '') . $amount . '</td>';
            echo '<td>' . htmlspecialchars($detail) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    Views::layout('Cüzdan', ob_get_clean() ?: '');
});

$router->post('/wallet/topup', function () {
    $user = Auth::user();
    if (!$user) { header('Location: /login'); return; }
    $amountTl = (int)($_POST['amount'] ?? 0);
    if ($amountTl <= 0) { header('Location: /wallet?error=Gecersiz+tutar'); return; }
    $ok = Payments::topup($user['id'], $amountTl * 100, 'manual-topup');
    if ($ok) {
        // Session bakiyeyi tazele
        $stmt = App\DB::conn()->prepare('SELECT credit_cents FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $newCents = (int)$stmt->fetchColumn();
        $_SESSION['user']['credit_cents'] = $newCents;
        header('Location: /wallet?msg=ok');
    } else {
        header('Location: /wallet?error=Yukleme+basarisiz');
    }
});

// PNR ile bilet görüntüleme (IDOR'a dayanıklı)
$router->get('/ticket/{pnr}', function ($pnr) {
    $user = Auth::user();
    if (!$user || $user['role'] !== 'user') {
        header('Location: /login');
        return;
    }
    
    // Önce PNR ile ara
    $data = Tickets::getTicketByPnr($pnr, (int)$user['id']);
    
    // PNR bulunamazsa, ID olarak dene (eski biletler için - geçici)
    if (!$data && is_numeric($pnr)) {
        $data = Tickets::getTicket((int)$pnr);
        if ($data && (int)$data['user_id'] !== (int)$user['id']) {
            $data = null; // Başka kullanıcının bileti
        }
    }
    
    if (!$data) {
        header('Location: /my-tickets?error=Bilet bulunamadı');
        return;
    }
    
    // Gelişmiş HTML bilet çıktısı (PDF benzeri tasarım)
    $when = (new DateTimeImmutable($data['departure_at']))->format('d.m.Y H:i');
    $amount = number_format(((int)$data['price_paid_cents'])/100, 2, ',', '.') . ' TL';
    $pnr = $data['pnr'] ?? 'N/A';
    $filename = 'bilet_' . ($pnr !== 'N/A' ? $pnr : $data['id']) . '.html';
    
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $html = PDFGenerator::generateTicketPDF($data, [
        'origin' => $data['origin'],
        'destination' => $data['destination'], 
        'departure_at' => $data['departure_at']
    ], ['name' => $data['company_name']], $user);
    
    echo $html;
});

$router->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');


