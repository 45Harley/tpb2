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
- `config.php` — DB credentials, SMTP, site settings (gitignored)
- `config-claude.php` — Anthropic API key, clerk system (gitignored)

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

## Conventions
- URL routing for states/towns is handled by `.htaccess` rewrite rules
- `.htaccess` includes a cPanel-generated PHP handler (`ea-php84`) — don't remove it
- Bot/scanner blocking rules are at the top of `.htaccess`
