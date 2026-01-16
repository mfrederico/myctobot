# MyCTOBot Infrastructure Setup

This guide covers setting up the complete MyCTOBot infrastructure on a new system.

## Architecture Overview

```mermaid
flowchart TB
    subgraph Client["Client Browser"]
        Browser[Web Browser]
    end

    subgraph MainServer["Main Server"]
        subgraph WebStack["Web Stack"]
            Nginx[Nginx Reverse Proxy<br/>:80/:443]
            PHP[PHP-FPM 8.2+]
            App[MyCTOBot App<br/>FlightPHP]
        end

        subgraph AIStack["AI Translation Layer"]
            LiteLLM[LiteLLM Proxy<br/>:4000]
            Ollama[Ollama<br/>:11434]
            GPU[GPU / CUDA]
        end

        subgraph Data["Data Layer"]
            MySQL[(MySQL/MariaDB)]
            SQLite[(SQLite<br/>per-tenant)]
        end
    end

    subgraph Workstations["Remote Workstations"]
        WS1[Workstation 1<br/>Claude CLI]
        WS2[Workstation 2<br/>Claude CLI]
        WS3[Workstation N<br/>Claude CLI]
    end

    subgraph External["External Services"]
        Anthropic[Anthropic API]
        GitHub[GitHub API]
        Jira[Jira/Atlassian]
    end

    Browser -->|HTTPS| Nginx
    Nginx -->|FastCGI| PHP
    PHP --> App
    App --> MySQL
    App --> SQLite
    App -->|SSH| WS1
    App -->|SSH| WS2
    App -->|SSH| WS3

    WS1 -->|Anthropic API Format| LiteLLM
    WS2 -->|Anthropic API Format| LiteLLM
    WS3 -->|Anthropic API Format| LiteLLM
    LiteLLM -->|OpenAI API Format| Ollama
    Ollama --> GPU

    WS1 -.->|Optional Direct| Anthropic
    App --> GitHub
    App --> Jira
```

## Component Interaction Flow

```mermaid
sequenceDiagram
    participant U as User
    participant W as Web App
    participant WS as Workstation
    participant L as LiteLLM :4000
    participant O as Ollama :11434
    participant M as Local Model

    U->>W: Start AI Dev Job
    W->>WS: SSH: Run Claude CLI
    WS->>L: POST /v1/messages (Anthropic format)
    L->>O: POST /v1/chat/completions (OpenAI format)
    O->>M: Inference Request
    M->>O: Generated Response
    O->>L: OpenAI Response
    L->>WS: Anthropic Response
    WS->>W: Job Result
    W->>U: Display Result
```

## Prerequisites

| Component | Version | Purpose |
|-----------|---------|---------|
| Ubuntu/Debian | 22.04+ | Operating System |
| Nginx | 1.18+ | Reverse proxy, static files |
| PHP | 8.2+ | Application runtime |
| Node.js | 20+ | Claude CLI dependency |
| MySQL/MariaDB | 8.0+/10.6+ | Primary database |
| Ollama | Latest | Local LLM server |
| LiteLLM | Latest | API translation proxy |
| Claude CLI | Latest | AI agent runner |

---

## Installation Steps

### 1. System Packages

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install essential packages
sudo apt install -y \
    curl wget git unzip \
    software-properties-common \
    build-essential \
    ca-certificates \
    gnupg lsb-release
```

### 2. Nginx

```bash
# Install Nginx
sudo apt install -y nginx

# Enable and start
sudo systemctl enable nginx
sudo systemctl start nginx

# Verify
nginx -v
```

### 3. PHP 8.2+

```bash
# Add PHP repository (Ubuntu)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP and extensions
sudo apt install -y \
    php8.2-fpm \
    php8.2-cli \
    php8.2-mysql \
    php8.2-sqlite3 \
    php8.2-curl \
    php8.2-xml \
    php8.2-mbstring \
    php8.2-zip \
    php8.2-gd \
    php8.2-intl \
    php8.2-bcmath

# Enable and start PHP-FPM
sudo systemctl enable php8.2-fpm
sudo systemctl start php8.2-fpm

# Verify
php -v
```

### 4. Node.js via NVM

```bash
# Install NVM
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash

# Load NVM (or restart terminal)
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"

# Install Node.js LTS
nvm install --lts
nvm use --lts
nvm alias default node

