<?php

namespace App\Libraries;

class Deployer
{
    public static function run($domain, $config, $timestamp, $repoName = null, $branch = null, $sshUrl = null)
    {
        $logDir = WRITEPATH . 'logs/deploy';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
        
        $repoName = $repoName ?? ($config['repository'] ?? $domain);
        $targetBranch = $branch ?? $config['branch'];
        $sshUrl = $sshUrl ?? "git@github.com:" . (function_exists('env') && env('GITHUB_USER') ? env('GITHUB_USER') : 'your-user') . "/{$repoName}.git";
        
        $projectLog = $logDir . "/deploy_{$domain}_{$targetBranch}.log";
        file_put_contents($projectLog, '[' . date('Y-m-d H:i:s') . "] === INICIANDO NOVO DEPLOY para $domain (Repo: $repoName, Branch: $targetBranch) ===\n");
        @chmod($projectLog, 0664);

        $ssh_key = '/var/www/.ssh/' . $config['key'];
        $scriptLines = self::buildScriptLines($domain, $config, $timestamp, $repoName, $targetBranch, $sshUrl);

        // Transforma o array em texto para salvar no arquivo
        $shContent = implode("\n", $scriptLines) . "\n";
        $shPath = "/tmp/deploy_{$domain}_" . time() . ".sh";
        file_put_contents($shPath, $shContent);
        chmod($shPath, 0777); // Torna o arquivo executável

        // Verifica se é deploy remoto (Opção 1)
        if (!empty($config['ssh_host']) && !empty($config['ssh_user'])) {
            $sshHost = escapeshellarg($config['ssh_host']);
            $sshUser = escapeshellarg($config['ssh_user']);
            
            // Usa a mesma chave configurada no projeto para conectar no remoto
            $sshKeyPath = escapeshellarg($ssh_key);
            
            $sshCmd = "ssh -i {$sshKeyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$sshUser}@{$sshHost} 'bash -s' < " . escapeshellarg($shPath);
            $wrapperCmd = "{$sshCmd} >> " . escapeshellarg($projectLog) . " 2>&1 || (echo \"[$(date '+%Y-%m-%d %H:%M:%S')] !!!ERRO FATAL!!! Ocorreu uma falha no deploy REMOTO para {$config['ssh_host']}.\" >> " . escapeshellarg($projectLog) . ")";
        } else {
            // Executamos o script bash localmente e redirecionamos tudo para o log final
            $wrapperCmd = "bash {$shPath} >> " . escapeshellarg($projectLog) . " 2>&1 || (echo \"[$(date '+%Y-%m-%d %H:%M:%S')] !!!ERRO FATAL!!! Ocorreu uma falha em algum dos passos acima (LOCAL).\" >> " . escapeshellarg($projectLog) . ")";
        }
        
        exec("nohup sh -c " . escapeshellarg($wrapperCmd) . " > /dev/null 2>&1 &");
    }

