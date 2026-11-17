<?php

use App\Libraries\Deployer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DeployerBackupTest extends CIUnitTestCase
{
    public function testLaravelSkipsDatabaseBackupByDefaultButRunsMigrations(): void
    {
        $lines = Deployer::buildScriptLines('app.example.com', [
            'repository' => 'app',
            'path'       => '/var/www/app.example.com',
            'branch'     => 'main',
            'key'        => 'id_deploy_app',
            'type'       => 'laravel',
        ], '2026-11-17 01:30:00');

        $script = implode("\n", $lines);
        $this->assertStringNotContainsString('mysqldump', $script);
        $this->assertStringContainsString('backup_database=false', $script);
        $this->assertStringContainsString('php artisan migrate --force', $script);
    }

    public function testLaravelRunsDatabaseBackupWhenEnabled(): void
    {
        $lines = Deployer::buildScriptLines('app.example.com', [
            'repository'      => 'app',
            'path'            => '/var/www/app.example.com',
            'branch'          => 'main',
            'key'             => 'id_deploy_app',
            'type'            => 'laravel',
            'backup_database' => true,
        ], '2026-11-17 01:30:00');

        $script = implode("\n", $lines);
        $this->assertStringContainsString('mysqldump', $script);
        $this->assertStringContainsString('{ set +x; } 2>/dev/null', $script);
        $this->assertStringContainsString('MYSQL_PWD="${DB_PASSWORD}" mysqldump', $script);
        $this->assertStringContainsString('unset MYSQL_PWD DB_PASSWORD', $script);
        $this->assertStringNotContainsString('-p"${DB_PASSWORD}"', $script);
        $this->assertStringContainsString('php artisan migrate --force', $script);
    }

    public function testCi4SkipsDatabaseBackupByDefaultAndRunsWhenEnabled(): void
    {
        $defaultScript = implode("\n", Deployer::buildScriptLines('ci4.example.com', [
            'repository' => 'ci4',
            'path'       => '/var/www/ci4.example.com',
            'branch'     => 'main',
            'key'        => 'id_deploy_ci4',
            'type'       => 'ci4',
        ], '2026-11-17 01:30:00'));

        $this->assertStringNotContainsString('mysqldump', $defaultScript);
        $this->assertStringContainsString('php spark migrate', $defaultScript);

        $enabledScript = implode("\n", Deployer::buildScriptLines('ci4.example.com', [
            'repository'      => 'ci4',
            'path'            => '/var/www/ci4.example.com',
            'branch'          => 'main',
            'key'             => 'id_deploy_ci4',
            'type'            => 'ci4',
            'backup_database' => true,
        ], '2026-11-17 01:30:00'));

        $this->assertStringContainsString('mysqldump', $enabledScript);
        $this->assertStringContainsString('php spark migrate', $enabledScript);
    }

    public function testNoDatabaseSkipsBothBackupAndMigrations(): void
    {
        $script = implode("\n", Deployer::buildScriptLines('static.example.com', [
            'repository'      => 'static',
            'path'            => '/var/www/static.example.com',
            'branch'          => 'main',
            'key'             => 'id_deploy_static',
            'type'            => 'laravel',
            'has_database'    => false,
            'backup_database' => true,
        ], '2026-11-17 01:30:00'));

        $this->assertStringNotContainsString('mysqldump', $script);
        $this->assertStringNotContainsString('php artisan migrate', $script);
    }
}
