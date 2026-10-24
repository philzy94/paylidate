# Paylidate

Paylidate is an escrow service API. Sellers create products, buyers pay through
Flutterwave, and funds are held in a wallet until the buyer confirms
fulfilment. Disputes, refunds, referrals and withdrawals are handled by the
same API.

Built with Laravel 12, Laravel Passport (auth), Flutterwave (payments) and
Sentry (error reporting).

## Requirements

- PHP 8.2+ (with `mbstring`, `pdo`, `curl`, `bcmath`)
- Composer
- MySQL (SQLite in-memory is used for tests)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# fill in DB_*, FLW_PUBLIC_KEY, FLW_SECRET_KEY, TWILIO_* and mail settings in .env
php artisan migrate
php artisan paylidate:install   # Passport keys and personal access client
```

Serve locally with `php artisan serve`. The API is on http://localhost:8000 and all
endpoints live under `/api`.

### Docker

```bash
cp .env.example .env
sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
sed -i.bak "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 16)|" .env   # fill in the provider secrets too
docker compose up --build -d
```

On start the container waits for MySQL, runs the migrations and `php artisan paylidate:install`
(Passport keys and personal access client), so the API is ready on http://localhost:8000
once the logs show Apache running (`docker compose logs -f app`). `./docker/smoke-test.sh` signs up a user and checks the main
access rules against the running stack (CI runs it on every push). Without Docker, run
`php artisan migrate` and `php artisan paylidate:install` yourself.

### Running the tests

The suite uses an in-memory SQLite database and fakes every external HTTP call, so it needs
no `.env`, database server or network:

```bash
composer install
composer test   # runs vendor/bin/phpunit
```

## Architecture

| Path | Responsibility |
| --- | --- |
| `routes/api.php` | Route definitions (first-party API and the third-party API) |
| `app/Http/Controllers/Api` | Thin HTTP layer: validate input, call services, shape the response |
| `app/Services` | Business logic (`AuthService`, `TransactionService`, `PaymentService`, `WalletService`, `FlutterwaveService`, ...) |
| `app/Exceptions` | Typed domain errors rendered as JSON (`AppException` and subclasses) |
| `app/*.php` | Eloquent models |

More detail: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) (layers, transaction lifecycle, security model)
[docs/API.md](docs/API.md) (endpoint reference), the machine-readable
[docs/openapi.json](docs/openapi.json) (OpenAPI 3, regenerate with `php artisan paylidate:openapi`;
a test fails when it drifts from the routes) and [docs/SECRETS.md](docs/SECRETS.md) (rotating keys).

New business logic belongs in a service, not in a controller
(see `TransactionController` + `TransactionService` for the pattern).

## Development

```bash
composer lint      # PSR-12 via phpcs (whole app, routes, tests and config)
composer analyse   # PHPStan level 8 via Larastan
composer complexity # PHPMD limits on method and class complexity
composer test      # PHPUnit (see CONTRIBUTING.md for the workflow)
composer check     # lint + analyse + test, what CI runs
composer coverage  # tests with a coverage report (needs pcov or Xdebug)
```

CI (`.github/workflows/ci.yml`) runs lint, static analysis, a dependency audit, the tests with a minimum-coverage gate (95%), the tests and a Docker build on
every push and pull request. Pushing a `v*` tag runs `release.yml`, which re-runs the checks,
publishes the image to GitHub Container Registry and creates a GitHub release. Dependabot keeps dependencies and workflows
up to date.

## Security

Never commit secrets. Keys live in `.env` only. See [SECURITY.md](SECURITY.md) for how to report
a vulnerability privately.

## License and conduct

Released under the [MIT License](LICENSE). Participation is governed by the
[Code of Conduct](CODE_OF_CONDUCT.md); see [CONTRIBUTING.md](CONTRIBUTING.md) to get involved.
