<?php

namespace App\Controllers;

class Admin extends BaseController
{
    protected $secretKey;
    protected $logDir = WRITEPATH . 'logs/deploy/';

    public function __construct()
    {
        // $this->secretKey was removed. Now using AuthFilter.
    }

    public function index()
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
        }

        $logs = glob($this->logDir . "*.log");
        $selectedLog = $this->request->getGet('log');

        $content = '';
        if ($selectedLog && in_array($this->logDir . basename($selectedLog), $logs)) {
            $content = file_get_contents($this->logDir . basename($selectedLog));
        }

        $sitesEnabled = glob("/etc/nginx/sites-enabled/*");
        $sites = array_map('basename', $sitesEnabled);

        $projectsFile = WRITEPATH . 'projects.json';
        $projectsJson = file_exists($projectsFile) ? file_get_contents($projectsFile) : '{}';

        $cheatsheetFile = WRITEPATH . 'cheatsheet.html';
        $cheatsheetHtml = file_exists($cheatsheetFile) ? file_get_contents($cheatsheetFile) : '';

        $securityAudit = \App\Libraries\SecurityAudit::run();
        $currentUser = $this->findCurrentUserRecord();

        return view('admin/dashboard', [
            'logs' => $logs,
            'selectedLog' => $selectedLog,
            'content' => $content,
            'sites' => $sites,
            'projects_json' => $projectsJson,
            'cheatsheet_html' => $cheatsheetHtml,
            'securityAudit' => $securityAudit,
            'currentUser2faEnabled' => !empty($currentUser['two_factor_enabled']) && !empty($currentUser['totp_secret']),
            'currentUser2faIssuer' => $currentUser['totp_issuer'] ?? 'DeployHub',
            'message' => session()->getFlashdata('message')
        ]);
    }

    public function securityAudit()
    {
        return $this->response->setJSON(\App\Libraries\SecurityAudit::run());
    }

    public function prepare2fa()
    {
        $username = (string) session()->get('username');
        $issuer = trim((string) ($this->request->getPost('issuer') ?? 'DeployHub'));
        if ($issuer === '') {
            $issuer = 'DeployHub';
        }
        $issuer = mb_substr($issuer, 0, 40);

        $secret = \App\Libraries\Totp::generateSecret();
        session()->set([
            'pending_totp_secret' => $secret,
            'pending_totp_issuer' => $issuer,
        ]);

        return $this->response->setJSON([
            'secret' => $secret,
            'issuer' => $issuer,
            'uri'    => \App\Libraries\Totp::getProvisioningUri($secret, $username, $issuer),
        ]);
    }

    public function enable2fa()
    {
        $secret = (string) session()->get('pending_totp_secret');
        $issuer = (string) (session()->get('pending_totp_issuer') ?: 'DeployHub');
        $code = (string) ($this->request->getPost('code') ?? '');

        if ($secret === '') {
            return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'error' => 'Gere a chave 2FA primeiro.']);
        }

        if (!\App\Libraries\Totp::verify($secret, $code)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Código de 6 dígitos inválido. Verifique o relógio do servidor/app.']);
        }

        $recoveryCodes = \App\Libraries\Totp::generateRecoveryCodes(6);
        $recoveryHashes = array_map(
            static fn (string $c): string => password_hash($c, PASSWORD_DEFAULT),
            $recoveryCodes
        );

        $username = (string) session()->get('username');
        if (!$this->updateUserRecord($username, [
            'totp_secret'           => $secret,
            'totp_issuer'           => $issuer,
            'two_factor_enabled'    => true,
            'recovery_codes_hashes' => $recoveryHashes,
        ])) {
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'error' => 'Não foi possível salvar em writable/users.json.']);
        }

        session()->remove(['pending_totp_secret', 'pending_totp_issuer']);
        return $this->response->setJSON([
            'ok'             => true,
            'issuer'         => $issuer,
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function disable2fa()
    {
        $username = (string) session()->get('username');
        $user = $this->findCurrentUserRecord();
        $code = (string) ($this->request->getPost('code') ?? '');

        $totpOk = !empty($user['totp_secret']) && \App\Libraries\Totp::verify((string) $user['totp_secret'], $code);
        $recoveryOk = false;
        if (!$totpOk && is_array($user['recovery_codes_hashes'] ?? null)) {
            $res = \App\Libraries\Totp::consumeRecoveryCode($code, $user['recovery_codes_hashes']);
            $recoveryOk = $res['matched'];
        }

        if (!$totpOk && !$recoveryOk) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Informe um código TOTP (6 dígitos) ou código de emergência válido.']);
        }

        $this->updateUserRecord($username, [
            'totp_secret'           => null,
            'totp_issuer'           => null,
            'two_factor_enabled'    => false,
            'recovery_codes_hashes' => null,
        ]);

        return $this->response->setJSON(['ok' => true]);
    }

    private function findCurrentUserRecord(): ?array
    {
        $username = (string) session()->get('username');
        $usersFile = WRITEPATH . 'users.json';
        if ($username === '' || !file_exists($usersFile)) {
            return null;
        }
        $users = json_decode((string) file_get_contents($usersFile), true);
        if (!is_array($users)) {
            return null;
        }
        foreach ($users as $user) {
            if (($user['username'] ?? '') === $username) {
                return $user;
            }
        }
        return null;
    }

    private function updateUserRecord(string $username, array $fields): bool
    {
        $usersFile = WRITEPATH . 'users.json';
        if ($username === '' || !file_exists($usersFile)) {
            return false;
        }
        $users = json_decode((string) file_get_contents($usersFile), true);
        if (!is_array($users)) {
            return false;
        }

        $updated = false;
        foreach ($users as &$user) {
            if (($user['username'] ?? '') === $username) {
                foreach ($fields as $k => $v) {
                    if ($v === null) {
                        unset($user[$k]);
                    } else {
                        $user[$k] = $v;
                    }
                }
                $updated = true;
                break;
            }
        }
        unset($user);

        if (!$updated) {
            return false;
        }

        return file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    public function runAction()
    {
        $action = $this->request->getPost('action');
        $domain = $this->request->getPost('domain');
        $phpVer = "8.4";

        if ($action === 'fix_permissions' && !empty($domain)) {
            $target = "/var/www/" . basename($domain);
            $cmd = "sudo chown -R www-data:www-data $target && sudo find $target -type d -exec chmod 775 {} \; && sudo find $target -type f -exec chmod 664 {} \;";
            exec($cmd . " 2>&1", $output, $result);
            if ($result === 0) {
                session()->setFlashdata('message', "Permissões corrigidas!");
            } else {
                $errorDetails = implode("<br>", $output);
                session()->setFlashdata('message', "Erro ao corrigir.<br><span class='text-xs text-red-300'>Detalhes: $errorDetails</span>");
            }
        }

        if ($action === 'generate_ssh_key' && !empty($domain)) {
            $dom = basename($domain);
            $keyName = "id_deploy_" . str_replace('.com.br', '', $dom);
            $keyPath = "/var/www/.ssh/$keyName";

            if (file_exists($keyPath)) {
                session()->setFlashdata('message', "A chave SSH ($keyName) já existe!");
            } else {
                $cmd = "sudo mkdir -p /var/www/.ssh && sudo chown -R www-data:www-data /var/www/.ssh && ssh-keygen -t ed25519 -f $keyPath -q -N \"\"";
                exec($cmd . " 2>&1", $output, $result);

                if ($result === 0 && file_exists($keyPath . '.pub')) {
                    $pubKey = file_get_contents($keyPath . '.pub');
                    session()->setFlashdata('message', "<strong>Chave gerada com sucesso!</strong> ($keyName)<br>Copie e adicione no GitHub Deploy Keys do repositório:<br><textarea class='w-full bg-gray-900 text-green-400 p-2 mt-2 rounded font-mono text-xs' rows='3' readonly>$pubKey</textarea>");
                } else {
                    $errorDetails = implode("<br>", $output);
                    session()->setFlashdata('message', "Erro ao gerar chave SSH.<br><span class='text-xs text-red-300'>Detalhes: $errorDetails</span>");
                }
            }
        }

        if ($action === 'create_site' && !empty($domain)) {
            $dom = basename($domain);
            $fullPath = "/var/www/$dom";

            $nginxConfig = "server {
    listen 80;
    server_name $dom www.$dom;
    root $fullPath/public;
    index index.php index.html;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php$phpVer-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    }
}";
            $tmpNginx = "/tmp/nginx_$dom";
            file_put_contents($tmpNginx, $nginxConfig);

            $cmd = "sudo mkdir -p $fullPath/public && sudo chown -R www-data:www-data $fullPath && ";
            $cmd .= "sudo cp $tmpNginx /etc/nginx/sites-available/$dom && ";
            $cmd .= "sudo ln -sf /etc/nginx/sites-available/$dom /etc/nginx/sites-enabled/ && ";
            $cmd .= "sudo nginx -t && sudo systemctl reload nginx && ";
            $cmd .= "sudo certbot --nginx -d $dom -d www.$dom --non-interactive --agree-tos -m \$certbotEmail --redirect";

            exec($cmd . " 2>&1", $output, $result);

            if ($result === 0) {
                $placeholder = "<!DOCTYPE html>
<html lang=\"pt-BR\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>$dom</title>
    <style>
        body { font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #f5f5f5; }
        .box { text-align: center; color: #555; }
        h1 { font-size: 2rem; margin-bottom: 0.5rem; }
        p { font-size: 1rem; color: #999; }
    </style>
</head>
<body>
    <div class=\"box\">
        <h1>$dom</h1>
        <p>Site criado e SSL ativado automaticamente!</p>
    </div>
</body>
</html>";
                file_put_contents("$fullPath/public/index.php", $placeholder);
                session()->setFlashdata('message', "Site criado com SSL! Acesse https://$dom");
            } else {
                $errorDetails = implode("<br>", $output);
                session()->setFlashdata('message', "Erro ao criar site.<br><span class='text-xs text-red-300'>Detalhes: $errorDetails</span>");
            }
        }

        if ($action === 'create_site_github' && !empty($domain)) {
            $dom = basename($domain);
            $fullPath = "/var/www/$dom";
            $phpVer = "8.4";
            
            $repo = $this->request->getPost('repository');
            $branch = $this->request->getPost('branch') ?? 'main';
            $repoParts = explode('/', $repo);
            $repoName = end($repoParts); // ex: 'site-exemplo' from 'user/site-exemplo'

            $type = $this->request->getPost('type') ?: 'auto';
            $sshHost = $this->request->getPost('ssh_host');
            $sshUser = $this->request->getPost('ssh_user');
            $proxyPort = $this->request->getPost('proxy_port');
            $pm2Name = $this->request->getPost('pm2_name');
            $backupDatabase = $this->request->getPost('backup_database') === '1';

            // 1. Criar Host
            if (!empty($proxyPort)) {
                $proxyTarget = !empty($sshHost) ? $sshHost : '127.0.0.1';
                $nginxConfig = "server {
    listen 80;
    server_name $dom;
    location / {
        proxy_pass http://$proxyTarget:$proxyPort;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host \$host;
        proxy_cache_bypass \$http_upgrade;
    }
}";
                $certbotCmd = "sudo certbot --nginx -d $dom --non-interactive --agree-tos -m \$certbotEmail --redirect";
            } else {
                $isSubdomain = substr_count($dom, '.') >= 2;
                $serverNames = $isSubdomain ? $dom : "$dom www.$dom";
                $certbotDomains = $isSubdomain ? "-d $dom" : "-d $dom -d www.$dom";
                
                $nginxConfig = "server {
    listen 80;
    server_name $serverNames;
    root $fullPath/public;
    index index.php index.html;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php$phpVer-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    }
}";
                $certbotCmd = "sudo certbot --nginx $certbotDomains --non-interactive --agree-tos -m \$certbotEmail --redirect";
            }
            
            $tmpNginx = "/tmp/nginx_$dom";
            file_put_contents($tmpNginx, $nginxConfig);

            $cmd = "sudo mkdir -p $fullPath/public && sudo chown -R www-data:www-data $fullPath && ";
            $cmd .= "sudo cp $tmpNginx /etc/nginx/sites-available/$dom && ";
            $cmd .= "sudo ln -sf /etc/nginx/sites-available/$dom /etc/nginx/sites-enabled/ && ";
            $cmd .= "sudo nginx -t && sudo systemctl reload nginx && ";
            $cmd .= $certbotCmd;
            
            exec($cmd . " 2>&1", $output, $result);

            if ($result === 0) {
                // 2. Criar Chave SSH
                $keyName = "id_deploy_" . str_replace('.com.br', '', $dom);
                $keyPath = "/var/www/.ssh/$keyName";
                
                $sshCmd = "sudo mkdir -p /var/www/.ssh && sudo chown -R www-data:www-data /var/www/.ssh && ssh-keygen -t ed25519 -f $keyPath -q -N \"\"";
                exec($sshCmd . " 2>&1", $sshOut, $sshRes);

                $pubKey = '';
                if ($sshRes === 0 && file_exists($keyPath . '.pub')) {
                    $pubKey = file_get_contents($keyPath . '.pub');
                }

                // 3. Atualizar projects.json
                $projectsFile = WRITEPATH . 'projects.json';
                $projects = [];
                if (file_exists($projectsFile)) {
                    $projects = json_decode(file_get_contents($projectsFile), true) ?? [];
                }
                
                $projectConfig = [
                    'repository' => $repoName,
                    'path' => $fullPath,
                    'branch' => $branch,
                    'key' => $keyName,
                    'type' => $type,
                    'backup_database' => $backupDatabase,
                ];
                
                if (!empty($pm2Name)) {
                    $projectConfig['pm2_name'] = $pm2Name;
                }

                if (!empty($sshHost) && !empty($sshUser)) {
                    $projectConfig['ssh_host'] = $sshHost;
                    $projectConfig['ssh_user'] = $sshUser;
                }
                
                if (!empty($proxyPort)) {
                    $projectConfig['proxy_port'] = $proxyPort;
                }
                
                $projects[$dom] = $projectConfig;
                
                file_put_contents($projectsFile, json_encode($projects, JSON_PRETTY_PRINT));

                $msg = "<strong>Site Integrado Criado com Sucesso! ($dom)</strong><br>";
                $msg .= "O webhook já está configurado para o repositório <strong>$repo</strong>.<br>";
                if ($pubKey) {
                    $msg .= "Copie a chave SSH abaixo e adicione no <strong>Deploy Keys</strong> do repositório no GitHub";
                    if (!empty($sshHost)) {
                        $msg .= " E TAMBÉM no arquivo <strong>~/.ssh/authorized_keys</strong> do seu servidor remoto ($sshHost)";
                    }
                    $msg .= ":<br><textarea class='w-full bg-gray-900 text-green-400 p-2 mt-2 rounded font-mono text-xs' rows='3' readonly>$pubKey</textarea>";
                } else {
                    $msg .= "<br><span class='text-red-400'>Atenção: Houve um erro ao gerar a chave SSH.</span>";
                }
                session()->setFlashdata('message', $msg);
            } else {
                $errorDetails = implode("<br>", $output);
                session()->setFlashdata('message', "Erro ao criar site integrado.<br><span class='text-xs text-red-300'>Detalhes: $errorDetails</span>");
            }
        }

        if ($action === 'install_ssl' && !empty($domain)) {
            $dom = basename($domain);
            $cmd = "sudo certbot --nginx -d $dom -d www.$dom --non-interactive --agree-tos -m \$certbotEmail --redirect";
            exec($cmd . " 2>&1", $output, $result);

            if ($result === 0) {
                session()->setFlashdata('message', "SSL instalado com sucesso para $dom!");
            } else {
                $errorDetails = implode("<br>", $output);
                session()->setFlashdata('message', "Erro ao instalar SSL.<br><span class='text-xs text-red-300'>Detalhes: $errorDetails</span>");
            }
        }

        if ($action === 'change_password') {
            $current = $this->request->getPost('current_password');
            $new = $this->request->getPost('new_password');
            $confirm = $this->request->getPost('confirm_password');
            
            if ($new !== $confirm) {
                session()->setFlashdata('message', "A nova senha e a confirmação não conferem.");
            } else {
                $username = session()->get('username');
                $usersFile = WRITEPATH . 'users.json';
                
                if (file_exists($usersFile)) {
                    $users = json_decode(file_get_contents($usersFile), true) ?? [];
                    $updated = false;
                    
                    foreach ($users as &$user) {
                        if ($user['username'] === $username) {
                            if (password_verify($current, $user['password_hash'])) {
                                $user['password_hash'] = password_hash($new, PASSWORD_BCRYPT);
                                $updated = true;
                            } else {
                                session()->setFlashdata('message', "A senha atual está incorreta.");
                                break;
                            }
                        }
                    }
                    
                    if ($updated) {
                        file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
                        session()->setFlashdata('message', "Senha alterada com sucesso!");
                    }
                } else {
                    session()->setFlashdata('message', "Erro: Arquivo de usuários não encontrado.");
                }
            }
        }

        if ($action === 'clear_logs') {
            $files = glob($this->logDir . "*");
            $count = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    $count++;
                }
            }
            session()->setFlashdata('message', "Foram apagados $count arquivos de log com sucesso!");
        }

        if ($action === 'save_projects_json') {
            $jsonString = $this->request->getPost('projects_json');
            // Validate JSON
            json_decode($jsonString);
            if (json_last_error() === JSON_ERROR_NONE) {
                file_put_contents(WRITEPATH . 'projects.json', $jsonString);
                session()->setFlashdata('message', "Arquivo projects.json salvo com sucesso!");
            } else {
                session()->setFlashdata('message', "Erro ao salvar: JSON inválido. Verifique a sintaxe.");
            }
        }

        if ($action === 'save_cheatsheet') {
            $html = $this->request->getPost('cheatsheet_html');
            file_put_contents(WRITEPATH . 'cheatsheet.html', $html);
            session()->setFlashdata('message', "Dicas salvas com sucesso!");
        }

        if ($action === 'redeploy' && !empty($domain)) {
            $projectsFile = WRITEPATH . 'projects.json';
            if (file_exists($projectsFile)) {
                $projects = json_decode(file_get_contents($projectsFile), true) ?? [];
                if (isset($projects[$domain])) {
                    $config = $projects[$domain];
                    $timestamp = date('Y-m-d H:i:s');
                    \App\Libraries\Deployer::run($domain, $config, $timestamp);
                    session()->setFlashdata('message', "Redeploy iniciado para $domain! Acompanhe a aba de logs.");
                } else {
                    session()->setFlashdata('message', "Erro: Projeto $domain não encontrado no projects.json.");
                }
            }
        }

        if ($action === 'add_server') {
            $name = $this->request->getPost('name');
            $ssh_host = $this->request->getPost('ssh_host');
            $ssh_user = $this->request->getPost('ssh_user');
            $ssh_key = $this->request->getPost('ssh_key');
            
            $serversFile = WRITEPATH . 'servers.json';
            $servers = file_exists($serversFile) ? json_decode(file_get_contents($serversFile), true) ?? [] : [];
            
            $servers[] = [
                'name' => $name,
                'ssh_host' => $ssh_host,
                'ssh_user' => $ssh_user,
                'ssh_key' => $ssh_key
            ];
            
            file_put_contents($serversFile, json_encode($servers, JSON_PRETTY_PRINT));
            session()->setFlashdata('message', "Servidor '$name' adicionado com sucesso!");
        }

        if ($action === 'delete_server') {
            $ssh_host = $this->request->getPost('ssh_host');
            
            $serversFile = WRITEPATH . 'servers.json';
            $servers = file_exists($serversFile) ? json_decode(file_get_contents($serversFile), true) ?? [] : [];
            
            $newServers = [];
            foreach ($servers as $srv) {
                if (($srv['ssh_host'] ?? '') !== $ssh_host) {
                    $newServers[] = $srv;
                }
            }
            
            file_put_contents($serversFile, json_encode($newServers, JSON_PRETTY_PRINT));
            session()->setFlashdata('message', "Servidor $ssh_host removido!");
        }

        $tab = $this->request->getPost('tab') ?? 'logs';
        return redirect()->to("/admin?tab=" . $tab);
    }

    public function stats()
    {
        $serversFile = WRITEPATH . 'servers.json';
        $servers = [];
        
        // Servidor 1 (Local)
        $localCpu = $this->getLocalCpu();
        $localMem = $this->getLocalMemory();
        $localDisk = $this->getLocalDisk();
        
        $servers[] = [
            'name' => 'Servidor Local (Principal)',
            'cpu' => $localCpu,
            'memory' => $localMem,
            'disk' => $localDisk
        ];
        
        // Servidor 2 (Remoto - via servers.json)
        if (file_exists($serversFile)) {
            $remoteConfigs = json_decode(file_get_contents($serversFile), true) ?? [];
            foreach ($remoteConfigs as $conf) {
                if (!empty($conf['ssh_host']) && !empty($conf['ssh_user'])) {
                    $key = $conf['ssh_key'] ?? '';
                    $stats = $this->getRemoteStats($conf['ssh_host'], $conf['ssh_user'], $conf['name'] ?? $conf['ssh_host'], $key);
                    if ($stats) {
                        $servers[] = $stats;
                    }
                }
            }
        }
        
        return $this->response->setJSON(['servers' => $servers]);
    }

    private function getLocalCpu() {
        $load = sys_getloadavg();
        $cores = (int)shell_exec("nproc");
        if ($cores == 0) $cores = 1;
        $cpu = round((float)$load[0] * 100 / $cores, 1);
        return $cpu > 100 ? 100 : $cpu;
    }

    private function getLocalMemory() {
        $free = shell_exec('free');
        if (!$free) return ['total' => 0, 'used' => 0, 'percent' => 0];
        $free_arr = explode("\n", trim($free));
        $mem = explode(" ", preg_replace('/\s+/', ' ', $free_arr[1]));
        $total = isset($mem[1]) ? (float)$mem[1] : 1;
        $used = isset($mem[2]) ? (float)$mem[2] : 0;
        return [
            'total' => round($total / 1024 / 1024, 2),
            'used' => round($used / 1024 / 1024, 2),
            'percent' => round(($used / $total) * 100, 1)
        ];
    }

    private function getLocalDisk() {
        $total = disk_total_space("/");
        $free = disk_free_space("/");
        $used = $total - $free;
        if ($total == 0) $total = 1;
        return [
            'total' => round($total / 1024 / 1024 / 1024, 2),
            'used' => round($used / 1024 / 1024 / 1024, 2),
            'percent' => round(($used / $total) * 100, 1)
        ];
    }

    private function getRemoteStats($host, $user, $name, $sshKey = '') {
        $identity = !empty($sshKey) ? "-i $sshKey" : "";
        $cmd = "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=3 -o BatchMode=yes $identity $user@$host \"cat /proc/loadavg && echo '---' && nproc && echo '---' && free -m | grep Mem && echo '---' && df -m / | tail -1\"";
        $out = shell_exec($cmd);
        if (!$out) {
            return [
                'name' => $name,
                'ssh_host' => $host,
                'cpu' => 0,
                'memory' => ['total' => 0, 'used' => 0, 'percent' => 0],
                'disk' => ['total' => 0, 'used' => 0, 'percent' => 0],
                'error' => 'Timeout ou erro de conexão SSH'
            ];
        }
        
        $parts = explode("---", $out);
        if (count($parts) < 4) return null;
        
        $load = explode(' ', trim($parts[0]));
        $cores = (int)trim($parts[1]);
        if ($cores == 0) $cores = 1;
        $cpu = round(((float)$load[0] * 100) / $cores, 1);
        if ($cpu > 100) $cpu = 100;
        
        $memLine = explode(" ", preg_replace('/\s+/', ' ', trim($parts[2])));
        $memTotal = isset($memLine[1]) ? (float)$memLine[1] : 1;
        $memUsed = isset($memLine[2]) ? (float)$memLine[2] : 0;
        $memory = [
            'total' => round($memTotal / 1024, 2),
            'used' => round($memUsed / 1024, 2),
            'percent' => round(($memUsed / $memTotal) * 100, 1)
        ];
        
        $diskLine = explode(" ", preg_replace('/\s+/', ' ', trim($parts[3])));
        $diskTotal = isset($diskLine[1]) ? (float)$diskLine[1] : 1;
        $diskUsed = isset($diskLine[2]) ? (float)$diskLine[2] : 0;
        $disk = [
            'total' => round($diskTotal / 1024, 2),
            'used' => round($diskUsed / 1024, 2),
            'percent' => round(($diskUsed / $diskTotal) * 100, 1)
        ];
        
        return [
            'name' => $name,
            'ssh_host' => $host,
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk
        ];
    }
}
