## Getting started

cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed


![CI](https://github.com/Falllll/bloodlink/actions/workflows/ci.yml/badge.svg)