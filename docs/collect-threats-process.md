# Collect Threats Process

How to research, deduplicate, and load new threats into the `trump_threats` table in `sandge5_election`.

## Database

- **Database:** `sandge5_election` (separate from `sandge5_tpb2`)
- **Table:** `trump_threats`
- **Access:** phpMyAdmin on InMotion hosting
- **MCP note:** Claude Code connects to `sandge5_tpb2`, not `sandge5_election` — so a SQL dump must be uploaded manually for dedup

## Steps

### Step 0: Research new threats

Search news sources for recent Trump administration / mobster threats:

- NPR, CNN, NYT, Washington Post
- Al Jazeera, Democracy Now
- Government sites (.gov)
- AP News, Reuters

Look for: executive orders, firings, agency dismantling, policy changes, legal actions, institutional threats.

### Step 1: Get existing threats

Upload a current SQL dump of `sandge5_election.sql` so Claude can see what's already in the database. This enables deduplication.

**How to export:**
1. Open phpMyAdmin on InMotion
2. Select `sandge5_election` database
3. Export `trump_threats` table as SQL
4. Upload the `.sql` file to the conversation

### Step 2: Deduplicate

Compare new threats against existing by:
- Title similarity (fuzzy match)
- Date + target combos (same date, same target = likely duplicate)
- Source URL match

Skip anything already captured.

### Step 3: Build INSERT data

Each threat needs these fields:

| Field | Type | Description |
|-------|------|-------------|
| `threat_date` | date | When the threat occurred or was announced |
| `title` | varchar | Short headline (e.g., "Fires FBI Director") |
| `description` | text | 2-4 sentence description of the threat and its impact |
| `threat_type` | enum | `tactical` (specific action) or `strategic` (systemic/institutional) |
| `target` | varchar | What's being threatened (e.g., "FBI Independence", "USAID") |
| `source_url` | varchar | Link to primary news source |
| `action_script` | text | What citizens can do about it |
| `official_id` | int | FK to `officials` table — who is responsible |
| `is_active` | tinyint | 1 = ongoing, 0 = resolved |

### Step 4: Generate and load SQL

Claude generates an INSERT SQL file. Load it via phpMyAdmin:

1. Review the generated SQL
2. Open phpMyAdmin > `sandge5_election`
3. Go to SQL tab
4. Paste or import the file
5. Execute

## Key Official IDs

| ID | Name |
|----|------|
| 326 | Trump |
| 9112 | Vance |
| 9224 | Musk / DOGE |
| 9203 | Bondi |
| 9202 | Hegseth |
| 9200 | Rubio |
| 9214 | Noem |

## History

- **Last collection:** January 25, 2026
- **Total threats at that time:** 107

## Running a Collection

To start a new collection:

1. Export fresh `trump_threats` SQL dump from phpMyAdmin
2. Upload it to Claude conversation
3. Ask Claude to search for new threats since the last collection date
4. Claude will research, dedup, and generate INSERT SQL
5. Review and load via phpMyAdmin
