# Steve — run these on your Mac to locate the real HuaHinExpats.Co source

This is the one-and-only thing to do right now. The Claude session is in a Linux container and cannot see your Mac. The block below is **read-only** — it lists files, prints headers, reads `git remote -v`. It does not modify anything.

---

## 1. The single command block — copy, paste, run

Open **Terminal** on your Mac. Paste this whole block in. It will print a labeled report and also save it to `~/hhe-source-search.txt`. **Send that file (or paste its contents) back to Claude Code.**

```bash
{
echo "===================== HHE SOURCE SEARCH ====================="
echo "Date: $(date)"
echo "Mac: $(scutil --get ComputerName 2>/dev/null) — user: $USER — home: $HOME"
echo "macOS: $(sw_vers -productName) $(sw_vers -productVersion)"
echo

echo "----- 1A. huahinexpats-core (plugin) directories -----"
find "$HOME" -name "huahinexpats-core" -type d 2>/dev/null

echo
echo "----- 1B. huahinexpats-co (theme) directories -----"
find "$HOME" -name "huahinexpats-co" -type d 2>/dev/null

echo
echo "----- 1C. every wp-config.php under \$HOME -----"
find "$HOME" -name "wp-config.php" -not -path "*/node_modules/*" 2>/dev/null

echo
echo "----- 1D. LocalWP standard locations -----"
for p in "$HOME/Local Sites" "$HOME/Documents/Local Sites" "$HOME/Library/CloudStorage/iCloud Drive/Local Sites"; do
  [ -d "$p" ] && { echo "[FOUND] $p"; ls -la "$p"; echo; }
done

echo
echo "----- 1E. brief-referenced ~/ai-lab/ventures/huahinexpats -----"
[ -d "$HOME/ai-lab/ventures/huahinexpats" ] && ls -la "$HOME/ai-lab/ventures/huahinexpats" || echo "(not present)"

echo
echo "----- 1F. LocalWP sites.json (lists every Local site + URL) -----"
for f in \
  "$HOME/Library/Application Support/Local/sites.json" \
  "$HOME/Library/Application Support/Local by Flywheel/sites.json"; do
  if [ -f "$f" ]; then
    echo "[FOUND] $f"
    python3 - "$f" <<'PY' 2>/dev/null || cat "$f"
import json, sys
d = json.load(open(sys.argv[1]))
sites = d.values() if isinstance(d, dict) else d
for s in sites:
    if not isinstance(s, dict): continue
    name = s.get("name") or s.get("siteName") or "?"
    if "huahin" not in name.lower(): continue
    print(f"  site:   {name}")
    print(f"  path:   {s.get('path') or s.get('localPath')}")
    print(f"  domain: {s.get('domain') or s.get('siteDomain')}")
    print(f"  url:    {s.get('siteUrl') or s.get('localUrl')}")
    print(f"  php:    {s.get('phpVersion')}  wp: {s.get('wordPressVersion')}")
    print()
PY
  fi
done

echo
echo "===================== INSPECT EACH WP SITE FOUND ====================="
# For every wp-config.php found above, print the bits that matter.
while read -r CFG; do
  [ -z "$CFG" ] && continue
  ROOT="$(dirname "$CFG")"
  echo
  echo "######## $ROOT ########"

  echo "-- DB credentials (DB_NAME / DB_USER / DB_HOST only — passwords NOT printed) --"
  grep -E "^[[:space:]]*define\\([[:space:]]*'DB_(NAME|USER|HOST)'" "$CFG" 2>/dev/null

  echo "-- WordPress version --"
  grep "\$wp_version" "$ROOT/wp-includes/version.php" 2>/dev/null | head -1

  echo "-- Themes installed --"
  ls -1 "$ROOT/wp-content/themes" 2>/dev/null

  echo "-- Plugins installed --"
  ls -1 "$ROOT/wp-content/plugins" 2>/dev/null

  echo "-- huahinexpats-co theme header (style.css) --"
  head -12 "$ROOT/wp-content/themes/huahinexpats-co/style.css" 2>/dev/null || echo "(theme dir not found)"

  echo "-- huahinexpats-core plugin entry --"
  for PEN in "$ROOT/wp-content/plugins/huahinexpats-core/huahinexpats-core.php" \
             "$ROOT/wp-content/plugins/huahinexpats-core/plugin.php" \
             "$ROOT/wp-content/plugins/huahinexpats-core/index.php"; do
    [ -f "$PEN" ] && { echo "[$PEN]"; head -20 "$PEN"; break; }
  done

  echo "-- .git presence at common roots --"
  for D in "$ROOT" "$ROOT/wp-content" \
           "$ROOT/wp-content/themes/huahinexpats-co" \
           "$ROOT/wp-content/plugins/huahinexpats-core"; do
    if [ -d "$D/.git" ]; then
      echo "[GIT] $D"
      (cd "$D" && \
        echo "  remote: $(git remote -v | head -2 | tr '\n' '|')" && \
        echo "  branch: $(git branch --show-current 2>/dev/null)" && \
        echo "  HEAD:   $(git log -1 --pretty='%h %s' 2>/dev/null)")
    fi
  done
done < <(find "$HOME" -name "wp-config.php" -not -path "*/node_modules/*" 2>/dev/null)

echo
echo "===================== LIVE-SITE PROBES ====================="
# Works as long as your Mac has normal internet.
for U in \
  "https://huahinexpats.co/" \
  "https://huahinexpats.co/wp-json/" \
  "https://huahinexpats.co/wp-login.php" \
  "https://huahinexpats.co/wp-content/themes/huahinexpats-co/style.css" \
  "https://huahinexpats.co/wp-content/plugins/huahinexpats-core/readme.txt"; do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "$U" 2>/dev/null)
  printf '  %3s  %s\n' "$CODE" "$U"
done
echo "-- stack header signals --"
curl -sI --max-time 8 https://huahinexpats.co/ 2>/dev/null | grep -iE 'server|x-powered|x-pingback|x-litespeed|x-kinsta|x-pantheon|x-wpe'
echo "-- generator meta tag --"
curl -s --max-time 8 https://huahinexpats.co/ 2>/dev/null | grep -iE '<meta name="generator"' | head -3
echo "-- DNS --"
dig +short huahinexpats.co A 2>/dev/null
dig +short huahinexpats.co CNAME 2>/dev/null
echo "-- TLS issuer/subject (sometimes tells you who hosts it) --"
echo | openssl s_client -servername huahinexpats.co -connect huahinexpats.co:443 2>/dev/null \
  | openssl x509 -noout -issuer -subject 2>/dev/null

echo
echo "===================== GITHUB (your account) ====================="
if command -v gh >/dev/null 2>&1; then
  echo "(repos whose name contains 'huahin')"
  gh repo list --limit 200 --json name,description --jq '.[] | select(.name | test("huahin"; "i")) | "\(.name)\t\(.description // "")"' 2>/dev/null
else
  echo "(gh CLI not installed — manually open https://github.com/stevegray1957-lgtm?tab=repositories and search 'huahin')"
fi
echo
echo "===================== END ====================="
} 2>&1 | tee ~/hhe-source-search.txt
echo
echo "Done. Output saved to ~/hhe-source-search.txt"
```

