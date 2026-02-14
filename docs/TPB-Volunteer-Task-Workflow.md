# TPB Volunteer & Task Workflow

*The People's Branch — How Citizens Build Their Own Democracy*

---

## Philosophy

TPB volunteers aren't employees — they're citizens contributing to civic infrastructure. The volunteer system is designed around three principles:

1. **Trust is earned progressively** — no one gets keys to the kingdom on day one
2. **Division of labor teaches civic participation** — you contribute your piece, not everything
3. **Mentorship is mutual** — teachers volunteer to educate, trainees commit to learn; both earn points, both rate each other

Points are civic gratitude, not currency. They represent what you've given, not what you can spend.

---

## Dual-Track Progression

Every TPB user moves along two parallel tracks simultaneously:

### Identity Levels (Who You Are)

| Level | Name | Requirements | Capabilities |
|-------|------|-------------|-------------|
| 1 | **Anonymous** | None — just show up | View only |
| 2 | **Remembered** | Verify email | View, vote, respond |
| 3 | **Verified** | Verify phone (2FA) | View, vote, post, respond |
| 4 | **Vetted** | Background check | Full access for sensitive roles |

### Engagement Levels (What You Do)

| Level | Name | Min Identity | Requirements |
|-------|------|-------------|-------------|
| 1 | **Viewer** | Anonymous | None — passive participation |
| 2 | **Voter** | Remembered | Cast first vote |
| 3 | **Volunteer** | Verified | Apply and be accepted |
| 4 | **Trainee** | Verified | Assigned to training |
| 5 | **Active** | Verified | Complete required training |
| 6 | **Lead** | Verified | Promoted by admin or existing lead |

Identity gates what you *can* do. Engagement tracks what you *have* done.

---

## The 15 Skill Boxes

When volunteers apply, they select one or more skill areas:

| ID | Skill | Icon | Focus |
|----|-------|------|-------|
| 1 | **Technical** | 💻 | Beta testing, bug finding, code contributions, API integrations |
| 2 | **Content** | 📝 | Documentation, editing, translation, UX writing |
| 3 | **Governance** | 🏛️ | Discussion moderation, policy shaping, volunteer coordination |
| 4 | **Connected** | 📣 | Introductions to officials, representative outreach, coalition building |
| 6 | **Community** | 🏘️ | Town organizing, hosting events, recruiting participants |
| 7 | **Educator** | 🎓 | Civics integration, classroom projects, democracy education |
| 8 | **Project Management** | 📊 | Initiative coordination, team management, progress tracking |
| 9 | **Storyteller** | 🎤 | Video creation, podcasts, media outreach |
| 10 | **Trainer** | 👥 | Platform tutorials, workshops, user onboarding |
| 11 | **Youth** | 🌟 | Digital native energy, social media amplification, peer recruitment |
| 12 | **Designer** | 🎨 | UX/UI improvement, accessibility, visual identity |
| 13 | **Legal/Policy** | ⚖️ | Compliance guidance, policy research, pro bono support |
| 14 | **Social Media** | 📱 | Message amplification, content strategy, viral campaigns |
| 15 | **Security** | 🛡️ | Ethical hacking, vulnerability testing, security audits |

---

## Volunteer Application Flow

```
Citizen visits town page (town.php)
       │
       ▼
  ┌─────────────────────────────┐
  │ Are they logged in?          │──No──▶ "Join TPB" button
  └─────────────────────────────┘
       │ Yes
       ▼
  ┌─────────────────────────────┐
  │ Identity Level >= 3?         │──No──▶ "Verify My Identity" button
  │ (email + phone verified)     │
  └─────────────────────────────┘
       │ Yes
       ▼
  ┌─────────────────────────────┐
  │ Have they applied?           │──No──▶ "Apply to Volunteer" button
  └─────────────────────────────┘
       │ Yes
       ▼
  ┌─────────────────────────────┐
  │ Application status?          │
  │ pending/under_review         │──────▶ "Application being reviewed"
  │ accepted (non-tech)          │──────▶ "Other tasks available"
  │ accepted (tech, skill_id=1)  │──────▶ Show task box + "Accept Task"
  └─────────────────────────────┘
```

### Application Fields

The volunteer application (`volunteer_applications` table) collects:

- **Age range** — includes minor protections (13-17 requires parental consent)
- **Skill set** — which of the 15 skill boxes
- **Desired role** — what they want to do
- **Motivation** — why they want to volunteer
- **Experience** — relevant background
- **Availability** — hours/schedule
- **Verification** — LinkedIn, website, GitHub URLs; vouch name/email
- **Agreements** — review commitment, contribution agreement
- **Minor protections** — parent name, email, consent flag, consent timestamp

---

