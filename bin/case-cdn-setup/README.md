# case-cdn-setup — caseantiques.com S3 "CDN" reference

How the caseantiques.com image CDN works, how to offload images to it, and how the
nginx fallback-redirects are wired. This is a **custom, manual offload setup** — there
is no offload/CDN plugin involved.

---

## 1. The model: manual offload, not a mirror

- The "CDN" is the S3 bucket **`cdn.caseantiques.com`** (served via `s3.amazonaws.com/cdn.caseantiques.com`).
- You offload by hand: `aws s3 cp` files up, then **delete them from the server disk**.
- Therefore every image lives in **exactly one place — local disk XOR S3, never both.**
- **No WordPress plugin** manages any of this. The database stores **no S3 keys**;
  `_wp_attached_file` holds relative paths (e.g. `auctions/2026-winter/12_3.jpeg`) that
  WordPress resolves against the Bedrock uploads base URL `https://caseantiques.com/app/uploads`.
- nginx is the only bridge between a request URL and where the bytes actually are:
  it tries local disk first, and **on a 404 redirects (302) to S3**.

```
request ──▶ nginx try_files (local disk) ──▶ found? serve 200 locally
                                   └─ 404 ─▶ 302 redirect ──▶ S3 object
```

---

## 2. Bucket layout (two prefixes, by era)

The bucket root has two folders, reflecting the two upload-path eras of the site:

| S3 prefix              | What's there                                              |
|------------------------|-----------------------------------------------------------|
| `app/uploads/`         | Post-Bedrock offloads (current). **Offload here from now on.** |
| `wp-content/uploads/`  | Pre-Bedrock offloads — a large, ~10yr / ~500k-image frozen set. Do not add to it. |

On the **server disk**, all uploads live under `web/app/uploads/` — the WP→Bedrock
migration moved everything there. So the S3 key you offload to should **mirror the
file's path under `web/app/uploads/`**, under the `app/uploads/` prefix.

---

## 3. How to offload a folder to the CDN

Run from **inside the local directory you want to offload**. The S3 destination must
mirror that directory's path relative to `web/app/uploads/`.

```bash
# cd into the folder you want to push, e.g.
#   web/app/uploads/auctions/2026-winter
cd /path/to/web/app/uploads/<SUBPATH>

# Parameterized offload:
CDN_SUBPATH="<SUBPATH>"          # path under app/uploads/, e.g. auctions/2026-winter
aws s3 cp . "s3://cdn.caseantiques.com/app/uploads/${CDN_SUBPATH}" --recursive
```

Concrete example (the real command this is generalized from):

```bash
# from within web/app/uploads/auctions/2026-winter
aws s3 cp . s3://cdn.caseantiques.com/app/uploads/auctions/2026-winter --recursive
```

Then, **after verifying the files resolve** (see §6), delete the local copies to reclaim
disk. Because of the redirect, requests then fall through to S3 automatically.

> ⚠️ Always offload under `app/uploads/...`. Never create new keys under the legacy
> `wp-content/uploads/` prefix.

---

## 4. The nginx redirects — `bin/nginx/server/cdn-redirect.conf`

This is a **custom** (non-SpinupWP-managed) file, so it's safe to edit in place. It is
mirrored on the server at `/etc/nginx/sites-available/caseantiques.com/server/cdn-redirect.conf`.

```nginx
set $production s3.amazonaws.com/cdn.caseantiques.com;

# --- Legacy /wp-content/uploads/* (pre-Bedrock URLs hardcoded in old content) ---
location @prod_uploads {
    rewrite "^(.*)/wp-content/uploads/(.*)$" "https://$production/wp-content/uploads/$2" break;
}
# STOPGAP: the file may now live on disk under app/uploads/ (migration moved it, not yet
# offloaded). Try that local path before falling back to the S3 wp-content/ prefix.
location ~ "^/wp-content/uploads/(.*)$" {
    try_files $uri /app/uploads/$1 @prod_uploads;
}

# --- Bedrock /app/uploads/* (current) ---
location @prod_bedrock_uploads {
    rewrite "^(.*)/app/uploads/(.*)$" "https://$production/app/uploads/$2" break;
}
location ~ "^/app/uploads/(.*)$" {
    try_files $uri @prod_bedrock_uploads;
}
```

