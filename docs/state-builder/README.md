# TPB State Builder Kit

Welcome to the TPB State Builder! This kit helps volunteers build comprehensive state pages for The People's Branch (TPB) platform.

---

## What is This?

The State Builder is a volunteer-driven system for creating rich, data-driven state pages that help residents find:
- **Benefits programs** (housing, energy, seniors, EV, education)
- **State government** structure and officials
- **State agencies** directory
- **Budget** transparency
- **Elections** and voting information
- **Their town** within the state
- **Civic engagement** through state-level thought submission

---

## Who Can Build?

- **Technical skill volunteers** (skill_set_id = 1) can claim state build tasks
- **No coding knowledge required** - you work with AI assistance
- **4-6 hours** estimated time per state
- **Verification required** - must have email + phone verified

---

## What You'll Build

A complete state page with **11 sections:**

1. **Overview** - State facts, history, identity
2. **Benefits** - State programs (housing, energy, seniors, EV, DMV, education)
3. **Your Reps** - Federal delegation + state legislator lookup
4. **Government** - Governor, officials, legislature composition
5. **State Agencies** - 50+ agencies with search functionality
6. **Budget** - Revenue sources, major spending
7. **Education** - Colleges, financial aid
8. **Jobs & Economy** - Key industries, unemployment, GDP
9. **Elections** - Voter registration, recent results, voting info
10. **Your Town** - Interactive grid of all towns (active vs coming soon)
11. **Brainstorm [State]** - State-level thought submission

---

## The Process

### Build → Test → Deploy

**BUILD (you):**
- Research state data with AI assistance
- Generate PHP page + JSON data + SQL updates
- Package as ZIP and upload
- **Deliverable:** 4 files (PHP, JSON, SQL, BUILD-LOG)

**TEST (different volunteer):**
- Verify all links work
- Check data accuracy
- Test mobile responsiveness
- Approve or reject with feedback

**DEPLOY (trusted volunteer):**
- Upload to production server
- Run SQL updates
- Verify live page
- Mark complete

---

## Resources in This Kit

### 1. [STATE-BUILDER-AI-GUIDE.md](STATE-BUILDER-AI-GUIDE.md) ⭐ **START HERE**
**Comprehensive guide for working with Claude AI** to build your state page.

- Phase-by-phase workflow
- Research tips for each section
- PHP code generation guidance
- Quality checklist
- **This is your main resource** - upload this file to Claude when you start

### 2. [state-data-template.json](state-data-template.json)
**JSON schema** showing all data sections with Connecticut as example.

- Copy this structure for your state
- Replace Connecticut data with your state's data
- Reference for what data to research

### 3. [state-build-checklist.md](state-build-checklist.md)
**Quality assurance checklist** before submission.

- Verify data accuracy
- Check all links
- Ensure completeness
- Final pre-submission checks

### 4. [README.md](README.md) (this file)
Overview of the State Builder system.

---

## Getting Started

### Prerequisites

1. **TPB Account** - You must be logged in
2. **Verified Identity** - Email + phone verified (2FA)
3. **Volunteer Status** - Applied and accepted as Technical volunteer
4. **Task Claimed** - Claim a "BUILD: [State] State Page" task

### Step 1: Claim a Task

