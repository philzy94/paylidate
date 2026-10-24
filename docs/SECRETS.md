# Managing and rotating secrets

No credential is stored in this repository. Everything secret is read from
environment variables (see `.env.example`), and CI runs
[gitleaks](https://github.com/gitleaks/gitleaks) on every push.

## What counts as a secret

| Variable | Used for | Rotate by |
| --- | --- | --- |
| `APP_KEY` | Encrypting cookies and stored values | `php artisan key:generate`; existing sessions and encrypted values stop working |
| `FLW_SECRET_KEY`, `FLW_PUBLIC_KEY` | Flutterwave API | Flutterwave dashboard, Settings, API keys, then redeploy |
| `FLW_WEBHOOK_HASH` | Verifying Flutterwave webhooks | Change it in the dashboard and in `.env` together |
| `TWILIO_AUTH_TOKEN` | SMS | Twilio console |
| `DB_PASSWORD` | Database | Change it in MySQL and in `.env`, then restart |
| `SEED_ADMIN_PASSWORD` | First admin account | Change the password through the app after the first login |
| Passport keys (`storage/oauth-*.key`) | Signing access tokens | `php artisan passport:keys --force`; every issued token is invalidated |

## Rotating a key that was exposed

1. Create the new value with the provider (or generate it) **before** removing the old one.
2. Put the new value in the server environment and restart the app.
3. Revoke the old value with the provider. Treat it as public from the moment it was committed.
4. If it was in git history, rotation is what protects you; rewriting history only hides it
   from new clones. Rewrite only if you also need the value gone from forks and caches:
   `git filter-repo --replace-text expressions.txt`, then force-push and ask collaborators to re-clone.
5. Note the rotation (date, not the value) in the deployment log.

## Local development

Copy `.env.example` to `.env` and fill it in. `.env` is git-ignored and must never be committed.
Tests need no secrets: `tests/bootstrap.php` generates a throwaway `APP_KEY` for each run and
every provider call is faked.
