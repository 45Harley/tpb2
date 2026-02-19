# State Builder - Quality Checklist

Use this checklist before submitting your state page build to ensure everything is complete and accurate.

---

## Data Quality

### Benefits Programs
- [ ] All benefit programs have working website links (.gov or official sites)
- [ ] Dollar amounts are accurate and current (2025-2026)
- [ ] Eligibility requirements are clearly stated
- [ ] Application process is described
- [ ] Phone numbers are included where available
- [ ] Deadlines or enrollment periods are noted
- [ ] Source URLs are documented in JSON

### Government Officials
- [ ] Governor name, party, and term are current
- [ ] Lt. Governor name and party are current
- [ ] All statewide officials (AG, Secretary of State, Treasurer, Comptroller) are current
- [ ] Party affiliations are correct
- [ ] Legislature composition (Senate/House breakdown) is accurate
- [ ] Legislature website is correct

### Budget Data
- [ ] Budget year matches current/recent fiscal year
- [ ] Total budget amount is accurate
- [ ] Revenue sources add up correctly
- [ ] Spending categories add up correctly
- [ ] Budget type (annual/biennial) is noted
- [ ] Rainy day fund amount is current
- [ ] Source document URL is included

### Elections Data
- [ ] Voter registration numbers are recent (within last year)
- [ ] Party percentages add up to ~100%
- [ ] Recent election results are accurate (winner, margin, turnout)
- [ ] Upcoming elections are listed with correct dates
- [ ] Voting info (registration deadline, early voting, etc.) is current

### Economy Data
- [ ] Unemployment rate is recent (within last 3 months)
- [ ] GDP is from recent year (note year)
- [ ] Key industries are accurately described
- [ ] Major employers are correct
- [ ] Sources are cited

### State Agencies
- [ ] At least 40-50 agencies listed
- [ ] All agency website URLs work
- [ ] Phone numbers are formatted correctly
- [ ] Services descriptions are accurate
- [ ] Search keywords are relevant

### Department → Civic Group Mapping
- [ ] Each agency is mapped to a standard group template (22 civic categories)
- [ ] Mappings seeded into `town_department_map` (with `state_id`, `town_id = NULL`)
- [ ] Local agency names are accurate (e.g., "CT Dept of Emergency Services" not generic "Public Safety")
- [ ] Contact URLs point to the agency's official page
- [ ] All 18 scope-appropriate templates have at least one agency mapped
- [ ] Run `seed-all-departments.php` or equivalent to populate staging DB
- [ ] Verify Talk groups page shows agency names under civic topic headings

---

## Content Quality

### Writing
- [ ] No spelling errors
- [ ] No grammar errors
- [ ] Numbers formatted with commas (e.g., 3,605,944 not 3605944)
- [ ] Percentages include % symbol
- [ ] Dates are consistent format (YYYY-MM-DD or spelled out)
- [ ] Town/city names are capitalized correctly
- [ ] Acronyms are defined on first use

### Tone
- [ ] Content is written for citizens, not bureaucrats
- [ ] Language is clear and direct
- [ ] Active voice used ("Apply online" not "Applications may be submitted")
- [ ] No jargon without explanation
- [ ] Civic-focused and action-oriented

---

## Technical Quality

### PHP Code
- [ ] File includes all necessary `require` statements
- [ ] Database connection code is present
- [ ] State constants (stateId, stateName, stateAbbr) are correct
- [ ] User detection via `getUser()` is included
- [ ] Secondary nav array has all 11 sections
- [ ] Thought form is included with `$defaultIsLocal = false`
- [ ] All sections have proper anchor IDs matching secondary nav
- [ ] Styles are inline in `$pageStyles` variable (not external CSS)
- [ ] External links have `target="_blank"`
- [ ] Header, nav, and footer includes are present

### JSON File
- [ ] JSON validates (no trailing commas, proper escaping)
- [ ] All required sections are present (_meta, hero, overview, etc.)
- [ ] Sources array is populated
- [ ] Builder name and build date are filled in
- [ ] State ID, abbreviation, name are consistent

### SQL File
- [ ] SQL queries are valid (no syntax errors)
- [ ] State ID and abbreviation are correct
- [ ] UPDATE statements use WHERE clause
- [ ] INSERT statements have all required fields
- [ ] Comments explain what each query does
- [ ] Verification queries are included (commented out)

