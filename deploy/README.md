# nginx deploy

Syncs this repo with the live config at `/etc/nginx/sites-available/caseantiques.com/` on web3 (174.138.66.201), without needing root on this machine.

## Commands

```bash
deploy/nginx-deploy diff    # show how the server differs from the working tree (changes nothing)
deploy/nginx-deploy pull    # overwrite the working tree with the server's files, then review with git diff
deploy/nginx-deploy push    # deploy the committed custom files; nginx -t gated, auto-rollback
deploy/nginx-deploy test    # run nginx -t on the server
```

`push` deploys `HEAD`, not the working tree, so commit first. It sends only the files listed in `deploy/custom-files.txt`. If `nginx -t` fails on the server, the previous files are restored and nginx is not reloaded. Previous versions of every replaced file are kept in `/var/backups/caseantiques-nginx/<timestamp>/`.

Run `pull` (or `diff`) before editing: SpinupWP rewrites its managed files during server updates, so the mirror drifts.

## How it works

- `nginxdeploy` is a dedicated user on web3. Its only SSH key (`~/.ssh/caseantiques_nginx_deploy` on this machine) is pinned by a forced command to `sudo /usr/local/sbin/caseantiques-nginx-deploy`, with no pty or forwarding, so the key cannot open a shell.
- Its sudoers entry (`/etc/sudoers.d/caseantiques-nginx-deploy`) allows that one script and nothing else.
- The script (`deploy/server/caseantiques-nginx-deploy`) accepts `export`, `test` and `deploy`. `deploy` writes only the files in its hard-coded `ALLOWED` list, so SpinupWP-managed files can't be overwritten, and it never deletes anything.

## Adding a new custom file

1. Add it to `deploy/custom-files.txt` and to `ALLOWED` in `deploy/server/caseantiques-nginx-deploy`.
2. Copy `deploy/server/*` to the server and re-run `sudo bash install.sh` there (needs the sudo user). The allowlist is root-owned on purpose.
3. Commit, then `deploy/nginx-deploy push`.

Removing a custom file from the server is a manual step for the sudo user; the script does not delete.
