# TPB Platform Overview

**The People's Branch** — A civic engagement platform where citizens participate in local and national governance.

Last updated: 2026-02-27

---

## Executive Branch Accountability

- **Executive Threat Tracker** (`usa/executive.php`) — 140+ documented executive threats with per-official cards, severity scores, call-to-action scripts, and contact info. Threats are scored on a 1–1,000 criminality scale across 10 zones (Clean → Genocide). Deep-linkable via `#threat-{id}` anchors.
- **Criminality Scale** — 10 severity zones: Clean (0), Questionable (1–10), Misconduct (11–30), Misdemeanor (31–70), Felony (71–150), Serious Felony (151–300), High Crime (301–500), Atrocity (501–700), Crime Against Humanity (701–900), Genocide (901–1,000). Constitutional impeachment threshold ("High Crimes and Misdemeanors") starts at 31.
- **Severity engine** (`includes/severity.php`) — Shared PHP helper: `getSeverityZone($score)` returns label + color for any score. Used by executive.php and all poll pages.

---

## Polls — Citizen & Congressional Voting

- **Vote** (`poll/index.php`) — Every executive action scoring 300+ becomes a poll. Citizens answer "Is this acceptable?" Congress members answer "Will you act on this?" Includes full criminality scale legend, category tag filters, and severity sorting.
- **National Results** (`poll/national.php`) — Aggregate citizen votes across all 50 states with yea/nay/abstain bars per threat.
- **By State** (`poll/by-state.php`) — 50-state landing table. Click a state to see how it voted on each threat side-by-side with the national average.
- **By Rep** (`poll/by-rep.php`) — State delegation view showing each rep's silence rate, positions, and gap vs. constituent votes. Full roll call per rep.
- All threat titles link back to the executive threat tracker (`/usa/executive.php#threat-{id}`) for full detail.

---

## Congressional Digest

- **Congressional Scorecard** (`usa/digest.php`) — Browse 119th Congress: reps, votes, bills, committees, nominations. Filterable by state, party, chamber. Individual rep detail pages with full voting record.
- Query parameter routing: `?state=CT`, `?rep=441`, `?vote=123`, `?bill=hr-144`, `?committee=1049`, `?nom=650`.

---

## Elections 2026

- **Landing page** (`elections/index.php`) — Election overview with live stats (threat count, citizen count, poll votes), feature cards linking to The Fight/The War/Polls, share buttons (X, Bluesky, Facebook) scattered throughout, email-a-friend modal.
- **The Fight** (`elections/the-fight.php`) — 14 pledges + 14 knockouts action tracker. Logged-in users check off pledges (+5 civic points) and knockouts (+25 civic points). Progress bar, auto-tracked activity feed.
- **The War** (`elections/the-amendment.php`) — 28th Amendment strategy page: 70% recall power, 6 numbered sections with expandable details (full amendment text, ratification path, objection rebuttals). Share buttons + email recruit modal.
- **Sub-nav** — All three pages share view-links: Elections | The Fight | The War
- **APIs**: `api/pledge-action.php`, `api/knockout-action.php` (toggle pledges/knockouts with civic points), `api/email-recruit.php` (send amendment recruitment email)
- **DB tables**: `pledges`, `knockouts`, `pledge_knockouts`, `user_pledges`, `user_knockouts`
- **Point actions**: `pledge_made` (id 60, 5 pts), `knockout_achieved` (id 61, 25 pts)

---

## Core Civic Participation

- **Talk** (`talk/`) — Group deliberation. Post ideas, vote, gather/crystallize into action. Geographic streams (USA/state/town) + user-created and standard civic groups.
- **Thoughts** (`thought.php`, `read.php`, `voice.php`) — Individual civic positions with voting. Public feed + personal voice.
- **Aspirations** (`aspirations.php`) — Shared civic goals (database-driven).

---

## Civic Identity & Trust

- **4-tier identity levels** — anonymous → remembered (email) → verified (phone) → vetted (background check)
- **Passwordless auth** — magic link login via `login.php` / `join.php`
- **Parental consent** — minors (13-17) require parent verification
- **Civic points** — gamified engagement tracking (57 point actions, 40 wired) via `includes/point-logger.php`

---

## USA Section

- **Interactive map** (`usa/index.php`) — Multi-mode USA map: National (state delegations), Election, Bills, Orders, Courts
- **Executive** (`usa/executive.php`) — Threat tracker (see above)
- **Congressional** (`usa/digest.php`) — Scorecard (see above)
- **Judicial** (`usa/judicial.php`) — Placeholder (coming soon)
- **Documents** (`usa/docs/`) — Constitution, Declaration of Independence, Gettysburg Address, Federalist Papers, Letter from Birmingham Jail, Oath of Office
- **Glossary** (`usa/glossary.php`) — 130+ congressional terms, searchable by category

---

## Representation & Government

- **Reps** (`reps.php`) — Browse representatives at federal/state/town level, all branches
- **District lookup** — OpenStates integration, lat/lng → districts
- **Amendment 28** (`28/`) — Petition page for "The People's Amendment" (see also Elections → The War)

---

## Community Building

- **People Power** (`0t/`) — Grassroots referral/signaling system with generation tracking
- **Our Story** (`story.php`) — Landing page with avatar video, mission narrative, scroll-tracked engagement
- **State/town pages** (`z-states/`) — Local civic landing pages (CT towns live)

---

## Volunteer & Building

- **Task board** (`volunteer/`) — Claim, expand, complete tasks with PM approval workflow
- **Applications** — 15 skill categories, admin approves/rejects
- **State builder recruitment** — Onboarding flow for volunteers building state pages

---

## AI / Claudia

- **Claudia widget** (`includes/c-widget.php`) — Voice/text civic guide with 7 event hooks
- **Claude Chat API** (`api/claude-chat.php`) — Clerk system with system documentation concordance
- **Talk AI clerks** — Gatherer + brainstorm clerks for gather/crystallize

---

## Infrastructure

- **Admin dashboard** (`admin.php`) — User management, moderation, volunteer approvals, audit logs
- **Modal system** (`api/modal/`) — Page-specific help modals with analytics
- **39 platform roles** — Independent from volunteer system
- **Open Graph** — All pages emit `og:title`, `og:description`, `og:image`, `twitter:card` via `includes/header.php` (set optional `$ogTitle`, `$ogDescription`, `$ogImage` before include)
- **SMTP email** — All mail via `sendSmtpMail()`, not PHP `mail()`
- **Soft deletes** — Users never hard-deleted, `deleted_at` timestamp
- **CSRF protection** — All admin POST forms validated
- **Audit logging** — `admin_actions` table via `logAdminAction()`

---

## Stack

- Vanilla PHP 8.4 (no framework), MySQL, Apache
- XAMPP locally, InMotion hosting (staging + production)
- Google Maps API for interactive map
- Anthropic Claude API for AI features
- OpenStates API for legislative data
