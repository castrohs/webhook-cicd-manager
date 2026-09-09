#!/usr/bin/env bash

# Captura argumentos ou pede se for interativo
endereco=$1
caminho=$2

if [ -z "$endereco" ] || [ -z "$caminho" ]; then
    echo "Uso: $0 <dominio> <caminho_absoluto>"
    echo "Exemplo: $0 api.meusite.com /var/www/meu-projeto"
    exit 1
fi

# Validação do domínio
if [ ! -d "$caminho" ]; then
    echo "Erro: O caminho '$caminho' não existe."
    exit 1
fi

# Detecta versão do PHP instalada
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
if [ -z "$PHP_VERSION" ]; then
    echo "Erro: PHP não encontrado no PATH."
    exit 1
fi
echo "PHP detectado: $PHP_VERSION"

# ===================== SETUP LARAVEL PARA PRODUÇÃO =====================

cd "$caminho"

# 1. Verifica e cria o .env se não existir
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        echo "Criando .env a partir do .env.example..."
        cp .env.example .env
    else
        echo "Aviso: .env não encontrado e .env.example não existe!"
    fi
fi

# 2. Instala dependências do Composer (modo produção)
echo "Instalando dependências do Composer (--no-dev --optimize-autoloader)..."
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --ignore-platform-reqs


# ... dentro do setupExistingLaravel.sh ...

# Instala e compila assets do Vite
if [ -f "package.json" ]; then
    echo "Compilando assets com Vite..."
    npm install
    npm run build
    
    # Garante que as permissões da pasta build estejam corretas para o Nginx
    sudo chown -R $USER:www-data public/build
    sudo chmod -R 775 public/build
fi


# ===================== AJUSTE DE PERMISSÕES PÓS-COMPOSER =====================
echo "Garantindo que os novos arquivos do vendor pertençam ao usuário correto..."
# Aplica o CHOWN logo a seguir para corrigir o que o root criou
sudo chown -R $USER:www-data "$caminho"
sudo find "$caminho" -type d -exec chmod 775 {} \;
sudo find "$caminho" -type f -exec chmod 664 {} \;
# ===================== FIM DO AJUSTE =====================



# 3. Gera a APP_KEY se não estiver definida
if grep -q "APP_KEY=" .env && grep -q "APP_KEY=$" .env; then
    echo "Gerando APP_KEY..."
    php artisan key:generate --force
fi

# 4. Otimizações de produção
echo "Otimizando Laravel para produção..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 5. Pergunta se quer rodar as migrations
echo "Deseja rodar as migrations? (s/n)"
read migrate_confirm
if [[ "$migrate_confirm" =~ ^[sS]$ ]]; then
    php artisan migrate --force
fi

# 6. Storage link (se não existir)
if [ ! -L "public/storage" ]; then
    echo "Criando link simbólico para storage..."
    php artisan storage:link
fi

# ===================== PERMISSÕES =====================

# Define o DONO como você e o GRUPO como o Nginx (www-data)
echo "Ajustando permissões em $caminho..."
sudo chown -R $USER:www-data "$caminho"

# Define permissões gerais (pastas 775, arquivos 664)
sudo find "$caminho" -type d -exec chmod 775 {} \;
sudo find "$caminho" -type f -exec chmod 664 {} \;

# Garante escrita para o grupo nas pastas específicas do Laravel
if [ -d "$caminho/storage" ]; then
    sudo chmod -R g+w "$caminho/storage"
    sudo chmod -R g+w "$caminho/bootstrap/cache"
    echo "Permissões de storage e cache ajustadas."
else
    echo "Aviso: Pasta 'storage' não encontrada. Verifique se este é um projeto Laravel válido."
fi

# 4. Cria o arquivo de configuração do Nginx
# Note que usamos 'try_files' para as rotas do Laravel funcionarem
echo "Criando configuração do Nginx para $endereco..."
sudo bash -c "cat > /etc/nginx/sites-available/$endereco" <<EOF
server {
    listen 80;
    listen [::]:80;

    server_name $endereco;
    root $caminho/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php index.html;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

# 5. Ativa o site criando o link simbólico
if [ -L "/etc/nginx/sites-enabled/$endereco" ]; then
    echo "Link simbólico já existe, pulando."
else
    sudo ln -s /etc/nginx/sites-available/$endereco /etc/nginx/sites-enabled/
fi

# 6. Testa a sintaxe e recarrega o serviço
sudo nginx -t && sudo systemctl reload nginx

# 7. Emite certificado SSL (Certbot rodará em modo não-interativo)
echo "Verificando certificado SSL com Certbot..."
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/.env" ]; then
    ENV_CERTBOT_EMAIL=$(grep -E '^CERTBOT_EMAIL' "$SCRIPT_DIR/.env" | cut -d '=' -f2 | tr -d " '\"")
fi

if [ -n "$ENV_CERTBOT_EMAIL" ]; then
    certbot_email="$ENV_CERTBOT_EMAIL"
else
    echo "Digite o e-mail para o Certbot (SSL) [padrão: admin@$endereco]:"
    read certbot_email
    certbot_email=${certbot_email:-admin@$endereco}
fi

sudo certbot --nginx -d $endereco --non-interactive --agree-tos -m "$certbot_email"


# ===================== QUEUE WORKER (SUPERVISOR) =====================

echo "Configurando queue worker com Supervisor..."

# Instala o Supervisor se não estiver disponível
if ! command -v supervisorctl &>/dev/null; then
    echo "Instalando Supervisor..."
    sudo apt-get update && sudo apt-get install -y supervisor
fi

WORKER_NAME=$(basename "$caminho")
CONF_FILE="/etc/supervisor/conf.d/${WORKER_NAME}-worker.conf"

echo "Atualizando configuração do Supervisor em $CONF_FILE..."
sudo bash -c "cat > $CONF_FILE" <<EOF
[program:${WORKER_NAME}-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $caminho/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=$caminho/storage/logs/worker.log
stopwaitsecs=3600
EOF

    sudo supervisorctl reread
    sudo supervisorctl update
    sudo supervisorctl restart "${WORKER_NAME}-worker:*" 2>/dev/null || sudo supervisorctl start "${WORKER_NAME}-worker:*"

    echo "Queue worker processado via Supervisor."
    sudo supervisorctl status "${WORKER_NAME}-worker:*"

# ===================== FIM QUEUE WORKER =====================

# ===================== DATABASE MIGRATION =====================
echo "Rodando migrações do banco de dados (automático)..."
# Rodamos com sudo -u www-data para garantir que arquivos de log/cache criados pertençam ao usuário correto
# O --force é necessário para rodar em produção sem pedir confirmação
sudo -u www-data php artisan migrate --force
echo "Migrações concluídas."
# ==============================================================

echo "Sucesso! O Nginx está configurado para $endereco em $caminho (PHP $PHP_VERSION)"
