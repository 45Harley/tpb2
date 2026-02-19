# Standard Groups: Scoped by Level + Local Department Mapping

**Status:** In progress — implementing for Putnam

---

## Summary

Replace the current 28 flat SIC Division J standard groups with scope-aware grouped templates. Towns get ~13 relevant civic topics, states ~18, national gets all ~22. Each group maps to one or more SIC codes.

Add a local department mapping layer so standard groups display the town's actual department names (e.g., "Putnam Police Department" instead of generic "Police & Public Safety").

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

This keeps the template table simple (22 rows) while allowing real-world flexibility.

---

## New Table: `town_department_map`

```sql
CREATE TABLE town_department_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT,
    template_id INT,                -- FK to standard_group_templates
    local_name VARCHAR(200),        -- "Putnam Police Department"
    contact_url VARCHAR(500),       -- link to official dept page
    UNIQUE KEY (town_id, template_id, local_name)
);
```

Multiple departments can map to the same template (e.g., "Board of Finance" + "Tax Collector" + "Assessor" all map to "Budget & Taxes").

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

## Changes Required

1. Create `standard_group_templates` table + seed 22 rows
2. Create `town_department_map` table
3. Update `auto_create_standard_groups` in `talk/api.php` to read templates + filter by scope
4. Update group cards in `talk/groups.php` to show local department names
5. Add department mapping step to town builder kit docs
6. Migrate existing 28 standard groups → new template-based system
