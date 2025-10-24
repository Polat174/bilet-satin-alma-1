<?php
declare(strict_types=1);

namespace App;

class Company
{
    public static function create(string $name): bool|string
    {
        $name = trim($name);
        if (empty($name)) {
            return 'Firma adı boş olamaz';
        }
        $pdo = DB::conn();
        $stmt = $pdo->prepare('INSERT INTO companies(name, created_at) VALUES(:n, :t)');
        try {
            $stmt->execute([':n' => $name, ':t' => date('c')]);
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'Bu firma adı zaten kullanılıyor';
            }
            return 'Firma oluşturma hatası: ' . $e->getMessage();
        }
    }

    public static function list(): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->query('SELECT * FROM companies ORDER BY name');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getById(int $id): ?array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function update(int $id, string $name): bool|string
    {
        $name = trim($name);
        if (empty($name)) {
            return 'Firma adı boş olamaz';
        }
        $pdo = DB::conn();
        $stmt = $pdo->prepare('UPDATE companies SET name = :n WHERE id = :id');
        try {
            $stmt->execute([':n' => $name, ':id' => $id]);
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'Bu firma adı zaten kullanılıyor';
            }
            return 'Firma güncelleme hatası: ' . $e->getMessage();
        }
    }

    public static function delete(int $id): bool|string
    {
        $pdo = DB::conn();
        try {
            $stmt = $pdo->prepare('DELETE FROM companies WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return true;
        } catch (\PDOException $e) {
            return 'Firma silme hatası: ' . $e->getMessage();
        }
    }

    public static function getUserCompany(int $userId): ?array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT c.* FROM companies c JOIN firm_admin_companies fac ON c.id = fac.company_id WHERE fac.user_id = :u');
        $stmt->execute([':u' => $userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
