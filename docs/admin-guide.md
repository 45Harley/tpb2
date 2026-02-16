# TPB Admin Dashboard Guide

**URL**: `/admin.php` (staging: `tpb2.sandgems.net/admin.php` | production: `4tpb.org/admin.php`)

---

## Logging In

There are two ways to access the admin dashboard:

### Automatic (role-based)
If you're already logged into TPB as a user with the **Admin** role, you'll be logged in automatically when you visit `/admin.php`. The header will show "Admin: your@email.com".

Admin roles are assigned in the `user_role_membership` table (role_id = 1).

### Password
If you don't have the Admin role (or aren't logged in), enter the admin password on the login screen. The header will show "Admin: password".

The password is set in `config.php` under `admin_password`.

---

## Dashboard Tab

The default view. Shows the health of the platform at a glance.

### Growth
| Stat | What it means |
|------|---------------|
| **Citizens** | Total real users (excludes anonymous/system accounts and soft-deleted users) |
| **Active This Week** | Users with an active device session in the last 7 days |
| **States** | Number of US states represented by users who set their location |

### Identity Verification
A bar showing how many users are at each identity level:
- **Anonymous** (gray) — signed up but no verification
- **Remembered** (blue) — email verified
- **Verified** (green) — phone verified
- **Vetted** (gold) — background checked

This tells you how much of your user base has proven identity.

### Civic Engagement
| Stat | What it means |
|------|---------------|
| **Thoughts Published** | Total published thoughts (+ weekly count). Hidden thoughts shown separately. |
| **Votes Cast** | Total up/down votes on thoughts (+ weekly count) |
| **Volunteers** | Approved volunteer count, plus open tasks needing assignment |

### Needs Attention
Appears only when there's something to act on:
- **Pending volunteer applications** — click to go to Volunteers tab
- **Open tasks** — tasks not yet assigned

### Recent Civic Activity
Last 15 meaningful events: thoughts published, votes cast, email/phone verifications, volunteer applications. Does NOT show page visits, scroll depth, or other noise.

### Admin Actions
Audit log of recent admin actions (delete, hide, restore, approve, reject). Shows who did what and when. Password-auth actions show "(password auth)" instead of an email.

---

## Volunteers Tab

### Pending Applications
Card view of each pending volunteer with:
- Name, email, phone, location
- Applied date, age range, skill set
- **Motivation** and **Background** sections
- Verification links (LinkedIn, GitHub, website, vouch)
- Minor flag with parent info if under 18

**Actions:**
- **Approve** — sets status to `accepted`, sends welcome email via SMTP with link to volunteer workspace
- **Reject** — sets status to `rejected`, sends polite rejection email via SMTP

Both actions are protected against double-submission (checks `approval_email_sent = 0`).

### All Applications
Table view of all applications (pending, approved, rejected) for historical reference.

---

## Thoughts Tab

Table of all thoughts sorted by **engagement** (total votes) descending.

| Column | Meaning |
|--------|---------|
| **Content** | Truncated thought text (hover for full) |
| **Author** | First name or email |
| **Location** | Town, State if set |
| **Level** | Jurisdiction: Federal (blue), State (purple), Town (green) |
| **Score** | Net score (upvotes minus downvotes). Green = positive, red = negative |
| **Engagement** | Total votes (up + down) |
| **Status** | Published (green) or Draft/hidden (orange) |

**Actions:**
- **Hide** — sets thought to `draft` status (removes from public view, reversible)
- **Restore** — sets hidden thought back to `published`
- **Delete** — permanently deletes the thought and its votes (confirmation required)

---

## Users Tab

Table of all real users (excludes anonymous system accounts), sorted by join date.

| Column | Meaning |
|--------|---------|
| **Identity** | Current identity level (color-coded) or red "Deleted" for soft-deleted users |
| **Roles** | Admin, Moderator, Beta Tester, etc. from role membership |
| **Points** | Civic points earned |
| **Thoughts** | Published thought count |
| **Votes** | Total votes cast |
| **Devices** | Active device sessions |

**Actions:**
- **Delete** — soft delete only. Sets `deleted_at` timestamp, deactivates all devices, hides all published thoughts. User cannot log in. Row appears dimmed.
- **Restore** — clears `deleted_at`. User can log in again. Note: devices stay inactive and thoughts stay hidden (manual re-enable if needed).

**Important:** Users are NEVER hard-deleted. All data is preserved for potential restore.

---

## Activity Tab

Table of all civic events in reverse chronological order:

| Event Type | Color | Source |
|------------|-------|--------|
| **thought** | Green | Published thoughts |
| **vote** | Blue | Up/down votes on thoughts |
| **identity** | Gold | Email or phone verifications |
| **volunteer** | Purple | Volunteer applications |

This feed intentionally excludes noise events (page visits, scroll depth, button clicks, etc.) to show only meaningful civic participation.

---

## Bot Tab

Shows bot detection activity across all forms (thought submission and /talk).

### Summary Cards
| Stat | What it means |
|------|---------------|
| **Attempts (24h)** | Bot attempts detected in the last 24 hours |
| **Attempts (7d)** | Bot attempts detected in the last 7 days |
| **Unique IPs (24h)** | Number of distinct IP addresses flagged |
| **Top Form (7d)** | Which form is most targeted by bots |

### Top Offenders
IPs with 3 or more attempts in the last 7 days. Shows attempt count, last seen time, and which forms were targeted.

### Recent Attempts
Last 100 bot attempts with:
- **Time** — when the attempt occurred
- **IP** — source IP address
- **Form** — which form was targeted (submit_thought or talk_save)
- **Triggers** — what detection fired: honeypot (filled hidden field), too_fast (submitted in under 3 seconds), no_referrer (missing HTTP referrer)
- **User Agent** — browser string (truncated, hover for full)

Bot detection does not block IPs — it silently rejects submissions and logs the attempt. IP blocking is handled at the server level via .htaccess if needed.

---

## Security Features

### CSRF Protection
Every form includes a hidden CSRF token. All POST actions validate the token before executing. If the token is invalid or missing, the action is blocked with a 403 error.

### Audit Logging
Every admin action (delete, hide, restore, approve, reject) is logged to the `admin_actions` table with:
- Which admin performed it (user_id or null for password auth)
- What action was taken
- What it targeted (thought, user, volunteer)
- IP address
- Timestamp

### Session Management
- Logout link in the header clears the admin session
- Admin sessions are separate from regular user sessions
- Auto-refresh every 60 seconds keeps stats current

---

## Quick Reference

| Task | Where | How |
|------|-------|-----|
| Check platform health | Dashboard | Look at Growth + Engagement stats |
| Review volunteer | Volunteers tab | Read card, click Approve or Reject |
| Hide inappropriate thought | Thoughts tab | Click Hide button |
| Remove a user | Users tab | Click Delete (soft delete) |
| Undo user removal | Users tab | Click Restore on dimmed row |
| See who did what | Dashboard | Scroll to Admin Actions section |
| See citizen activity | Activity tab | Full chronological event feed |
| Check bot activity | Bot tab | Summary cards + recent attempts |
