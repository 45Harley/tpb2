# TPB Platform Overview

**The People's Branch** — A civic engagement platform where citizens participate in local and national governance.

Last updated: 2026-02-19

---

## Core Civic Participation

- **Talk** (`talk/`) — Group deliberation. Post ideas, vote, gather/crystallize into action. Geographic streams (USA/state/town) + user-created and standard civic groups.
- **Thoughts** (`thought.php`, `read.php`, `voice.php`) — Individual civic positions with voting. Public feed + personal voice.
- **Polls** (`poll/`) — Direct democratic voting on questions. Admin-created, email verification required.
- **Aspirations** (`aspirations.php`) — Shared civic goals (database-driven).

---

## Civic Identity & Trust

- **4-tier identity levels** — anonymous → remembered (email) → verified (phone) → vetted (background check)
- **Passwordless auth** — magic link login via `login.php` / `join.php`
- **Parental consent** — minors (13-17) require parent verification
- **Civic points** — gamified engagement tracking for actions

---

## Representation & Government

- **Reps** (`reps.php`) — Browse representatives at federal/state/town level, all branches
- **Constitution** (`constitution/`) — Interactive preamble, articles, amendments
- **District lookup** — OpenStates integration, lat/lng → districts
- **Amendment 28** (`28/`) — Petition page for "The People's Amendment"

---

## Community Building

- **People Power** (`0t/`) — Grassroots referral/signaling system with generation tracking
- **Interactive map** (`map.php` / `index.php`) — USA map showing active states, onboarding flow
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
