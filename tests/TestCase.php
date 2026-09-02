<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use PDOException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Some feature tests need MySQL because historical migrations use CHANGE/MODIFY.
     */
    protected bool $forceMysqlTesting = false;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite') || $this->forceMysqlTesting) {
            $this->useMysqlTestingDatabase();
        }

        parent::setUp();
    }

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        if (! extension_loaded('pdo_sqlite') || $this->forceMysqlTesting) {
            $database = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'travela_testing';
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql.database', $database);
        }

        return $app;
    }

    /**
     * phpunit.xml uses sqlite :memory:, which requires pdo_sqlite.
     * Ubuntu images often omit that extension; fall back to an isolated MySQL schema.
     */
    private function useMysqlTestingDatabase(): void
    {
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306');
        $user = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root');
        $pass = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');
        $database = (string) ($_ENV['DB_TEST_DATABASE'] ?? getenv('DB_TEST_DATABASE') ?: 'travela_testing');
        $database = str_replace(['`', '/', '\\', ';'], '', $database);

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s', $host, $port),
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $database
            ));
        } catch (PDOException $e) {
            $this->markTestSkipped(
                'pdo_sqlite is missing and the MySQL test database could not be created. '
                .'Install SQLite: sudo apt-get install php-sqlite3 && sudo systemctl restart php*-fpm. '
                .$e->getMessage()
            );
        }

        putenv('DB_CONNECTION=mysql');
        $_ENV['DB_CONNECTION'] = 'mysql';
        $_SERVER['DB_CONNECTION'] = 'mysql';
        putenv('DB_DATABASE='.$database);
        $_ENV['DB_DATABASE'] = $database;
        $_SERVER['DB_DATABASE'] = $database;
    }
}
