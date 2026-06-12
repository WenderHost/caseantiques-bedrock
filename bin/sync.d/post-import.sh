#!/usr/bin/env bash
set -euo pipefail

cd "$WEB_DIR"

echo "👉 [Elementor] Replacing https://$SYNC_REMOTE_HOST with https://$SYNC_LOCAL_HOST..."
wp elementor replace_urls "https://$SYNC_REMOTE_HOST" "https://$SYNC_LOCAL_HOST"

echo "👉 [Elementor] Flushing CSS..."
wp elementor flush_css
wp plugin deactivate spinupwp limit-login-attempts-reloaded

# Keep project-specific plugin toggles non-fatal.
#wp plugin deactivate spinupwp limit-login-attempts-reloaded || true
#wp plugin activate localdev-switcher || true
