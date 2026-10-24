# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `TransactionService`, `AuthService` and typed domain exceptions (`AppException`
  and subclasses) so controllers stay thin and errors map to JSON responses.
- `needs_password_reset` column on `users`; flagged accounts can be claimed by signing up.
- Routes for `payment/card`, `payment/validate`, `payment/verify` and `signup/referral/{ref}`.
- Tests for transactions, payments, auth, wallets, Flutterwave and Providus services.
- `CONTRIBUTING.md`, this changelog, Sentry logging channel and per-request error context.
- Providus settings in `config/services.php` (`PROVIDUS_*` environment variables).
- `ADMIN_EMAILS` to configure staff notification recipients.

### Changed
- `ThirdPartyApi` controllers for banks, cards, disputes, payments, refunds, withdrawals and
  similar resources now extend their `Api` counterparts instead of duplicating them.
- Duplicate payments now return HTTP 409; forbidden transaction actions return 403
  (previously 200 and 401); quantity and own-referral errors return 422.
- Validation errors in signup are no longer swallowed into a generic error.
- Wallet credit/debit run in a transaction with row locks.
- Staff notifications are sent to `ADMIN_EMAILS` instead of hardcoded addresses.

### Fixed
- `FulfilmentService` redeclared global functions and failed on its second call.
- `transaction_reff` typo in report emails and a crash on `get_transaction` for guests.
- Referral signup used a collection as a user and was not routed.
- `ThirdPartyApi` withdrawal, refund and referral-withdrawal handlers called
  `transfer_to_bank` with an outdated argument list.
- Raw exceptions were returned to API clients from many `catch` blocks.

### Removed
- Hardcoded Providus password and signature headers, and the comparison against a literal
  bcrypt hash during signup (existing placeholder accounts must be flagged with
  `needs_password_reset = 1`).

## [1.0.0] - 2026-10-24

### Changed
- Upgraded to Laravel 12 and PHP 8.2 with Passport 13, Sentry 4 and PHPUnit 11.
- Removed the vendored `twilio-php` copy in favour of `twilio/sdk`.

### Added
- GitHub Actions CI (lint, audit, tests, Docker build), Dependabot, Dockerfile and docker-compose.
- PSR-12 linting and the first PHPUnit suite.
