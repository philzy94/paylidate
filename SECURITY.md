# Security Policy

Paylidate handles payments, so security reports are taken seriously.

## Supported versions

Only the latest release receives security fixes.

| Version | Supported |
|---------|-----------|
| 1.1.x   | Yes       |
| < 1.1   | No        |

## Reporting a vulnerability

Please do **not** open a public issue for a security problem.

Report it privately through GitHub's
[private vulnerability reporting](https://github.com/philzy94/paylidate/security/advisories/new)
or by email to holyphilzy@gmail.com. Include:

- a description of the issue and its impact,
- steps or a request that reproduces it,
- the version or commit you tested.

You can expect an acknowledgement within 3 working days and a status update
within 10 working days. Fixes are released with a note in the
[CHANGELOG](CHANGELOG.md), and reporters are credited unless they prefer
otherwise.

## Scope

In scope: the API in this repository, its Docker image and its CI workflows.
Out of scope: third-party services (Flutterwave, Twilio) and issues
that need a compromised server or `.env` file.

## Secrets

Never commit credentials. Configuration comes from environment variables, as
listed in `.env.example`. If a key has been exposed, rotate it with the provider
first and then report it here.
