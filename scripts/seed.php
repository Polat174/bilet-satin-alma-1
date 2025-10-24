<?php
declare(strict_types=1);

use App\DB;
use App\Auth;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

$pdo = DB::conn();

// Create an admin if not exists
$exists = (int)$pdo->query("SELECT COUNT(1) FROM users WHERE role='admin'")->fetchColumn();
if ($exists === 0) {
    $stmt = $pdo->prepare('INSERT INTO users(email,password_hash,role,credit_cents,created_at) VALUES(:e,:p,\'admin\',0,:t)');
    $stmt->execute([
        ':e' => 'admin@example.com',
        ':p' => Auth::hashPassword('admin123'),
        ':t' => date('c'),
    ]);
    echo "Admin eklendi: admin@example.com / admin123\n";
}

// Companies
$companies = ['Yavuzlar Turizm', 'Anadolu Ekspres'];
foreach ($companies as $name) {
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO companies(name, created_at) VALUES(:n,:t)');
    $stmt->execute([':n' => $name, ':t' => date('c')]);
}

// Trips sample
$companyIds = $pdo->query('SELECT id, name FROM companies')->fetchAll(PDO::FETCH_KEY_PAIR);
if (!empty($companyIds)) {
    $now = new DateTimeImmutable('now');
    $samples = [
        ['Istanbul', 'Ankara', '+1 day 09:00', 85000, 40],
        ['Ankara', 'Istanbul', '+1 day 18:00', 85000, 40],
        ['Izmir', 'Bursa', '+2 days 10:00', 45000, 35],
        ['Antalya', 'Konya', '+2 days 20:00', 35000, 30],
        ['Istanbul', 'Izmir', '+1 day 14:00', 120000, 40],
        ['Ankara', 'Antalya', '+2 days 08:00', 95000, 35],
        ['Bursa', 'Istanbul', '+1 day 16:00', 25000, 30],
        ['Konya', 'Ankara', '+2 days 12:00', 40000, 35],
    ];
    foreach ($samples as [$o, $d, $rel, $price, $seats]) {
        foreach ($companyIds as $companyId => $companyName) {
            $dep = $now->modify($rel)->format('c');
            $stmt = $pdo->prepare('INSERT INTO trips(company_id, origin, destination, departure_at, price_cents, seat_count, created_at) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$companyId, $o, $d, $dep, $price, $seats, date('c')]);
        }
    }
    echo "Örnek firmalar ve seferler eklendi.\n";
}

// Coupons
$pdo->prepare('INSERT OR IGNORE INTO coupons(code, percent, usage_limit, used_count, expires_at, created_at) VALUES(?,?,?,?,?,?)')
    ->execute(['INDIRIM10', 10, 100, 0, (new DateTimeImmutable('+30 days'))->format('c'), date('c')]);

echo "Seed tamamlandı.\n";


