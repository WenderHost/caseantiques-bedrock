# caseantiques.com — Server Log Analysis Runbook

Purpose: process a fresh batch of nginx logs from the caseantiques.com production
server (web3), compare against the June 2026 baseline, and produce a client-facing
report. Read this first whenever the user drops a new `bin/logs/YYYY-MM-DD/` folder
and asks to "examine the logs" / "see if the fixes are still holding."

Background (why we do this): June 2026 the site went down almost daily ~07:00 ET
from a morning bot crawl hammering AIOSEO sitemaps (~500k image-attachment URLs) +
malformed `[CDATA[` URLs. Fixes deployed 2026-06-15. Full story:
`bin/logs/downtime-analysis.html`, `bin/logs/index.html` (client report),
`bin/logs/2026-06-16/monitoring-2026-06-16.html` (day-after confirmation).
nginx config mirror + conventions: `bin/nginx/` (see "Server-side" below).

---

## Log layout

- `bin/logs/YYYY-MM-DD/` — one snapshot per download day. Inside each:
  - `access.log`, `error.log`, `debug.log` — the **live** (current-day, partial) files.
  - `access.log-YYYYMMDD.gz`, `error.log-YYYYMMDD.gz` — rotated archives.
- `bin/logs/*.html` — generated reports (keep at top level or in the date folder).
- The newest date folder = the batch to analyze. Prior folders are history.

---

## ⚠️ Gotchas (these will bite you — read before running anything)

1. **Use `gunzip -c`, NOT `zcat`.** On macOS `zcat` silently produces *empty output*
   (it expects a `.Z` suffix). Every count comes back 0 and looks like "great news."
   Always `gunzip -c file.gz`.
2. **The failure signal is HTTP `499`, not 5xx.** When the site is overwhelmed,
   requests hang until the client/uptime-monitor gives up → logged as `499`. Actual
   `502/503` are ~absent; `504` is a small secondary signal. Count 499s.
3. **gz rotation is off-by-one:** `access.log-20260614.gz` contains **Jun 13** traffic
   (rotated at 00:00 Jun 14). The live `access.log` holds the current/most-recent day.
4. **Timestamps are `-0400` = US Eastern.** No timezone conversion needed; the outage
   window is **06:00–08:59 ET** (peak ~07:00).
5. **`error.log` only retains ~2 days.** Don't expect historical PHP timeouts there;
   derive the before/after from `access.log` 499s instead.
6. **`302` is NOT a clean signal.** The site emitted ~250k–350k redirects/day even
   before the fix (http→https, www, attachment→parent). Don't read 302 volume as
   "the fix working."

### access.log field map (combined format)
`IP - - [16/Jun/2026:00:00:01 -0400] "METHOD /path HTTP/1.1" STATUS BYTES "ref" "ua"`
- status = `$9`
- date  = `substr($4,2,11)`  → e.g. `16/Jun/2026`
- hour  = `substr($4,14,2)`  → e.g. `07`
- 10-min bucket = `substr($4,14,4)"0"` → e.g. `07:10`

---

## Analysis recipes

Set the target folder first:
```bash
cd /Users/mwender/webdev/laravel-valet/bedrock/caseantiques.com/app/bin/logs/<NEW-DATE>
```
Most commands iterate **all** access logs (archives + live) so you get a full timeline.
Helper to stream every access log oldest→newest:
```bash
allaccess() { for f in $(ls access.log-*.gz 2>/dev/null | sort); do gunzip -c "$f"; done; cat access.log; }
```

**1. Per-day status mix (total / 499 / 504 / 302):**
```bash
for f in $(ls access.log-*.gz | sort); do
  gunzip -c "$f" | awk '{tot++; s=$9; if(s=="499")a++; else if(s=="504")b++; else if(s=="302")c++}
    NR==1{d=substr($4,2,11)} END{printf "%-12s tot=%-8d 499=%-6d 504=%-4d 302=%d\n",d,tot,a,b,c}'
done
awk '{tot++; s=$9; if(s=="499")a++; else if(s=="504")b++; else if(s=="302")c++}
  NR==1{d=substr($4,2,11)} END{printf "%-12s tot=%-8d 499=%-6d 504=%-4d 302=%d  (live)\n",d,tot,a,b,c}' access.log
```

**2. Morning outage window (06–08 ET) — the key before/after table:**
```bash
win() { awk '{h=substr($4,14,2); if(h>="06"&&h<="08"){w++; if($9=="499")x++; else if($9=="504")y++}}
  NR==1{d=substr($4,2,11)} END{printf "%-12s reqs=%-7d 499=%-6d 504=%d\n",d,w,x,y}'; }
for f in $(ls access.log-*.gz | sort); do gunzip -c "$f" | win; done
win < access.log
```

