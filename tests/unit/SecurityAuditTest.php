<?php

use App\Libraries\SecurityAudit;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SecurityAuditTest extends CIUnitTestCase
{
    private function findCheck(array $audit, string $id): array
    {
        foreach ($audit['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }
        $this->fail("Check '{$id}' not found in audit result.");
    }

    public function testFullyHardenedEnvironmentPassesAllChecks(): void
    {
        $audit = SecurityAudit::run([
            'process_user'     => 'www-data',
            'deployer_user'    => 'deployer',
            'deployer_exists'  => true,
            'sudo_list_output' => [
                'User www-data may run the following commands on vps:',
                '    (deployer) NOPASSWD: /opt/deployhub/deploy.sh',
                '    (root) NOPASSWD: /bin/systemctl reload php8.4-fpm',
            ],
            'script_path'      => '/opt/deployhub/deploy.sh',
            'script_exists'    => true,
            'script_writable'  => false,
            'script_owner'     => 'root',
            'script_perms'     => 0750,
            'users'            => [
                ['username' => 'admin', 'two_factor_enabled' => true],
            ],
            'webhook_secret'   => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
            'projects'         => [
                'app.example.com' => ['branch' => 'main'],
            ],
        ]);

        $this->assertSame(5, $audit['passed']);
        $this->assertSame(5, $audit['total']);
        $this->assertSame(100, $audit['score_pct']);
        $this->assertSame('Produção Blindada', $audit['level']);
        $this->assertSame('ok', $audit['level_status']);

        foreach ($audit['checks'] as $check) {
            $this->assertSame('ok', $check['status']);
            $this->assertNull($check['fix_command']);
        }
    }

    public function testOverbroadSudoersIsFlaggedAsDanger(): void
    {
        $audit = SecurityAudit::run([
            'process_user'     => 'www-data',
            'deployer_user'    => 'deployer',
            'deployer_exists'  => true,
            'sudo_list_output' => [
                '    (deployer) NOPASSWD: ALL',
            ],
            'script_exists'    => false,
            'users'            => [['username' => 'admin']],
            'webhook_secret'   => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
            'projects'         => ['app.example.com' => ['branch' => 'main']],
        ]);

        $isolation = $this->findCheck($audit, 'user_isolation');
        $this->assertSame('danger', $isolation['status']);
        $this->assertNotEmpty($isolation['fix_command']);
        $this->assertSame('danger', $audit['level_status']);
    }

    public function testWritableScriptIsFlaggedAsDanger(): void
    {
        $audit = SecurityAudit::run([
            'process_user'     => 'www-data',
            'deployer_user'    => 'deployer',
            'deployer_exists'  => true,
            'sudo_list_output' => ['    (deployer) NOPASSWD: /opt/deployhub/deploy.sh'],
            'script_exists'    => true,
            'script_writable'  => true,
            'script_owner'     => 'www-data',
            'script_perms'     => 0775,
            'users'            => [['username' => 'admin', 'totp_secret' => 'JBSWY3DPEHPK3PXP']],
            'webhook_secret'   => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
            'projects'         => ['app.example.com' => ['branch' => 'main']],
        ]);

        $script = $this->findCheck($audit, 'script_integrity');
        $this->assertSame('danger', $script['status']);
        $this->assertStringContainsString('chmod 750', $script['fix_command']);
    }

    public function testWeakWebhookSecretIsFlaggedAsDanger(): void
    {
        $audit = SecurityAudit::run([
            'process_user'     => 'www-data',
            'deployer_exists'  => false,
            'sudo_list_output' => [],
            'script_exists'    => false,
            'users'            => [['username' => 'admin']],
            'webhook_secret'   => 'changeme',
            'projects'         => [],
        ]);

        $webhook = $this->findCheck($audit, 'webhook_hmac_branch');
        $this->assertSame('danger', $webhook['status']);
        $this->assertStringContainsString('openssl rand -hex 32', $webhook['fix_command']);
    }

    public function testRealEnvironmentAuditDoesNotCrashOrHang(): void
    {
        $audit = SecurityAudit::run();

        $this->assertArrayHasKey('checks', $audit);
        $this->assertCount(5, $audit['checks']);
        $this->assertNotEmpty($audit['process_user']);
        $this->assertNotEmpty($audit['full_fix_cmd']);
    }

    public function testMissingPhpFpmReloadAndUnwritableBackupDirAreFlagged(): void
    {
        $audit = SecurityAudit::run([
            'process_user'        => 'www-data',
            'deployer_exists'     => true,
            'sudo_list_output'    => ['    (deployer) NOPASSWD: /opt/deployhub/deploy.sh'],
            'script_exists'       => false,
            'users'               => [['username' => 'admin', 'two_factor_enabled' => true]],
            'webhook_secret'      => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
            'projects'            => ['app.example.com' => ['branch' => 'main', 'backup_database' => true]],
            'backup_dir_writable' => false,
        ]);

        $privs = $this->findCheck($audit, 'deploy_privileges');
        $this->assertSame('warn', $privs['status']);
        $this->assertStringContainsString('systemctl reload php8.4-fpm', $privs['fix_command']);
        $this->assertStringContainsString('/var/www/backups', $privs['fix_command']);
    }
}
