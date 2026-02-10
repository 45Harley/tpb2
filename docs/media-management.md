# Media File Management

## Overview

The `0media/` directory holds media assets (images, audio, video) used across TPB sites. Large binary files are **not tracked in git** — they're deployed separately via `scp`.

## Current Files

| File | Size | Git Tracked | Used By |
|------|------|-------------|---------|
| `PeoplesBranch.png` | 1.1 MB | Yes | `story.php`, `index.php` (hero background, video poster) |
| `onemarblman.mp3` | 3.3 MB | No (gitignored) | `story.php` (audio player, footer link) |
| `Avatar IV Video.mp4` | 10 MB | No (gitignored) | `story.php` (solution section video) |
| `Civic_Power_Unleashed.mp4` | 9.5 MB | No (gitignored) | `story.php` (use case video) |
| `tpb2-location-fix-v6.zip` | 17 KB | No (gitignored) | Not referenced in code |

## Why the Split?

`.gitignore` excludes `*.mp3`, `*.mp4`, and `*.zip` to keep the git repo small. Git LFS is not available on the InMotion hosting server. Images (`*.png`) are small enough to track normally.

## Deploying Media Files

Large files that aren't in git must be deployed manually via `scp`:

```bash
# Deploy all media files
bash scripts/deploy/media.sh

# Or manually:
scp -P 2222 0media/*.mp4 0media/*.mp3 \
  sandge5@ecngx308.inmotionhosting.com:/home/sandge5/tpb2.sandgems.net/0media/
```

## Adding New Media

1. Place the file in `c:\tpb2\0media\`
2. If it's a small image (png, jpg, svg): it will be tracked by git automatically
3. If it's a large file (mp4, mp3, zip): it's gitignored — deploy with `scripts/deploy/media.sh`
4. Reference it in PHP with a relative path: `0media/filename.ext`
5. Update this document with the new file

## Where 0media/ Exists

| Location | Status |
|----------|--------|
| `tpb2.sandgems.net` (live) | Active |
| `tpb2.sandgems.net.bak` (backup) | Archive |
| `4tpb.org` | Active (same files) |
| Local repo (`c:\tpb2\0media\`) | PNG only; mp3/mp4 gitignored |

## PHP Files That Reference 0media/

- **`story.php`**: lines 145, 779, 856-857, 938-939, 1032
- **`index.php`**: line 202
- **`story-old.php`**, **`index-old.php`**, **`index-old2.php`**, **`index2-old.php`**: archived versions with same references