    /**
     * Monta as linhas do script bash de deploy para um projeto/modelo.
     *
     * @return string[]
     */
    public static function buildScriptLines($domain, $config, $timestamp, $repoName = null, $branch = null, $sshUrl = null): array
    {
        $repoName = $repoName ?? ($config['repository'] ?? $domain);
        $targetBranch = $branch ?? $config['branch'];
        $sshUrl = $sshUrl ?? "git@github.com:" . (function_exists('env') && env('GITHUB_USER') ? env('GITHUB_USER') : 'your-user') . "/{$repoName}.git";
        $path = $config['path'];
        $ssh_key = '/var/www/.ssh/' . $config['key'];

        $scriptLines = [];
        $scriptLines[] = "#!/bin/bash";
        $scriptLines[] = "set -e"; // Para no primeiro erro
        $scriptLines[] = "set -x"; // Imprime comandos sendo executados no log
        $scriptLines[] = "";

        $scriptLines[] = "export GIT_SSH_COMMAND='ssh -i {$ssh_key} -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no'";
        $scriptLines[] = "echo \"[$timestamp] === INICIANDO DEPLOY para $domain ===\"";

        $scriptLines[] = "if [ ! -d \"{$path}/.git\" ]; then";
        $scriptLines[] = "  echo \"[$timestamp] [1/n] Repositório não encontrado. Criando pasta e clonando...\"";
        $scriptLines[] = "  mkdir -p \"{$path}\"";
        $scriptLines[] = "  git clone -b \"{$targetBranch}\" \"{$sshUrl}\" \"{$path}\"";
        $scriptLines[] = "  cd \"{$path}\"";
        $scriptLines[] = "else";
        $scriptLines[] = "  cd \"{$path}\"";
        $scriptLines[] = "  echo \"[$timestamp] [1/n] Diretório atual: \$(pwd)\"";
        $scriptLines[] = "  echo \"[$timestamp] [1/n] Atualizando repositório e descartando alterações locais...\"";
        $scriptLines[] = "  git fetch origin \"{$targetBranch}\"";
        $scriptLines[] = "  git reset --hard HEAD";
        $scriptLines[] = "  git clean -fd";
        $scriptLines[] = "  git checkout -B \"{$targetBranch}\" origin/\"{$targetBranch}\"";
        $scriptLines[] = "  git reset --hard origin/\"{$targetBranch}\"";
        $scriptLines[] = "  git clean -fd";
        $scriptLines[] = "  echo \"[$timestamp] [1/n] Sincronização concluída com sucesso\"";
        $scriptLines[] = "fi";

        $type = $config['type'] ?? 'legacy';
        $hasDatabase = !isset($config['has_database']) || $config['has_database'] === true;
        $backupDatabase = isset($config['backup_database']) && $config['backup_database'] === true;

        switch ($type) {
            case 'laravel':
                $scriptLines[] = "echo \"[$timestamp] [Laravel] Iniciando modo manutenção...\"";
                $scriptLines[] = "if [ -f artisan ] && [ -d vendor ]; then php artisan down || true; fi";
                $scriptLines[] = "echo \"[$timestamp] [Laravel] Atualizando dependências PHP...\"";
                $scriptLines[] = "export COMPOSER_ALLOW_SUPERUSER=1";
                $scriptLines[] = "composer install --no-dev --optimize-autoloader --ignore-platform-reqs";

                $scriptLines[] = "if [ -f package.json ]; then";
                $scriptLines[] = "  echo \"[$timestamp] [Laravel] Atualizando dependências JS e buildando...\"";
                $scriptLines[] = "  if [ -f package-lock.json ]; then npm ci --include=dev; else npm install --include=dev; fi";
                $scriptLines[] = "  export NODE_OPTIONS=\"--max-old-space-size=1024\"";
                $scriptLines[] = "  if [ -f vite.config.js ]; then ./node_modules/.bin/vite build; else npm run build; fi";
                $scriptLines[] = "fi";

                if ($hasDatabase) {
                    if ($backupDatabase) {
                        self::appendDatabaseBackupLines($scriptLines, 'laravel', $domain, $timestamp);
                    } else {
                        $scriptLines[] = "echo \"[$timestamp] [Laravel] Backup do banco de dados desativado para este projeto (backup_database=false).\"";
                    }
                    $scriptLines[] = "echo \"[$timestamp] [Laravel] Rodando migrations...\"";
                    $scriptLines[] = "php artisan migrate --force";
                } else {
                    $scriptLines[] = "echo \"[$timestamp] [Laravel] Projeto sem banco de dados configurado (pulando backup e migrations)...\"";
                }

                $scriptLines[] = "echo \"[$timestamp] [Laravel] Otimizando cache...\"";
                $scriptLines[] = "php artisan optimize:clear";
                $scriptLines[] = "php artisan optimize";
                $scriptLines[] = "echo \"[$timestamp] [Laravel] Desativando modo manutenção...\"";
                $scriptLines[] = "php artisan up";
                break;

            case 'ci4':
                $scriptLines[] = "echo \"[$timestamp] [CI4] Atualizando dependências PHP...\"";
                $scriptLines[] = "export COMPOSER_ALLOW_SUPERUSER=1";
                $scriptLines[] = "composer install --no-dev --optimize-autoloader --ignore-platform-reqs";

                if ($hasDatabase) {
                    if ($backupDatabase) {
                        self::appendDatabaseBackupLines($scriptLines, 'ci4', $domain, $timestamp);
                    } else {
                        $scriptLines[] = "echo \"[$timestamp] [CI4] Backup do banco de dados desativado para este projeto (backup_database=false).\"";
                    }
                    $scriptLines[] = "echo \"[$timestamp] [CI4] Rodando migrations...\"";
                    $scriptLines[] = "php spark migrate";
                } else {
                    $scriptLines[] = "echo \"[$timestamp] [CI4] Projeto sem banco de dados configurado (pulando migrations)...\"";
                }
                break;

            case 'nodejs_bot':
                $scriptLines[] = "echo \"[$timestamp] [Node.js] Executando atualizações npm...\"";
                $scriptLines[] = "if [ -f package-lock.json ]; then npm ci; else npm install; fi";
                break;

            case 'legacy':
            default:
                $scriptLines[] = "export COMPOSER_ALLOW_SUPERUSER=1";
                $scriptLines[] = "echo \"[$timestamp] [Legacy] Executando composer install...\"";
                $scriptLines[] = "composer install --no-dev --optimize-autoloader --ignore-platform-reqs";

                $scriptLines[] = "if [ -f package.json ]; then";
                $scriptLines[] = "  echo \"[$timestamp] [Legacy] package.json encontrado, executando npm...\"";
                $scriptLines[] = "  if [ -f package-lock.json ]; then npm ci --include=dev; else npm install --include=dev; fi";
                $scriptLines[] = "  if [ -f vite.config.js ]; then ./node_modules/.bin/vite build; else npm run build; fi";
                $scriptLines[] = "fi";

                if ($hasDatabase) {
                    if ($backupDatabase) {
                        self::appendDatabaseBackupLines($scriptLines, 'legacy', $domain, $timestamp);
                    }
                    $scriptLines[] = "if [ -f artisan ]; then";
                    $scriptLines[] = "  echo \"[$timestamp] [Legacy] Laravel detectado, executando artisan optimize e migrate...\"";
                    $scriptLines[] = "  php artisan optimize";
                    $scriptLines[] = "  php artisan migrate --force";
                    $scriptLines[] = "elif [ -f spark ]; then";
                    $scriptLines[] = "  echo \"[$timestamp] [Legacy] CodeIgniter 4 detectado, executando spark migrate...\"";
                    $scriptLines[] = "  php spark migrate";
                    $scriptLines[] = "fi";
                } else {
                    $scriptLines[] = "if [ -f artisan ]; then";
                    $scriptLines[] = "  echo \"[$timestamp] [Legacy] Laravel detectado (sem DB), executando artisan optimize...\"";
                    $scriptLines[] = "  php artisan optimize";
                    $scriptLines[] = "fi";
                }
                break;
        }

        // Gerenciamento PM2
        if (!empty($config['pm2_name'])) {
            $pm2User = !empty($config['pm2_user']) ? escapeshellarg($config['pm2_user']) : "";
            $pm2Prefix = $pm2User ? "sudo -u " . $pm2User . " " : "";
            $pm2Name = escapeshellarg($config['pm2_name']);

            $scriptLines[] = "echo \"[$timestamp] [PM2] Reiniciando/Iniciando processo '{$config['pm2_name']}'...\"";
            $scriptLines[] = "{$pm2Prefix}pm2 restart {$pm2Name} --update-env || {$pm2Prefix}pm2 start index.js --name {$pm2Name}";
        }

        // Permissões
        $scriptLines[] = "echo \"[$timestamp] [Permissões] Ajustando arquivos para 644 e pastas para 755 (ignorando .git, node_modules e vendor)...\"";
        $scriptLines[] = "find . -path ./node_modules -prune -o -path ./vendor -prune -o -path ./.git -prune -o -type f -exec chmod 644 {} + 2>/dev/null || true";
        $scriptLines[] = "find . -path ./node_modules -prune -o -path ./vendor -prune -o -path ./.git -prune -o -type d -exec chmod 755 {} + 2>/dev/null || true";

        if ($type === 'laravel') {
            $scriptLines[] = "chmod -R 775 storage bootstrap/cache 2>/dev/null || true";
        } elseif ($type === 'ci4') {
            $scriptLines[] = "chmod -R 775 writable 2>/dev/null || true";
        }

        if ($type === 'laravel' || $type === 'ci4') {
            $scriptLines[] = "echo \"[$timestamp] [Serviços] Recarregando PHP-FPM...\"";
            $scriptLines[] = "sudo -n systemctl reload php8.4-fpm || echo \"[$timestamp] AVISO: Falha ao recarregar o PHP-FPM. O usuário precisa de permissão no sudoers.\"";
        }

        $scriptLines[] = "echo \"[$timestamp] === Deploy finalizado com SUCESSO para $domain ===\"";

        return $scriptLines;
    }

