## Bilet Satın Alma Platformu (PHP + SQLite)

### Çalıştırma (bu klasörde)

```bash
docker compose up -d --build
docker compose exec app php scripts/migrate.php
docker compose exec app php scripts/seed.php
# Uygulama: http://localhost:8080
# Sağlık: http://localhost:8080/health
```

### Notlar
- PHP 8.2, Apache, SQLite (PDO)
- Document root: `public/`
- Basit Router ve Auth formları hazır


