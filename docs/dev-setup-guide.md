# TPB2 Dev Setup Guide

**From a fresh Windows 11 machine to a running local development environment.**

Last updated: 2026-02-19

---

## Prerequisites

You'll need:
- A Windows 11 machine with admin access
- TPB credentials (provided by project lead):
  - MySQL username & password
  - SMTP credentials (mail.sandgems.net)
  - Anthropic API key
  - Admin password
  - SSH key for InMotion server (for deployment)

---

## Step 1: Install Core Software

Open PowerShell as Administrator:

```powershell
# Git
winget install Git.Git

# Node.js (for Playwright tests)
winget install OpenJS.NodeJS.LTS

# VSCode
winget install Microsoft.VisualStudioCode
```

### Install XAMPP

Download XAMPP with PHP 8.4 from https://www.apachefriends.org/

Run the installer (requires manual click-through):
- Select components: Apache, MySQL, PHP, phpMyAdmin
- Install to `C:\xampp` (default)
- **Manual step:** Windows firewall will prompt — allow Apache through

After install, open XAMPP Control Panel and start **Apache** and **MySQL**.

---

## Step 2: Clone the Repository

```bash
cd /c
git clone https://github.com/45Harley/tpb2.git
```

This puts the project at `c:\tpb2`.

---

## Step 3: Configure Apache

### Set document root

Edit `C:\xampp\apache\conf\httpd.conf`:

```apache
DocumentRoot "C:/tpb2"
<Directory "C:/tpb2">
    Options Indexes FollowSymLinks Includes ExecCGI
    AllowOverride All
    Require all granted
</Directory>
```

### Enable mod_rewrite

In the same `httpd.conf`, ensure this line is uncommented:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

Restart Apache from XAMPP Control Panel.

---

## Step 4: Create config.php

Create `c:\tpb2\config.php` (this file is gitignored):

```php
<?php
return [
    // Site
    'base_url'         => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    'default_redirect' => '/profile.php',
    'email_from'       => 'harley@sandgems.net',
    'email_from_name'  => 'The People\'s Branch',

    // SMTP
    'smtp' => [
        'host'       => 'mail.sandgems.net',
        'port'       => 465,
        'username'   => '________',          // ← SMTP username
        'password'   => '________',          // ← SMTP password
        'encryption' => 'ssl',
    ],

    // Database
    'host'     => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => '________',                // ← DB username
    'password' => '________',                // ← DB password
    'charset'  => 'utf8mb4',

    // Admin
    'admin_email'    => 'hhg@sandgems.net',
    'admin_password' => '________',          // ← Admin password

    // Bot detection
    'bot_detection' => [
        'enabled'              => true,
        'honeypot_field'       => 'website_url',
        'min_submit_time'      => 3,
        'email_alerts'         => true,
        'alert_email'          => 'hhg@sandgems.net',
        'alert_threshold_ip'   => 10,
        'alert_threshold_total' => 50,
    ],

    // People Power
    'trump' => [
        'notify_email' => 'hhg@sandgems.net',
        'notify_every' => 1,
    ],
];
```

---

## Step 5: Create config-claude.php

Create `c:\tpb2\config-claude.php` (this file is gitignored):

```php
<?php
define('ANTHROPIC_API_KEY', 'sk-ant-________');  // ← Anthropic API key
define('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages');
define('CLAUDE_MODEL', 'claude-sonnet-4-20250514');

// Clerk system functions (getClerk, buildClerkPrompt, etc.)
// are defined in this file — copy from an existing environment
// or request the full file from the project lead.
```

**Note:** This file contains ~200 lines of clerk system functions beyond the defines. Copy the full file from the staging server:

```bash
scp -P 2222 sandge5@ecngx308.inmotionhosting.com:/home/sandge5/tpb2.sandgems.net/config-claude.php c:\tpb2\config-claude.php
```

---

## Step 6: Set Up the Database

### Create the database

Open phpMyAdmin at `http://localhost/phpmyadmin` and create:
- Database: `sandge5_tpb2` (utf8mb4_general_ci)
- Database: `sandge5_election` (utf8mb4_general_ci)

### Option A: Import from staging (recommended)

Export the full database from the staging server and import locally:

```bash
# Export from staging
ssh -p 2222 sandge5@ecngx308.inmotionhosting.com "mysqldump -u sandge5_tpb2 -p sandge5_tpb2 > /tmp/tpb2-dump.sql"
scp -P 2222 sandge5@ecngx308.inmotionhosting.com:/tmp/tpb2-dump.sql c:\tpb2\tpb2-dump.sql

# Import locally via XAMPP MySQL
C:\xampp\mysql\bin\mysql -u root sandge5_tpb2 < c:\tpb2\tpb2-dump.sql
```