    private static function appendDatabaseBackupLines(array &$scriptLines, string $type, string $domain, string $timestamp): void
    {
        $label = match ($type) {
            'laravel' => 'Laravel',
            'ci4'     => 'CI4',
            default   => 'Legacy',
        };

        $scriptLines[] = "echo \"[$timestamp] [{$label}] Realizando backup do banco de dados...\"";
        $scriptLines[] = "mkdir -p /var/www/backups";
        $scriptLines[] = "{ set +x; } 2>/dev/null";

        if ($type === 'ci4') {
            $scriptLines[] = "DB_HOST=\$(grep -E '^database\.default\.hostname\s*=' .env | awk -F '=' '{print \$2}' | sed \"s/['\\\"]//g\" | xargs)";
            $scriptLines[] = "DB_PORT=\$(grep -E '^database\.default\.port\s*=' .env | awk -F '=' '{print \$2}' | sed \"s/['\\\"]//g\" | xargs)";
            $scriptLines[] = "DB_DATABASE=\$(grep -E '^database\.default\.database\s*=' .env | awk -F '=' '{print \$2}' | sed \"s/['\\\"]//g\" | xargs)";
            $scriptLines[] = "DB_USERNAME=\$(grep -E '^database\.default\.username\s*=' .env | awk -F '=' '{print \$2}' | sed \"s/['\\\"]//g\" | xargs)";
            $scriptLines[] = "DB_PASSWORD=\$(grep -E '^database\.default\.password\s*=' .env | awk -F '=' '{print \$2}' | sed \"s/['\\\"]//g\" | xargs)";
        } else {
            $scriptLines[] = "DB_HOST=\$(grep -E '^DB_HOST=' .env | cut -d= -f2 | sed \"s/['\\\"]//g\" | xargs)";
            $scriptLines[] = "DB_PORT=\$(grep -E '^DB_PORT=' .env | cut -d= -f2 | sed \"s/['\\\"]//g\" | xargs)";
            $scriptLines[] = "DB_DATABASE=\$(grep -E '^DB_DATABASE=' .env | cut -d= -f2 | sed \"s/['\\\"]//g\" | xargs)";
            $scriptLines[] = "DB_USERNAME=\$(grep -E '^DB_USERNAME=' .env | cut -d= -f2 | sed \"s/['\\\"]//g\" | xargs)";
            $scriptLines[] = "DB_PASSWORD=\$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2 | sed \"s/['\\\"]//g\" | xargs)";
        }

        $scriptLines[] = "if [ ! -z \"\$DB_DATABASE\" ]; then MYSQL_PWD=\"\${DB_PASSWORD}\" mysqldump -u\"\${DB_USERNAME}\" -h\"\${DB_HOST:-127.0.0.1}\" -P\"\${DB_PORT:-3306}\" \"\${DB_DATABASE}\" > /var/www/backups/{$domain}_\$(date +%Y%m%d_%H%M%S).sql || echo \"[$timestamp] Falha no backup DB, continuando...\"; fi";
        $scriptLines[] = "unset MYSQL_PWD DB_PASSWORD";
        $scriptLines[] = "set -x";
    }
}
