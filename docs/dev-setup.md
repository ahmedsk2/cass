# Development machine setup (Windows)

1. **PHP 8.4** with `gd`, `intl` and `sockets` loaded. Windows PHP ships `php_sockets.dll` but leaves `extension=sockets` commented out in `php.ini` (`php --ini` finds the file); without it `composer install` fails its platform check, because `pestphp/pest-plugin-browser` requires it. Check: `php -m | grep -E "^(gd|intl|sockets)$"` prints three lines.
2. **Composer 2.** Call it as `composer` (sh wrapper) or `php <path>/composer.phar`, never through `composer.bat`: cmd.exe silently strips `^` from version constraints.
3. **PATH.** Keep PHP and Composer outside `AppData` (the Claude desktop app sandboxes what a session writes there) and put both folders on the user PATH once, in PowerShell:
   `$t = '<tools folder>'; [Environment]::SetEnvironmentVariable('Path', [Environment]::GetEnvironmentVariable('Path','User') + ";$t\php84;$t\composer", 'User')`
   then open a new terminal and restart the Claude app. `php -v` and `composer -V` must work from Git Bash.
4. **Node 24**, **Docker Desktop** (for the MySQL suite and Mailpit: `docker compose -f docker-compose.dev.yml up -d`), **`gh`** signed in, Git Bash.
5. **Git hooks**, once per clone: `git config core.hooksPath tools/git-hooks`. The pre-push hook refuses direct pushes to `main`.
6. **Git-ignored files**: restore `.env`, `database/database.sqlite`, `Legacy/`, `legacy-review.md` and `assets/envato/` from the owner's private bundle (`docs/HANDOVER.md`, "Files that are not in this repository").
7. **`CLAUDE.local.md`** at the repository root, git-ignored, from this template:

```markdown
# CASS: this machine (git-ignored, never commit)

Windows 11. Repository at <repo path>. Tools folder <tools path>, outside AppData (the desktop app sandboxes writes under AppData). Checked <date>.

- PHP 8.4.x (gd, intl, sockets on) and Composer 2.x are on the user PATH: `php -v` and `composer -V` work from Git Bash. If a session cannot see them: `export PATH="<tools>/php84:<tools>/composer:$PATH"`.
- Node 24 with npm <version>. Docker Desktop <version>; CLI path if it is not on PATH.
- Python <version>; PyMuPDF, Pillow and pypdf are installed on demand for the icon and PDF helpers.
- Mailpit, `gh` signed in as the owner, git identity set per repo, SSH key for the host in `~/.ssh/` (the host address lives in the project memory, never in a file).

Session quirks: <anything a session on this machine must know>.
```

Then build and prove it, each exit code captured: `composer install`, `npm ci`, `npm run build`, `php artisan test`, `./vendor/bin/phpstan analyse`, `./vendor/bin/pint --test`.
