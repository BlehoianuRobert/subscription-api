# Subscription Service API

A small HTTP API that manages subscription lifecycle for a digital content billing system.
Built with PHP 8.1 + Slim 4 + PostgreSQL as a take-home assignment for Gameloft Bucharest.

## Stack

- **PHP 8.1+**
- **Slim Framework 4** — lightweight HTTP router
- **PostgreSQL 14+** — relational database
- **PHPUnit 10** — test runner

## Requirements

- PHP 8.1 or higher with the `pdo_pgsql` extension enabled
- PostgreSQL 14+ running locally
- Composer

## Install

```bash
composer install
```

## Database Setup

Copy the example env file and fill in your credentials:

```bash
cp .env.example .env
```

Create the databases:

```bash
psql -U postgres -c "CREATE DATABASE subscriptions;"
psql -U postgres -c "CREATE DATABASE subscriptions_test;"
```

Run migrations:

```bash
php bin/migrate.php
```

## Run

```bash
php -S localhost:8080 index.php
```

The API is now available at `http://localhost:8080`.

## Test

```bash
php composer.phar exec phpunit
```

All tests run against the `subscriptions_test` database. Each test wipes all rows before running so tests are fully isolated.

## API Examples

### Create a subscription

```bash
curl -s -X POST http://localhost:8080/subscriptions \
  -H "Content-Type: application/json" \
  -d '{"user_id": "user_123"}'
```

### Get a subscription

```bash
curl -s http://localhost:8080/subscriptions/{id}
```

### Cancel a subscription

```bash
curl -s -X POST http://localhost:8080/subscriptions/{id}/cancel
```

### Payment succeeded webhook

```bash
curl -s -X POST http://localhost:8080/webhooks/billing \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "evt_001",
    "subscription_id": "{id}",
    "type": "payment.succeeded",
    "timestamp": "2026-06-04T14:00:01Z",
    "amount": 9.99
  }'
```

### Payment failed webhook

```bash
curl -s -X POST http://localhost:8080/webhooks/billing \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "evt_002",
    "subscription_id": "{id}",
    "type": "payment.failed",
    "timestamp": "2026-06-04T14:00:00Z",
    "amount": 9.99
  }'
```

### Get audit history

```bash
curl -s http://localhost:8080/subscriptions/{id}/history
```

## Notes

Authentication is out of scope. The assignment spec states that requests can be assumed
to be already authenticated.