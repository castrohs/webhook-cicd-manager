<?php

namespace App\Controllers;

class Webhook extends BaseController
{
    public function index()
    {
        $secret = env('WEBHOOK_SECRET');
        $logDir = WRITEPATH . 'logs/deploy';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/webhook_debug.log';

        $rawBody = $this->request->getBody();
        $signature = $this->request->getServer('HTTP_X_HUB_SIGNATURE_256') ?? '';
        $timestamp = date('Y-m-d H:i:s');

        // 1. Validação de Assinatura HMAC (Sempre sobre o corpo CRU)
        if (!hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $secret), $signature)) {
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ERRO: Assinatura inválida.\n", FILE_APPEND);
            return $this->response->setStatusCode(403)->setBody('Assinatura inválida.');
        }

        // 2. Trata payload= (GitHub Form Data) ou JSON puro
        if (strpos($rawBody, 'payload=') === 0) {
            $payload = urldecode(substr($rawBody, 8));
        } else {
            $payload = $rawBody;
        }

        $data = json_decode($payload, true);
        if (!$data) {
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ERRO: Falha ao decodificar JSON.\n", FILE_APPEND);
            return $this->response->setStatusCode(400)->setBody('JSON inválido.');
        }

        $event = $this->request->getServer('HTTP_X_GITHUB_EVENT') ?? 'push';
        $repoName = $data['repository']['name'] ?? '';
        $branch = ltrim($data['ref'] ?? '', 'refs/heads/');

        $pusherName = $data['pusher']['name'] ?? 'Desconhecido';
        $pusherEmail = $data['pusher']['email'] ?? '';
        $commitMsg = $data['head_commit']['message'] ?? 'Sem mensagem';
        $shortHash = substr($data['after'] ?? '0000000', 0, 7);

        $summary = sprintf(
            "[%s] PUSH: %s | Repositório: %s | Branch: %s | Autor: %s <%s> | Commit: [%s] %s\n",
            date('Y-m-d H:i:s'),
            $event,
            $repoName,
            $branch,
            $pusherName,
            $pusherEmail,
            $shortHash,
            str_replace(["\n", "\r"], ' ', $commitMsg)
        );

        file_put_contents($logFile, $summary, FILE_APPEND);
        log_message('info', $summary);

        if ($event !== 'push') {
            return $this->response->setBody('Evento ignorado: ' . $event);
        }

        $projectsFile = WRITEPATH . 'projects.json';
        $projects = [];
        if (file_exists($projectsFile)) {
            $projects = json_decode(file_get_contents($projectsFile), true);
        }

        $deployedAny = false;
        $matchedDomains = [];

        foreach ($projects as $domain => $config) {
            $configRepo = $config['repository'] ?? $domain;

            if ($configRepo === $repoName && $branch === $config['branch']) {
                $deployedAny = true;
                $matchedDomains[] = $domain;

                $projectLog = $logDir . "/deploy_{$domain}_{$branch}.log";
                file_put_contents($projectLog, '[' . date('Y-m-d H:i:s') . "] === INICIANDO NOVO DEPLOY para $domain (Repo: $repoName, Branch: $branch) ===\n");
                @chmod($projectLog, 0664);

                $path = $config['path'];
                $ssh_key = '/var/www/.ssh/' . $config['key'];
                $targetBranch = $config['branch'];
                $sshUrl = $data['repository']['ssh_url'] ?? ($config['ssh_url'] ?? null);

                \App\Libraries\Deployer::run($domain, $config, $timestamp, $repoName, $branch, $sshUrl);
            }
        }

        if (!$deployedAny) {
            $msg = "[$timestamp] Nenhuma configuração válida encontrada para o repositório $repoName na branch $branch.\n";
            file_put_contents($logFile, $msg, FILE_APPEND);
            return $this->response->setBody('Nenhum deploy realizado. Verifique mapeamento de repositório e branch.');
        }

        return $this->response->setStatusCode(202)->setBody("Deploy iniciado para: " . implode(', ', $matchedDomains));
    }
}
