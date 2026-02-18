# TPB Town Page Template Guide
## For Volunteers and AI Assistants Building New Town Pages

**Model town:** Putnam, CT → `/z-states/ct/putnam/index.php`  
**Dynamic fallback:** `town.php` (shows vision page until a real page is built)  
**Last updated:** February 2026

---

## Quick Start

To build a town page for **[Town], [State]**, you need:

1. **Research** the town (see Data Checklist below)
2. **Create** the PHP file at `/z-states/{st}/{town}/index.php`
3. **Copy** the Putnam structure, replace content
4. The dynamic `town.php` fallback will stop showing once the static file exists

---

## File Structure

```
/z-states/
  {state-abbrev-lowercase}/         ← e.g., sc/
    index.html                       ← State overview page
    {town-name-lowercase}/           ← e.g., fort-mill/
      index.php                      ← Main town page (YOU BUILD THIS)
      {town}-schools-budget.html     ← Optional: school budget dashboard
      {town}-ta-reports.html         ← Optional: admin reports tracker
      calendar.php                   ← Optional: community calendar
      {town}-town-code.html          ← Optional: local ordinances
```

All paths are lowercase, hyphens for spaces. Example: `fort-mill`, `new-york`.

---

## PHP Boilerplate (Top of File)

Every town page starts with this. Copy from Putnam and change the constants:

```php
<?php
/**
 * [Town], [State] - Town Page
 * Built by [volunteer name] with AI assistance
 * Date: [date]
 */

// Bootstrap - path goes up to site root
$config = require __DIR__ . '/../../../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// ─── CHANGE THESE FOR YOUR TOWN ───
$townId = ???;          // From towns table: SELECT town_id FROM towns WHERE town_name = '[Town]'
$townName = '[Town]';
$townSlug = '[town]';   // lowercase, hyphens
$stateAbbr = '[st]';    // lowercase 2-letter
$stateId = ???;          // From states table: SELECT state_id FROM states WHERE abbreviation = '[ST]'

// Session handling (don't change)
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;

// Load user data (don't change)
require_once __DIR__ . '/../../../includes/get-user.php';
$dbUser = $sessionId ? getUserBySession($pdo, $sessionId) : null;

// Nav variables (don't change)
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

// Page config
$currentPage = 'town';
$pageTitle = '[Town] [ST] - [Tagline] | The People\'s Branch';
```

---

## Secondary Navigation

Define sections the page will have. The nav renders automatically:

```php
$secondaryNavBrand = '[Town]';
$secondaryNav = [
    ['label' => 'Overview', 'anchor' => 'overview'],
    ['label' => 'History', 'anchor' => 'history'],
    ['label' => 'Government', 'anchor' => 'government'],
    ['label' => 'Budget', 'anchor' => 'budget'],
    ['label' => 'Schools', 'anchor' => 'schools'],
    ['label' => 'Living Here', 'anchor' => 'living'],
    ['label' => 'Talk', 'url' => '/talk/?town=[town_id]'],
];
```

Then include the shared nav:
```php
require __DIR__ . '/../../../includes/header.php';
require __DIR__ . '/../../../includes/nav.php';
```

---

## Required Sections

### 1. Hero
```html
<section class="hero" id="top">
    <div class="town-badge">[REGION] • [STATE]</div>
    <h1>[Town]: <span>[Tagline]</span></h1>
    <p class="tagline">[One-line description]</p>
</section>
```

### 2. Overview
**Data needed:** Population, area, incorporated date, named for, nearest metro, nearest airport, bordering towns, official website URL.

```html
<section class="overview" id="overview">
    <h2>Overview</h2>
    <p class="section-intro">[2-3 sentence description of the town]</p>
    
    <div class="quick-facts">
        <div class="fact-card"><div class="label">Population</div><div class="value">[#]</div></div>
        <div class="fact-card"><div class="label">Area</div><div class="value">[# sq mi]</div></div>
        <div class="fact-card"><div class="label">Incorporated</div><div class="value">[year]</div></div>
        <div class="fact-card"><div class="label">Named For</div><div class="value">[who/what]</div></div>
        <div class="fact-card"><div class="label">Nearest Metro</div><div class="value">[city (# mi)]</div></div>
        <div class="fact-card"><div class="label">Nearest Airport</div><div class="value">[airport (# mi)]</div></div>
    </div>
    
    <h3>Location</h3>
    <p>[Bordering towns, major roads, geographic features]
        <a href="[official-url]" class="external-link" target="_blank">Official Town Info</a>
    </p>
</section>
```

### 3. History
**Data needed:** Founding story, key historical events, notable transformations. Optional: audio narration, Wikipedia link, historical society link.

### 4. Government
**Data needed:** Form of government (mayor/council/manager), current officials (mayor, manager/administrator, clerk), boards & commissions with meeting schedules, departments with contact info, town hall address & phone.

**Database:** Government branches can be stored in the `government_branches` table:
```sql
-- Check what's already in the DB for this town:
SELECT * FROM government_branches WHERE town_id = [town_id];

-- If empty, you'll need to INSERT. See Putnam's entries for the pattern:
SELECT * FROM government_branches WHERE town_id = 119;
```

### 5. Budget & Taxes
**Data needed:** Total town budget, mill rate/tax rate, major capital projects, links to finance department, tax collector.

**Bonus:** If you can get the actual budget document, create a `{town}-budget.html` dashboard like Putnam's school budget analysis.

### 6. Schools
**Data needed:** School district name, list of schools with grades and enrollment, total students, school budget amount, superintendent info, school district website.

