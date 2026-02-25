# The People's Branch (TPB)

Making democracy visible through continuous conversation.

## What Is This?

TPB is a civic engagement platform that creates continuous democratic dialogue between citizens and their elected officials. Built by citizens, for citizens — on a retirement budget with AI assistance.

## What's Here

- **Executive Threat Tracker** — 140+ documented executive threats scored on a 1–1,000 criminality scale. Per-official cards with severity zones, call scripts, and contact info.
- **Polls** — Citizens vote "Is this acceptable?" on every threat scoring 300+. Congress members go on record with "Will you act?" Four views: Vote, National results, By State comparison, By Rep roll call with silence rates and gap analysis.
- **Congressional Digest** — 119th Congress scorecard: reps, votes, bills, committees, nominations. Filterable by state/party/chamber.
- **Talk** — Group deliberation with AI-assisted gather/crystallize pipeline. Geographic streams (USA/state/town) + civic groups.
- **Interactive USA Map** — Multi-mode map: National delegations, Election, Bills, Orders, Courts.
- **Civic Points** — Gamified engagement: 55 tracked actions from page visits to voting to volunteering.
- **Volunteer System** — Task board with claim/expand/complete workflow, 15 skill categories, PM approval.
- **State/Town Pages** — Local civic landing pages with benefits, representatives, and community info.
- **AI Civic Guide (Claudia)** — Voice/text widget with canned + live AI responses via clerk system.
- **Founding Documents** — Constitution, Declaration, Gettysburg Address, Federalist Papers, Letter from Birmingham Jail, Oath of Office.

## Tech Stack

- PHP 8.4, MySQL/MariaDB, Apache
- No framework — vanilla PHP with shared includes
- Google Maps API for interactive maps
- Anthropic Claude API for AI features
- OpenStates API for legislative data
- XAMPP for local development
- InMotion hosting for staging + production

## Project Structure

```
├── usa/            USA section (executive, congressional, judicial, map, docs)
├── poll/           Poll system (vote, national, by-state, by-rep)
├── talk/           Talk deliberation system
├── api/            API endpoints
├── includes/       Shared components (nav, footer, auth, points, severity)
├── assets/         JS, CSS, frontend files
├── z-states/       State/town pages (e.g., CT/Putnam)
├── volunteer/      Volunteer system
├── 0t/             People Power (grassroots referral)
├── 0media/         Media assets (images tracked, audio/video gitignored)
├── docs/           Project documentation
├── scripts/        Reusable scripts (deploy, db, setup, maintenance)
```

## Getting Started

See [docs/dev-setup-guide.md](docs/dev-setup-guide.md) for full setup instructions.

1. Install [XAMPP](https://www.apachefriends.org/) (Apache + MySQL + PHP 8.4)
2. Clone the repo: `git clone https://github.com/45Harley/tpb2.git c:\tpb2`
3. Get `config.php` and `config-claude.php` from an admin (gitignored)
4. Import the database from staging (see setup guide)
5. Start Apache and MySQL, visit `http://localhost/`

## Documentation

- [docs/platform-overview.md](docs/platform-overview.md) — Full feature overview
- [docs/README.md](docs/README.md) — Documentation index
- [docs/dev-setup-guide.md](docs/dev-setup-guide.md) — Local dev environment setup
- [docs/admin-guide.md](docs/admin-guide.md) — Admin dashboard guide
- `system_documentation` table in the database — master documentation registry (role-aware, tagged)

## Links

- **Staging**: https://tpb2.sandgems.net
- **Production**: https://4tpb.org