## Task System

### Task Structure

Every task in TPB lives in the `tasks` table with these key fields:

| Field | Purpose |
|-------|---------|
| `task_key` | Unique identifier (e.g., `build-fort-mill-sc`) |
| `title` | Human-readable name |
| `short_description` | Brief summary |
| `full_content` | Detailed instructions |
| `starter_prompt` | AI Guide prompt for the volunteer |
| `skill_set_id` | Required skill (links to skill_sets) |
| `parent_task_id` | Chain support — links to predecessor task |
| `status` | `open` → `claimed` → `in_progress` → `review` → `completed` |
| `claimed_by_user_id` | Who picked it up |
| `claim_status` | `none` → `pending` → `approved` / `denied` |
| `claim_approved_by` | Admin who approved the claim |
| `points` | Civic points earned on completion |
| `priority` | `low` / `medium` / `high` / `critical` |
| `estimated_minutes` | Time estimate |

### Task Status Flow

```
   open ──▶ claimed ──▶ in_progress ──▶ review ──▶ completed
                │                          │
                ▼                          ▼
        claim_status:              Reviewed by lead
        none → pending → approved    or admin
```

### Claim Approval

Not every task is self-serve. The `claim_status` field controls whether a volunteer can just grab a task or needs approval:

- `claim_status = 'none'` — task not yet claimed
- `claim_status = 'pending'` — volunteer requested it, awaiting admin approval
- `claim_status = 'approved'` — admin approved, volunteer can proceed
- `claim_status = 'denied'` — admin denied with reason in `claim_denied_reason`

---

## Build → Test → Deploy: The Task Chain

The primary workflow for town page creation uses **task chaining** via `parent_task_id`:

```
┌─────────────────────────────────────┐
│ BUILD                                │
│ task_key: build-{town}-{state}       │
│ skill: Technical                     │
│ deliverable: ZIP (HTML + SQL)        │
│ parent_task_id: NULL                 │
│ status: open                         │
└──────────────┬──────────────────────┘
               │ When completed...
               ▼
┌─────────────────────────────────────┐
│ TEST                                 │
│ task_key: test-{town}-{state}        │
│ skill: Technical                     │
│ deliverable: Test report             │
│ parent_task_id: → BUILD task_id      │
│ status: open (auto-created)          │
│ DIFFERENT volunteer reviews work     │
└──────────────┬──────────────────────┘
               │ When completed...
               ▼
┌─────────────────────────────────────┐
│ DEPLOY                               │
│ task_key: deploy-{town}-{state}      │
│ skill: Technical                     │
│ deliverable: Files uploaded to server│
│ parent_task_id: → TEST task_id       │
│ status: open (auto-created)          │
│ TRUSTED volunteer uploads to server  │
└─────────────────────────────────────┘
```

### Chain Rules

- Each task is a separate row in the `tasks` table
- `parent_task_id` points to the previous task in the chain
- When a task reaches `completed`, the next task in the chain automatically becomes `open`
- **Different volunteers** should handle each step when possible (separation of duties)
- In small towns with fewer volunteers, a trusted volunteer may handle multiple steps

### Why Separation of Duties?

The Build → Test → Deploy pattern isn't just software engineering best practice — it's a **civic engagement exercise**:

- The **builder** creates the page using AI-assisted tools
- The **tester** verifies the work independently (catches errors, checks accessibility)
- The **deployer** handles server access (restricted to highest-trust volunteers)

This teaches citizens that contributing doesn't mean doing everything — it means doing your piece well.

---

## Town Page as Recruitment Portal

The dynamic `town.php` page serves as the **front door** to the volunteer pipeline. It shows different content based on the visitor's trust level — six tiers, one page:

| Tier | User Status | What They See |
|------|-------------|---------------|
| 1 | Anonymous (not logged in) | Steps 1-4 + "Join TPB" button |
| 2 | Logged in, not verified | Step 1 ✓, Steps 2-4 + "Verify My Identity" button |
| 3 | Verified, not a volunteer | Steps 1-2 ✓, Steps 3-4 + "Apply to Volunteer" button |
| 4 | Volunteer application pending | "Application being reviewed" status |
| 5 | Approved volunteer, non-tech skill | "Other tasks available for your skill set" |
| 6 | Approved tech volunteer | Full task box with details + "Accept This Task" button |

### Task Box (Tier 6 Only)

When an approved tech volunteer visits a town that needs building, they see:

- **Task type**: Town Page Build
- **Skill required**: Technical
- **Tools**: AI-assisted (Town Builder Kit + Claude)
- **Deliverable**: ZIP file with HTML page + SQL
- **Chain explanation**: "When you complete this build, a TEST task will be created for another volunteer to review your work. After testing, a DEPLOY task completes the chain."

