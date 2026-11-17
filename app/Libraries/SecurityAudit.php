<?php

namespace App\Libraries;

class SecurityAudit
{
    public const DEFAULT_SCRIPT_PATH = '/opt/deployhub/deploy.sh';
    public const DEFAULT_DEPLOYER_USER = 'deployer';

    private array $probes;

    public function __construct(array $probes = [])
    {
        $this->probes = $probes;
    }

    /**
     * Executa a auditoria completa de segurança/aderência sem travar o painel.
     */
    public static function run(array $probes = []): array
    {
        $audit = new self($probes);
        return $audit->evaluate();
    }

    public function evaluate(): array
    {
        $scriptPath = $this->probes['script_path']
            ?? (function_exists('env') ? env('DEPLOY_SCRIPT_PATH', self::DEFAULT_SCRIPT_PATH) : self::DEFAULT_SCRIPT_PATH);
        if (empty($scriptPath)) {
            $scriptPath = self::DEFAULT_SCRIPT_PATH;
        }

        $deployerUser = $this->probes['deployer_user'] ?? self::DEFAULT_DEPLOYER_USER;
        $processUser = $this->getProcessUser();

        $checks = [
            $this->checkUserIsolation($processUser, $deployerUser, $scriptPath),
            $this->checkScriptIntegrity($processUser, $deployerUser, $scriptPath),
            $this->checkTwoFactorAuth(),
            $this->checkWebhookHmacAndBranch(),
            $this->checkDeployPrivileges($processUser),
        ];

        $passed = 0;
        $hasDanger = false;
        foreach ($checks as $check) {
            if ($check['status'] === 'ok') {
                $passed++;
            } elseif ($check['status'] === 'danger') {
                $hasDanger = true;
            }
        }

        $total = count($checks);
        if ($passed === $total) {
            $level = 'Produção Blindada';
            $levelStatus = 'ok';
        } elseif ($hasDanger) {
            $level = 'Atenção: Risco Detectado';
            $levelStatus = 'danger';
        } else {
            $level = 'Modo Rápido (Plug-and-Play)';
            $levelStatus = 'warn';
        }

        return [
            'checked_at'   => date('Y-m-d H:i:s'),
            'process_user' => $processUser,
            'deployer_user'=> $deployerUser,
            'script_path'  => $scriptPath,
            'passed'       => $passed,
            'total'        => $total,
            'score_pct'    => (int) round(($passed / $total) * 100),
            'level'        => $level,
            'level_status' => $levelStatus,
            'checks'       => $checks,
            'full_fix_cmd' => $this->buildHardeningScript($processUser, $deployerUser, $scriptPath),
        ];
    }

