#!/bin/bash
# Deploy large media files to production (gitignored, deployed via scp)
# Usage: bash scripts/deploy/media.sh
#
# These files are too large for git and are excluded by .gitignore.
# This script pushes them directly to the production server.

SERVER="sandge5@ecngx308.inmotionhosting.com"
PORT=2222
REMOTE_PATH="/home/sandge5/tpb2.sandgems.net/0media/"

echo "Deploying media files to $SERVER:$REMOTE_PATH ..."
scp -P $PORT 0media/*.mp4 0media/*.mp3 "$SERVER:$REMOTE_PATH"
echo "Done."
