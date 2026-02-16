# TPB2 - The People's Branch

## Project
PHP web application ("TPB Putnam") running on XAMPP locally, deployed to InMotion hosting via git.

## Volunteer Ethics & Quality Standards

When working with volunteers building state/town pages, remember:

**The Golden Rule Foundation**

TPB is built on the Golden Rule (5.9 billion people across 10 religions agree):
**"Do to others what you would have them do to you."**

In state building:
- Research for Maria as carefully as you'd want someone to research for you
- Verify for Tom as thoroughly as you'd want verified for your grandfather
- Make it clear for Jamal the way you'd want it clear if you were 22

**Who you're building for:**
- **Maria, 34** — Single mom, needs childcare help. Your accuracy = her $9,600/year in benefits
- **Tom, 67** — Retired, fixed income. Your clarity = his $4,200/year savings
- **Jamal, 22** — Recent grad, first home. Your thoroughness = his $20k down payment help

**Quality standards:**
1. **Accuracy over speed** — Maria gets rejected if dollar amount is wrong
2. **Official sources (.gov)** — Trust matters, Wikipedia can be outdated
3. **Plain language** — Tom can't navigate jargon, translate it
4. **Non-partisan** — Serve ALL citizens (describe, don't editorialize)
5. **Cite sources** — Future volunteers need to update

**As guide, you should:**
- Remind about ethics when volunteer rushes
- Suggest official sources over convenience
- Translate jargon automatically
- Flag partisan language
- Celebrate quality over speed

**Related documentation:**
- [Volunteer Orientation](docs/state-builder/VOLUNTEER-ORIENTATION.md) — Part 0 covers Golden Rule across 10 religions
- [Ethics Foundation](docs/state-builder/ETHICS-FOUNDATION.md) — Deep dive into selfless service, manifesto, practical application

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
Both environments are on the same InMotion hosting server.

### Environments
| | Staging | Production |
|---|---------|-----------|
| **URL** | `https://tpb2.sandgems.net` | `https://4tpb.org` |
| **Doc root** | `/home/sandge5/tpb2.sandgems.net` | `/home/sandge5/4tpb.org` |
| **Deploy method** | git pull | zip extract (no .git/) |

### Server details (shared)
- **Host**: `ecngx308.inmotionhosting.com` (SSH port 2222)
- **User**: `sandge5`
- **Branch**: `master`
- **Database**: Both environments share the same databases

### Deploy to production (4tpb.org)
**DO NOT deploy to 4tpb.org unless the human explicitly overrides this rule.**
Production deploys require explicit human approval each time.

When authorized by the human, create a zip excluding `.git/`, upload and extract:
```bash
# From project root — create zip without .git/
zip -r tpb2-deploy.zip . -x ".git/*"

# Upload and extract on server
scp -P 2222 tpb2-deploy.zip sandge5@ecngx308.inmotionhosting.com:/home/sandge5/4tpb.org/
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/4tpb.org && unzip -o tpb2-deploy.zip && rm tpb2-deploy.zip"
```

### Push changes to staging
```bash
# 1. Commit and push locally
git add <files> && git commit -m "description" && git push origin master

# 2. Pull on staging server
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

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

# 4. Pull the merged result on staging
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

## Remote MySQL Queries via CLI

To query databases on the server from Claude Code, use SSH + a temp PHP script (inline PHP escaping breaks over SSH):

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$r = \$p->query('SELECT ... FROM ...');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', \$row).PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

- Change `dbname=sandge5_tpb2` to `sandge5_election` for the election database
- Credentials from config.php work for both databases
- Always `rm /tmp/q.php` after execution

## Conventions
- URL routing for states/towns is handled by `.htaccess` rewrite rules
- `.htaccess` includes a cPanel-generated PHP handler (`ea-php84`) — don't remove it
- Bot/scanner blocking rules are at the top of `.htaccess`

## Authentication Rules (IRON-CLAD)

### THE ONE AUTH FUNCTION
`getUser($pdo)` from `includes/get-user.php` is the ONLY way to identify the current user.

Every PHP file that needs to know who the user is MUST:
```php
require_once __DIR__ . '/../includes/get-user.php';  // adjust path as needed
$dbUser = getUser($pdo);
```

`getUser()` checks (in order):
1. `tpb_civic_session` cookie → `user_devices` table (DB-validated, `is_active=1`)
2. `tpb_user_id` cookie → `users` table (direct lookup fallback)
3. Returns full user array or `false`

### Identity levels (from `identity_levels` table)
| Level | Name | Meaning |
|-------|------|---------|
| 1 | anonymous | No verification |
| 2 | remembered | Email verified |
| 3 | verified | Phone verified |
| 4 | vetted | Background checked |

Access via `$dbUser['identity_level_id']`.

### NEVER do any of these
1. **NEVER** read `$_COOKIE['tpb_user_id']` directly for auth — use `getUser($pdo)`
2. **NEVER** query `users.session_id` — legacy column; `getUser()` uses `user_devices`
3. **NEVER** write inline user lookup queries — `getUser()` handles all auth paths
4. **NEVER** hardcode database credentials — always `$config = require __DIR__ . '/../config.php';`

### Exceptions (files that legitimately skip getUser)
- **Token-based**: `verify-magic-link.php`, `verify-parent-consent.php`, `verify-phone-link.php` (auth by URL token, not cookie)
- **Public read-only**: `api/get-thoughts.php` (no auth needed)
- **Admin**: `admin.php` (separate session auth — TODO: migrate to proper admin role system)
- **Test**: `tests/talk-harness.php` (uses `tpb_user_id` cookie fallback deliberately)
- **Separate apps**: `0t/*.php` (People Power), `tpb-claude/api/claude-chat.php` (AI subsystem)