### Option B: Run migrations from scratch

Run the SQL files in `scripts/db/` in this order:

```
 1. create-state-build-tasks.sql
 2. alter-tasks-for-state-builder.sql
 3. alter-tasks-check-columns.sql
 4. ct-state-updates.sql
 5. threats-2026-01-26-to-02-11.sql
 6. talk-phase1-alter-idea-log.sql
 7. talk-phase2-brainstorm-clerk.sql
 8. talk-phase3-schema.sql
 9. talk-phase3-clerks.sql
10. talk-phase4-edit-delete.sql
11. talk-phase4-group-roles.sql
12. talk-phase5-invites.sql
13. talk-phase6-group-scoped-ideas.sql
14. talk-phase7-public-access.sql
15. talk-phase8-geo-streams.sql
```

**Note:** These are ALTER/INSERT scripts. The base tables (users, states, towns, etc.) are not covered by these migrations — use Option A for a complete database.

---

## Step 7: Install Test Dependencies

```bash
cd /c/tpb2
npm install
npx playwright install
```

This installs Playwright 1.58.2 and downloads browser binaries (Chromium, WebKit, Firefox).

---

## Step 8: Set Up SSH Access (for deployment)

```bash
# Generate SSH key if you don't have one
ssh-keygen -t ed25519 -C "your-email@example.com"

# Copy public key to server (ask project lead to add it)
cat ~/.ssh/id_ed25519.pub
```

Test connection:

```bash
ssh -p 2222 sandge5@ecngx308.inmotionhosting.com "echo connected"
```

---

## Step 9: Verify Everything Works

### Check Apache is serving TPB

Open `http://localhost` in a browser. You should see the TPB homepage with the USA map.

### Run tests

```bash
cd /c/tpb2
npm test
```

### Test specific subsystems

```bash
npm run test:talk        # Talk deliberation system
npm run test:security    # Security checks
npm run test:ethics      # Ethics/quality checks
```

---

## Deployment Commands (once set up)

### Push to staging

```bash
git add <files> && git commit -m "description" && git push origin master
ssh -p 2222 sandge5@ecngx308.inmotionhosting.com "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

### Query remote database

```bash
# Write PHP query to local file, scp to server, execute
scp -P 2222 tmp-query.php sandge5@ecngx308.inmotionhosting.com:/tmp/
ssh -p 2222 sandge5@ecngx308.inmotionhosting.com "/usr/local/bin/php /tmp/tmp-query.php && rm /tmp/tmp-query.php"
```

---

## Directory Structure After Setup

```
C:\xampp\                          ← XAMPP (Apache, MySQL, PHP)
C:\tpb2\                           ← Project root
  ├── config.php                   ← Local DB/SMTP creds (gitignored)
  ├── config-claude.php            ← Anthropic API key (gitignored)
  ├── .htaccess                    ← URL rewrites, security rules
  ├── api/                         ← API endpoints
  ├── talk/                        ← Talk deliberation system
  ├── poll/                        ← Poll system
  ├── volunteer/                   ← Volunteer task board
  ├── constitution/                ← Constitution section
  ├── includes/                    ← Shared PHP components
  ├── assets/                      ← JS, CSS
  ├── z-states/                    ← State/town pages
  ├── 0media/                      ← Media files
  ├── scripts/                     ← DB migrations, deploy scripts
  ├── tests/                       ← Playwright tests
  ├── docs/                        ← Project documentation
  ├── node_modules/                ← npm deps (gitignored)
  └── package.json                 ← Test dependencies
```

---

## Gotchas

| Issue | Solution |
|-------|----------|
| **XAMPP MySQL port conflict** | If port 3306 is taken, change in XAMPP config or stop the conflicting service |
| **Apache won't start** | Check if IIS or another web server is on port 80. Stop it or change Apache's port |
| **Playwright can't connect** | XAMPP Apache must be running before tests. Playwright does NOT auto-start it |
| **config.php missing** | Every PHP page will break — this is the first file to create |
| **Migrations fail** | Option B migrations are incremental. If base tables don't exist, use Option A (full dump) instead |
| **OPcache stale on server** | After deploying, clear web opcache via HTTP request (CLI reset only clears CLI cache) |
| **Line endings** | Git is configured with `core.autocrlf=true` — Windows CRLF auto-converted |
| **PHP handler mismatch** | .htaccess has `ea-php84` handler for cPanel. Locally XAMPP handles PHP natively — this line is harmless but don't copy it to other setups |

---

## Claude Code Setup (optional but recommended)

Install the Claude Code VSCode extension for AI-assisted development. The project includes `CLAUDE.md` with project-specific instructions that Claude Code reads automatically.

---

*This guide assumes a clean Windows 11 install. Adjust paths if XAMPP or the project are installed elsewhere.*