    private function checkUserIsolation(string $processUser, string $deployerUser, string $scriptPath): array
    {
        $deployerExists = $this->doesUserExist($deployerUser);
        $sudoOutput = $this->getSudoListOutput();

        $hasExactRule = false;
        $hasOverbroadRule = false;

        foreach ($sudoOutput as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // Detecta regra perigosa: liberar ALL comandos como deployer ou root sem senha
            if (preg_match('/\(\s*(?:' . preg_quote($deployerUser, '/') . '|ALL)\s*\)\s*NOPASSWD:\s*ALL\b/i', $trimmed)) {
                $hasOverbroadRule = true;
            }

            // Detecta regra restrita ao script fixo
            if (
                preg_match('/\(\s*' . preg_quote($deployerUser, '/') . '\s*\)/', $trimmed)
                && str_contains($trimmed, 'NOPASSWD:')
                && str_contains($trimmed, $scriptPath)
            ) {
                $hasExactRule = true;
            }
        }

        if ($hasOverbroadRule) {
            return [
                'id'          => 'user_isolation',
                'title'       => '1. Isolamento de Usuário (' . $deployerUser . ')',
                'description' => 'Verifica se o processo atual (' . $processUser . ') tem regra sudo -n restrita ao script de build.',
                'status'      => 'danger',
                'badge'       => '🔴 Risco: Sudoers Permissivo (ALL)',
                'details'     => "O usuário '{$processUser}' possui permissão sudoers irrestrita (ALL). Se o webserver for comprometido, o atacante assume o usuário alvo imediatamente.",
                'fix_command' => "# Restrinja o sudoers exclusivamente ao script fixo:\necho \"{$processUser} ALL=({$deployerUser}) NOPASSWD: {$scriptPath}\" | sudo tee /etc/sudoers.d/deployhub\nsudo chmod 440 /etc/sudoers.d/deployhub\nsudo visudo -cf /etc/sudoers.d/deployhub",
            ];
        }

        if ($deployerExists && $hasExactRule) {
            return [
                'id'          => 'user_isolation',
                'title'       => '1. Isolamento de Usuário (' . $deployerUser . ')',
                'description' => 'Verifica se o processo atual (' . $processUser . ') tem regra sudo -n restrita ao script de build.',
                'status'      => 'ok',
                'badge'       => '🟢 Aderente (Isolado)',
                'details'     => "Usuário '{$deployerUser}' ativo e regra sudoers restrita exclusivamente a {$scriptPath} para '{$processUser}'.",
                'fix_command' => null,
            ];
        }

        $reason = !$deployerExists
            ? "Usuário dedicado '{$deployerUser}' ainda não existe no sistema."
            : "Usuário '{$deployerUser}' existe, mas '{$processUser}' ainda não tem regra sudo -n restrita para {$scriptPath}.";

        return [
            'id'          => 'user_isolation',
            'title'       => '1. Isolamento de Usuário (' . $deployerUser . ')',
            'description' => 'Verifica se o processo atual (' . $processUser . ') tem regra sudo -n restrita ao script de build.',
            'status'      => 'warn',
            'badge'       => "🟡 Modo Rápido (Rodando via {$processUser})",
            'details'     => $reason . ' O fluxo plug-and-play continua funcionando, mas recomenda-se isolar builds.',
            'fix_command' => $this->buildUserAndSudoersCommands($processUser, $deployerUser, $scriptPath),
        ];
    }

    private function checkScriptIntegrity(string $processUser, string $deployerUser, string $scriptPath): array
    {
        $exists = $this->probes['script_exists'] ?? file_exists($scriptPath);

        if (!$exists) {
            return [
                'id'          => 'script_integrity',
                'title'       => "2. Integridade do Script ({$scriptPath})",
                'description' => 'Checa via is_writable() e fileowner() se o webserver não consegue editar o script de deploy.',
                'status'      => 'warn',
                'badge'       => '🟡 Pendente: Script não instalado',
                'details'     => "Arquivo {$scriptPath} não encontrado. Instale o wrapper validado e proteja com chmod 750.",
                'fix_command' => $this->buildScriptInstallCommands($deployerUser, $scriptPath),
            ];
        }

        $isWritable = $this->probes['script_writable'] ?? is_writable($scriptPath);
        $ownerName  = $this->probes['script_owner'] ?? $this->resolveFileOwnerName($scriptPath);
        $perms      = $this->probes['script_perms'] ?? (@fileperms($scriptPath) & 0777);
        $permsOctal = sprintf('%04o', $perms);

        $worldWritable = ($perms & 0002) !== 0;
        $ownedByWeb    = ($ownerName === $processUser && $processUser !== 'root' && $processUser !== $deployerUser);

        if ($isWritable || $worldWritable || $ownedByWeb) {
            return [
                'id'          => 'script_integrity',
                'title'       => "2. Integridade do Script ({$scriptPath})",
                'description' => 'Checa via is_writable() e fileowner() se o webserver não consegue editar o script de deploy.',
                'status'      => 'danger',
                'badge'       => '🔴 Risco: Editável pelo Webserver',
                'details'     => "Dono atual: '{$ownerName}', permissões: {$permsOctal}. O processo '{$processUser}' consegue alterar o script.",
                'fix_command' => "sudo chown root:{$deployerUser} {$scriptPath}\nsudo chmod 750 {$scriptPath}",
            ];
        }

        return [
            'id'          => 'script_integrity',
            'title'       => "2. Integridade do Script ({$scriptPath})",
            'description' => 'Checa via is_writable() e fileowner() se o webserver não consegue editar o script de deploy.',
            'status'      => 'ok',
            'badge'       => '🟢 Protegido (Read-Only para Webserver)',
            'details'     => "Dono: '{$ownerName}', permissões: {$permsOctal}. Não editável por '{$processUser}'.",
            'fix_command' => null,
        ];
    }

