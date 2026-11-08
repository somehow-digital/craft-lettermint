# AGENTS.md

Lettermint mailer adapter plugin for Craft CMS 5 (PHP 8.2+).

## Code

- Design for real Craft behavior. Adapt tests to the architecture, not the other way around.
- Respect the architecture rules in `deptrac.yaml` and `tests/PHPStan`.
- Keep the public API small. Classes not listed in `phpstan.neon` are `@internal`.
- Use code comments sparingly. Comment only what the code can't say, and keep the wording precise and concise.

## Changelog

- Add user-facing changes to `## [Unreleased]` in `CHANGELOG.md`. Mark Pro-only features with `(Pro)`.

## Checks

- Iterate with focused checks, e.g. `composer test -- --filter=…` or `composer phpstan`.
- Run `composer check` before finishing. It covers style, static analysis, architecture, dependencies, tests, 100% coverage and mutation testing.
- Auto-fix with `composer ecs-fix`, `composer rector-fix`, `composer twig-fix` and `composer composer-normalize-fix`.
- Never weaken a check to make it pass: no new ignores, baselines, deptrac exceptions or lowered thresholds. Fix the cause.

## Git

- Commit only when asked.
- Use Conventional Commits, e.g. `refactor: slim the architecture down`.
- Don't add AI attribution such as co-author trailers or generated-by footers.
