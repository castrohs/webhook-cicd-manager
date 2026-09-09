# Lições Aprendidas - Automação de Infraestrutura e Deploy Laravel

Este documento registra as melhorias e padrões estabelecidos durante a configuração do ambiente de desenvolvimento e produção.

## 1. Configuração de Nginx para Múltiplos Hosts
- **Padrão de Pastas:** Manter projetos em `/var/www/` com permissões consistentes (`$USER:www-data`).
- **Automação:** O uso de scripts Bash (`multpleHostNgix.sh`) reduz erros manuais na criação de vhosts e links simbólicos.
- **SSL:** Automatizar o Certbot (`--non-interactive`) no final do script garante segurança imediata.

## 2. Setup de Projetos Laravel Existentes
- **Independência de Pastas:** O script `setupExistingLaravel.sh` permite configurar projetos clonados do Git em qualquer diretório.
- **Reset de Permissões:** O Laravel exige que `storage` e `bootstrap/cache` tenham permissão de escrita para o grupo `www-data`. 
- **Otimização de Produção:** Sempre rodar `composer install --no-dev --optimize-autoloader` e `php artisan optimize` para extrair o máximo de performance.

## 3. Webhook de Deploy Automático (CI/CD)
- **Segurança:** Validação via HMAC (SHA256) com segredo compartilhado é essencial para evitar deploys maliciosos.
- **Processamento em Background:** Comandos pesados como `npm run build` podem causar timeout no Webhook do GitHub.
    - **Solução:** Usar `nohup (comando) &` para liberar o GitHub instantaneamente (HTTP 200) enquanto o servidor processa o build.
- **Nomenclatura de Deploy:** Utilizar o termo **"branch destino"** em vez de "branch esperada" no código e logs. Isso deixa mais claro que o webhook está validando se o push recebido é direcionado para a branch final de produção (ex: `master` ou `main`).
- **Log Externo:** Redirecionar `2>&1` para um arquivo de log fora do webroot facilita o debug sem expor informações sensíveis.

## 4. Build de Assets (Vite/Mix)
- **Check Automático:** Verificar a existência de `package.json` antes de rodar `npm install` permite que o script de deploy seja genérico para APIs (só PHP) e Front-ends (PHP + JS).
- **Ambiente:** Em servidores de produção, o uso de `npm run build` é obrigatório para gerar os manifestos corretos.

## 5. Comandos Úteis para Monitoramento
- **Acompanhar Deploy:** `tail -f webhook_deploy.log`
- **Permissões Rápidas:** `sudo chown -R $USER:www-data . && sudo find . -type d -exec chmod 775 {} \; && sudo find . -type f -exec chmod 664 {} \;`

## 6. Exemplo de Mapeamento de Projetos

```php
    $projects = [
        'app1' => [
            'path'   => '/var/www/app1.exemplo.com.br',
            'branch' => 'master',
        ],
        'app2' => [
            'path'   => '/var/www/app2.exemplo.com.br',
            'branch' => 'master',
        ],
    ];
```
