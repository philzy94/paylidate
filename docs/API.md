# API reference

Base path: `/api`. All bodies are JSON. Authenticated routes need
`Authorization: Bearer <token>` (obtained from `POST /api/login` or `/api/signup`).
Errors are JSON: `{ "status": false, "message": "...", "code": "machine_code" }`
with a matching HTTP status (401 unauthenticated, 403 forbidden, 404 not found,
409 conflict, 422 validation/provider failure, 429 rate limited).

Legend: **public** = no token, **auth** = bearer token, **admin** = token of an
admin user (`ADMIN_EMAILS` / `is_admin`).

## Account

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| POST | `/signup` | public | Register (or claim a placeholder account) |
| POST | `/signup/referral/{ref}` | public | Register through a referral link |
| GET | `/signup/activate/{token}` | public | Activate an account |
| POST | `/login` | public, 10/min | Log in, returns a token |
| GET | `/logout` | auth | Revoke the current token |
| GET | `/user` | auth | Current user |
| POST | `/user/update` | auth | Update profile (whitelisted fields) |
| GET | `/check/email/{email}` | public | Does an account exist for this email |
| POST | `/verifyEmail/{token}` | auth | Verify email |
| POST | `/resendVerificationEmail/{email}` | auth | Resend verification |
| GET | `/verify-number/{phone_number}` | auth | Phone verification |
| POST | `/password/create` | public, 5/min | Request a reset link |
| GET | `/password/find/{token}` | public | Validate a reset token |
| POST | `/password/reset` | public, 10/min | Set a new password |
| POST | `/rate-user/{id}`, GET `/get-user-rate/{id}`, POST `/get-rate` | auth/public | Ratings |

## Escrow transactions

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET/POST | `/transaction` | auth | List / create (buyer or seller) |
| GET | `/transaction/{T_ref}` | public | Transaction details by reference |
| POST | `/transaction/accept/{id}`, `/decline/{id}` | auth | Seller response |
| POST | `/transaction/confirm/{id}` | auth | Buyer confirms delivery, releasing funds |
| POST | `/transaction/report-transaction/{id}` | auth | Buyer reports a problem (24h window) |
| POST | `/transaction/reslove-report/{id}/{sellerEmail}` | auth | Resolve a report |
| POST | `/transaction/cancel/{id}` | auth | Cancel |
| GET/POST | `/fulfillment/{hash}` | public, 5/min | Seller fulfilment link |

## Payments, wallet and payouts

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| POST | `/make-payment` | auth | Record a payment, verified with Flutterwave; buyer only |
| POST | `/payment/card`, `/payment/validate`, `/payment/verify` | auth | Card payment flow |
| GET/PUT/DELETE | `/payment`, `/payment/{payment}` | auth | Own payments |
| POST | `/create-wallet`, `/fund`, `/credit-wallet`, `/debit-wallet` | auth | Wallet operations |
| GET | `/get-wallet`, `/get-wallet-history` | auth | Balance and history |
| POST | `/request-withdrawal`, `/request-refund`, `/request-referral-bonus-withdrawal` | auth | Payout requests |
| GET | `/withdraw-requests`, `/refund-requests`, `/referral-withdraw-requests` | auth | Own requests |
| POST | `/transfer-to-bank`, `/transfer-to-buyer-bank`, `/transfer-referral-bonus-to-bank` | admin | Execute a payout |
| POST | `/instant-pay/tranfer`, `/instant-pay/withdraw`, `/instant-pay/verify` | auth | Instant pay (verify is rate limited) |
| GET | `/instant-pay/history-send`, `/history-receive` | auth | Instant pay history |
| GET/POST/PUT | `/user-bank`, `/card` | auth | Saved bank accounts and cards |
| GET | `/get-banks` | public | Supported banks |

## Products, disputes, referrals

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET/POST/PUT/DELETE | `/product`, `/product/{product}` | auth | Own products |
| GET | `/product/{slug}`, `/catalog/{id}` | public | Public product pages |
| POST/DELETE | `/product/{id}/recommend` | auth | Recommend a product |
| POST | `/product/status/{id}`, `/product/status/delivered/{id}` | auth | Product status |
| POST | `/open-dispute`, `/resolve-dispute`, `/send-dispute-chat` | auth | Dispute workflow |
| GET | `/dispute-chat/{dispute_id}`, `/transaction-disputes/{transaction_id}` | auth | Dispute data |
| GET | `/referral`, `/referral-bonus`, `/dashboard` | auth | Referral and dashboard data |

## Admin

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/admin/dashboard` | admin | Platform totals |
| GET | `/admin/users`, `/admin/users/{startDate}/{endDate}` | admin | User listings |

## Partner health check

`GET /api/v1/ping` is guarded by the `third_party_Api` middleware, which accepts a
bearer token that matches a stored `Apikey`. It returns `pong` and lets partners
verify their key.

The route table in `routes/api.php` is the source of truth; run
`php artisan route:list --path=api` for the live list.