    private function checkTwoFactorAuth(): array
    {
        $users = $this->readUsers();

        if (empty($users)) {
            return [
                'id'          => 'two_factor_auth',
                'title'       => '3. Autenticação do Painel (2FA / TOTP)',
                'description' => 'Verifica se o administrador ativou o segundo fator de autenticação para acesso ao painel.',
                'status'      => 'warn',
                'badge'       => '🟡 Sem usuários configurados',
                'details'     => 'Arquivo writable/users.json não encontrado ou vazio.',
                'fix_command' => "# Crie writable/users.json a partir do exemplo e habilite TOTP:\ncp writable/users.json.example writable/users.json",
            ];
        }

        $totalUsers = count($users);
        $usersWith2fa = 0;
        foreach ($users as $user) {
            $hasTotp = !empty($user['totp_secret']) || !empty($user['two_factor_enabled']);
            if ($hasTotp) {
                $usersWith2fa++;
            }
        }

        if ($usersWith2fa === $totalUsers) {
            return [
                'id'          => 'two_factor_auth',
                'title'       => '3. Autenticação do Painel (2FA / TOTP)',
                'description' => 'Verifica se o administrador ativou o segundo fator de autenticação para acesso ao painel.',
                'status'      => 'ok',
                'badge'       => '🟢 2FA Ativo',
                'details'     => "Todos os {$totalUsers} usuário(s) possuem segundo fator (TOTP) habilitado.",
                'fix_command' => null,
            ];
        }

        return [
            'id'          => 'two_factor_auth',
            'title'       => '3. Autenticação do Painel (2FA / TOTP)',
            'description' => 'Verifica se o administrador ativou o segundo fator de autenticação para acesso ao painel.',
            'status'      => 'warn',
            'badge'       => '🟡 Recomendado ativar 2FA',
            'details'     => "{$usersWith2fa} de {$totalUsers} usuário(s) com 2FA configurado. Use o card de configuração 2FA abaixo nesta aba para ativar em 1 clique.",
            'fix_command' => "# Sem dependências externas: ative no card 'Autenticação em 2 Etapas (TOTP)' nesta mesma aba Segurança.",
        ];
    }

    private function checkWebhookHmacAndBranch(): array
    {
        $secret = $this->probes['webhook_secret']
            ?? (function_exists('env') ? (string) env('WEBHOOK_SECRET', '') : (string) getenv('WEBHOOK_SECRET'));

        $weakSecrets = ['', 'your-secret', 'changeme', 'secret', '123456', 'webhook_secret'];
        $secretOk = $secret !== '' && strlen($secret) >= 16 && !in_array(strtolower($secret), $weakSecrets, true);

        $projects = $this->readProjects();
        $projectsWithoutBranch = [];
        foreach ($projects as $domain => $cfg) {
            $branch = trim((string) ($cfg['branch'] ?? ''));
            if ($branch === '' || $branch === '*') {
                $projectsWithoutBranch[] = $domain;
            }
        }

        if (!$secretOk) {
            return [
                'id'          => 'webhook_hmac_branch',
                'title'       => '4. Validação de Branch (ref) e Assinatura HMAC',
                'description' => 'Confirma se o webhook exige assinatura secreta (X-Hub-Signature-256) e filtro estrito de branch.',
                'status'      => 'danger',
                'badge'       => '🔴 Risco: WEBHOOK_SECRET fraco ou ausente',
                'details'     => 'Defina WEBHOOK_SECRET no .env com pelo menos 16 caracteres aleatórios para validar HMAC SHA-256.',
                'fix_command' => "# Gere um segredo forte de 32 bytes e adicione ao .env:\nopenssl rand -hex 32",
            ];
        }

        if (!empty($projectsWithoutBranch)) {
            $list = implode(', ', $projectsWithoutBranch);
            return [
                'id'          => 'webhook_hmac_branch',
                'title'       => '4. Validação de Branch (ref) e Assinatura HMAC',
                'description' => 'Confirma se o webhook exige assinatura secreta (X-Hub-Signature-256) e filtro estrito de branch.',
                'status'      => 'warn',
                'badge'       => '🟡 Branch não restrita em projetos',
                'details'     => "HMAC configurado, mas os seguintes hosts estão sem branch estrita: {$list}.",
                'fix_command' => "# Defina \"branch\": \"main\" (ou \"master\") em cada entrada de writable/projects.json.",
            ];
        }

        $projectCount = count($projects);
        return [
            'id'          => 'webhook_hmac_branch',
            'title'       => '4. Validação de Branch (ref) e Assinatura HMAC',
            'description' => 'Confirma se o webhook exige assinatura secreta (X-Hub-Signature-256) e filtro estrito de branch.',
            'status'      => 'ok',
            'badge'       => '🟢 Blindado',
            'details'     => "WEBHOOK_SECRET ativo (HMAC SHA-256 via hash_equals) e {$projectCount} projeto(s) com filtro estrito de branch.",
            'fix_command' => null,
        ];
    }

