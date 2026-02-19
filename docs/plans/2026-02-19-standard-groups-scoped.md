# Standard Groups: Scoped by Level + Local Department Mapping

**Status:** Complete — implemented and deployed

---

## Summary

Scope-aware standard civic groups with three distinct tiers:
- **Town**: 13 civic topics auto-created per town (police, fire, courts, education, etc.)
- **State**: 18 civic topics auto-created per state (town groups + utilities, agriculture, corrections, etc.)
- **Federal**: 19 hand-curated government categories pre-seeded (Defense & Military, Justice & Law Enforcement, Federal Courts, etc.)

Each group maps to a template in `standard_group_templates` (32 total rows). A local department mapping layer (`town_department_map`) displays real agency names instead of generic category names (e.g., "Putnam Police Department" instead of "Police & Public Safety").

---

## New Table: `standard_group_templates`

```sql
CREATE TABLE standard_group_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),                              -- "Police & Public Safety"
    sic_codes VARCHAR(50),                          -- "9221,9229"
    min_scope ENUM('town','state','national'),      -- lowest level this applies
    sort_order INT
);
```

### Town level (13 groups)

| # | Name | SIC Codes | min_scope |
|---|------|-----------|-----------|
| 1 | Police & Public Safety | 9221, 9229 | town |
| 2 | Fire Protection | 9224 | town |
| 3 | Courts & Legal | 9211, 9222 | town |
| 4 | Schools & Education | 9411 | town |
| 5 | Public Health | 9431 | town |
| 6 | Social Services | 9441 | town |
| 7 | Roads & Transportation | 9621 | town |
| 8 | Water, Sewer & Waste | 9511 | town |
| 9 | Parks, Land & Conservation | 9512 | town |
| 10 | Housing | 9531 | town |
| 11 | Zoning & Planning | 9532 | town |
| 12 | Budget & Taxes | 9311 | town |
| 13 | General Government | 9111, 9121, 9131, 9199 | town |

### State adds (+5)

| # | Name | SIC Codes | min_scope |
|---|------|-----------|-----------|
| 14 | Utilities Regulation | 9631 | state |
| 15 | Agriculture | 9641 | state |
| 16 | Commercial Licensing | 9651 | state |
| 17 | Veterans' Affairs | 9451 | state |
| 18 | Corrections | 9223 | state |

### National adds (+4)

| # | Name | SIC Codes | min_scope |
|---|------|-----------|-----------|
| 19 | National Security | 9711 | national |
| 20 | International Affairs | 9721 | national |
| 21 | Space, Research & Technology | 9661 | national |
| 22 | Economic Programs | 9611 | national |

### Federal-specific templates (+10)

Federal groups don't cascade from town/state templates — they have their own categories that reflect how the U.S. government actually works. These 10 additional templates (IDs 23-32) were added to support federal groups that have no town/state equivalent:

| # | Name | min_scope |
|---|------|-----------|
| 23 | Defense & Military | national |
| 24 | Justice & Law Enforcement | national |
| 25 | Federal Courts | national |
| 26 | Intelligence & Homeland Security | national |
| 27 | Foreign Affairs | national |
| 28 | Public Lands & Conservation | national |
| 29 | Environment & Energy | national |
| 30 | Emergency Management | national |
| 31 | Congress & Executive | national |
| 32 | Commerce & Regulation | national |

**Total: 32 templates** (13 town + 5 state + 4 original national + 10 federal-specific)

SIC 9999 (Nonclassifiable) dropped — that's what user-created groups are for.

---

## Size Tiers: Expand / Collapse Standard Groups

Not every town needs all 13 groups. A village of 800 people has a volunteer fire chief and a selectman — not 13 departments. Meanwhile a city of 100k needs groups split further.

| Tier | Population | Groups | Strategy | Example |
|------|-----------|--------|----------|---------|
| **Small** | <5k | ~6 | Collapse related groups | Brooklyn CT |
| **Medium** | 5k-50k | ~13 | All town-level groups | Putnam CT |
| **Large** | 50k+ | 13+ | Split broad groups into specifics | Hartford CT |

**Small town collapses:**
- "Police & Public Safety" + "Fire Protection" → **"Public Safety"**
- "Courts & Legal" folds into **"General Government"**
- "Housing" + "Zoning & Planning" → **"Housing & Planning"**
- Result: ~6 groups that match the actual town structure

**Large city expands:**
- "Schools & Education" → "K-12 Education" + "Higher Education"
- "Public Safety" → "Police" + "Fire" + "EMS" + "Emergency Management"
- Result: 15-20+ groups matching city department structure

### Implementation approach

The `standard_group_templates` table stores the **medium** tier as the baseline (13 town groups). Small towns and large cities are handled by:

1. **Town builder volunteer decides** — start with all 13, toggle off irrelevant ones
2. **Auto-hide** — groups with zero posts after 90 days are hidden from discovery (not deleted)
3. **Facilitator split/merge** — large city facilitators can request group splits

This keeps the template table simple while allowing real-world flexibility.

---

## Federal Groups (19 categories, pre-seeded)

Federal groups are NOT auto-created from templates like town/state. They were manually designed to reflect the actual structure of the U.S. federal government and pre-seeded via `scripts/db/redo-federal-groups.php`.