1. Go to [Volunteer Dashboard](/volunteer/)
2. Find "BUILD: [State Name] State Page" in Available Tasks
3. Click "Claim Task"
4. Wait for PM approval (you'll be notified)

### Step 2: Start Building

1. Click "Start Build" in your claimed task
2. You'll be directed to the onboarding page
3. Download resources:
   - **STATE-BUILDER-AI-GUIDE.md** (your main guide)
   - **state-data-template.json** (data structure)
   - **state-build-checklist.md** (quality checklist)

### Step 3: Work with Claude AI

1. Open Claude (claude.ai) in a new tab
2. Upload **STATE-BUILDER-AI-GUIDE.md** to Claude
3. Tell Claude: "I'm building a state page for [Your State]. Let's follow the guide."
4. Claude will walk you through:
   - Researching your state's data
   - Filling in the JSON template
   - Generating the PHP code
   - Creating SQL updates
   - Writing the BUILD-LOG

### Step 4: Package & Submit

1. Create a ZIP file with 4 files:
   - `[state]-state-page.php`
   - `[state]-state-data.json`
   - `[state]-state-updates.sql`
   - `BUILD-LOG-[STATE].md`
2. Name the ZIP: `[state]-state-build-YYYY-MM-DD.zip`
3. Upload to your task in volunteer dashboard
4. Mark task as "Ready for Review"
5. You're done! Wait for TEST volunteer to review

---

## File Deliverables

### 1. [state]-state-page.php
**Main PHP file** - the complete state page code

- Uses includes: header.php, nav.php, footer.php, thought-form.php
- 11 sections with comprehensive data
- Styled like Putnam (dark mode, gold accents)
- 1,500-2,000 lines

### 2. [state]-state-data.json
**Structured data reference** - all researched data in JSON format

- Complete data for all sections
- Sources documented
- Used for reference (not loaded by PHP page)

### 3. [state]-state-updates.sql
**Database updates** - SQL queries to update state metadata

- UPDATE states table (population, capital, etc.)
- UPDATE/INSERT elected_officials (governor, etc.)
- INSERT system_documentation entry

### 4. BUILD-LOG-[STATE].md
**Build documentation** - what you built, sources, notes

- Sections completed
- Data sources used
- Known gaps
- Notes for TEST volunteer
- Personal notes

---

## Time Estimate

**Total: 4-6 hours** (can be split across multiple sessions)

- Phase 1: State Basics (15-20 min)
- Phase 2: Benefits Research (60-90 min) ⭐ **Most time here**
- Phase 3: Government Structure (20-30 min) ✅ **Officials in database!** + Constitution
- Phase 4: Budget, Economy, Elections (45-60 min)
- Phase 5: Agencies, Education, Towns (30-45 min)
- Phase 6: PHP Code Generation (60-90 min)
- Phase 7: SQL & Documentation (15-30 min)

---

## Tips for Success

### Research Tips
- **Start with .gov sites** - most reliable sources
- **Cross-reference data** - if numbers vary, use most recent official source
- **Benefits are critical** - spend time getting these right
- **Cite everything** - builds trust, allows updates
- **When stuck, ask Claude** - AI can help find sources

### Quality Tips
- **Accuracy over completeness** - better to have 5 accurate programs than 10 questionable ones
- **Links must work** - every benefit/agency link should be live
- **Current data** - budget should be current/recent fiscal year, officials should be currently serving
- **Write for citizens** - clear, direct language, not bureaucratic
- **Don't aim for perfection** - 95% accurate is better than never shipping

### Time Management
- **Benefits take longest** - budget 90 minutes for thorough research
- **Take breaks** - this is 4-6 hours, split across sessions
- **Don't get stuck** - if you can't find data after 15 min, mark as [TBD] and move on

---

## FAQ

### Q: Do I need to know PHP to build a state page?
**A:** No! Claude AI will generate all the PHP code for you. You focus on researching accurate data.

### Q: What if I can't find certain data?
**A:** It's okay! Note it in your BUILD-LOG as "Known Gaps" and mark fields as [TBD] or skip them. The page can be updated later.

### Q: How accurate does the data need to be?
**A:** Very accurate for benefits (dollar amounts, eligibility, deadlines). Moderately accurate for other sections. When in doubt, cite your source.

### Q: Can I work on this over multiple days?
**A:** Yes! Save your progress (JSON file, partial PHP code) and pick up where you left off. Many volunteers split this into 2-3 sessions.

### Q: What happens after I submit?
**A:** A TEST volunteer will review your build within 3-5 days. They'll check links, verify data, and either approve or request changes. Once approved, a DEPLOY volunteer will upload it to the live site.

### Q: What if my build is rejected?
**A:** The TEST volunteer will provide specific feedback. Fix the issues they note and resubmit. This is normal - quality control ensures accuracy.

### Q: Can I update a state page later?
**A:** Yes! Budget numbers change, officials change, programs change. Volunteers can claim "UPDATE: [State] State Page" tasks to refresh data.

---

## Support

### Need Help?

- **During build:** Ask Claude AI - it has the full guide
- **Technical issues:** Post in volunteer Slack channel
- **Can't find data:** Ask in volunteer Slack - another volunteer may know
- **Stuck on task:** Contact PM through task notes

### Found a Problem with the Kit?

- **Bug in AI Guide:** Report in volunteer Slack
- **Missing section:** Suggest in volunteer Slack
- **Unclear instructions:** Ask for clarification

---

## Examples

### Reference State Page
**Connecticut** - [z-states/ct/index.php](/ct/)
- Complete 11-section state page
- Shows what a finished build looks like
- Use as pattern for your state

### Example Town Page (Pattern)
**Putnam, CT** - [z-states/ct/putnam/index.php](/ct/putnam/)
- Shows the PHP structure (includes, nav, footer)
- Dark mode styling
- Thought submission

---

## Recognition

When your state page is deployed, you'll receive:
- **500 civic points**
- **"State Builder" badge**
- **Your name in the BUILD-LOG** (credited on server)
- **Satisfaction** of helping thousands of residents find benefits

---

## Next Steps

Ready to build?

1. **Claim a task** - [Volunteer Dashboard](/volunteer/)
2. **Download this kit** - All resources in docs/state-builder/
3. **Open Claude AI** - Upload STATE-BUILDER-AI-GUIDE.md
4. **Start building!** - Follow the guide phase by phase

---

**Thank you for building civic infrastructure!** 🏛️

---

*Questions? Contact the volunteer coordinator or ask in Slack.*
