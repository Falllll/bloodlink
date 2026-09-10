## Getting started

cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

## Secret scanning

```
git config core.hooksPath .githooks
```

Hook ini butuh Docker berjalan. Detail konfigurasi & perintah: https://github.com/gitleaks/gitleaks

Scan penuh riwayat repo (2026-09-10, 55 commit): nol temuan.

![CI](https://github.com/Falllll/bloodlink/actions/workflows/ci.yml/badge.svg)