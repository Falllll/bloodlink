# BloodLink

[![CI](https://github.com/Falllll/bloodlink/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/Falllll/bloodlink/actions/workflows/ci.yml)

Blood bank management API for Indonesian blood services: donor registry, eligibility screening, inventory tracking with expiry-aware allocation, and hospital blood requests.

This repository is the backend only. It is headless — there are no Blade views and `routes/web.php` is intentionally empty. The web client (Next.js) and the mobile app live in separate repositories and consume this API over `/api/v1`.

## Status

Foundation phase. The API conventions, module boundaries, database schema and quality gates are in place; domain endpoints are being built on top of them.

Working today:

- Health probes (`/health/live`, `/health/ready`)
- A single response envelope and a global exception handler with stable, machine-readable error codes
- `Idempotency-Key` middleware on every write request
- Cursor-based list pagination with allowlisted filtering and sorting
- Structured JSON logging with a request correlation ID, and redaction of sensitive fields
- PostGIS geography columns and GiST indexes for donor and facility locations

Next: authentication, facility-scoped RBAC, donor eligibility, and inventory.

## Stack

| | |
|---|---|
| Runtime | PHP 8.5, Laravel 13 |
| Database | PostgreSQL 17 + PostGIS 3.5 |
| Cache & queue | Redis 7 |
| Local environment | Docker Compose (`app`, `worker`, `db`, `redis`) |
| Quality | Pest, Larastan, Pint, Deptrac, gitleaks |

Every tool here is free and open source. There is no paid service anywhere in the pipeline.

## Architecture

A modular monolith — one deployable, with the boundaries of separate services drawn inside it and enforced by a machine rather than by convention.

```
app/
  Models/            Eloquent models
  Shared/            cross-module building blocks: response envelope,
                     error codes, trace-id and idempotency middleware
  Modules/
    Identity/  Donor/  Inventory/  Request/  Notification/
      Domain/ Application/ Http/ Infrastructure/ Database/ Tests/
```

Modules never reach into each other's internals; only domain events cross a module boundary. `deptrac.yaml` encodes that rule and CI fails on any violation, including code that no layer covers.

## API conventions

Every successful response is enveloped:

```json
{ "data": {}, "meta": {} }
```

Every failure uses the same shape, whatever went wrong:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Validation failed.",
    "details": {},
    "trace_id": "0f8c2a5e-..."
  }
}
```

Clients branch on `error.code`, never on `error.message`. Codes are a stable contract; messages are free to change or be translated. This matters more than it looks: BloodLink refuses requests for medical safety reasons — a unit that has not cleared lab testing, an incompatible blood group, a donor who has not waited long enough — and a client has to be able to tell a medical refusal from a broken server without pattern-matching on prose.

Every response carries an `X-Request-Id` header. Send your own to correlate a client action with server logs, or one is generated for you. The same value appears as `error.trace_id` and on every log line belonging to that request, including work that continues in the queue.

Write requests (`POST`, `PUT`, `PATCH`) require an `Idempotency-Key` header: a UUID generated once per user action and reused on every retry of that same action. Repeating a key replays the stored response instead of creating a second record. The clients are a mobile app used in the field and hospital staff on unreliable connections, so a duplicate submission is a matter of when, not if — and a duplicated blood request allocates scarce units twice.

List endpoints paginate by cursor, not offset. Filterable and sortable columns are declared per resource on an allowlist; anything outside it is rejected with `422` rather than silently ignored.

## Getting started

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

The API is then served at `http://localhost:8000/api/v1`, and `GET /health/ready` should answer `200`.

The seeder creates three facilities, thirty donors with coordinates around Jabodetabek, and sixty blood units deliberately spread across expiry dates — expired, expiring within a week, and long-dated — so that expiry-driven logic can be tested against data that actually varies.

## Quality gates

```bash
docker compose exec app composer check
```

Runs Pint, PHPStan, Deptrac and the test suite in that order, stopping at the first failure. CI runs the same four on every push and pull request, preceded by a gitleaks secret scan.

## Secret scanning

Enable the local pre-commit hook once per clone:

```bash
git config core.hooksPath .githooks
```

The hook scans staged changes with gitleaks and requires Docker to be running. It is pinned to the same image version CI uses, so a commit that passes locally passes in CI. Rules and the allowlist live in `.gitleaks.toml`.
