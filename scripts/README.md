# scripts/

Reusable scripts grouped by purpose. Structure unfolds as the project needs it.

## Groups

| Directory | Purpose | Roles |
|-----------|---------|-------|
| `deploy/` | Deployment tasks — media, config, git pull | dev, admin, devops |
| `db/` | Database backup, sync, migration | dev, admin, dba |
| `setup/` | Local environment setup, scaffolding | dev, new contributors |
| `maintenance/` | Cleanup, link checking, health checks | admin, moderator |

## Current Scripts

- **`deploy/media.sh`** — Deploy large media files (mp4, mp3) to production via scp
- **`db/update-committees.php`** — Import/refresh congressional committee data (see below)

## Committee Data Updates

Congressional committees change every 2 years (new Congress) and occasionally mid-session.

### Quick run
```bash
php scripts/db/update-committees.php                 # defaults to congress 119
php scripts/db/update-committees.php --congress=120  # next congress (2027)
php scripts/db/update-committees.php --dry-run       # preview without DB changes
```

### What it does
1. Downloads committee definitions from `unitedstates/congress-legislators` (GitHub)
2. Downloads member assignments from the same source
3. Looks up subcommittee names via Congress.gov API (`config.php → apis.congress_gov.key`)
4. Rebuilds `committees` and `committee_memberships` tables for the given congress

### When to run
- **January of odd years** (new Congress seated): run with `--congress=N` where N is the new congress number
- **Mid-session** (if assignments change): re-run same congress number to refresh
- The script is idempotent — safe to re-run anytime

### Requirements
- `config.php` must have `apis.congress_gov.key` set (free from https://api.data.gov/signup/)
- Internet access to GitHub and api.congress.gov
- Tables: `committees`, `committee_memberships` (created by `tmp/create-committee-tables.php`)

### Data sources
| Source | URL | Auth | Rate Limit |
|--------|-----|------|------------|
| Committee defs | `github.com/unitedstates/congress-legislators` | None | N/A |
| Member assignments | Same repo, `committee-membership-current.yaml` | None | N/A |
| Subcommittee names | `api.congress.gov/v3/committee/` | data.gov key | 5,000/hr |