That's it. Wait for it to finish (should take well under a minute), then either send me `~/hhe-source-search.txt` or paste its contents into the next Claude message.

---

## 2. If nothing is found locally — send this to the server administrator

If section 1A/1B/1C/1D/1E/1F all came back empty, the WordPress source only lives on the host. Send this email to whoever runs the server for `huahinexpats.co`:

> **Subject:** Read-only access to the codebase for huahinexpats.co
>
> Hello,
>
> I'm preparing some development work on the website at https://huahinexpats.co and need to set up a local copy of the codebase before staging any changes. I don't currently have one. Could you please provide the following (read-only is fine — I do not need write access to production):
>
> 1. **SSH or SFTP access** to the server, plus the absolute path to the WordPress document root.
> 2. **A tarball of `wp-content/`** (themes, plugins, mu-plugins, uploads):
>    ```
>    tar -C /path/to/wp-content -czf huahinexpats-co_wpcontent_$(date +%F).tar.gz .
>    ```
> 3. **A gzipped database export**:
>    ```
>    mysqldump --single-transaction --quick --routines --triggers <dbname> | gzip > huahinexpats-co_$(date +%F).sql.gz
>    ```
> 4. **Active theme slug**, **list of active plugins**, and **WordPress core version** (from WP Admin → Updates, or `wp core version` / `wp plugin list --status=active` / `wp theme list --status=active` if WP-CLI is installed).
> 5. **Whether the site is in any Git repository** (GitHub / Bitbucket / GitLab). If yes, the repo URL and the branch currently deployed. If yes, please add my GitHub user `stevegray1957-lgtm` as read-only collaborator.
> 6. **How the site is deployed today**: manual SFTP edits, host's git-pull, host-managed Git workflow (Kinsta / WP Engine / Pantheon style), or other.
> 7. **Backup retention and the rollback process**: how many days of automatic snapshots are kept, and the exact steps to roll back if needed.
>
> Please send the two archives over your preferred secure channel — a host-managed file area, an expiring link, or directly via your support portal. They will be handled as confidential.
>
> Thanks,
> Steve

---

## 3. What to paste back into Claude Code

When you next message Claude Code, include **one or both** of:

1. **The local search result** — the contents of `~/hhe-source-search.txt` from §1. The whole file is fine; it does not contain passwords (passwords were filtered out of the wp-config print).
2. **The host's reply** — once your server admin responds to §2, send the answers verbatim (or paraphrase). Especially:
   - the WordPress root path on the server,
   - the active theme slug + active plugin list,
   - whether a Git repo exists and its URL,
   - the deployment method and rollback process.

If a local copy exists (section 1 turns up a `wp-config.php` for `huahinexpats-co`), Claude Code will:
- Point you at the correct local path.
- Walk you through pushing that folder to a new GitHub repo (e.g. `huahinexpats-co-wp`).
- Create the branch `huahinexpats-co-airwallex-enrichment` **in that new repo**, and start the real PHP plugin work there.

If no local copy exists (section 1 is empty), Claude Code will wait for the host's tarball + DB dump from §2 before doing anything further.

---

## What is explicitly NOT happening right now

- No more code is being written in this repo (the Portal, `huahin-expats-portal`).
- No branch is being created in this repo for the `.co` work.
- No deploys, no merges to `main`, no production touches.
- No further Airwallex or enrichment work until the `.co` source is in hand.

Acceptance: one block on the Mac, no production change, no more Portal work, and we get the signal we need to identify the real `.co` source.
