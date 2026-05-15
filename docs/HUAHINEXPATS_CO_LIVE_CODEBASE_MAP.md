# HuaHinExpats.Co — Live Codebase Map

**Question Steve asked:** *Is anything I am feeding into this Claude Code repo being pushed live to https://HuaHinExpats.Co?*

**Short answer: No. Nothing committed to this repo can reach `huahinexpats.co` automatically.** Details and the (small) list of things that could falsify this are below, along with the exact info needed from your server administrator to fully complete the map.

---

## 1. Is this repo the codebase for `https://huahinexpats.co`?

**No.** Verified by exhaustive in-repo audit on commit `d5b2800`:

| Check | Result |
|---|---|
| Repo name | `stevegray1957-lgtm/huahin-expats-portal` — not `-co`. |
| Branding in every static page | `https://HuaHinExpatsPortal.com/...` — different domain (`.com` ≠ `.co`). |
| Logo asset | `public/images/logo-huahin-expatsportal.png`. |
| `.env.example` | `SITE_URL=https://HuaHinExpatsPortal.com`. |
| `vercel.json` | Configured for Vercel `@vercel/node`. |
| PHP files | **None** anywhere in the tree (`find . -name '*.php' -not -path './node_modules/*'` returns empty). |
| WordPress markers | **None** (`grep -ri "wp-content\|wp-includes\|wp-json\|wp-admin\|wordpress\|huahinexpats-(core\|co)"` returns only my own docs from yesterday). |
| `composer.json` / `wp-config.php` | Absent. |
| `wp-content/themes/huahinexpats-co/` | Does not exist in this repo. |
| `wp-content/plugins/huahinexpats-core/` | Does not exist in this repo. |
| Theme `style.css` | The only `style.css` is `public/css/style.css`, a plain web stylesheet for the Portal — not a WP theme header. |

So this repo is **not** the WordPress codebase the brief was describing.

## 2. Are commits I make here being pushed live to `huahinexpats.co`?

**No automated path from this repo to `huahinexpats.co` exists in this checkout.** Specifically:

