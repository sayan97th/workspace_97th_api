#!/usr/bin/env bash
# =============================================================================
# Supervisor deployment — LOCAL environment (Arch Linux)
#
# Arch ships supervisor with the `supervisord` service and reads program
# definitions from /etc/supervisor.d/*.ini|*.conf. Run this script from the
# `supervisor/` directory: `bash archlinux.sh`
# =============================================================================
set -euo pipefail

sudo cp -f ./config/workspace_97th_api_local.conf /etc/supervisor.d/workspace_97th_api_local.conf
sudo systemctl restart supervisord
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start workspace_97th_api:*
sudo supervisorctl status
