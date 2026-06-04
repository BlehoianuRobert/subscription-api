<?php

declare(strict_types=1);

namespace App;

use PDO;

class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $host = getenv('DB_HOST')     ?: 'localhost';
            $port = getenv('DB_PORT')     ?: '5432';
            $name = getenv('DB_NAME')     ?: 'subscriptions';
            $user = getenv('DB_USER')     ?: 'postgres';
            $pass = getenv('DB_PASSWORD') ?: '';

            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

            self::$connection = new PDO($dsn, $user, $pass);
            self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }

        return self::$connection;
    }

    public static function initialize(): void
    {
        $db = self::getConnection();

        $db->exec("
            CREATE TABLE IF NOT EXISTS subscriptions (
                id               TEXT        PRIMARY KEY,
                user_id          TEXT        NOT NULL,
                tenant_id        TEXT,
                status           TEXT        NOT NULL DEFAULT 'trialing',
                trial_starts_at  TIMESTAMPTZ NOT NULL,
                trial_ends_at    TIMESTAMPTZ NOT NULL,
                grace_started_at TIMESTAMPTZ,
                grace_ends_at    TIMESTAMPTZ,
                cancelled_at     TIMESTAMPTZ,
                created_at       TIMESTAMPTZ NOT NULL,
                updated_at       TIMESTAMPTZ NOT NULL
            );
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS billing_events (
                event_id          TEXT          PRIMARY KEY,
                subscription_id   TEXT          NOT NULL,
                event_type        TEXT          NOT NULL,
                carrier_timestamp TIMESTAMPTZ   NOT NULL,
                amount            NUMERIC(10,2) NOT NULL,
                received_at       TIMESTAMPTZ   NOT NULL,
                processed_result  TEXT          NOT NULL
            );
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_events (
                id                TEXT        PRIMARY KEY,
                subscription_id   TEXT        NOT NULL,
                event_type        TEXT        NOT NULL,
                from_status       TEXT,
                to_status         TEXT,
                source            TEXT        NOT NULL,
                external_event_id TEXT,
                message           TEXT        NOT NULL,
                metadata          JSONB,
                created_at        TIMESTAMPTZ NOT NULL
            );
        ");
    }

    public static function reset(): void
    {
        $db = self::getConnection();
        $db->exec('TRUNCATE TABLE audit_events, billing_events, subscriptions RESTART IDENTITY CASCADE;');
    }
}