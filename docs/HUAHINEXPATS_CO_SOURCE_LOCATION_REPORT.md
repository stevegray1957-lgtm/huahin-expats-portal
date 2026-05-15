# HuaHinExpats.Co — Source Location Report

## 0. Hard constraint

The brief asked me to **search Steve's Mac** for the WordPress source. **I cannot.** This Claude Code session is running in a remote-execution container (`uname -a → Linux vm 6.18.5 x86_64`), not on Steve's local machine. Specifically:

- `HOME = /root`. Steve's Mac would have `HOME = /Users/Steve`.
- The repo was cloned fresh into this container; there is no LocalWP install, no `~/Local Sites`, no `~/ai-lab` here.
- This container has restricted outbound network (probing `huahinexpats.co` returns `x-deny-reason: host_not_allowed`), so I cannot fetch the live site either.

**Steve must run the search commands on his own Mac.** Section 2 is the verbatim script for him to run.

## 1. What I ran inside this container (for transparency)

The brief's three `find` commands, executed on this container:

```text
$ find ~ -name "huahinexpats-core" -type d 2>/dev/null
(no results)

$ find ~ -name "huahinexpats-co" -type d 2>/dev/null
(no results)

$ find ~ -name "wp-config.php" 2>/dev/null | grep -i huahin
(no results)
```

I also searched the whole container filesystem for any `wp-config.php`, any `Local Sites` directory, any `.lh-version` LocalWP marker, and the brief's referenced `~/ai-lab/ventures/huahinexpats/` path. **All empty.** That is not evidence about Steve's Mac — it is evidence that this container has nothing to do with Steve's Mac.

## 2. Steve: run these on your Mac

Open Terminal on your Mac and paste each block. They are read-only; nothing is modified.

### 2.1 Find any WordPress codebase named `huahinexpats-*` on the Mac

```bash
echo "--- huahinexpats-core (plugin) ---"
find ~ -name "huahinexpats-core" -type d 2>/dev/null

echo "--- huahinexpats-co (theme) ---"
find ~ -name "huahinexpats-co" -type d 2>/dev/null

echo "--- any wp-config.php with 'huahin' in the path ---"
find ~ -name "wp-config.php" 2>/dev/null | grep -i huahin

echo "--- ALL wp-config.php on the Mac (might reveal sites you forgot about) ---"
find ~ -name "wp-config.php" 2>/dev/null

echo "--- LocalWP sites root (the standard path) ---"
ls -la "$HOME/Local Sites" 2>/dev/null
ls -la "$HOME/Documents/Local Sites" 2>/dev/null

echo "--- brief referenced ~/ai-lab/ventures/huahinexpats ---"
ls -la "$HOME/ai-lab/ventures/huahinexpats" 2>/dev/null
find "$HOME/ai-lab" -maxdepth 5 -type d 2>/dev/null | head -30

echo "--- any LocalWP marker files (LocalWP labels each site dir) ---"
find ~ -maxdepth 6 -name "site.conf" 2>/dev/null | head -10
find ~ -maxdepth 6 -name ".lh-version" 2>/dev/null | head -10
```

### 2.2 Inspect the WordPress site once you locate it

For each `wp-config.php` directory the previous block finds, run:

