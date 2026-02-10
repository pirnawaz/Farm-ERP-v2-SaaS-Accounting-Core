<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Drop PostgreSQL types when running migrate:fresh (required for ENUMs etc.).
     */
    protected bool $dropTypes = true;

    protected function setUp(): void
    {
        $this->preflightTestDatabase();
        parent::setUp();
    }

    /**
     * Before running migrations: detect missing test DB and report how to create it.
     * Does not auto-create the database.
     */
    private function preflightTestDatabase(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (getenv('DB_CONNECTION') !== 'pgsql') {
            return;
        }

        $done = true;
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $user = getenv('DB_USERNAME') ?: 'postgres';
        $pass = getenv('DB_PASSWORD') ?: '';
        $database = getenv('DB_DATABASE') ?: 'farm_erp_test';

        $envPath = dirname(__DIR__) . '/.env.testing';
        if (file_exists($envPath)) {
            $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0) {
                    continue;
                }
                if (preg_match('/^DB_HOST=(.*)$/', $line, $m)) {
                    $host = trim($m[1], " \t\"'");
                }
                if (preg_match('/^DB_PORT=(.*)$/', $line, $m)) {
                    $port = trim($m[1], " \t\"'");
                }
                if (preg_match('/^DB_USERNAME=(.*)$/', $line, $m)) {
                    $user = trim($m[1], " \t\"'");
                }
                if (preg_match('/^DB_PASSWORD=(.*)$/', $line, $m)) {
                    $pass = trim($m[1], " \t\"'");
                }
                if (preg_match('/^DB_DATABASE=(.*)$/', $line, $m)) {
                    $database = trim($m[1], " \t\"'");
                }
            }
        }
        $host = getenv('DB_HOST') ?: $host;
        $port = getenv('DB_PORT') ?: $port;
        $user = getenv('DB_USERNAME') ?: $user;
        $pass = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : $pass;
        $database = getenv('DB_DATABASE') ?: $database;

        try {
            $pdo = new \PDO(
                "pgsql:host={$host};port={$port};dbname=postgres",
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $st = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $st->execute([$database]);
            if ($st->fetch() === false) {
                fwrite(STDERR, "\n[ERROR] Test database \"" . $database . "\" does not exist.\n\n");
                fwrite(STDERR, "Create it by running:\n\n  .\\scripts\\create-test-db.ps1\n\n");
                fwrite(STDERR, "Then run: cd apps\\api; php artisan test\n\n");
                exit(1);
            }
        } catch (\PDOException $e) {
            fwrite(STDERR, "\n[ERROR] Could not connect to PostgreSQL. Is it running? (e.g. start Laragon and ensure PostgreSQL is running.)\n\n");
            fwrite(STDERR, "Create the test database by running:\n\n  .\\scripts\\create-test-db.ps1\n\n");
            fwrite(STDERR, "Then run: cd apps\\api; php artisan test\n\n");
            exit(1);
        }
    }
}
