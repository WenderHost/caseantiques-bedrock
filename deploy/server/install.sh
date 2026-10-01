#!/usr/bin/env bash
#
# One-time server setup for the nginx deploy gate. Run on web3 with sudo, from
# the directory holding this file plus caseantiques-nginx-deploy, sudoers and
# authorized_keys. Safe to re-run (e.g. after changing the allowlist).

set -euo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo "Run with sudo." >&2; exit 1; }

HERE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
DEPLOY_USER=nginxdeploy
DEPLOY_HOME=/home/$DEPLOY_USER

for f in caseantiques-nginx-deploy sudoers authorized_keys; do
  [[ -f "$HERE/$f" ]] || { echo "Missing $HERE/$f" >&2; exit 1; }
done

bash -n "$HERE/caseantiques-nginx-deploy"
visudo -cf "$HERE/sudoers"

if ! id "$DEPLOY_USER" &>/dev/null; then
  useradd --create-home --home-dir "$DEPLOY_HOME" --shell /bin/bash "$DEPLOY_USER"
  echo "Created user $DEPLOY_USER"
fi

# Root-owned so the deploy user can't loosen its own forced command.
install -d -o root -g root -m 755 "$DEPLOY_HOME/.ssh"
install -o root -g root -m 644 "$HERE/authorized_keys" "$DEPLOY_HOME/.ssh/authorized_keys"

install -o root -g root -m 755 "$HERE/caseantiques-nginx-deploy" /usr/local/sbin/caseantiques-nginx-deploy
install -o root -g root -m 440 "$HERE/sudoers" /etc/sudoers.d/caseantiques-nginx-deploy

echo "Installed. Deploy gate: /usr/local/sbin/caseantiques-nginx-deploy"
