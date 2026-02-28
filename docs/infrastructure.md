# TPB Infrastructure & Technology Stack

**Development, staging, and production — the complete picture.**

Last updated: 2026-02-27

---

## Development Environment

| Component | Detail |
|-----------|--------|
| **OS** | Windows 11 Home (10.0.26100) |
| **Local server** | XAMPP at `C:\xampp` (Apache, MySQL, PHP 8.4, phpMyAdmin, sendmail) |
| **Project root** | `c:\tpb2` |
| **IDE** | VSCode with Claude Code extension |
| **Shell** | Bash (Unix syntax on Windows) |
| **Version control** | Git → GitHub (`https://github.com/45Harley/tpb2.git`, master branch) |
| **Testing** | Playwright 1.58.2 (Chromium desktop + iPhone 13 mobile) |
| **Package manager** | npm (package.json for test dependencies only) |
| **PHP dependencies** | None — vanilla PHP with PDO, no Composer |

---

## Hosting Infrastructure

**Provider:** InMotion Hosting (shared cPanel environment)

**Server:** `ecngx308.inmotionhosting.com` (SSH port 2222, user `sandge5`)

| | Staging | Production |
|---|---------|-----------|
| **Domain** | `tpb2.sandgems.net` | `4tpb.org` |
| **Doc root** | `/home/sandge5/tpb2.sandgems.net` | `/home/sandge5/4tpb.org` |
| **Deploy method** | `git pull` | zip upload + extract (no .git/) |
| **HTTPS** | Yes | Yes |

**Both environments share the same MySQL databases.** No separate staging DB.

---

## Application Stack

| Layer | Technology |
|-------|-----------|
| **Language** | PHP 8.4 (ea-php84 cPanel handler) |
| **Framework** | None — vanilla PHP with shared includes |
| **Web server** | Apache 2.x with mod_rewrite |
| **Database** | MySQL (utf8mb4) |
| **Primary DB** | `sandge5_tpb2` |
| **Secondary DB** | `sandge5_election` |
| **Connection** | PDO with MySQL driver |
| **Session management** | Cookie-based (`tpb_civic_session`) → `user_devices` table validation |

---

## Email Infrastructure

| Component | Detail |
|-----------|--------|
| **SMTP host** | `mail.sandgems.net` |
| **Port** | 465 (SSL/TLS) |
| **Auth** | SMTP AUTH LOGIN |
| **From** | `harley@sandgems.net` / "The People's Branch" |
| **Implementation** | Custom raw socket SMTP wrapper (`includes/smtp-mail.php`) — no external library |

---

## Third-Party APIs & Services

| Service | Purpose | Integration |
|---------|---------|-------------|
| **Anthropic Claude** | AI clerks (guide, gatherer, brainstorm) | `api/claude-chat.php` → `https://api.anthropic.com/v1/messages` |
| **Google Maps** | Interactive map, geocoding, Street View | JavaScript embed with API key |
| **OpenStates** | Legislative district lookup | `api/lookup-districts.php` (OCD-ID based, cached locally) |
| **GitHub** | Source control, collaboration | `https://github.com/45Harley/tpb2.git` |

---

## Security & Access Control

**Web server hardening (.htaccess):**
- Bot/scanner blocking: AhrefsBot, SemrushBot, GPTBot, ClaudeBot, Shodan, Nmap, python-requests, etc.
- **Social media crawlers ALLOWED**: facebookexternalhit, meta-externalagent, LinkedInBot, Twitterbot, Bluesky — required for share link preview cards (Open Graph)
- Sensitive file protection: `.env`, `.git/`, `config.php`, `config-claude.php`, `.sql`, `.bak`
- Directory protection: `/tests/`, `/scripts/`, `/docs/` (except public volunteer docs)
- WordPress probe blocking: wp-login.php, wp-admin, xmlrpc.php

**Open Graph meta tags (header.php):**
- All pages automatically emit `og:title`, `og:description`, `og:image`, `og:url`, `og:type`, `twitter:card`
- Pages can override defaults by setting `$ogTitle`, `$ogDescription`, `$ogImage` before including header.php
- Default image: `0media/PeoplesBranch.png`

**Application security:**
- CSRF tokens on all POST forms
- HTML output escaped via `htmlspecialchars()`
- Soft deletes only (no hard-delete of users)
- Audit logging (`admin_actions` table)
- 4-tier identity verification (anonymous → remembered → verified → vetted)
- 39 platform roles via `user_role_membership`

---

## Deployment Pipeline

```
Local (XAMPP)
    │
    ├── git push origin master
    │
    ▼
GitHub (master branch)
    │
    ├── Staging: SSH → git pull
    │     └── tpb2.sandgems.net
    │
    └── Production: zip → scp → extract (manual, human-approved)
          └── 4tpb.org
```

**Media files** (mp3/mp4) are gitignored and deployed separately via `scp` using `scripts/deploy/media.sh`.

**Config files** (`config.php`, `config-claude.php`) are gitignored and managed on-server only.

**OPcache caveat:** After deploy, must clear web opcache via HTTP request — CLI `opcache_reset()` only clears CLI cache.

---

## Database Schema Evolution

18+ migration scripts in `scripts/db/`, organized by Talk phase:
- Phases 1-3: Core idea_log, groups, membership
- Phases 4-5: Roles, invites, links
- Phase 6: AI clerks, gather/crystallize
- Phase 7: Public access flags (public_readable, public_voting)
- Phase 8: Geo-streams, SIC standard groups, access gates

---

## Testing

| Tool | Purpose | Config |
|------|---------|--------|
| **Playwright** | Browser E2E testing | `playwright.config.js` |
| **Chromium** | Desktop browser profile | Default viewport |
| **iPhone 13** | Mobile browser profile | Safari emulation |

Test scripts via npm:
- `npm test` — full suite
- `npm run test:staging` — against staging URL
- `npm run test:talk` — Talk subsystem
- `npm run test:security` — security checks
- `npm run test:ethics` — ethics/quality checks

---

## File Storage

| Type | Location | Deployment |
|------|----------|-----------|
| **PHP code** | Git-tracked | git push / git pull |
| **Images** | `0media/` (git-tracked) | git push / git pull |
| **Audio/Video** | `0media/` (gitignored) | scp via `scripts/deploy/media.sh` |
| **Config** | Server only (gitignored) | scp manual |
| **Test output** | Local only (gitignored) | Not deployed |

---

## Network Topology

```
User Browser
    │
    ▼ (HTTPS)
InMotion Apache (ecngx308)
    │
    ├── PHP 8.4 ──→ MySQL (localhost)
    │
    ├── SMTP ──→ mail.sandgems.net:465
    │
    ├── API ──→ api.anthropic.com (Claude)
    │
    └── API ──→ Google Maps / OpenStates
```

Developer workstation connects via:
- **SSH** (port 2222) for deployment and remote DB queries
- **Git/HTTPS** to GitHub for source control
- **Browser** to staging/production for testing
