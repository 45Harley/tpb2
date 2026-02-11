-- ================================================
-- State Builder - Initial Task Creation
-- ================================================
-- Creates BUILD tasks for state pages
-- When completed, auto-creates TEST → DEPLOY chain
-- ================================================

-- Connecticut (reference implementation)
INSERT INTO tasks (
    task_key,
    task_type,
    title,
    short_description,
    full_content,
    skill_set_id,
    status,
    priority,
    estimated_hours,
    points,
    created_by_user_id,
    created_at
) VALUES (
    'build-state-ct',
    'build',
    'BUILD: Connecticut State Page',
    'Build comprehensive state page for Connecticut using State Builder Kit with AI assistance.',
    '## BUILD: Connecticut State Page

Welcome! You\'ll build a comprehensive state page for Connecticut that helps residents find benefits, understand their government, and engage civically.

### What You\'ll Build:

A complete state page with **11 sections:**
1. **Overview** - State facts, history, identity
2. **Benefits** - Housing, energy, seniors, EV, DMV, education programs
3. **Your Reps** - Federal delegation + state legislator lookup
4. **Government** - Governor, officials, legislature composition
5. **State Agencies** - 50+ agencies with contact info and search
6. **Budget** - Revenue sources, major spending, transparency
7. **Education** - Universities, community colleges, financial aid
8. **Jobs & Economy** - Key industries, unemployment, GDP
9. **Elections** - Voter registration, recent results, voting info
10. **Your Town** - Interactive grid of all 169 CT towns
11. **Brainstorm CT** - State-level civic thought submission

### Your Resources:

1. **AI Guide** - `/docs/state-builder/STATE-BUILDER-AI-GUIDE.md` ⭐ UPLOAD THIS TO CLAUDE
2. **Data Template** - `/docs/state-builder/state-data-template.json` (Connecticut example)
3. **Quality Checklist** - `/docs/state-builder/state-build-checklist.md`
4. **README** - `/docs/state-builder/README.md` (full workflow, FAQ)

### Process:

1. Click **"Start Build"** button (redirects to onboarding page)
2. Download resources (AI Guide, Data Template, Checklist)
3. Open Claude AI in new tab (https://claude.ai)
4. Upload **STATE-BUILDER-AI-GUIDE.md** to Claude
5. Tell Claude: "I\'m building Connecticut state page. Let\'s follow the guide."
6. Work through 7 phases with Claude:
   - Phase 1: State Basics & Overview (15-20 min)
   - Phase 2: Benefits Programs Research (60-90 min) ⭐ Most time here
   - Phase 3: Government Structure (30-45 min)
   - Phase 4: Budget, Economy, Elections (45-60 min)
   - Phase 5: Agencies, Education, Towns (30-45 min)
   - Phase 6: PHP Code Generation (60-90 min)
   - Phase 7: SQL & Documentation (15-30 min)
7. Package deliverable as ZIP:
   - `ct-state-page.php` (complete PHP file, 1,500-2,000 lines)
   - `ct-state-data.json` (structured data for reference)
   - `ct-state-updates.sql` (database updates)
   - `BUILD-LOG-CT.md` (build documentation)
8. Name ZIP: `ct-state-build-YYYY-MM-DD.zip`
9. Upload to this task, mark **"Ready for Review"**

### What Happens Next:

- System auto-creates **TEST task** (different volunteer reviews your build)
- If TEST approves → system auto-creates **DEPLOY task**
- Deploy volunteer uploads your page to live site
- You earn **500 civic points** + **"State Builder" badge**

### Estimated Time:

**4-6 hours total** (can be split across multiple sessions)

### Tips:

- **Benefits section is most valuable** - residents need accurate program info
- **Start with .gov sites** - most reliable sources
- **Document every source** - builds trust, allows updates
- **Write for citizens** - clear, direct language (not bureaucratic)
- **Don\'t aim for perfection** - 95% accurate is better than never shipping
- **Take breaks** - this is 4-6 hours of work, quality over speed

### Need Help?

- **During build:** Ask Claude - it has the full guide
- **Technical issues:** Post in volunteer Slack
- **Stuck on task:** Add notes here, PM will respond

**Ready to build civic infrastructure? Let\'s go!** 🏛️',
    1, -- skill_set_id (Technical skills)
    'open',
    'high',
    5.5, -- 4-6 hours estimated
    500, -- 500 civic points
    1, -- created_by_user_id (system/admin)
    NOW()
);

-- ================================================
-- Verification Query (run after insert)
-- ================================================
-- SELECT task_id, task_key, title, status, estimated_hours, points
-- FROM tasks
-- WHERE task_key = 'build-state-ct';

-- ================================================
-- Additional States (uncomment after CT is proven)
-- ================================================

-- Massachusetts
-- INSERT INTO tasks (task_key, task_type, title, short_description, full_content, skill_set_id, status, priority, estimated_hours, points, created_by_user_id, created_at)
-- VALUES ('build-state-ma', 'build', 'BUILD: Massachusetts State Page', 'Build comprehensive state page for Massachusetts using State Builder Kit.', '[Same full_content pattern as CT]', 1, 'open', 'high', 5.5, 500, 1, NOW());

-- Rhode Island
-- INSERT INTO tasks (task_key, task_type, title, short_description, full_content, skill_set_id, status, priority, estimated_hours, points, created_by_user_id, created_at)
-- VALUES ('build-state-ri', 'build', 'BUILD: Rhode Island State Page', 'Build comprehensive state page for Rhode Island using State Builder Kit.', '[Same full_content pattern as CT]', 1, 'open', 'high', 5.5, 500, 1, NOW());

-- ================================================
-- Notes
-- ================================================
-- When a volunteer completes this BUILD task:
-- 1. volunteer/api/complete-task.php detects task_type='build' and task_key pattern 'build-state-ct'
-- 2. Auto-creates TEST task: task_key='test-state-ct', parent_task_id=[this task's ID]
-- 3. When TEST approves, auto-creates DEPLOY task: task_key='deploy-state-ct'
--
-- This mirrors the Town Builder workflow (BUILD → TEST → DEPLOY)
