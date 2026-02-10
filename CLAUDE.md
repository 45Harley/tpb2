# TPB2 - The People's Branch

## Project
PHP web application ("TPB Putnam") running on XAMPP locally, deployed to InMotion hosting via git.

## Stack
- PHP 8.4, MySQL, Apache
- No framework — vanilla PHP with shared includes
- Google Maps API for interactive map

## Structure
- `api/` — API endpoints
- `includes/` — shared components (nav, footer, get-user, point-logger, smtp-mail)
- `assets/` — JS, CSS, frontend files
- `z-states/` — state/town pages (e.g., `z-states/ct/putnam/`)
- `poll/` — poll system
- `volunteer/` — volunteer system
- `constitution/` — constitution section
- `0media/` — media assets (images git-tracked; mp3/mp4 gitignored, deployed via scp)
- `docs/` — project documentation (detailed docs referenced by system_documentation table)
- `scripts/` — reusable scripts grouped by purpose (deploy/, db/, setup/, maintenance/)
- `config.php` — DB credentials, SMTP, site settings (gitignored)
- `config-claude.php` — Anthropic API key, clerk system (gitignored)

## Documentation
The `system_documentation` table in sandge5_tpb2 is the master documentation registry.
It is role-aware (`roles` column) and tagged for search. Detailed docs live in `docs/`.
- [Media files](docs/media-management.md) — 0media/ workflow, large file deployment
- Query: `SELECT doc_key, doc_title, roles, tags FROM system_documentation` for full index

## Deployment
Git-based deployment to `tpb2.sandgems.net` on InMotion hosting.

### Push changes to production
```bash
# 1. Commit and push locally
git add <files> && git commit -m "description" && git push origin master

# 2. Pull on server
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

### Server details
- **Host**: `ecngx308.inmotionhosting.com` (SSH port 2222)
- **User**: `sandge5`
- **Doc root**: `/home/sandge5/tpb2.sandgems.net`
- **Branch**: `master`
- **URL**: `https://tpb2.sandgems.net`

### Config files
`config.php` and `config-claude.php` are gitignored. They exist on the server but are NOT managed by git. If you need to update them on the server, use scp:
```bash
scp -P 2222 config.php sandge5@ecngx308.inmotionhosting.com:/home/sandge5/tpb2.sandgems.net/
```

### Collaborative workflow (multiple contributors)
If more than one person is working on the code, use branches and pull requests instead of pushing directly to `master`:
```bash
# 1. Create a branch for your change
git checkout -b fix-logout

# 2. Make changes, commit, push the branch
git add <files> && git commit -m "description" && git push origin fix-logout

# 3. Open a PR on GitHub, review the diff, merge to master

# 4. Pull the merged result on the server
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

## Conventions
- URL routing for states/towns is handled by `.htaccess` rewrite rules
- `.htaccess` includes a cPanel-generated PHP handler (`ea-php84`) — don't remove it
- Bot/scanner blocking rules are at the top of `.htaccess`
