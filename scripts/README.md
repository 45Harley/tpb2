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