| # | Federal Group | Agencies Mapped |
|---|--------------|-----------------|
| 1 | Defense & Military | DOD, Army, Navy, Air Force, Marines, Space Force, Coast Guard, National Guard |
| 2 | Justice & Law Enforcement | DOJ, FBI, ATF, DEA, U.S. Marshals, BOP |
| 3 | Federal Courts | Supreme Court, U.S. Courts |
| 4 | Health & Human Services | HHS, CDC, FDA, NIH, CMS |
| 5 | Treasury & Finance | Treasury, IRS, GAO, OMB, Federal Reserve, CBO |
| 6 | Education | Dept of Education |
| 7 | Transportation | DOT, FAA, FHWA, NHTSA, Amtrak |
| 8 | Environment & Energy | EPA, DOE, NRC, FERC, Army Corps |
| 9 | Public Lands & Conservation | NPS, BLM, Forest Service, Fish & Wildlife |
| 10 | Foreign Affairs | State Dept, USAID, Peace Corps |
| 11 | Intelligence & Homeland Security | DHS, CIA, NSA, DNI |
| 12 | Labor & Social Services | DOL, SSA, AmeriCorps |
| 13 | Commerce & Regulation | Commerce, FTC, SEC, SBA, CFPB, FCC, BEA, BLS |
| 14 | Housing & Urban Development | HUD |
| 15 | Veterans Affairs | VA |
| 16 | Agriculture & Food | USDA |
| 17 | Science & Technology | NASA, NSF, NOAA |
| 18 | Emergency Management | FEMA, U.S. Fire Administration |
| 19 | Congress & Executive | White House, Senate, House, GSA, National Archives |

**73 total agency mappings** with official .gov URLs stored in `town_department_map` (state_id=NULL, town_id=NULL).

The `auto_create_standard_groups` action in `talk/api.php` skips federal scope and returns the pre-seeded count instead.

---

## New Table: `town_department_map`

```sql
CREATE TABLE town_department_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT,                    -- NULL for state/federal mappings
    state_id INT,                   -- NULL for town/federal mappings
    template_id INT,                -- FK to standard_group_templates
    local_name VARCHAR(200),        -- "Putnam Police Department"
    contact_url VARCHAR(500),       -- link to official dept page
    UNIQUE KEY (town_id, state_id, template_id, local_name)
);
```

Multiple departments can map to the same template (e.g., "Board of Finance" + "Tax Collector" + "Assessor" all map to "Budget & Taxes").

**Scope is determined by which IDs are NULL:**
- Town mapping: `town_id` set, `state_id` set → e.g., Putnam Police Department
- State mapping: `town_id` NULL, `state_id` set → e.g., CT State Police
- Federal mapping: both NULL → e.g., Department of Defense (DOD)

---

## Putnam Example Mapping

| Local Department/Board | Template |
|---|---|
| Mayor & Board of Selectmen | General Government |
| Town Meeting | General Government |
| Board of Finance, Treasurer, Tax Collector, Assessor, Board of Tax Review | Budget & Taxes |
| Board of Education | Schools & Education |
| Planning & Zoning Commission | Zoning & Planning |
| Putnam Police Department | Police & Public Safety |
| Fire Department | Fire Protection |
| Recreation Commission | Parks, Land & Conservation |
| Redevelopment Agency | Housing |
| Library Board of Trustees | Schools & Education |
| Veterans Advisory Committee | Veterans' Affairs |
| Trails Committee | Parks, Land & Conservation |
| Putnam Arts Council | General Government |
| Pension Committee | Budget & Taxes |

---

## Integration with Town/State Builder Kits

This becomes a checklist step in the volunteer builder workflow:

> **Step N: Map Local Government**
> - Research your town's departments, boards, and commissions
> - Match each to a civic category from the standard list
> - Add the local name and official website link
> - This powers the Talk groups for your community

When Talk shows standard groups for a town, it displays the local department name(s) as subtitles under the generic group name. Citizens see "Putnam Board of Education" not "Administration of Educational Programs (SIC 9411)."

---

## Changes Completed

1. Created `standard_group_templates` table + seeded 32 rows (13 town + 5 state + 4 national + 10 federal)
2. Created `town_department_map` table with `state_id` column for multi-scope support
3. Updated `auto_create_standard_groups` in `talk/api.php` to read templates + filter by scope (skips federal)
4. Updated group cards in `talk/groups.php` to show local department names
5. Added department mapping step to town + state builder kit docs
6. Migrated existing 28 SIC-based standard groups → new template-based system
7. Pre-seeded 19 federal groups with 73 agency mappings via `scripts/db/redo-federal-groups.php`
8. Fixed federal template uniqueness via `scripts/db/fix-federal-templates.php`

### Migration scripts (in `scripts/db/`)
- `talk-phase8-geo-streams.sql` — initial schema changes
- `seed-standard-group-templates.sql` — seed 22 base templates
- `redo-federal-groups.php` — delete cascaded federal groups, create 19 proper ones + 73 agency mappings
- `fix-federal-templates.php` — add 10 federal-specific templates (IDs 23-32), fix template_id uniqueness
