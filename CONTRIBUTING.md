# Contributing to particle-academy/laravel-fun-lab

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Setup and tests

```bash
composer install
vendor/bin/pest
vendor/bin/pint        # code style
```

Tests are Pest, in three suites defined in `phpunit.xml`: `tests/Unit`,
`tests/Feature` and `tests/ApiGate`. Fixture models live in `tests/Fixtures`.

`tests/Feature/ReadmeExamplesTest.php` exercises the README's Quick Start code
examples, so a change to those examples (or to the API they use) needs that test
updated in step.

## Docs

Longer reference docs live in `docs/` (`usage.md`, `api.md`, `react.md`,
`broadcasting.md`, `configuration.md`, `extending.md`) and are linked from the
README.

## Changelog

Update `CHANGELOG.md` (`[Unreleased]`) in the same commit as the change.
