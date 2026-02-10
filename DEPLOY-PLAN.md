# Git-Based Deployment Workflow: Round-Trip Test

## Context
Currently deploying via zip uploads to cPanel. Moving to git-based deployment for reliability and traceability. Starting with a small test change to prove the pipeline before pushing all 126 changed files.

- **Repo**: `https://github.com/45Harley/tpb2.git` (main branch, 3 commits)
- **Server**: `tpb2.sandgems.net` on InMotion hosting (cPanel, SSH enabled)
- **Local**: `c:\tpb2` on XAMPP

## Plan

### Step 1: Commit a small test change locally
- Create a simple test file (e.g., `deploy-test.php`) that echoes a timestamp
- Commit just that one file to `main`
- Push to GitHub

### Step 2: Set up git on InMotion server via SSH
- SSH into InMotion
- Navigate to the site's document root (likely `~/public_html/tpb2.sandgems.net/` or similar)
- `git init` and add the GitHub remote (or `git clone` if starting fresh)
- Configure `.gitignore` — ensure `config.php` and `config-claude.php` stay untouched on server
- `git pull origin main` to deploy

### Step 3: Verify round-trip
- Hit `https://tpb2.sandgems.net/deploy-test.php` in browser — confirm it shows the timestamp
- Confirm existing site still works (config.php on server wasn't overwritten)

### Step 4: If test passes — push everything
- Commit all 126 changed files + 11 new files from the zip sync
- Push to GitHub
- SSH into InMotion, `git pull`
- Verify `https://tpb2.sandgems.net/` loads correctly

### Step 5: Clean up
- Delete `deploy-test.php` from repo
- Document the workflow for future deploys

## Critical safeguards
- **config.php / config-claude.php** are in `.gitignore` — git pull won't overwrite server configs
- The `nul` file (Windows artifact) should be removed before committing
- Server's `.htaccess` PHP handler line may differ from local — verify after pull

## Verification
1. `https://tpb2.sandgems.net/deploy-test.php` shows test output
2. `https://tpb2.sandgems.net/` home page loads without errors
3. `git log` on server matches GitHub

## SSH Access
```
ssh sandge5@ecngx308.inmotionhosting.com -p 2222
```

## Server Document Root
```
/home/sandge5/tpb2.sandgems.net
```
