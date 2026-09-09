#!/usr/bin/env bash

echo "Digite o domínio ou subdomínio (ex: api.meusite.com):"
read endereco

# Validação do domínio
if [[ ! "$endereco" =~ ^[a-zA-Z0-9._-]+$ ]]; then
    echo "Erro: domínio inválido '$endereco'."
    exit 1
fi

# Detecta versão do PHP instalada
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
if [ -z "$PHP_VERSION" ]; then
    echo "Erro: PHP não encontrado no PATH."
    exit 1
fi
echo "PHP detectado: $PHP_VERSION"

# 1. Cria as pastas
sudo mkdir -p /var/www/$endereco/public

# 2. Define o DONO como você e o GRUPO como o Nginx (www-data)
# Isso permite que ambos convivam em harmonia
sudo chown -R $USER:www-data /var/www/$endereco

# 3. Define permissões gerais (pastas 755, arquivos 644 é o ideal)
sudo find /var/www/$endereco -type d -exec chmod 775 {} \;
sudo find /var/www/$endereco -type f -exec chmod 664 {} \;

# 4. Agora sim, garante escrita para o grupo nas pastas específicas
# Laravel
if [ -d "/var/www/$endereco/storage" ]; then
    sudo chmod -R g+w /var/www/$endereco/storage
    sudo chmod -R g+w /var/www/$endereco/bootstrap/cache
fi

# CodeIgniter 4
if [ -d "/var/www/$endereco/writable" ]; then
    sudo chmod -R g+w /var/www/$endereco/writable
fi

# 5. Cria o arquivo de configuração do Nginx
# Note que usamos 'try_files' para as rotas do Laravel funcionarem
sudo bash -c "cat > /etc/nginx/sites-available/$endereco" <<EOF
server {
    listen 80;
    listen [::]:80;

    server_name $endereco;
    root /var/www/$endereco/public;

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

# 6. Ativa o site criando o link simbólico (o Nginx não tem 'a2ensite')
if [ -L "/etc/nginx/sites-enabled/$endereco" ]; then
    echo "Link simbólico já existe, pulando."
else
    sudo ln -s /etc/nginx/sites-available/$endereco /etc/nginx/sites-enabled/
fi

# 7. Testa a sintaxe e recarrega o serviço
sudo nginx -t && sudo systemctl reload nginx

# 8. Emite certificado SSL
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

echo "Sucesso! O Nginx está configurado para $endereco (PHP $PHP_VERSION)"