**3. Minute-level shape of the 7AM hour for ONE day (live = new day):**
```bash
awk '{t=substr($4,14,5); if(t>="06:30"&&t<="08:00"){b=substr($4,14,4)"0"; n[b]++; if($9=="499")e[b]++}}
  END{for(k in n) printf "%s reqs=%-5d 499=%d\n",k,n[k],e[k]+0}' access.log | sort
# For a BEFORE day, replace `access.log` with `gunzip -c access.log-YYYYMMDD.gz |` piped into the awk.
```

**4. Root-cause signals — malformed CDATA URLs (expect 0 now):**
```bash
awk '/CDATA/{c++; s[$9]++} END{print "CDATA reqs:",c+0; for(k in s)print "  "k":",s[k]}' access.log
```

**5. Root-cause signals — attachment-sitemap (expect ~0 status-200 generations now):**
```bash
awk '/attachment-sitemap/{c++; s[$9]++} END{print "attachment-sitemap reqs:",c+0; for(k in s)print "  "k":",s[k]}' access.log
```

**6. Crawler volumes (did the wave still arrive?):**
```bash
echo "Meta:      $(grep -icE 'meta-externalagent|meta-webindexer|facebookexternalhit' access.log)"
echo "Bytespider:$(grep -ic 'Bytespider' access.log)"
echo "bingbot:   $(grep -ic 'bingbot' access.log)"
echo "Googlebot: $(grep -ic 'Googlebot' access.log)"
```

---

## Baseline to compare against (June 2026)

**Before fixes — morning window (06–08 ET) 499s / 504s:**

| Day | 499 | 504 |
|---|---|---|
| Jun 4 | 1,747 | 76 |
| Jun 5 | 1,541 | 28 |
| Jun 6 (worst) | 6,768 | 50 |
| Jun 7 | 1,409 | 92 |
| Jun 13 | 2,004 | 45 |

**After fixes:** Jun 15 = 65 / 0 · Jun 16 = 43 / 0.
Jun 13 7AM 499s by 10-min: 950 (07:00), 676 (07:10), 333 (07:20) → Jun 16: 5, 4, 0.
Root-cause signals Jun13→Jun16: CDATA 1,000→0; attachment-sitemap 200-gens 675→1; 504s 45→0.
Crawlers Jun13→Jun16: Meta 10,486→4,562; Bytespider 13,924→15,851 (burst still came, didn't matter).

A healthy new batch looks like: morning 499s in the tens (not thousands), 504s ≈ 0,
CDATA ≈ 0, attachment-sitemap 200s ≈ 0, even if Meta/Bytespider volume is high.
If 499s spike back into the hundreds/thousands at ~07:00, something regressed —
check the nginx verifications below first.

---

## Report generation

Match the existing client deliverable style — **copy `bin/logs/index.html` or
`bin/logs/2026-06-16/monitoring-2026-06-16.html`** as the template (light "Wenmark
Digital" masthead theme, paper cards, KPI grid, before/after tables, and a
**Copy-for-Basecamp Markdown** `<textarea>` block at the bottom with the JS copy button).

- Save into the date folder: `bin/logs/<NEW-DATE>/monitoring-<NEW-DATE>.html`.
- Lead the client copy with the strongest evidence: "the bot wave still arrived (cite
  Bytespider volume) and the site held" — that proves it's the fix, not a quiet day.
- Always include the morning-window before/after table and the 7AM 10-min comparison.
- `open <file>.html` to preview; offer to send via SendUserFile if useful.

---

## Server-side verification (when a result looks off, or after any nginx change)

SSH to web3 (`root@web3`, 174.138.66.201). Config lives at
`/etc/nginx/sites-available/caseantiques.com/`. Mirror: `bin/nginx/`.

```bash
sudo nginx -t                                                  # syntax gate
sudo nginx -T | grep -nE 'skip_cache|sitemap'                  # sitemap override (set 0) must appear AFTER managed set 1
sudo nginx -T | grep -nE 'fastcgi_cache|cache_lock|use_stale'  # stampede directives + 'updating' present
# live behaviour:
curl -sI https://caseantiques.com/sitemap.xml | grep -i fastcgi-cache         # expect HIT (after 1st warm)
curl -s -o /dev/null -w '%{http_code}\n' -g --path-as-is \
  "https://caseantiques.com/[CDATA[https:/caseantiques.com/item_tags/molded/]]"  # expect 410
```

**nginx customization convention (per SpinupWP support):** never edit SpinupWP's
managed files (`server/fastcgi-cache.conf`, `location/fastcgi-cache.conf`). Put custom
config in separately-named `.conf` files in the same `server/` or `location/` include
dir — the `*/*` wildcard loads them alphabetically (glob sort). To override a value the
managed file sets, name the file so it sorts AFTER it (e.g. `server/sitemap-cache.conf`
> `fastcgi-cache.conf`). Current customs: `server/sitemap-cache.conf`,
`location/cache-stampede-protection.conf`, `server/kill-malformed-urls.conf` (the 410).
Deploy = copy files to server include dirs → `sudo nginx -t` → `sudo systemctl reload nginx`.
```
