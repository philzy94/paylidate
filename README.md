# Paylidate

Paylidate is an escrow service API. Sellers create products, buyers pay through
Flutterwave, and funds are held in a wallet until the buyer confirms
fulfilment. Disputes, refunds, referrals and withdrawals are handled by the
same API.

Built with Laravel 7, Laravel Passport (auth), Flutterwave (payments) and
Sentry (error reporting).

## Requirements

- PHP 7.4+ (with `mbstring`, `pdo`, `curl`, `bcmath`)
- Composer
- MySQL (SQLite in-memory is used for tests)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# fill in DB_*, FLW_PUBLIC_KEY, FLW_SECRET_KEY, TWILIO_* and mail settings in .env
php artisan migrate
php artisan passport:install
```

Serve locally with `php artisan serve`. All endpoints live under `/api`.
Generated API docs: `php artisan apidoc:generate`.

## Architecture

| Path | Responsibility |
| --- | --- |
| `routes/api.php` | Route definitions (first-party API and the third-party API) |
| `app/Http/Controllers/Api` | Thin HTTP layer: validate input, call services, shape the response |
| `app/Http/Controllers/ThirdPartyApi` | Same API authenticated by API key for integrators |
| `app/Services` | Business logic (`PaymentService`, `WalletService`, `RefundService`, `FlutterwaveService`, ...) |
| `app/*.php` | Eloquent models |

New business logic belongs in a service, not in a controller
(see `PaymentController` + `PaymentService` for the pattern).

## Development

```bash
composer lint   # PSR-12 via phpcs (phpcs.xml lists the files that are enforced)
composer test   # PHPUnit
```

CI (`.github/workflows/ci.yml`) runs lint, a dependency audit and the tests on
every push and pull request. Dependabot keeps dependencies and workflows
up to date.

## Security

Never commit secrets. Keys live in `.env` only. Report vulnerabilities
privately to the maintainers.
