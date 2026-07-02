#!/usr/bin/env bash
# =============================================================================
# Vhost deployment script — Workspace 97th API (Testing)
# Domain  : api.workspace97th.97dev.com
#
# Usage:
#   bash vhost/testing.sh
#
# Deploys the Nginx virtual host for the testing environment.
# Target server: Ubuntu / Debian
# =============================================================================

set -euo pipefail

conf_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/conf"
domain="api.workspace97th.97dev.com"

deployNginx() {
    local src="${conf_dir}/nginx/testing.conf"
    local dst="/etc/nginx/sites-available/${domain}.conf"

    echo "[workspace_97th_api] Copying Nginx config to ${dst} ..."
    sudo cp -f "${src}" "${dst}"

    echo "[workspace_97th_api] Enabling site ..."
    sudo ln -sf "${dst}" "/etc/nginx/sites-enabled/${domain}.conf"

    echo "[workspace_97th_api] Testing Nginx configuration ..."
    sudo nginx -t

    echo "[workspace_97th_api] Reloading Nginx ..."
    sudo systemctl reload nginx

    echo "[workspace_97th_api] Nginx site enabled: ${domain}"
}

deployNginx
