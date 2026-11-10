# Changelog

## [Unreleased]

### Added

- Emails can be sent through Lettermint's SMTP server instead of its API, including the route and tags, on one of four ports.
- Emails can be labeled with tags.
- Plugin now comes in a Pro edition.
- Pro: Each site can get its own route slug.
- Pro: Each site can get its own tags.
- Pro: Every email is enriched with Craft metadata: the system message key, site, language, recipient and environment.
- Pro: A team API token can be configured to unlock account-level insights.
- Pro: A project can be configured to match the settings against.
- Pro: Lettermint webhooks are received at a dedicated endpoint, which verifies each delivery and fires its event.
- Pro: Monitoring tools can poll a health endpoint for the API token status, plan usage and settings checks as JSON, protected by a health token.
- Pro: A new `lettermint/health` command runs the same checks from the command line, e.g. during a deployment.
- Pro: A health check reports whether SMTP is enabled for the project when emails are sent through SMTP.
- Pro: A new Lettermint utility gives an overview of the team, usage, statistics, projects and domains behind the team API token.
- Pro: The utility also lists the API token status, a health card for admins and the transport, email settings, route and tags of every site, minus tokens and secrets.
- Pro: A new dashboard widget puts the usage and statistics of the utility on the front page of the control panel.
- Pro: The utility and the widget fetch their data in the background, so pages never wait for Lettermint.
- A documentation site with guides and references at https://craft-lettermint.somehow-digital.dev.

### Changed

- Symfony Mailer 7.3 or later is now required.
- `Plugin` and `Adapter` classes are now final and can no longer be extended.

### Fixed

- Emails are now sent through the HTTP proxy of Craft (`httpProxy`).

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
