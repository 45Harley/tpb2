#!/bin/bash
# ================================================
# TPB State Page Deployment Script
# ================================================
# Automates deployment of state pages to production
#
# Usage: ./state-page.sh [state-abbr] [zip-file-path]
# Example: ./state-page.sh ct ~/Downloads/ct-state-build-2026-02-10.zip
#
# This script:
# 1. Extracts ZIP locally
# 2. Verifies required files exist
# 3. Backs up current page on server
# 4. Uploads new PHP file
# 5. Runs SQL updates
# 6. Verifies live page
# 7. Reports success
# ================================================

set -e  # Exit on error

STATE_ABBR=$1
ZIP_FILE=$2

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Server config
SERVER="sandge5@ecngx308.inmotionhosting.com"
PORT=2222
REMOTE_BASE="/home/sandge5/4tpb.org/z-states"
BACKUP_DATE=$(date +%Y%m%d)

# ================================================
# VALIDATION
# ================================================

if [ -z "$STATE_ABBR" ] || [ -z "$ZIP_FILE" ]; then
    echo -e "${RED}❌ Error: Missing arguments${NC}"
    echo ""
    echo "Usage: ./state-page.sh [state-abbr] [zip-file-path]"
    echo "Example: ./state-page.sh ct ~/Downloads/ct-state-build-2026-02-10.zip"
    echo ""
    exit 1
fi

if [ ! -f "$ZIP_FILE" ]; then
    echo -e "${RED}❌ Error: ZIP file not found: $ZIP_FILE${NC}"
    exit 1
fi

