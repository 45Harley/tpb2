# Invite System Design

**Date:** 2026-03-02
**Status:** Approved (mockups reviewed)

## Overview

A referral/invite system where existing TPB members invite friends via personalized email. Invitor earns 100 Civic Points when invitee joins. Full tracking of who referred whom.

## Three Screens

### 1. Invite Email (sent to invitee)
- **Mockup:** `tmp/send-mockup.php` (approved)
- **Format:** HTML email, threat-bulletin style (600px table, #1a1a2e header, #c8a415 gold)
- **Subject:** "Your friend {invitor_email} invited you to The People's Branch"
- **Content:**
  - Personal intro: "Your friend {invitor_email} thinks you should be part of this."
  - What is TPB (Golden Rule link to /goldenrule.html)
  - "Just imagine..." narrative sequence (4 items + free/ad-free closer + founding volunteers)
  - Gold CTA button: "Accept Invitation" → `/invite/accept.php?token=TOKEN`
  - "How Invitations Work" callout (100 Civic Points mention)
  - Footer with no-spam assurance
- **BCC:** contacts@4tpb.org on every invite

### 2. Acceptance Landing Page (invitee's first logged-in experience)
- **Mockup:** `tmp/mockup-invite-landing.html` (approved)
- **URL:** `/invite/accept.php?token=TOKEN`
- **Flow:** Token validates → account created, email verified, session started → page renders
- **Content:**
  - Welcome hero: "Invited by {invitor_email}", points-earned badge
  - "Here's what's yours now" — 4 feature cards (Talk, Threats, Polls, Map) each opens new tab
  - "Make it personal" — 4 profile steps with "because..." reasoning:
    - Set your town (+50 pts) — "Because your neighbors are already here"
    - Verify your phone (+25 pts) — "Because trust earns influence"
    - Set a password (+10 pts) — "Because any device, anytime"
    - Invite a friend (+100 pts) — "Because {invitor_email} did it for you"

### 3. Invitor's Page (logged-in member sends invites)
- **Mockup:** `tmp/mockup-invite-page.html` (approved)
- **URL:** `/invite/` (nav item after "My TPB", logged-in only)
- **Access:** Email-verified users only (identity_level_id >= 2)
- **Content:**
  - Header: "Invite a Friend" + "Earn 100 Civic Points" badge
  - "How It Works" box (4 steps + tip about giving friends a heads-up)
  - Email form: input + Add button, builds list with remove buttons
  - Email validation: checks if already a member (shows "Already a member" badge, skips on send)
  - Collapsible email preview: shows full rendered invite email inline
  - Send button: "Send N Invitations" (count excludes existing members)
  - History table: past invites with Pending/Joined status + points earned
  - Summary: total points earned from invitations, acceptance rate

## Database

### `invitations` table
```sql
CREATE TABLE invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invitor_user_id INT NOT NULL,
    invitee_email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('sent','joined') DEFAULT 'sent',
    invitee_user_id INT NULL,
    points_awarded TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    joined_at DATETIME NULL,
    FOREIGN KEY (invitor_user_id) REFERENCES users(user_id),
    FOREIGN KEY (invitee_user_id) REFERENCES users(user_id),
    INDEX idx_token (token),
    INDEX idx_invitor (invitor_user_id),
    INDEX idx_invitee_email (invitee_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### `point_actions` entry
```sql
INSERT INTO point_actions (action_name, points_value, cooldown_hours, daily_limit, is_active)
VALUES ('referral_joined', 100, 0, NULL, 1);
```

## API Endpoints

### `POST /api/send-invite.php`
- **Auth:** getUser(), require identity_level_id >= 2
- **Input:** `{ emails: ["john@example.com", "sarah@example.com"] }`
- **Per email:**
  1. Validate email format
  2. Check if already a member (users table, active, not deleted) → skip, return "already_member"
  3. Generate token: `bin2hex(random_bytes(16))`
  4. Insert into `invitations` table
  5. Build HTML email with invitor's email + token
  6. `sendSmtpMail()` to invitee + BCC contacts@4tpb.org
  7. 1s throttle between sends
- **Response:** `{ results: [{ email, status: "sent"|"already_member"|"failed" }] }`

### `GET /invite/accept.php?token=TOKEN`
- **Flow:**
  1. Look up token in `invitations` table (status = 'sent')
  2. If invalid/expired → error page
  3. Check if invitee_email already exists as active user → redirect to login
  4. Create user account (email from invitation, email verified)
  5. Set session cookie (tpb_civic_session)
  6. Update invitation: status='joined', invitee_user_id, joined_at
  7. Award 100 pts to invitor via PointLogger::award()
  8. Send "thank you" email to invitor: "Your friend {invitee_email} just joined! +100 Civic Points"
  9. Render landing page

### `GET /api/check-invite-email.php`
- **Auth:** getUser()
- **Input:** `?email=someone@example.com`
- **Response:** `{ exists: true|false }`
- Used by form to show "Already a member" badge in real-time

## Invitor Notification Email (when invitee joins)
- **Subject:** "Your friend {invitee_email} just joined TPB! +100 Civic Points"
- **Format:** Simple, short HTML (same template style)
- **Content:** "{invitee_email} accepted your invitation. 100 Civic Points have been added to your total. Your new balance: {total} pts."

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| Invitee email already an active user | Don't send email. Show "Already a member" in form. |
| Invitee email is soft-deleted user | Treat as new — send invite. If they rejoin, that's a fresh start. |
| Same person invited by multiple people | All invites go through. Points awarded only to the invitor whose token was actually clicked. |
| Invitor invites same email twice | Allow it (new token each time). Previous invitation stays in history. |
| Token used twice | First use creates account. Second use → redirect to login. |

## Nav Integration
- Add "Invite" link after "My TPB" dropdown in nav row 2
- Logged-in only (inside the `$isLoggedIn` block)
- Active state: `$currentPage === 'invite'`

## Files to Create/Modify

| File | Action |
|------|--------|
| `invite/index.php` | CREATE — invitor's page (form + history) |
| `invite/accept.php` | CREATE — acceptance landing page |
| `api/send-invite.php` | CREATE — send invite API |
| `api/check-invite-email.php` | CREATE — email exists check |
| `includes/invite-email.php` | CREATE — HTML email builder function |
| `includes/nav.php` | MODIFY — add Invite nav link |
| `help/index.php` | MODIFY — add invite guide icon (if guide created) |

## Civic Points Wiring
- `referral_joined` (100 pts) → awarded to invitor when invitee joins
- Uses existing `PointLogger::award()` — context_type='referral', context_id=invitation.id
- Nav points badge updates on invitor's next page load
