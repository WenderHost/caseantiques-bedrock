#!/usr/bin/env bash
set -euo pipefail
# Runs after Spinup pulls production → local (DB imported, URLs rewritten).
# Spinup exports these for you:
#   WEB_DIR           local project root (this script starts here)
#   SYNC_REMOTE_HOST  production host, e.g. example.com
#   SYNC_LOCAL_HOST   local host,      e.g. example.test
cd "$WEB_DIR"

# --- Examples — uncomment what your project needs ----------------------
# Elementor stores absolute URLs in serialized data; rewrite + reflush:
wp elementor replace_urls "https://$SYNC_REMOTE_HOST" "https://$SYNC_LOCAL_HOST"
wp elementor flush_css
#
# Turn off prod-only plugins locally (keep non-fatal with || true):
# wp plugin deactivate spinupwp limit-login-attempts-reloaded || true
wp plugin activate localdev-switcher || true
# -----------------------------------------------------------------------

echo "post-import hook ran"
