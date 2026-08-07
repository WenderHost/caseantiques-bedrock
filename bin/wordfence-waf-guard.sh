#!/usr/bin/env bash
# Runs after every `composer install`/`composer update` (wired via composer.json).
#
# Wordfence Extended Protection points web/.user.ini's `auto_prepend_file` at a
# runtime-generated file inside web/wp/ (Composer-managed, not git-tracked). Any
# deploy that reinstalls the roots/wordpress package recreates web/wp/ from
# scratch and silently wipes that file, leaving .user.ini pointing at nothing —
# which fatals every single PHP request before WordPress even boots (see
# 2026-07-31 incident in wordpress-site-diagnostics/sites/caseantiques.com/incidents/).
#
# This script checks for that condition and neutralizes the directive before it
# can take the site down. It does not attempt to regenerate the file — re-enable
# Extended Protection manually via wp-admin > Firewall > "Optimize the Wordfence
# Firewall" if you want it back after this fires.
set -u

USER_INI="web/.user.ini"

[ -f "$USER_INI" ] || exit 0

line_num="$(grep -nE '^[[:space:]]*auto_prepend_file[[:space:]]*=' "$USER_INI" | head -n1 | cut -d: -f1)"
[ -n "$line_num" ] || exit 0

line_content="$(sed -n "${line_num}p" "$USER_INI")"
target="$(printf '%s' "$line_content" | sed -E "s/^[[:space:]]*auto_prepend_file[[:space:]]*=[[:space:]]*['\"]?([^'\"]*)['\"]?[[:space:]]*\$/\1/")"

[ -n "$target" ] || exit 0
[ -f "$target" ] && exit 0

echo "wordfence-waf-guard: auto_prepend_file (line $line_num of $USER_INI) points to a missing file: $target" >&2
echo "wordfence-waf-guard: commenting out the directive to prevent a site-wide PHP fatal." >&2
echo "wordfence-waf-guard: Wordfence Extended Protection is now effectively OFF. Re-enable manually via wp-admin > Firewall > 'Optimize the Wordfence Firewall' if you want it back." >&2

tmpfile="$(mktemp)" || exit 0
awk -v n="$line_num" 'NR == n { print ";" $0; next } { print }' "$USER_INI" > "$tmpfile" && mv "$tmpfile" "$USER_INI"

exit 0
