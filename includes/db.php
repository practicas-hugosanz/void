<?php
/**
 * VOID — Database Connection & Setup
 * Uses SQLite for zero-config deployment. Change to PDO MySQL trivially.
 */

define('DB_PATH', __DIR__ . '/../data/void.sqlite');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0750, true);

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL,
            email       TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            password    TEXT    NOT NULL,
            avatar      TEXT    DEFAULT NULL,
            api_key     TEXT    DEFAULT NULL,
            api_provider TEXT   DEFAULT 'gemini',
            created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS conversations (
            id          TEXT    PRIMARY KEY,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title       TEXT    NOT NULL DEFAULT 'Nueva conversación',
            messages    TEXT    NOT NULL DEFAULT '[]',
            created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS sessions (
            token       TEXT    PRIMARY KEY,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            last_seen   TEXT    NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS whitelist (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT    DEFAULT NULL,
            email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT    DEFAULT NULL,
            status        TEXT    NOT NULL DEFAULT 'pending',
            requested_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            reviewed_at   TEXT    DEFAULT NULL
        );
    ");

    // Migración segura para BBDDs existentes: añadir columnas si aún no existen
    // SQLite no soporta IF NOT EXISTS en ALTER TABLE, así que capturamos la excepción
    try { $pdo->exec("ALTER TABLE whitelist ADD COLUMN name TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN api_model TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE whitelist ADD COLUMN password_hash TEXT DEFAULT NULL"); } catch (Exception $e) {}
}