### 7. Living Here
**Data needed:** Key local businesses/districts, recreation, library, arts venues, police/fire, weather notes, tourism links.

### 8. Talk
Town pages link to their Talk stream instead of embedding a brainstorm form. Add a "Get Involved" section:
```php
<section id="voice">
    <h3>Get Involved</h3>
    <ul style="color: #ccc; line-height: 1.8;">
        <li><a href="/talk/?town=[town_id]" class="external-link">Join the conversation on Talk</a> — share ideas for [Town]</li>
        <li><a href="/volunteer/" class="external-link">Volunteer with TPB</a> — Help build civic infrastructure</li>
    </ul>
</section>
```

---

## Data Checklist

Research these items. AI can help find most of them via web search.

### Must Have (minimum viable town page)
- [ ] Town name, state, population
- [ ] 2-3 sentence overview
- [ ] Form of government (who runs the town)
- [ ] Mayor/manager name and contact
- [ ] Town hall address and phone
- [ ] Official town website URL
- [ ] School district and list of schools
- [ ] State legislature link (already in DB)

### Should Have (good town page)
- [ ] Area in square miles
- [ ] Year incorporated / founding history
- [ ] Bordering towns
- [ ] Nearest metro area and airport
- [ ] List of boards/commissions
- [ ] Key departments with contacts
- [ ] Town budget total and mill rate
- [ ] School budget total
- [ ] Library, parks & rec links
- [ ] Chamber of commerce or business association

### Nice to Have (great town page)
- [ ] Historical narrative (founding, key events)
- [ ] Audio narration of town history
- [ ] Budget dashboard (separate HTML file)
- [ ] School budget analysis (separate HTML file)
- [ ] Town code / ordinances reference
- [ ] Community calendar integration
- [ ] Board/commission vacancy info
- [ ] Capital projects list
- [ ] Local dining/shopping/arts highlights

---

## Data Sources (Where to Find Info)

| Data | Source |
|------|--------|
| Population, area | US Census Bureau, city-data.com, Wikipedia |
| Government structure | Town's official website |
| Officials & contacts | Town website → Government/Officials page |
| Boards & commissions | Town website → Boards page, meeting agendas |
| Budget | Town website → Finance dept, annual reports |
| Schools | State dept of education, school district website |
| History | Wikipedia, town historical society, local library |
| Boundaries | Google Maps, town GIS if available |
| Legislature URL | Already in TPB `states` table |
| District info | OpenStates API, state legislature website |

---

## Database Setup

Before building the page, make sure the town exists in the database:

```sql
-- Check if town exists
SELECT town_id, town_name, state_id, population 
FROM towns 
WHERE town_name = '[Town]' AND state_id = (SELECT state_id FROM states WHERE abbreviation = '[ST]');

-- If not, create it
INSERT INTO towns (town_name, state_id, population) 
VALUES ('[Town]', [state_id], [population]);

-- Get the town_id for your PHP constants
SELECT LAST_INSERT_ID();
```

### Optional: Government branches
```sql
INSERT INTO government_branches (town_id, branch_name, branch_type, contact_name, contact_phone, meeting_schedule)
VALUES 
([town_id], 'Town Council', 'board', NULL, '(555) 123-4567', '1st Monday, 7:00 PM'),
([town_id], 'Planning Commission', 'board', NULL, NULL, '3rd Tuesday, 7:00 PM'),
([town_id], 'Police Department', 'department', 'Chief [Name]', '(555) 123-4568', NULL);
```

---

## CSS

The town page CSS is embedded in each page (see Putnam for the full stylesheet). Key classes:

- `.hero` — Full-width banner with town name
- `.quick-facts` — Grid of fact cards
- `.gov-grid` — Grid layout for government cards
- `.gov-card` — Individual board/department card
- `.budget-link` — Styled button for budget links
- `.link-grid` / `.link-card` — Grid of external links
- `.external-link` — Styled external link with arrow
- `.section-intro` — Gray intro text under section headers
- `.vacancy-badge` — Red badge for board vacancies
- `.vacancy-cta` — Gold highlighted CTA for vacancies

Dark mode is default. Gold (#d4af37) is the accent color.

---

## Deployment

1. Create the folder: `/z-states/{st}/{town}/`
2. Place `index.php` in it
3. Place any supplementary HTML files alongside it
4. Test: visit `https://tpb2.sandgems.net/z-states/{st}/{town}/`
5. The `.htaccess` routing means if a static file exists, it takes priority over `town.php`

---

## AI Assistant Notes

When a volunteer asks you (Claude or other AI) to help build a town page:

1. **Search the web** for town data — official website, Wikipedia, census
2. **Check the TPB database** for existing town/state records
3. **Copy the Putnam structure** — don't reinvent, replicate
4. **Use this checklist** to gather data systematically
5. **Start with Must Have items** — ship a basic page, improve later
6. **Create the ZIP** with correct folder structure for deployment
7. **Test paths** — make sure `require` paths point correctly to `/../../../config.php`

The goal is NOT perfection. The goal is a real page that makes residents feel like their town has a civic home. Every town page that exists is better than a "Coming Soon" placeholder.

---

## Example: Building Fort Mill, SC

```
1. Web search: "Fort Mill South Carolina government officials population"
2. DB check: SELECT town_id FROM towns WHERE town_name = 'Fort Mill'
3. Create: /z-states/sc/fort-mill/index.php
4. Fill sections with researched data
5. ZIP and deploy
```

Time estimate: 30-60 minutes with AI assistance.

---

*"All politics is local. And all civic engagement starts with one person deciding to show up."*
