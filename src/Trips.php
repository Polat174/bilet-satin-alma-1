<?php
declare(strict_types=1);

namespace App;

class Trips
{
    /**
     * @param array{origin?:string,destination?:string,date?:string} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $filters): array
    {
        $pdo = DB::conn();
        $where = [];
        $params = [];

        if (!empty($filters['origin'])) {
            $where[] = 't.origin LIKE :origin';
            $params[':origin'] = '%' . trim($filters['origin']) . '%';
        }
        if (!empty($filters['destination'])) {
            $where[] = 't.destination LIKE :destination';
            $params[':destination'] = '%' . trim($filters['destination']) . '%';
        }
        if (!empty($filters['date'])) {
            // Match by date portion of ISO string
            $where[] = "substr(t.departure_at,1,10) = :date";
            $params[':date'] = $filters['date'];
        }

        // Geçmiş seferleri filtrele - sadece gelecekteki seferleri göster
        $where[] = "t.departure_at > datetime('now', 'localtime')";

        $sql = 'SELECT t.*, c.name AS company_name FROM trips t JOIN companies c ON c.id = t.company_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.departure_at ASC LIMIT 100';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        
        // PHP tarafında da geçmiş seferleri filtrele
        $now = new \DateTimeImmutable();
        $filtered = [];
        foreach ($results as $trip) {
            $departure = new \DateTimeImmutable($trip['departure_at']);
            if ($departure > $now) {
                $filtered[] = $trip;
            }
        }
        
        return $filtered;
    }
}