    private function checkDeployPrivileges(string $processUser): array
    {
        $sudoOutput = $this->getSudoListOutput();
        $hasFpmReload = false;

        foreach ($sudoOutput as $line) {
            if (
                str_contains($line, 'NOPASSWD:')
                && preg_match('#(/bin/systemctl|/usr/bin/systemctl)\s+reload\s+php8\.4-fpm\b#', $line)
            ) {
                $hasFpmReload = true;
                break;
            }
        }

        $projects = $this->readProjects();
        $backupEnabled = false;
        foreach ($projects as $cfg) {
            if (!empty($cfg['backup_database'])) {
                $backupEnabled = true;
                break;
            }
        }

        $backupDir = $this->probes['backup_dir'] ?? '/var/www/backups';
        $backupDirOk = true;
        if ($backupEnabled) {
            $backupDirOk = $this->probes['backup_dir_writable']
                ?? (is_dir($backupDir) && is_writable($backupDir));
        }

        if ($hasFpmReload && $backupDirOk) {
            $details = "Permissão sudo -n para reload do php8.4-fpm ativa.";
            if ($backupEnabled) {
                $details .= " Diretório {$backupDir} pronto para escrita por '{$processUser}'.";
            } else {
                $details .= " Backup MySQL desativado nos projetos (opcional).";
            }

            return [
                'id'          => 'deploy_privileges',
                'title'       => '5. Reload PHP-FPM & Diretório de Backup',
                'description' => 'Verifica permissão sudoers para recarregar o php8.4-fpm e escrita em /var/www/backups (quando ativado).',
                'status'      => 'ok',
                'badge'       => '🟢 Liberado',
                'details'     => $details,
                'fix_command' => null,
            ];
        }

        $issues = [];
        $cmds = [];

        if (!$hasFpmReload) {
            $issues[] = "'{$processUser}' sem regra sudoers para reload do php8.4-fpm";
            $cmds[] = "# Libera apenas o reload do PHP-FPM sem senha:";
            $cmds[] = "echo \"{$processUser} ALL=(root) NOPASSWD: /bin/systemctl reload php8.4-fpm\" | sudo tee /etc/sudoers.d/deploy-php-fpm";
            $cmds[] = "sudo chmod 440 /etc/sudoers.d/deploy-php-fpm";
        }

        if (!$backupDirOk) {
            $issues[] = "diretório {$backupDir} inexistente ou sem permissão de escrita para '{$processUser}'";
            $cmds[] = "";
            $cmds[] = "# Cria e libera o diretório de backups MySQL:";
            $cmds[] = "sudo mkdir -p {$backupDir}";
            $cmds[] = "sudo chown -R {$processUser}:{$processUser} {$backupDir}";
            $cmds[] = "sudo chmod 750 {$backupDir}";
        }

        return [
            'id'          => 'deploy_privileges',
            'title'       => '5. Reload PHP-FPM & Diretório de Backup',
            'description' => 'Verifica permissão sudoers para recarregar o php8.4-fpm e escrita em /var/www/backups (quando ativado).',
            'status'      => 'warn',
            'badge'       => '🟡 Permissão Pendente',
            'details'     => 'Pendências: ' . implode('; ', $issues) . '.',
            'fix_command' => implode("\n", $cmds),
        ];
    }

    private function getProcessUser(): string
    {
        if (isset($this->probes['process_user'])) {
            return (string) $this->probes['process_user'];
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                return $info['name'];
            }
        }

