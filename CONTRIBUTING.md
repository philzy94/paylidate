# Contributing

Thanks for helping improve Paylidate.

## Getting started

Follow the setup in [README.md](README.md) (`composer install`, copy `.env.example`,
`php artisan key:generate`, `php artisan migrate`), or use `docker compose up --build`.

## Before you open a pull request

```bash
composer lint      # PSR-12 via phpcs
composer analyse   # PHPStan level 8 (Larastan); no baseline, no ignores
composer test      # PHPUnit (SQLite in memory, external HTTP calls are faked)
composer check     # all three
```

All must exit 0, and coverage must stay at or above 90%; CI runs them on every push and pull request.

## Workflow

0. New code needs full types (parameters, returns, array shapes) so PHPStan level 8 stays clean.
1. Branch from `master` (`feature/<short-name>` or `fix/<short-name>`).
2. Keep each commit to one feature or fix, and include the tests that pin the new behaviour.
   Avoid bulk commits that mix formatting, refactors and features.
3. Put business logic in `app/Services`; controllers validate input, call a service and shape the response.
4. Throw an `App\Exceptions\AppException` subclass for expected failures instead of catching
   `\Exception` and returning a generic message.
5. Read secrets through `config()` (backed by `.env`); never commit credentials or API keys.
6. Update [CHANGELOG.md](CHANGELOG.md) under *Unreleased*.
7. Open a pull request describing what changed and how you tested it.

## Reporting security issues

Do not open a public issue; follow [SECURITY.md](SECURITY.md).

Participation is governed by the [Code of Conduct](CODE_OF_CONDUCT.md).