| Possible path | Status here |
|---|---|
| GitHub Actions workflow | **None** — `.github/workflows/` directory does not exist. |
| GitLab CI / CircleCI / other CI | **None** — no `.gitlab-ci.yml`, no `.circleci/`. |
| Vercel project linked to this checkout | **None** — `.vercel/` directory does not exist. (Vercel may still be linked to the GitHub repo via Vercel's dashboard; see "what I cannot verify" below.) |
| Netlify config | **None** — no `netlify.toml`. |
| Fly / Render config | **None** — no `fly.toml`, `render.yaml`. |
| Procfile / Dockerfile | **None**. |
| `deploy.sh` or similar | **None**. |
| Multiple git remotes | **None** — only one remote `origin → stevegray1957-lgtm/huahin-expats-portal`. There is no second remote pointing at the live WP server. |
| post-commit / post-receive hooks in `.git/hooks/` | All hooks are the default Git samples; none active. |
| Vercel CLI link | None present in working tree. |

So every push from this checkout lands at exactly one place: the GitHub repository `stevegray1957-lgtm/huahin-expats-portal`. From there:

- It **can** trigger a build on a Vercel project if the Vercel dashboard has a project bound to that GitHub repo. That project's custom domain (set in the Vercel dashboard, not in this repo) determines what site goes live. **If that domain is `huahinexpatsportal.com`, then commits update the Portal — not `huahinexpats.co`.**
- It **cannot** push to a WordPress server. There is no WP-Pusher, no SFTP step, no `wp deploy`, no rsync, no git-pull cron configured here.

### What would falsify this (I cannot inspect from this container)

You should check these three things — none of which live in this repo, but any of them could create an invisible pipeline:

1. **Vercel dashboard** at https://vercel.com → your projects. Look for any project bound to this GitHub repo. For each, note the custom domains attached. **If `huahinexpats.co` is attached to a Vercel project that uses this repo, then yes — commits would deploy there, and you need to either detach the domain or stop pushing.**
2. **Cloudflare or your DNS provider.** Look up the DNS for `huahinexpats.co`. The IP / CNAME tells you the host.
3. **GitHub repo settings** on `stevegray1957-lgtm/huahin-expats-portal` → Settings → Webhooks. List the registered webhooks. Common deploy webhooks point at Vercel, Netlify, or a custom `deploy.example.com/hook`.

I cannot reach the GitHub web UI, the Vercel dashboard, or DNS from this remote-execution container. You can do this in five minutes; I cannot.

## 3. Where is the real `huahinexpats.co` codebase?

**Unknown to me.** It is somewhere outside this repo. Best-guess candidate locations:

- **A managed WordPress host's filesystem** with no git mirror (very common for sites built originally with Elementor, Divi, etc.). The host could be Kinsta, WP Engine, SiteGround, GoDaddy, Hostinger, Bluehost, IONOS, OVH.
- **A LocalWP installation on your laptop** (path varies by OS):
  - macOS: `~/Local Sites/huahinexpats-co/app/public/`
  - Windows: `C:\Users\<you>\Local Sites\huahinexpats-co\app\public\`
  - Linux: `~/Local Sites/huahinexpats-co/app/public/`
- **A different GitHub/GitLab/Bitbucket repository** under your account that I cannot see from here. Search your GitHub repos for the keyword `huahinexpats-co` or `-core`.
- **An older self-managed VPS** where WordPress was installed via cPanel/Plesk, code edited live, no git.

If the `huahinexpats-core` plugin actually exists (the brief referenced it as if it does), then its source is in one of the four buckets above.

## 4. Live codebase map (filled in as far as I can)

| Field | Value | Confidence |
|---|---|---|
| Live domain | `https://huahinexpats.co` | Stated by Steve. |
| Production stack | Reported as WordPress + theme `huahinexpats-co` + plugin `huahinexpats-core` | Stated in the original brief; not verified from inside this container (egress to that domain is blocked: `x-deny-reason: host_not_allowed`). |
| Production database type | MySQL (almost certain, as standard for WP) | Inferred from "WordPress". Not verified. |
| Admin URL | `https://huahinexpats.co/wp-admin/` (WP convention) | Inferred. **Verify before relying on this.** |
| Repo name | **UNKNOWN** | Not in your local known repos that I can see. Possibly does not exist in git form. |
| Branch deployed | **UNKNOWN** | N/A unless a repo is identified. |
| Deployment method | **UNKNOWN** | Most likely "edit on host via WP Admin / SFTP" if there's no repo. |
| Server path (web root) | **UNKNOWN** | Typical: `/var/www/html/` on a VPS, `/home/<user>/public_html/` on cPanel, `~/Local Sites/huahinexpats-co/app/public/` on LocalWP. |
| Theme path | Convention: `wp-content/themes/huahinexpats-co/` | Verify against the live site (see §6). |
| Plugin path | Convention: `wp-content/plugins/huahinexpats-core/` | Verify against the live site (see §6). |
| How to push local changes live | **UNKNOWN** until host is identified | If no git pipeline exists, it's likely SFTP/SSH/WP-CLI/host control panel. |
| Rollback method | **UNKNOWN** | Most managed WP hosts offer automatic daily snapshots — that is the most likely rollback path. |

## 5. Exactly what I need from you (or your server admin) to finish this map

Please run these commands from **any machine with normal internet access** (e.g. your laptop, not this Claude container — which has restricted egress).

```bash
# 1. Confirm the stack
curl -sI https://huahinexpats.co/ | grep -iE 'server|x-powered|x-pingback'

# 2. Look for the WordPress generator and standard asset paths
curl -s https://huahinexpats.co/ | grep -iE 'wp-content|wp-includes|generator|<meta name="generator"'

# 3. WP REST API (only present on WordPress sites)
curl -sI https://huahinexpats.co/wp-json/ | head -1
curl -s  https://huahinexpats.co/wp-json/ | head -200

# 4. The login page (WP convention)
curl -sI https://huahinexpats.co/wp-login.php | head -1

# 5. Theme directory (confirms theme slug)
curl -sI https://huahinexpats.co/wp-content/themes/huahinexpats-co/style.css | head -1
curl -s  https://huahinexpats.co/wp-content/themes/huahinexpats-co/style.css | head -10

# 6. Plugin marker (any of these will return 200 or 403 if the plugin exists, 404 if not)
curl -sI https://huahinexpats.co/wp-content/plugins/huahinexpats-core/readme.txt   | head -1
curl -sI https://huahinexpats.co/wp-content/plugins/huahinexpats-core/             | head -1

# 7. DNS to find the host
dig +short huahinexpats.co A
dig +short huahinexpats.co CNAME
dig +short www.huahinexpats.co A
# Then for the resulting IP/CNAME, reverse lookup or whois to identify the host (e.g. Kinsta, SiteGround).

# 8. SSL cert issuer (sometimes tells you who hosts it)
echo | openssl s_client -servername huahinexpats.co -connect huahinexpats.co:443 2>/dev/null | openssl x509 -noout -issuer -subject
```

Send me the output of those (or paste anything you can identify) and I can complete the map.

**Separately**, in your hosting account:

- Note the host name (Kinsta dashboard? cPanel? Plesk? WP Engine? etc.).
- Note the FTP/SFTP/SSH credentials path — but **do not** paste them into the chat. Just confirm "I have SSH access" or "host has only WP Admin access".
- Check **GitHub Account → your repositories** for any other repo named `huahinexpats-co`, `huahinexpats-core`, `huahinexpats-wp`, or similar.

**Separately**, on your laptop:

```bash
# macOS / Linux
ls -la ~/"Local Sites" 2>/dev/null
ls -la ~/Documents/"Local Sites" 2>/dev/null
ls -la ~/"Sites" 2>/dev/null
find ~ -maxdepth 5 -type d -name "huahinexpats-co" 2>/dev/null
find ~ -maxdepth 6 -type d -name "huahinexpats-core" 2>/dev/null
```

If LocalWP shows a site there, the theme/plugin source is at:

```
~/Local Sites/huahinexpats-co/app/public/wp-content/themes/huahinexpats-co/
~/Local Sites/huahinexpats-co/app/public/wp-content/plugins/huahinexpats-core/
```

## 6. What to do next

1. **Do not merge this branch** (`claude/integrate-airwallex-payments-pcMOu`) to `main` until you have separately decided whether `huahinexpatsportal.com` (the Portal — what this repo actually builds) should be deployed.
2. **No more Airwallex / enrichment code** in this repo until §5 above resolves: there is no point continuing if the wrong codebase is being modified.
3. **Audit the Vercel dashboard** for any project bound to this GitHub repo. If one exists and is attached to `huahinexpats.co`, detach the custom domain or stop pushing.
4. **Once the real `.co` codebase is located**, decide:
   - Option A: re-implement Airwallex + enrichment as a real WordPress plugin (`huahinexpats-core`), in the correct repo. This means PHP, the WP REST API, post-meta, WP nonces, the WP hooks system. The data model and webhook design from this branch are portable as a reference.
   - Option B: keep `huahinexpats.co` as a content site, deploy the Portal here at `huahinexpatsportal.com` as the payments/enrichment app, and link to it from the `.co` site.
   - Option C: migrate `huahinexpats.co` to point at the Node Portal (DNS swap). Larger change; affects SEO and existing WP content.

I won't pick between A/B/C — it's a product call.

## 7. Confidence summary

- **Very high confidence:** Nothing in this Claude Code repo reaches `huahinexpats.co` automatically from this checkout. There is no CI, no Vercel link file, no deploy script, no second remote.
- **High confidence:** The repo here is for the Portal at `huahinexpatsportal.com`, not for the `.co` WordPress site.
- **Cannot verify from this container:** Whether the GitHub repo `stevegray1957-lgtm/huahin-expats-portal` is bound to a Vercel project on your dashboard, and (if so) what domain that project's deploys go to. **This is the only realistic path by which pushes could be reaching anywhere "live" — and it lives outside this checkout.**