### File Paths
- [ ] Includes use correct relative paths (../../includes/)
- [ ] Config file path is correct (../../config.php)
- [ ] No absolute paths (use relative paths)

---

## Completeness

### Required Sections (11 total)
- [ ] Overview (state facts, history, identity)
- [ ] Benefits (housing, energy, seniors, EV, DMV, education)
- [ ] Your Reps (federal delegation + state legislator lookup link)
- [ ] Government (governor, officials, legislature composition)
- [ ] State Agencies (50+ agencies with contact info)
- [ ] Budget (revenue sources, major spending)
- [ ] Education (universities, community colleges, financial aid)
- [ ] Jobs & Economy (key industries, unemployment, GDP)
- [ ] Elections (voter registration, recent results, voting info)
- [ ] Your Town (towns grid, active vs coming soon)
- [ ] Brainstorm [State] (state-level thought submission)

### Benefits Categories
- [ ] Housing (at least 1-2 programs)
- [ ] Energy (at least 1-2 programs)
- [ ] Seniors (at least 1 program)
- [ ] EV (at least 1 program)
- [ ] DMV (at least 1-2 services)
- [ ] Education (at least 1-2 programs)

### Documentation
- [ ] BUILD-LOG-[STATE].md is included
- [ ] Builder name is in BUILD-LOG
- [ ] Build date is in BUILD-LOG
- [ ] Total hours estimate is in BUILD-LOG
- [ ] Sections completed are checked off
- [ ] Data sources are listed
- [ ] Known gaps are noted
- [ ] Notes for TEST volunteer are included

---

## Links & Accessibility

### Link Checking
- [ ] All benefit program links work (no 404s)
- [ ] All state agency links work
- [ ] Legislature website link works
- [ ] Governor website link works
- [ ] Budget document link works
- [ ] Voter registration link works
- [ ] Education links (colleges, financial aid) work
- [ ] External links open in new tab (`target="_blank"`)

### Mobile Responsiveness
- [ ] Page loads on mobile (preview in browser dev tools)
- [ ] Secondary nav is readable on mobile
- [ ] Benefit cards stack properly on mobile
- [ ] Text is readable (not too small)
- [ ] Buttons are tap-friendly (not too small)

---

## Final Checks

### Before Packaging ZIP
- [ ] All 4 files are present (PHP, JSON, SQL, BUILD-LOG.md)
- [ ] File names match pattern: `[state]-state-page.php`, `[state]-state-data.json`, `[state]-state-updates.sql`, `BUILD-LOG-[STATE].md`
- [ ] Files are in root of ZIP (not in subdirectory)
- [ ] ZIP file name follows pattern: `[state]-state-build-YYYY-MM-DD.zip`

### Testing (if possible)
- [ ] PHP file runs without errors on local XAMPP
- [ ] Database connection works
- [ ] SQL updates run without errors on test database
- [ ] Page displays correctly in browser
- [ ] All anchors work (clicking secondary nav scrolls to sections)
- [ ] Thought form displays (even if submission doesn't work locally)

---

## Pre-Submission Verification

### Review with Fresh Eyes
- [ ] Take a 10-minute break, then re-read the entire page
- [ ] Check one more time: Are all dollar amounts accurate?
- [ ] Check one more time: Are all officials current?
- [ ] Check one more time: Do all links work?

### Compare to Checklist
- [ ] I have checked every box above
- [ ] I have verified data accuracy
- [ ] I have tested links
- [ ] I am confident this build meets quality standards

---

## Ready to Submit?

If all boxes are checked, you're ready to:

1. **Package the ZIP** with all 4 files
2. **Upload to task** in volunteer dashboard
3. **Mark task as "Ready for Review"**
4. **Celebrate!** You just built a comprehensive state page that will help thousands of residents.

---

## Notes

- **Don't aim for perfection** - 95% accurate is better than never shipping
- **Missing data is okay** - Note gaps in BUILD-LOG, can be filled later
- **Quality over quantity** - Better to have 5 accurate benefit programs than 10 questionable ones
- **When in doubt, cite sources** - If data conflicts, use most recent official source and cite it

---

**Good luck, and thank you for building civic infrastructure!** 🏛️
