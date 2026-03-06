# Mandate Summary & Rep Dashboard — Design

## The Second Derivative

Citizens save mandates (first derivative). The **aggregation, summary, and statistics** delivered to representatives is the second derivative — actionable intelligence from constituent voice.

The gap between citizen language and policy language is what town halls are supposed to bridge. This system makes that bridge permanent, scaled, and data-driven. The mandate summary **is** the town hall agenda.

---

## Audience & Delivery

- **Public page** per district — anyone (including rep staff) can view
- **Exportable report** — printable agenda/PDF for office delivery
- Both share the same underlying data

## Priority Statistics (ranked)

1. Topic clustering — what people care about
2. Volume over time — momentum
3. Constituent count — reach
4. Top specific mandates — actionable items
5. Geographic breakdown — where the energy is
6. Urgency/recency — what's hot now
7. Comparison — context vs peers

---

## Architecture: Hybrid (Approach C)

- **Database tags** at save time — AI assigns dual tags per mandate (fast SQL aggregation)
- **Periodic AI synthesis** — batch job generates narrative summaries, gap analysis, town hall agendas
- **Synthesis stored** in `mandate_summaries` table

### Dual Tagging

Every mandate gets two AI-assigned fields at save time:

- `citizen_summary` — plain language, citizen's voice ("get money out of politics")
- `policy_topic` — normalized committee-style category ("Campaign Finance & Elections")

The citizen-facing view shows their words. The rep-facing summary groups by policy topic. Gap detection emerges from the mismatch between the two.

### Fixed Taxonomy (15 topics)

```
Economy & Jobs
Healthcare
Education
Infrastructure & Transportation
Environment & Energy
Public Safety & Justice
Housing & Cost of Living
Campaign Finance & Elections
Civil Rights & Liberties
Government Accountability
Veterans & Military
Immigration
Technology & Privacy
Agriculture
Other
```

Stored as PHP constant, not a DB table.

---

## Schema

### Changes to `idea_log`

```sql
ALTER TABLE idea_log
  ADD COLUMN citizen_summary VARCHAR(200) DEFAULT NULL AFTER tags,
  ADD COLUMN policy_topic VARCHAR(60) DEFAULT NULL AFTER citizen_summary;
```

### New table: `mandate_summaries`

```sql
CREATE TABLE mandate_summaries (
  summary_id        INT AUTO_INCREMENT PRIMARY KEY,
  scope_type        ENUM('federal','state','town') NOT NULL,
  scope_value       VARCHAR(50) NOT NULL,
  period_start      DATE NOT NULL,
  period_end        DATE NOT NULL,
  mandate_count     INT DEFAULT 0,
  contributor_count INT DEFAULT 0,
  topic_breakdown   JSON,
  trending_topics   JSON,
  gap_analysis      JSON,
  narrative         TEXT,
  town_hall_agenda  TEXT,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## Save-Time AI Tagging

When a mandate is saved via `talk/api.php`:

1. INSERT into `idea_log` succeeds (existing flow, unchanged)
2. Fire Claude classify call (~100 tokens, ~$0.002):
   ```
   Given this citizen mandate, respond with ONLY a JSON object:
   {"citizen_summary": "<plain language, 5-10 words>",
    "policy_topic": "<one from taxonomy>"}
   ```
3. UPDATE `idea_log SET citizen_summary=?, policy_topic=? WHERE id=?`
4. If classify fails, mandate is still saved — tags stay NULL, backfill later

Same pattern as existing `autoClassify` in talk/api.php.

---

## API: `api/mandate-summary.php`

**GET parameters:**
- `scope` — federal | state | town
- `scope_value` — district code, state_id, or town_id
- `period` — all | month | week (default: all)

**Response:**
```json
{
  "success": true,
  "scope": "federal",
  "scope_value": "CT-2",
  "mandate_count": 87,
  "contributor_count": 312,
  "topics": [
    {
      "policy_topic": "Campaign Finance & Elections",
      "count": 37,
      "pct": 42.5,
      "citizen_voices": ["get money out of politics", "ban corporate donations"]
    }
  ],
  "recent_activity": {"this_week": 12, "last_week": 8, "trend": "up"},
  "top_mandates": [
    {"id": 123, "content": "Pass the DISCLOSE Act...", "policy_topic": "Campaign Finance & Elections", "created_at": "..."}
  ]
}
```

All pure SQL — GROUP BY, COUNT, date filters.

---

## Public Summary Page

Single page `mandate-summary.php` with query params.

**Layout (server-rendered, dark theme):**

1. **Header** — "Constituent Mandate Summary: CT-2" + delegation popup (reuse existing)
2. **Scoreboard** — three boxes: Total Mandates, Constituents, This Week
3. **Topic breakdown** — policy topics ranked by count with CSS percentage bars, expandable to show citizen_voices
4. **Full mandate list** — stream with policy_topic badges
5. **Export** — "Download CSV" (Layer 1), "Generate Report" placeholder (Layer 3)

---

## Implementation Layers

### Layer 1 — Visibility (build now)

- Schema: ALTER idea_log + CREATE mandate_summaries
- Save-time tagging: dual AI classify on mandate save
- Backfill script: classify existing mandates
- API: `api/mandate-summary.php` with topic grouping
- Public page: `mandate-summary.php` with scoreboard + topic breakdown + mandate list + CSV export

### Layer 2 — Trends & Geography (build next)

- Volume over time: month-by-month counts (sparklines or bar charts)
- Geographic breakdown: which towns contribute most within a district
- Trending detection: topics with biggest growth (last 30d vs prior 30d)
- Recent activity widget on public page

### Layer 3 — Synthesis & Town Hall (build last)

- Weekly cron: Claude synthesizes all mandates per scope into narrative + gap analysis + town hall agenda
- Stores in `mandate_summaries` table
- Gap detection: when many citizen_summaries cluster around a specific concern flattened into a broad policy bucket
- Town hall agenda: "Suggested Topics" section on public page + exportable PDF
- Email digest: rep offices subscribe to weekly summary via `sendSmtpMail`
- The summary report IS the town hall agenda — ranked by volume, citizen language alongside policy framing

---

## Key Files

| File | Action | Layer |
|------|--------|-------|
| `talk/api.php` | Modify — add dual AI tagging after mandate INSERT | 1 |
| `api/mandate-summary.php` | Create — topic-grouped aggregation endpoint | 1 |
| `mandate-summary.php` | Create — public summary page | 1 |
| `scripts/maintenance/backfill-mandate-topics.php` | Create — classify existing mandates | 1 |
| `scripts/db/add-mandate-summary-tables.sql` | Create — schema documentation | 1 |
| `config/mandate-topics.php` | Create — taxonomy constant | 1 |
| `mandate-summary.php` | Modify — add trend widgets | 2 |
| `scripts/cron/mandate-synthesis.php` | Create — weekly AI synthesis | 3 |
| `mandate-summary.php` | Modify — add town hall agenda section + PDF export | 3 |