        $user = trim((string) @shell_exec('whoami 2>/dev/null'));
        return $user !== '' ? $user : get_current_user();
    }

    private function doesUserExist(string $username): bool
    {
        if (isset($this->probes['deployer_exists'])) {
            return (bool) $this->probes['deployer_exists'];
        }

        if (function_exists('posix_getpwnam')) {
            return @posix_getpwnam($username) !== false;
        }

        $output = [];
        $code = 1;
        @exec('id -u ' . escapeshellarg($username) . ' 2>/dev/null', $output, $code);
        return $code === 0;
    }

    /**
     * @return string[]
     */
    private function getSudoListOutput(): array
    {
        if (isset($this->probes['sudo_list_output'])) {
            return (array) $this->probes['sudo_list_output'];
        }

        $output = [];
        $code = 1;
        // -n garante que o sudo nunca bloqueie aguardando senha no PHP-FPM
        @exec('sudo -n -l 2>/dev/null', $output, $code);
        return $code === 0 ? $output : [];
    }

    private function resolveFileOwnerName(string $path): string
    {
        $uid = @fileowner($path);
        if ($uid === false) {
            return 'desconhecido';
        }
        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($uid);
            if (is_array($info) && !empty($info['name'])) {
                return $info['name'];
            }
        }
        return (string) $uid;
    }

    private function readUsers(): array
    {
        if (isset($this->probes['users'])) {
            return (array) $this->probes['users'];
        }

        $file = defined('WRITEPATH') ? WRITEPATH . 'users.json' : __DIR__ . '/../../writable/users.json';
        if (!file_exists($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function readProjects(): array
    {
        if (isset($this->probes['projects'])) {
            return (array) $this->probes['projects'];
        }

        $file = defined('WRITEPATH') ? WRITEPATH . 'projects.json' : __DIR__ . '/../../writable/projects.json';
        if (!file_exists($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildUserAndSudoersCommands(string $processUser, string $deployerUser, string $scriptPath): string
    {
        return implode("\n", [
            "# 1. Cria usuário dedicado sem login interativo",
            "sudo useradd -r -m -d /home/{$deployerUser} -s /usr/sbin/nologin {$deployerUser}",
            "",
            "# 2. Libera exclusivamente o script de deploy no sudoers",
            "echo \"{$processUser} ALL=({$deployerUser}) NOPASSWD: {$scriptPath}\" | sudo tee /etc/sudoers.d/deployhub",
            "sudo chmod 440 /etc/sudoers.d/deployhub",
            "sudo visudo -cf /etc/sudoers.d/deployhub",
        ]);
    }

    private function buildScriptInstallCommands(string $deployerUser, string $scriptPath): string
    {
        $dir = dirname($scriptPath);
        $repoScript = defined('ROOTPATH') ? rtrim(ROOTPATH, '/') . '/scripts/deploy-wrapper.sh' : dirname(__DIR__, 2) . '/scripts/deploy-wrapper.sh';
        return implode("\n", [
            "# Cria diretório e instala o script validador",
            "sudo mkdir -p {$dir}",
            "sudo cp {$repoScript} {$scriptPath}",
            "sudo chown root:{$deployerUser} {$scriptPath}",
            "sudo chmod 750 {$scriptPath}",
        ]);
    }

    private function buildHardeningScript(string $processUser, string $deployerUser, string $scriptPath): string
    {
        $dir = dirname($scriptPath);
        $repoScript = defined('ROOTPATH') ? rtrim(ROOTPATH, '/') . '/scripts/deploy-wrapper.sh' : dirname(__DIR__, 2) . '/scripts/deploy-wrapper.sh';
        return implode("\n", [
            "# 1. Cria usuário dedicado sem login interativo",
            "sudo useradd -r -m -d /home/{$deployerUser} -s /usr/sbin/nologin {$deployerUser}",
            "",
            "# 2. Instala e protege o script de deploy (dono root:{$deployerUser}, apenas leitura/execução)",
            "sudo mkdir -p {$dir}",
            "sudo cp {$repoScript} {$scriptPath}",
            "sudo chown root:{$deployerUser} {$scriptPath}",
            "sudo chmod 750 {$scriptPath}",
            "",
            "# 3. Libera exclusivamente esse script no sudoers",
            "echo \"{$processUser} ALL=({$deployerUser}) NOPASSWD: {$scriptPath}\" | sudo tee /etc/sudoers.d/deployhub",
            "sudo chmod 440 /etc/sudoers.d/deployhub",
            "sudo visudo -cf /etc/sudoers.d/deployhub",
        ]);
    }
}
