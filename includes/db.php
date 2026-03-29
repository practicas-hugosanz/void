<?php
/**
 * VOID — Database Connection & Setup
 * PostgreSQL via PDO — persistente entre deploys en Railway.
 *
 * Variable de entorno requerida: DATABASE_URL
 * Railway la inyecta automáticamente al añadir el plugin de PostgreSQL.
 * Formato: postgresql://user:pass@host:port/dbname
 */

function get_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $url = getenv('DATABASE_URL');
    if (!$url) {
        throw new \RuntimeException('DATABASE_URL no está definida. Añade el plugin de PostgreSQL en Railway.');
    }

    $parts = parse_url($url);
    $host  = $parts['host'];
    $port  = $parts['port'] ?? 5432;
    $db    = ltrim($parts['path'], '/');
    $user  = $parts['user'];
    $pass  = $parts['pass'];

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id           SERIAL PRIMARY KEY,
            name         TEXT        NOT NULL,
            email        TEXT        NOT NULL UNIQUE,
            password     TEXT        NOT NULL,
            avatar       TEXT        DEFAULT NULL,
            api_key      TEXT        DEFAULT NULL,
            api_provider TEXT        NOT NULL DEFAULT 'gemini',
            api_model    TEXT        DEFAULT NULL,
            created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );

        CREATE TABLE IF NOT EXISTS conversations (
            id         TEXT        PRIMARY KEY,
            user_id    INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title      TEXT        NOT NULL DEFAULT 'Nueva conversación',
            messages   TEXT        NOT NULL DEFAULT '[]',
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );

        CREATE TABLE IF NOT EXISTS sessions (
            token      TEXT        PRIMARY KEY,
            user_id    INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            last_seen  TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );

        CREATE TABLE IF NOT EXISTS whitelist (
            id            SERIAL PRIMARY KEY,
            name          TEXT        DEFAULT NULL,
            email         TEXT        NOT NULL UNIQUE,
            password_hash TEXT        DEFAULT NULL,
            status        TEXT        NOT NULL DEFAULT 'pending',
            requested_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            reviewed_at   TIMESTAMPTZ DEFAULT NULL
        );
    ");

    // Migraciones seguras: añadir columnas si aún no existen (PostgreSQL soporta IF NOT EXISTS)
    $safeCols = [
        "ALTER TABLE users     ADD COLUMN IF NOT EXISTS api_model     TEXT DEFAULT NULL",
        "ALTER TABLE whitelist ADD COLUMN IF NOT EXISTS name          TEXT DEFAULT NULL",
        "ALTER TABLE whitelist ADD COLUMN IF NOT EXISTS password_hash TEXT DEFAULT NULL",
    ];
    foreach ($safeCols as $sql) {
        try { $pdo->exec($sql); } catch (\Exception $e) {}
    }
}
