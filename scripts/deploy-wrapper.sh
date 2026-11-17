#!/usr/bin/env bash
set -euo pipefail

# Hardened deploy wrapper for DeployHub (executed as 'deployer' via sudoers)
# Expected sudoers rule:
#   www-data ALL=(deployer) NOPASSWD: /opt/deployhub/deploy.sh

if [ "$#" -lt 2 ]; then
  echo "Uso: $0 <domain> <branch> [type]" >&2
  exit 64
fi

DOMAIN="$1"
BRANCH="$2"
PROJECT_TYPE="${3:-legacy}"

# 1. Validação estrita de entrada (allow-list)
if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]] || [[ "$DOMAIN" == *".."* ]]; then
  echo "Erro: domínio inválido: $DOMAIN" >&2
  exit 65
fi

if [[ ! "$BRANCH" =~ ^[a-zA-Z0-9._/-]+$ ]] || [[ "$BRANCH" == *".."* ]] || [[ "$BRANCH" == -* ]]; then
  echo "Erro: branch inválida: $BRANCH" >&2
  exit 65
fi

case "$PROJECT_TYPE" in
  laravel|ci4|nodejs_bot|legacy) ;;
  *)
    echo "Erro: tipo de projeto inválido: $PROJECT_TYPE" >&2
    exit 65
    ;;
esac

BASE_DIR="/var/www"
TARGET_DIR="${BASE_DIR}/${DOMAIN}"

if [ ! -d "${TARGET_DIR}/.git" ]; then
  echo "Erro: repositório git não encontrado em ${TARGET_DIR}" >&2
  exit 66
fi

cd "${TARGET_DIR}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === DEPLOY ISOLADO ($(whoami)) para ${DOMAIN} (${BRANCH}) ==="

git fetch origin "${BRANCH}"
git reset --hard HEAD
git clean -fd
git checkout -B "${BRANCH}" "origin/${BRANCH}"
git reset --hard "origin/${BRANCH}"
git clean -fd

case "$PROJECT_TYPE" in
  laravel)
    if [ -f artisan ] && [ -d vendor ]; then php artisan down || true; fi
    composer install --no-dev --optimize-autoloader --ignore-platform-reqs
    if [ -f package.json ]; then
      if [ -f package-lock.json ]; then npm ci --include=dev; else npm install --include=dev; fi
      export NODE_OPTIONS="--max-old-space-size=1024"
      if [ -f vite.config.js ]; then ./node_modules/.bin/vite build; else npm run build; fi
    fi
    php artisan migrate --force
    php artisan optimize:clear
    php artisan optimize
    php artisan up
    ;;
  ci4)
    composer install --no-dev --optimize-autoloader --ignore-platform-reqs
    php spark migrate
    ;;
  nodejs_bot)
    if [ -f package-lock.json ]; then npm ci; else npm install; fi
    ;;
  legacy)
    if [ -f composer.json ]; then
      composer install --no-dev --optimize-autoloader --ignore-platform-reqs
    fi
    if [ -f package.json ]; then
      if [ -f package-lock.json ]; then npm ci --include=dev; else npm install --include=dev; fi
      if [ -f vite.config.js ]; then ./node_modules/.bin/vite build; else npm run build; fi
    fi
    ;;
esac

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === Deploy finalizado com SUCESSO para ${DOMAIN} ==="
