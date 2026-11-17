# 🚀 DeployHub & Server Manager

> **A lightweight CI/CD automation hub, Nginx VirtualHost manager, and continuous deployment engine for web servers.**

Built with **PHP (CodeIgniter 4)**, **Bash**, and **Tailwind CSS**, DeployHub automates the continuous delivery lifecycle for multi-stack applications (Laravel, CodeIgniter 4, Node.js/PM2, and static sites), provisions Nginx VirtualHosts, and provides end-to-end deployment visibility through an intuitive web dashboard.

![DeployHub Dashboard](docs/screenshots/dashboard.png)

![Security & Hardening Panel](docs/screenshots/security-panel.jpg)

---

## 🌟 Key Features

- 🪝 **GitHub Webhooks with HMAC-SHA256 Security**: Cryptographic payload verification on every incoming GitHub request before triggering any deployment routine.
- 🔄 **Automated Multi-Stack Deployments**:
  - **Laravel**: Automatic maintenance mode (`artisan down`), clean Git synchronization (`reset/clean`), dependency resolution (`composer install`), database migrations, asset compilation via **Vite** (`npm run build`), cache flushing, and seamless re-activation (`artisan up`).
  - **CodeIgniter 4**: Clean repository synchronization, Composer updates, and database migrations.
  - **Node.js / PM2**: Automated dependency installation, remote server deployment via SSH with pipe redirection, and graceful process restarts with **PM2**.
  - **Static / Auto / Legacy**: Fast, clean git resets and zero-friction updates.
- 🌐 **Nginx VirtualHost Generator**: Provision new web domains, server blocks, PHP sockets, and reverse proxy configurations with automated SSL setup via web forms.
- ⚙️ **Visual `projects.json` Configuration**: Manage, edit, and map repositories, branches, deployment targets, and SSH keys directly from the browser.
- 🕹️ **Manual Trigger & Rollbacks**: Trigger builds on demand with a single click from the administrative control panel.
- 📜 **Real-time Deployment Logs**: Inspect execution outputs and debugging details in `writable/logs/deploy/` tracked with `set -e` and `set -x` shell traps.
- 🔐 **Secure Authentication & Native 2FA (TOTP)**: Protected sessions, Bcrypt password hashing, and zero-dependency RFC 6238 TOTP two-factor authentication with local QR code generation, customizable service name, and single-use emergency recovery codes.
- 🛡️ **Security Adherence & Progressive Hardening Panel**: Real-time 5-layer infrastructure audit (`deployer` user isolation, `/opt/deployhub/deploy.sh` integrity, 2FA status, webhook HMAC/branch validation, and PHP-FPM reload / backup directory permissions) with copy-and-paste remediation commands.
- 🗄️ **Per-Project Database Backups**: Optional `backup_database` flag in `projects.json` (disabled by default) using `MYSQL_PWD` and `set +x` so credentials never leak into deployment logs.

---

## 🛠️ Tech Stack

- **Backend**: PHP 8.2+ / [CodeIgniter 4](https://codeigniter.com)
- **Frontend / UI**: HTML5, Vanilla JavaScript, [Tailwind CSS](https://tailwindcss.com) (Modern Dark Theme)
- **Infrastructure & Automation**: Bash Shell Scripting, Nginx, Git, SSH Keys, Composer, NPM, PM2
- **Security**: HMAC-SHA256, Bcrypt, CSRF Protection, Environment Variables

---

## 📂 Project Structure

```text
├── app/
│   ├── Controllers/
│   │   ├── Admin.php        # Host provisioning, projects management, deployments & user profile
│   │   ├── Auth.php         # Authentication and session handling
│   │   └── Webhook.php      # GitHub Webhook listener with HMAC verification
│   ├── Libraries/
│   │   ├── Deployer.php       # Multi-stack shell script generation and deployment engine
│   │   ├── SecurityAudit.php  # 5-layer server security & hardening healthcheck
│   │   └── Totp.php           # Native RFC 6238 TOTP 2FA & recovery code helper
│   └── Views/
│       └── admin/             # Dashboard, security panel, login & project views
├── public/                    # Web server document root (index.php, local QR code JS)
├── scripts/
│   └── deploy-wrapper.sh      # Hardened input-validating wrapper for /opt/deployhub/deploy.sh
├── tests/unit/                # PHPUnit unit tests (SecurityAudit, Totp, Deployer)
├── writable/
│   ├── logs/deploy/         # Real-time deployment logs per project and branch
│   ├── projects.json        # Domain mapping, branches, and deploy configurations
│   └── users.json           # User credentials and password hashes
└── .env.example             # Environment variable template
```

---

## 🚀 Getting Started

### 1. Clone the repository and install dependencies

```bash
git clone https://github.com/castrohs/webhook-cicd-manager.git
cd webhook-cicd-manager
composer install
```

### 2. Configure Environment Variables (`.env`)

Copy the environment template and customize it:

```bash
cp .env.example .env
```

Open `.env` and set your configuration:
- `CI_ENVIRONMENT = production` (or `development` for local testing)
- `app.baseURL = 'https://your-domain.com/'`
- `WEBHOOK_SECRET = 'your_secret_github_webhook_token'`
- `CERTBOT_EMAIL = 'admin@your-domain.com'`
- `DEPLOY_MYSQL_BACKUP_ENABLED = true` (Set to `false` to disable automatic database backups before migrations)

### 3. Initialize Configuration Files

Copy the provided example files to create your initial users, project mappings, and server inventory:

```bash
cp writable/users.json.example writable/users.json
cp writable/projects.json.example writable/projects.json
cp writable/servers.json.example writable/servers.json
```

> **Default Seed Credentials:**
> - **Username**: `admin`
> - **Password**: `admin123` *(Be sure to change this immediately after your first login via the Profile menu)*

### 4. Directory Permissions & System Requirements

Ensure the `writable/` folder is writable by the web server user (e.g., `www-data`):

```bash
chmod -R 775 writable
sudo chown -R $USER:www-data writable
```

**System Requirements for Deployments:**
If you plan to use automated MySQL backups and seamless PHP-FPM reloads during deployments, ensure the web server user has the correct system privileges:

1. **MySQL Backup Directory**:
```bash
sudo mkdir -p /var/www/backups
sudo chown -R www-data:www-data /var/www/backups
```

2. **PHP-FPM Reload Permission (Sudoers)**:
Allow the web user to reload PHP-FPM without a password prompt. Use `sudo visudo` or run the following command as root to create the rule:
```bash
echo "www-data ALL=(ALL) NOPASSWD: /bin/systemctl reload php8.4-fpm" | sudo tee /etc/sudoers.d/deploy-php-fpm
```

---

## 💻 Running in Development

To start CodeIgniter's built-in development server:

```bash
php spark serve --host 0.0.0.0 --port 8002
```

Then visit in your browser: `http://localhost:8002/login`

---

## 🔗 GitHub Webhook Integration

To connect a GitHub repository for automatic deployments:
1. In your GitHub repository, navigate to **Settings** > **Webhooks** > **Add webhook**.
2. **Payload URL**: `https://your-domain.com/webhook`
3. **Content type**: `application/json`
4. **Secret**: Enter the exact secret configured in `WEBHOOK_SECRET` inside your `.env` file.
5. **Events**: Select *Just the `push` event*.
6. Click **Add webhook**.

---

## 📄 License

Distributed under the [MIT](LICENSE) License.
