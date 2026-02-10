# The People's Branch (TPB)

Making democracy visible through continuous conversation.

## What Is This?

TPB is a civic engagement platform that creates continuous democratic dialogue between citizens and their elected officials. Built by citizens, for citizens — on a retirement budget with AI assistance.

## Tech Stack

- PHP 8.4, MySQL/MariaDB, Apache
- No framework — vanilla PHP with shared includes
- Google Maps API for interactive maps
- XAMPP for local development
- InMotion hosting for production

## Project Structure

```
├── api/            API endpoints
├── includes/       Shared components (nav, footer, auth, points)
├── assets/         JS, CSS, frontend files
├── z-states/       State/town pages (e.g., CT/Putnam)
├── poll/           Poll system
├── volunteer/      Volunteer system
├── constitution/   Constitution section
├── 0media/         Media assets (images tracked, audio/video gitignored)
├── docs/           Project documentation
├── scripts/        Reusable scripts (deploy, db, setup, maintenance)
```

## Getting Started

1. Install [XAMPP](https://www.apachefriends.org/) (Apache + MySQL + PHP)
2. Clone the repo into your web root
3. Get `config.php` and `config-claude.php` from an admin (gitignored)
4. Import the database schemas into MySQL
5. Start Apache and MySQL, visit `http://localhost/tpb2/`

## Documentation

- `docs/` folder holds detailed documentation
- The `system_documentation` table in the database is the master documentation registry — role-aware, tagged, and versioned
- `CLAUDE.md` is the AI assistant's entry point

## Links

- **Live site**: https://tpb2.sandgems.net
- **Production server**: InMotion hosting (see CLAUDE.md for details)