```bash
# Replace this path with what you found.
SITE_ROOT="$HOME/Local Sites/huahinexpats-co/app/public"

echo "--- WordPress version ---"
grep "\$wp_version" "$SITE_ROOT/wp-includes/version.php" 2>/dev/null | head -1

echo "--- DB credentials (the lines that matter) ---"
grep -E "^define\\( *'DB_" "$SITE_ROOT/wp-config.php" 2>/dev/null

echo "--- theme directories ---"
ls -la "$SITE_ROOT/wp-content/themes" 2>/dev/null

echo "--- plugin directories ---"
ls -la "$SITE_ROOT/wp-content/plugins" 2>/dev/null

echo "--- huahinexpats-core plugin header (confirms plugin exists locally) ---"
head -20 "$SITE_ROOT/wp-content/plugins/huahinexpats-core/huahinexpats-core.php" 2>/dev/null
head -20 "$SITE_ROOT/wp-content/plugins/huahinexpats-core/plugin.php" 2>/dev/null

echo "--- huahinexpats-co theme header (confirms theme exists locally) ---"
head -20 "$SITE_ROOT/wp-content/themes/huahinexpats-co/style.css" 2>/dev/null

echo "--- is the WP root git-managed? ---"
ls -la "$SITE_ROOT/.git" 2>/dev/null
ls -la "$SITE_ROOT/wp-content/.git" 2>/dev/null
ls -la "$SITE_ROOT/wp-content/themes/huahinexpats-co/.git" 2>/dev/null
ls -la "$SITE_ROOT/wp-content/plugins/huahinexpats-core/.git" 2>/dev/null

echo "--- if any .git was found, its remote ---"
for d in "$SITE_ROOT" "$SITE_ROOT/wp-content" "$SITE_ROOT/wp-content/themes/huahinexpats-co" "$SITE_ROOT/wp-content/plugins/huahinexpats-core"; do
  if [ -d "$d/.git" ]; then
    echo "[$d]"
    (cd "$d" && git remote -v && git branch --show-current)
  fi
done
```

### 2.3 Probe the live site (works from any internet-connected machine)

```bash
# A) Stack signals
curl -sI https://huahinexpats.co/ | grep -iE 'server|x-powered|x-pingback'
curl -s  https://huahinexpats.co/ | grep -iE 'wp-content|wp-includes|generator|<meta name="generator"' | head -10

# B) WP REST API (200 if WordPress)
curl -s -o /dev/null -w '/wp-json/    -> %{http_code}\n' https://huahinexpats.co/wp-json/
curl -s -o /dev/null -w '/wp-login    -> %{http_code}\n' https://huahinexpats.co/wp-login.php

# C) Confirm the theme/plugin slugs you expect
curl -s -o /dev/null -w 'theme        -> %{http_code}\n'  https://huahinexpats.co/wp-content/themes/huahinexpats-co/style.css
curl -s -o /dev/null -w 'plugin index -> %{http_code}\n'  https://huahinexpats.co/wp-content/plugins/huahinexpats-core/
curl -s -o /dev/null -w 'plugin readme-> %{http_code}\n'  https://huahinexpats.co/wp-content/plugins/huahinexpats-core/readme.txt

# D) Who is hosting it
dig +short huahinexpats.co A
dig +short huahinexpats.co CNAME
echo | openssl s_client -servername huahinexpats.co -connect huahinexpats.co:443 2>/dev/null \
  | openssl x509 -noout -issuer -subject

# E) GitHub: any other repo I might have forgotten about
gh repo list --limit 200 --json name,description \
  | python3 -c 'import sys, json; print("\n".join(r["name"] for r in json.load(sys.stdin) if "huahin" in r["name"].lower()))'
# or open https://github.com/stevegray1957-lgtm?tab=repositories and search "huahin"
```

Paste the output back to me. I can fill in the remaining table fields and start the real PHP plugin work in the right repo.

## 3. Source-location table (to be filled in)

| Field | Value | How to find it |
|---|---|---|
| Local project path | TBD | §2.1 above (the `wp-config.php` directory). |
| Git remote (if any) | TBD | §2.2 — `.git` checks. Likely "none" if the WP site was built in WP Admin / Elementor. |
| Active branch | TBD | `git branch --show-current` in the WP root if it's a repo. |
| Production server path | TBD | Ask your host. SFTP path. Common: `/home/<user>/public_html/`, `/var/www/html/`. |
| Deployment method | TBD | If no git: SFTP / WP Admin / host control panel. If git on the host: pull-on-host. If Kinsta/WPE: their dashboard's Git integration. |
| Database export/import process | TBD | `wp db export` (WP-CLI), or phpMyAdmin export, or the host's backup tool. |
| Uploads sync process | TBD | `rsync -avz wp-content/uploads/` from prod down to local before working; flip the direction for upload. |
| Rollback process | TBD | Most managed WP hosts keep daily snapshots — use those. If not, restore from a pre-deploy `wp db export` + uploads tarball. |