Resolution matrix:

| Request URL                  | File on disk        | File offloaded to S3            | Result            |
|------------------------------|---------------------|--------------------------------|-------------------|
| `/app/uploads/X`             | yes                 | —                              | 200 local         |
| `/app/uploads/X`             | no                  | `app/uploads/X`                | 302 → S3 → 200    |
| `/wp-content/uploads/X`      | yes (at app/uploads)| —                              | 200 local (stopgap) |
| `/wp-content/uploads/X`      | no                  | `wp-content/uploads/X`         | 302 → S3 → 200    |

---

## 5. Deploying an nginx change

The local mirror of the server's nginx config lives in `bin/nginx/` (standalone git
repo, no remote, hand-synced). To deploy a change to `cdn-redirect.conf`:

```bash
# copy local file → server include dir
#   /etc/nginx/sites-available/caseantiques.com/server/cdn-redirect.conf
sudo nginx -t            # validate config
sudo systemctl reload nginx
```

See also the nginx customization convention: put custom config in **separately-named**
`.conf` files (never inside SpinupWP-managed ones), so it survives provider upgrades.

---

## 6. Verifying

```bash
# On-disk file via legacy URL — should be 200, served locally, NO redirect:
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" \
  "https://caseantiques.com/wp-content/uploads/2025/03/<file>.jpeg"

# Offloaded file — should 302 to S3, then 200 when followed:
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" \
  "https://caseantiques.com/app/uploads/auctions/<auction>/<file>.jpeg"
curl -sIL "https://caseantiques.com/app/uploads/auctions/<auction>/<file>.jpeg" | tail -1

# Direct S3 check (200 = present, 403 = absent / no list permission):
curl -s -o /dev/null -w "%{http_code}\n" \
  "https://s3.amazonaws.com/cdn.caseantiques.com/app/uploads/auctions/<auction>/<file>.jpeg"
```

---

## 7. Background & decisions (from the 2026-06-17 session)

**Why the stopgap exists.** Old content carries ~984 hardcoded `https://caseantiques.com/wp-content/uploads/...`
URLs (plus ~1,182 Elementor rows, which store URLs with **escaped slashes** `\/`). After
the WP→Bedrock migration those files live on disk at `app/uploads/...`, so the old URLs
404'd and the `wp-content/` S3 fallback didn't have them either. The stopgap's
`try_files ... /app/uploads/$1 ...` serves them straight from disk.

**Stopgap caveat.** It holds only while a legacy-URL'd file is still on disk. Once you
offload such a file, `aws s3 cp` puts it under `app/uploads/` (its disk path) but its
hardcoded `/wp-content/uploads/` URL redirects to the `wp-content/` S3 prefix → 404.
At-risk set is narrow (recent content with old URLs). If it ever bites, the fix is a
**targeted `wp search-replace`** of `/wp-content/uploads/ → /app/uploads/` limited to the
still-on-disk files (safe because they aren't in S3 yet) — NOT a bucket move.

**Why we did NOT consolidate the bucket.** A clean single-prefix end state (move
`wp-content/uploads/*` → `app/uploads/*` in S3 + a site-wide `wp search-replace`, with a
second pass for Elementor's escaped slashes) is correct but was **rejected on ROI**:
~500k objects / ~1TB, and a bare `aws s3 ls --recursive --summarize` ran 15–20 min+
without finishing. Notes if it's ever revisited:

- Same-region in-bucket copy is **free for data transfer**; there is **no S3 storage
  quota** to blow past — the cost is time + a few dollars in requests.
- Use `aws s3 mv` (not `cp`) to avoid a temporary 2× storage footprint; or **S3 Batch
  Operations** (manifest-driven Copy job) for the scale.
- **Check storage class first** — decade-old objects may be in Glacier/Deep Archive and
  would need a restore (cost + hours) before they can be copied.
- Do it **copy-first, verify, then delete** the old prefix — never delete before verifying.