### Task Status on Town Page

The town page also checks whether a build task already exists:

- **No task exists** → "This Town Needs a Builder" (show recruitment CTA)
- **Task claimed/in_progress/review** → "A volunteer is already building this town's page"
- **Task completed** → "This town's page has been built and is awaiting deployment"

---

## Mentorship System

TPB mentorship is **mutual** — it's not a one-way knowledge dump.

### Mentorship Structure

| Field | Purpose |
|-------|---------|
| `mentor_user_id` | The teacher |
| `trainee_user_id` | The learner |
| `skill_set_id` | What they're teaching |
| `status` | `pending` → `active` → `paused` → `completed` / `ended` |
| `mentor_hours_committed` / `given` | Mentor's time tracking |
| `trainee_hours_committed` / `completed` | Trainee's time tracking |
| `ended_reason` | `completed`, `mutual_end`, `mentor_withdrew`, `trainee_withdrew`, `admin_ended` |

### Mutual Accountability

- Both mentor and trainee commit hours upfront
- Both track hours completed
- Both earn civic points for participation
- Both rate each other bidirectionally
- Either party can end the mentorship, with a reason recorded

### Mentorship Flow

```
Mentor offers to teach          Trainee requests to learn
       │                                │
       ▼                                ▼
  ┌─────────────────────────────────────────┐
  │         Mentorship Created               │
  │         status: pending                  │
  └──────────────┬──────────────────────────┘
                 │ Both accept
                 ▼
  ┌─────────────────────────────────────────┐
  │         status: active                   │
  │         Hours tracked for both parties   │
  └──────────────┬──────────────────────────┘
                 │
        ┌────────┴────────┐
        ▼                 ▼
   Completed          Ended early
   (both rated)    (reason recorded)
```

---

## User Detection: How the Code Knows What to Show

The standardized `getUser()` function in `includes/get-user.php` handles authentication site-wide:

1. **Checks `tpb_user_id` cookie first** — most reliable, direct user_id lookup
2. **Falls back to `tpb_civic_session`** — looks up session in `user_devices` table
3. **Returns full user object** — all fields from `users` + `user_identity_status`
4. **Returns false** if neither cookie found

Then the page determines user status:

```
$dbUser = getUser($pdo);

if (!$dbUser)                          → anonymous
elseif (identity_level_id < 3)         → not_verified
elseif (no volunteer_application)      → verified
elseif (application pending/review)    → volunteer_pending
elseif (accepted, skill_set_id != 1)   → volunteer_no_tech
elseif (accepted, skill_set_id == 1)   → tech_volunteer
```

---

## Key Files

| File | Purpose |
|------|---------|
| `town.php` | Dynamic town/state page — recruitment portal with 6-tier CTA |
| `includes/get-user.php` | Standardized `getUser()` auth function |
| `volunteer/index.php` | Volunteer landing page |
| `volunteer/apply.php` | Volunteer application form |
| `volunteer/task.php` | Task detail and management |
| `volunteer/accept-task.php` | Task acceptance endpoint *(TODO)* |

---

## Database Tables Reference

| Table | Purpose |
|-------|---------|
| `users` | All user accounts with identity/engagement levels |
| `identity_levels` | The 4 identity tiers (anonymous → vetted) |
| `engagement_levels` | The 6 engagement tiers (viewer → lead) |
| `volunteer_applications` | Application form data + status + review |
| `skill_sets` | The 15 volunteer skill categories |
| `tasks` | All tasks with chaining, status, and claim tracking |
| `mentorships` | Mentor-trainee pairings with mutual hour tracking |
| `user_devices` | Session management for multi-device auth |
| `user_milestones` | Track milestone completion for points |
| `progression_milestones` | Define the 20+ milestones and their point values |

---

## Still TODO

- [ ] **`accept-task.php`** — endpoint for volunteers to claim tasks (changes task status from `open` → `claimed`, sets `claimed_by_user_id`)
- [ ] **Auto-create TEST task** — when BUILD task completes, automatically insert TEST task row with `parent_task_id` pointing back
- [ ] **Auto-create DEPLOY task** — when TEST task completes, same pattern
- [ ] **Task notification emails** — alert volunteers when tasks become available or their task is reviewed
- [ ] **Mentor matching** — connect mentorship offers with mentorship requests by skill_set_id
- [ ] **Bidirectional rating system** — mentor rates trainee, trainee rates mentor, after mentorship ends
- [ ] **Deploy site-wide-user-fix.zip to 4tpb.org** — currently only on tpb2.sandgems.net

---

*Document generated from TPB development sessions, February 2026.*
*Database: sandge5_tpb2 on tpb2.sandgems.net*