# Verify
node -v
npm -v
```

### 5. MySQL/MariaDB

```bash
# Install MariaDB
sudo apt install -y mariadb-server mariadb-client

# Secure installation
sudo mysql_secure_installation

# Enable and start
sudo systemctl enable mariadb
sudo systemctl start mariadb

# Create database and user
sudo mysql -e "CREATE DATABASE myctobot;"
sudo mysql -e "CREATE USER 'myctobot'@'localhost' IDENTIFIED BY 'your_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON myctobot.* TO 'myctobot'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
```

### 6. Ollama (GPU Server Only)

```bash
# Install Ollama
curl -fsSL https://ollama.com/install.sh | sh

# Start Ollama service
sudo systemctl enable ollama
sudo systemctl start ollama

# Pull models
ollama pull deepseek-coder-v2:16b
ollama pull devstral:latest
ollama pull llama3.2:latest

# Verify
ollama list
curl http://localhost:11434/v1/models
```

### 7. LiteLLM Proxy (GPU Server Only)

LiteLLM translates Claude CLI's Anthropic API format to Ollama's OpenAI format.

```bash
# Create virtualenv
python3 -m venv ~/.local/share/litellm-proxy

# Install LiteLLM with proxy dependencies
~/.local/share/litellm-proxy/bin/pip install 'litellm[proxy]'

# Create config directory
mkdir -p ~/.config/litellm

# Create config file
cat > ~/.config/litellm/config.yaml << 'EOF'
model_list:
  # Map model names to Ollama
  - model_name: deepseek-coder-v2:16b
    litellm_params:
      model: ollama/deepseek-coder-v2:16b
      api_base: http://localhost:11434

  - model_name: devstral:latest
    litellm_params:
      model: ollama/devstral:latest
      api_base: http://localhost:11434

  - model_name: llama3.2:latest
    litellm_params:
      model: ollama/llama3.2:latest
      api_base: http://localhost:11434

  # Claude model name fallbacks (routes to default model)
  - model_name: sonnet
    litellm_params:
      model: ollama/deepseek-coder-v2:16b
      api_base: http://localhost:11434

  - model_name: claude-sonnet-4-5-20250929
    litellm_params:
      model: ollama/deepseek-coder-v2:16b
      api_base: http://localhost:11434

general_settings:
  master_key: "sk-litellm-proxy-key"
EOF

# Create systemd user service
mkdir -p ~/.config/systemd/user

cat > ~/.config/systemd/user/litellm.service << 'EOF'
[Unit]
Description=LiteLLM Proxy - Anthropic to Ollama translation
After=network.target

[Service]
Type=simple
ExecStart=%h/.local/share/litellm-proxy/bin/litellm --config %h/.config/litellm/config.yaml --host 0.0.0.0 --port 4000
Restart=always
RestartSec=5

[Install]
WantedBy=default.target
EOF

# Enable and start service
systemctl --user daemon-reload
systemctl --user enable litellm
systemctl --user start litellm

# Enable lingering (keeps service running after logout)
sudo loginctl enable-linger $USER

# Verify
systemctl --user status litellm
curl http://localhost:4000/health -H "Authorization: Bearer sk-litellm-proxy-key"
```

### 8. Claude CLI (All Workstations)

```bash
# Install Claude CLI via npm
npm install -g @anthropic-ai/claude-code

# Or via direct download
curl -fsSL https://claude.ai/install.sh | sh