## 4. Server-administrator request template

If §2 turns up **nothing local**, then the WP code only exists on the production server. Send the message below to whoever hosts `huahinexpats.co`.

> Subject: Read-only access to the codebase for huahinexpats.co
>
> Hello,
>
> I'm preparing some development work on the website at https://huahinexpats.co. I do not have a current local copy of the codebase, and I need to set one up before any changes can be safely staged.
>
> Could you please provide the following (read-only is fine):
>
> 1. The hostname and SSH (or SFTP) credentials to the WordPress document root for `huahinexpats.co`.
> 2. The absolute path to that document root on the server.
> 3. A recent **gzipped** SQL dump of the WordPress database, with column-inserts (`--single-transaction` + `--quick`). Example: `mysqldump --single-transaction --quick --routines --triggers <dbname> | gzip > huahinexpats-co_$(date +%F).sql.gz`. Please redact API keys from `wp_options` if convenient, but I can also do that locally on receipt.
> 4. A **tarball of the entire `wp-content/` directory** (themes, plugins, mu-plugins, uploads), again `.tar.gz`. Example: `tar -C /path/to/wp-content -czf huahinexpats-co_wpcontent_$(date +%F).tar.gz .`.
> 5. The currently-active theme slug, the list of active plugins, and the WordPress version (any of these from WP Admin → Updates, or `wp core version` / `wp plugin list` / `wp theme list` if you have WP-CLI).
> 6. Whether the site is connected to any Git repository (GitHub, Bitbucket, GitLab) and, if so, the repo URL and which branch is deployed. If yes, please add my GitHub user `stevegray1957-lgtm` as a read collaborator.
> 7. How the site is deployed today: manual SFTP, host's git-pull, host-managed Git (Kinsta/WPE-style), or other.
> 8. Backup retention: how many days of automatic snapshots are kept, and how a rollback is performed (Kinsta one-click? Manual restore from cPanel? `wp db import`?).
>
> Important: I do **not** need write access to production at this time. Read-only credentials are sufficient.
>
> Thanks,
> Steve

When you receive (3) and (4), I can pull them into a new repo / a LocalWP install and start the real Airwallex/enrichment work as a WordPress plugin.

## 5. The next branch will be created when source is in hand

The brief specifies the future branch name: `huahinexpats-co-airwallex-enrichment`. I am **not** creating that branch yet, because:

- No source has been located.
- Creating an empty branch on this repo (`huahin-expats-portal`) would only deepen the existing confusion between the Portal and `.co`. The branch should be created on the **WordPress repo** once that repo is identified.
- If no Git repo for `.co` exists today, the right move is to create a new one (e.g. `stevegray1957-lgtm/huahinexpats-co-wp`) seeded from the wp-content tarball, **then** branch.

I'll create the branch in the correct repo as soon as §3 is filled in.

## 6. Status of the Portal repo

This repository (`huahin-expats-portal`) has been explicitly marked at the top level as the Portal, not the `.co` site. See the new `README.md` at the repo root.

The Portal repo is fully working for `huahinexpatsportal.com`. The Airwallex + enrichment work on branch `claude/integrate-airwallex-payments-pcMOu` remains valid for that property whenever Steve decides to deploy it. It will **not** be merged or deployed until Steve confirms the property mapping.

## 7. Acceptance-criteria status

| Criterion | Status |
|---|---|
| True `.co` source code is located | **Not yet.** Cannot be done from this container. §2 is the script Steve must run on his Mac. |
| We know how changes go live for `.co` | **Pending §2 results.** §4 is the question list ready to send to the host. |
| We know whether the site is Git-managed or manually deployed | **Pending §2.2 / §4 question 6.** |
| The Portal repo is clearly marked as unrelated | **Done** — `README.md` added at repo root in the same commit as this report. |
| Steve has a clear next instruction for the server administrator | **Done** — §4 above is copy-paste ready. |
