#!/usr/bin/env bash
# =============================================================================
# Supervisor deployment — TESTING environment (Debian/Ubuntu)
#
# Debian-based servers use the `supervisor` service and read program
# definitions from /etc/supervisor/conf.d/*.conf. Run this script from the
# `supervisor/` directory: `bash testing.sh`
# =============================================================================
set -euo pipefail

sudo cp -f ./config/workspace_97th_api_testing.conf /etc/supervisor/conf.d/workspace_97th_api.conf
sudo systemctl restart supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start workspace_97th_api:*
sudo supervisorctl status
