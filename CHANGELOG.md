# Changelog

## [Unreleased]

### Added

- Pro edition.
- Route slug per site (Pro).
- Tags setting.
- Tags per site (Pro).
- Craft metadata on every email: the system message key, the site, the language, the user it is sent to and the environment (Pro).
- Team API token setting (Pro).
- Project setting (Pro).
- Webhook endpoint, which verifies the deliveries of a Lettermint webhook and fires their events (Pro).
- Health endpoint, which reports the status of the API tokens, the usage of the plan and the checks of the settings against the project as JSON for monitoring, protected by a health token (Pro).
- `lettermint/health` command, which runs the checks of the health endpoint from the command line, e.g. during a deployment (Pro).
- Lettermint utility with the team, usage, statistics, projects (with their sandbox mode, SMTP, hidden email content, routes and tracking) and domains of the team API token, the status of the API tokens, and a health card with the checks of the settings against the project, its overall status and a link to the health endpoint for admins, and a table of the email settings, the route and the tags of all sites and of each site, without tokens and secrets (Pro).
- Lettermint dashboard widget with the usage and statistics of the utility (Pro).
- The utility and the widget load their data in the background, so their pages never wait for Lettermint. The data is fresh for 5 minutes, older data is shown while it is reloaded, and a placeholder in the same layout is shown before anything is loaded, with a refresh button to load it again (Pro).

### Changed

- The `Plugin` and `Adapter` classes are final, so they can no longer be extended.

### Fixed

- Emails are sent through the HTTP proxy of Craft (`httpProxy`).

## [2.0.0] - 2026-10-25

### Added

- Test suite, coding standard and static analysis checks.

### Changed

- Use the official [`lettermint/symfony-mailer`](https://github.com/lettermint/symfony-mailer) transport instead of `somehow-digital/symfony-lettermint-mailer`.
- Update the plugin icon and add an icon mask.

## [1.0.1] - 2025-12-24

### Added

- License file.

## [1.0.0] - 2025-12-11

### Added

- Initial release.

[Unreleased]: https://github.com/somehow-digital/craft-lettermint/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/somehow-digital/craft-lettermint/compare/v1.0.1...v2.0.0
[1.0.1]: https://github.com/somehow-digital/craft-lettermint/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/somehow-digital/craft-lettermint/releases/tag/v1.0.0