STATE_LOWER=$(echo "$STATE_ABBR" | tr '[:upper:]' '[:lower:]')
STATE_UPPER=$(echo "$STATE_ABBR" | tr '[:lower:]' '[:upper:]')
REMOTE_PATH="$REMOTE_BASE/$STATE_LOWER"

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}🏛️  Deploying $STATE_UPPER State Page${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# ================================================
# 1. EXTRACT ZIP
# ================================================

echo -e "${YELLOW}📦 Step 1: Extracting ZIP file...${NC}"
TEMP_DIR=$(mktemp -d)
unzip -q "$ZIP_FILE" -d "$TEMP_DIR"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ ZIP extracted successfully${NC}"
else
    echo -e "${RED}❌ Failed to extract ZIP${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

# ================================================
# 2. VERIFY FILES
# ================================================

echo ""
echo -e "${YELLOW}🔍 Step 2: Verifying files...${NC}"

PHP_FILE="$TEMP_DIR/${STATE_LOWER}-state-page.php"
SQL_FILE="$TEMP_DIR/${STATE_LOWER}-state-updates.sql"
JSON_FILE="$TEMP_DIR/${STATE_LOWER}-state-data.json"
LOG_FILE="$TEMP_DIR/BUILD-LOG-${STATE_UPPER}.md"

if [ ! -f "$PHP_FILE" ]; then
    echo -e "${RED}❌ Error: PHP file not found in ZIP: ${STATE_LOWER}-state-page.php${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

if [ ! -f "$SQL_FILE" ]; then
    echo -e "${RED}❌ Error: SQL file not found in ZIP: ${STATE_LOWER}-state-updates.sql${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

echo -e "${GREEN}✅ Required files verified:${NC}"
echo "   - PHP: ${STATE_LOWER}-state-page.php ($(wc -l < "$PHP_FILE") lines)"
echo "   - SQL: ${STATE_LOWER}-state-updates.sql"
if [ -f "$JSON_FILE" ]; then
    echo "   - JSON: ${STATE_LOWER}-state-data.json"
fi
if [ -f "$LOG_FILE" ]; then
    echo "   - LOG: BUILD-LOG-${STATE_UPPER}.md"
fi

# ================================================
# 3. BACKUP CURRENT PAGE
# ================================================

echo ""
echo -e "${YELLOW}💾 Step 3: Backing up current page on server...${NC}"

ssh -p $PORT $SERVER "cd $REMOTE_PATH 2>/dev/null && [ -f index.php ] && cp index.php index-old-$BACKUP_DATE.php && echo 'Backup created: index-old-$BACKUP_DATE.php' || echo 'No existing page to backup (new state page)'" 2>&1

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Backup completed${NC}"
else
    echo -e "${YELLOW}⚠️  Warning: Could not create backup (state directory may not exist yet)${NC}"
fi

# ================================================
# 4. UPLOAD NEW PAGE
# ================================================

echo ""
echo -e "${YELLOW}📤 Step 4: Uploading new state page...${NC}"

# Create directory if it doesn't exist
ssh -p $PORT $SERVER "mkdir -p $REMOTE_PATH"

# Upload PHP file as index.php
scp -P $PORT "$PHP_FILE" "$SERVER:$REMOTE_PATH/index.php"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ State page uploaded successfully${NC}"
else
    echo -e "${RED}❌ Failed to upload state page${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

# Upload JSON file (optional, for reference)
if [ -f "$JSON_FILE" ]; then
    scp -P $PORT "$JSON_FILE" "$SERVER:$REMOTE_PATH/${STATE_LOWER}-state-data.json" 2>/dev/null
fi

# Upload BUILD-LOG (optional, for reference)
if [ -f "$LOG_FILE" ]; then
    scp -P $PORT "$LOG_FILE" "$SERVER:$REMOTE_PATH/BUILD-LOG-${STATE_UPPER}.md" 2>/dev/null
fi

# ================================================
# 5. RUN SQL UPDATES
# ================================================

echo ""
echo -e "${YELLOW}🗄️  Step 5: Running database updates...${NC}"
echo -e "${BLUE}   (You may be prompted for MySQL password)${NC}"

# Copy SQL to server temp location
scp -P $PORT "$SQL_FILE" "$SERVER:/home/sandge5/temp-sql-update-${STATE_LOWER}.sql"

# Run SQL (will prompt for password)
ssh -p $PORT $SERVER "mysql -u sandge5_tpb2 -p sandge5_tpb2 < /home/sandge5/temp-sql-update-${STATE_LOWER}.sql && rm /home/sandge5/temp-sql-update-${STATE_LOWER}.sql"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ Database updated successfully${NC}"
else
    echo -e "${RED}❌ Database update failed${NC}"
    echo -e "${YELLOW}⚠️  State page uploaded but database not updated!${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

# ================================================
# 6. VERIFY LIVE PAGE
# ================================================

echo ""
echo -e "${YELLOW}🌐 Step 6: Verifying live page...${NC}"

LIVE_URL="https://4tpb.org/${STATE_LOWER}/"
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$LIVE_URL")

if [ "$HTTP_STATUS" == "200" ]; then
    echo -e "${GREEN}✅ Page is live and responding (HTTP 200)${NC}"
else
    echo -e "${YELLOW}⚠️  Warning: Page returned HTTP $HTTP_STATUS${NC}"
    echo -e "${YELLOW}   Check $LIVE_URL manually${NC}"
fi

# ================================================
# 7. CLEANUP
# ================================================

rm -rf "$TEMP_DIR"

# ================================================
# SUCCESS SUMMARY
# ================================================

echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}🎉 Deployment Complete!${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "${GREEN}✅ State Page:${NC} $LIVE_URL"
echo -e "${GREEN}✅ Backup:${NC} index-old-$BACKUP_DATE.php (on server)"
echo -e "${GREEN}✅ Database:${NC} Updated"
echo ""
echo -e "${BLUE}Next Steps:${NC}"
echo "  1. Verify page in browser: $LIVE_URL"
echo "  2. Check mobile responsiveness"
echo "  3. Test a few benefit links to ensure they work"
echo "  4. Mark DEPLOY task as complete in volunteer dashboard"
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# ================================================
# ROLLBACK INSTRUCTIONS
# ================================================

echo -e "${YELLOW}💡 Rollback Instructions (if needed):${NC}"
echo ""
echo "  ssh $SERVER -p $PORT"
echo "  cd $REMOTE_PATH"
echo "  cp index-old-$BACKUP_DATE.php index.php"
echo ""
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