# Verify
claude --version
```

---

## Nginx Configuration

Create `/etc/nginx/sites-available/myctobot`:

```nginx
server {
    listen 80;
    server_name myctobot.example.com;

    # Redirect to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name myctobot.example.com;

    # SSL certificates (use certbot for Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/myctobot.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/myctobot.example.com/privkey.pem;

    root /var/www/myctobot/public;
    index index.php;

    # Logging
    access_log /var/log/nginx/myctobot.access.log;
    error_log /var/log/nginx/myctobot.error.log;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Handle PHP
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # Deny access to sensitive files
    location ~ /\.(ht|git|env) {
        deny all;
    }

    location ~ ^/(conf|lib|models|controls)/ {
        deny all;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/myctobot /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## Workstation Setup

For each remote workstation that runs AI Developer jobs:

```mermaid
flowchart LR
    subgraph Workstation["Workstation Requirements"]
        SSH[SSH Server]
        Node[Node.js 20+]
        Claude[Claude CLI]
        Git[Git]
    end

    subgraph Config["Environment Config"]
        BASE[ANTHROPIC_BASE_URL<br/>= http://gpu-server:4000]
        KEY[ANTHROPIC_API_KEY<br/>= sk-litellm-proxy-key]
    end

    Workstation --> Config
```

### Workstation Install Script

```bash
#!/bin/bash
# workstation-setup.sh

# Install Node.js via NVM
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
nvm install --lts
nvm alias default node

# Install Claude CLI
npm install -g @anthropic-ai/claude-code

# Install Git
sudo apt install -y git

# Configure Git (replace with actual values)
git config --global user.name "AI Bot"
git config --global user.email "aibot@example.com"

# Add environment variables to profile
cat >> ~/.bashrc << 'EOF'

# MyCTOBot AI Configuration
export ANTHROPIC_BASE_URL="http://YOUR_GPU_SERVER_IP:4000"
export ANTHROPIC_API_KEY="sk-litellm-proxy-key"
EOF

source ~/.bashrc

# Verify
claude --version
echo "ANTHROPIC_BASE_URL=$ANTHROPIC_BASE_URL"
```

---

## Port Reference

| Port | Service | Access |
|------|---------|--------|
| 80 | Nginx HTTP | Public |
| 443 | Nginx HTTPS | Public |
| 3306 | MySQL/MariaDB | Local only |
| 4000 | LiteLLM Proxy | Internal network |
| 11434 | Ollama | Local only |
| 22 | SSH | Internal network |

---

## Firewall Configuration

```bash
# UFW setup
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Allow SSH
sudo ufw allow ssh

# Allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Allow LiteLLM from internal network only
sudo ufw allow from 192.168.0.0/16 to any port 4000

# Enable firewall
sudo ufw enable
sudo ufw status
```

---

## Environment Variables

### Main Server (.env or config.ini)

```ini
[database]
host = "localhost"
name = "myctobot"
user = "myctobot"
pass = "your_password"

[app]
baseurl = "https://myctobot.example.com"
debug = false

[ollama]
litellm_host = "http://localhost:4000"
litellm_key = "sk-litellm-proxy-key"
```

### Workstation Environment

```bash
# Required for Claude CLI to use LiteLLM/Ollama
export ANTHROPIC_BASE_URL="http://gpu-server-ip:4000"
export ANTHROPIC_API_KEY="sk-litellm-proxy-key"
```

---

## Service Management

```bash
# Nginx
sudo systemctl status nginx
sudo systemctl restart nginx

# PHP-FPM
sudo systemctl status php8.2-fpm
sudo systemctl restart php8.2-fpm

# MariaDB
sudo systemctl status mariadb
sudo systemctl restart mariadb

# Ollama
sudo systemctl status ollama
sudo systemctl restart ollama

# LiteLLM (user service)
systemctl --user status litellm
systemctl --user restart litellm
journalctl --user -u litellm -f  # View logs
```

---

## Health Checks

```bash
# Check all services
echo "=== Nginx ===" && curl -s -o /dev/null -w "%{http_code}" http://localhost
echo -e "\n=== PHP ===" && php -v | head -1
echo "=== MySQL ===" && mysqladmin ping -u root
echo "=== Ollama ===" && curl -s http://localhost:11434/api/tags | jq -r '.models[].name' | head -5
echo "=== LiteLLM ===" && curl -s http://localhost:4000/health -H "Authorization: Bearer sk-litellm-proxy-key" | jq -r '.healthy_count'
```

---

## Troubleshooting

### LiteLLM 404 Errors
- Ensure Ollama is running: `systemctl status ollama`
- Check model is pulled: `ollama list`
- Verify LiteLLM config has correct model names

### Claude CLI Not Found on Workstation
- Ensure NVM is loaded in SSH sessions
- Add to `~/.bashrc`: `source ~/.nvm/nvm.sh`

### GPU Out of Memory
- Check GPU usage: `nvidia-smi`
- Use smaller models or reduce concurrent jobs
- Consider model quantization (Q4, Q8)

### SSH Connection Refused
- Check SSH service: `systemctl status sshd`
- Verify firewall allows SSH: `sudo ufw status`
- Check SSH key permissions: `chmod 600 ~/.ssh/id_rsa`
