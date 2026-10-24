# Architecture

Paylidate is an escrow API: a buyer pays into escrow, the seller delivers, and
the money is released to the seller once the buyer confirms (or a dispute is
resolved). It is a Laravel 12 / PHP 8.2 JSON API with Passport (OAuth2) for
authentication.

## Layers

```
HTTP request
   │  routes/api.php            route + middleware (auth:api, admin, throttle)
   ▼
Controller                      validate input, call one service method, shape JSON
   ▼
Service (app/Services)          all business rules, DB transactions, provider calls
   ▼
Eloquent models (app/*.php)     persistence and relations
   ▼
External providers              Flutterwave (payments, transfers),
                                Twilio (SMS), mail
```

Rules that keep the layers honest:

- Controllers never talk to a provider or contain money logic.
- Services throw typed exceptions (`app/Exceptions`); `AppException` carries an
  HTTP status and a machine-readable `code`, and Laravel renders it as JSON.
- Anything that moves money runs inside a DB transaction with row locks
  (`lockForUpdate`) so concurrent requests cannot double-spend.
- Provider responses are never trusted from the client: payments are verified
  server-side against Flutterwave before they are recorded.

## Services

| Service | Responsibility |
| --- | --- |
| `AuthService`, `PasswordResetService` | Signup, claim of placeholder accounts, login, tokens, profile, verification |
| `TransactionService` | Escrow lifecycle: create, accept/decline, confirm, report, resolve, cancel |
| `PaymentService` | Splitting amounts, verifying payments with Flutterwave, card payments |
| `WalletService` | Wallet credit/debit/top-up, settlement ids, history |
| `PayoutService` | Withdrawal, refund and referral payout requests; admin-only bank transfers |
| `InstantPayService` | Peer-to-peer instant transfers protected by a withdrawal PIN |
| `DisputeService` | Opening disputes and the dispute chat |
| `FulfilmentService` | Seller fulfilment links (hashed, rate limited) |
| `ProductService`, `BankService`, `CardService` | Catalogue, saved bank accounts, saved cards |
| `ReportingService`, `AdminService` | Dashboards and admin listings |
| `FlutterwaveService`, `SmsService` | Thin clients for external providers |

## Transaction lifecycle

`TransactionService` owns the status values:

| Status | Constant | Meaning |
| --- | --- | --- |
| 0 | `STATUS_PENDING` | Created, awaiting payment/delivery/confirmation |
| 1 | `STATUS_COMPLETED` | Buyer confirmed; funds released to the seller |
| 2 | `STATUS_CANCELLED` | Cancelled or declined; funds refunded where paid |
| 3 | `STATUS_REPORTED` | Buyer reported a problem within `REPORT_WINDOW_HOURS` (24) |
| 4 | `STATUS_EXPIRED_REPORT` | The report window passed without resolution |

```
create ─► accept ─► payment verified ─► seller delivers ─► buyer confirms ─► COMPLETED
   │         │                                  │
   │         └─► decline ─► CANCELLED           └─► buyer reports ─► REPORTED ─► resolve ─► COMPLETED / CANCELLED
   └─► cancel ─► CANCELLED
```

## Security model

- **Authentication:** Laravel Passport personal access tokens; the `admin`
  middleware (`EnsureAdmin`) guards back-office routes.
- **Authorization:** every service method checks ownership (buyer/seller) before
  reading or changing a record.
- **Payments:** `PaymentService::recordVerifiedPayment` confirms the charge with
  Flutterwave, checks the payer is the buyer, and locks the row so a payment is
  only recorded once.
- **Webhooks:** compared in constant time (`hash_equals`) against
  `FLW_WEBHOOK_HASH`; settlement ids are unique, so replays are no-ops.
- **Secrets:** only in environment variables (`.env.example` lists them).
  PINs and fulfilment tokens are stored hashed.
- **Abuse limits:** named rate limiters (`api`, `fulfilment`) plus per-route
  throttles on login and password flows.

## Testing

PHPUnit runs against in-memory SQLite with `Http::fake` for providers, so the
suite needs no network or external services. `Tests\Feature` exercises the HTTP
API end to end; `Tests\Unit` covers services, models and exceptions.
Static analysis (PHPStan level 8 via Larastan) and PSR-12 linting run in CI.
