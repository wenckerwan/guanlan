# Registration and verification-email protection

The public registration page uses a managed Turnstile widget. Both `POST /api/v1/auth/email/code` and `POST /api/v1/auth/register` validate its token server-side with Cloudflare Siteverify before invoking the registration service. An enabled verifier rejects missing/oversized tokens, unsuccessful responses, mismatched exact hostnames, mismatched `action=register`, network failures and missing configuration. Tokens are reset after every send/register attempt, expiry and widget error. Existing login is unchanged.

## Limits

- Email: 60-second cooldown, five reservations in a rolling hour and ten in a rolling 24 hours.
- Visitor IP: twenty email reservations per rolling hour.
- Site: `MAIL_DAILY_LIMIT` reservations per rolling 24 hours, default 500. This counts attempted sends including failed delivery and already-registered addresses; failed SMTP does not refund quota. The API returns an explicit limit message; no external alert integration is configured.
- Registration IP: `REGISTRATION_IP_DAILY_LIMIT` valid-code registration attempts per 24-hour period beginning with the first reservation, default five. The quota is reserved after consuming a valid code, so invalid guesses cannot exhaust someone else's registration allowance. Failed account creation still consumes that reservation.
- Email code: five-minute lifetime, five wrong guesses maximum, newest code only, and one successful consumption. Codes are stored hashed.

The existing MySQL transactional email/IP locks are retained. A global sender lock serializes the budget check/reservation across different IPs and emails. SMTP happens only after commit. A separate InnoDB registration quota table serializes each registration IP counter. No Redis dependency is added.

## Configuration

Store these in the server's private `.env.production`, never in Git:

```
TURNSTILE_ENABLED=true
TURNSTILE_SITE_KEY=<public site key>
TURNSTILE_SECRET_KEY=<server secret>
BFF_PROXY_SECRET=<random secret of at least 32 characters>
NUXT_TRUSTED_GATEWAY_IPS=<actual guanlan gateway container IP>
NUXT_SESSION_ORIGINS=https://guanlan.wencker.top,https://guanlan.wencker.pro
MAIL_DAILY_LIMIT=500
REGISTRATION_IP_DAILY_LIMIT=5
```

Compose maps the public key to `NUXT_PUBLIC_TURNSTILE_SITE_KEY`, the BFF secret to both services, and the private verifier key to the API only. The allowed verifier hostnames are the exact two Guanlan domains. The CSP permits only `https://challenges.cloudflare.com` in script/frame/connect sources in addition to the original policy.

The BFF accepts `X-Real-IP` only from its configured gateway peer. It signs the address, HTTP method, API path and timestamp using HMAC-SHA256; the API accepts a correctly signed assertion within 60 seconds. This avoids trusting arbitrary inbound XFF or changing BFF container addresses. If the Docker network/gateway is recreated, refresh `NUXT_TRUSTED_GATEWAY_IPS` from `docker inspect guanlan-gateway-1` and recreate the web service. Preserve the outer gateway's official Cloudflare-network trust list and inner gateway's host bridge trust.

## Migration and release

Apply only `migrations/2026_11_17_000001_create_registration_quotas_table.php` using `hyperf.php migrate --path=... --force`. It adds a table and leaves existing data intact. Build the frontend from the lockfile, package API changes using the existing runtime image, and recreate only web/API services. Reload the inner gateway after updating CSP and resolving the recreated web container.

Back up `.env.production`, `compose.release-images.yml` and the gateway configuration before release; retain the previous web/API image IDs. Rollback restores those three files and the previous Compose base configuration, recreates web/API and reloads the gateway. The additive quota table can safely remain during rollback.

## Verification

`npm test` covers session/CSRF/BFF forwarding and widget token expiry/reset/disposal. `php tests/RegistrationProtectionTest.php` covers signed identity, verifier host/action checks and fail-closed behavior. `EmailVerificationConcurrencyTest.php` uses a separately created disposable `_admin_test` database and fake mail transport to verify simultaneous cooldown, hourly/daily/global caps, registration caps, wrong-code counting and one-time consumption. Never run its destructive test resets against the production database.

Preflight the packaged services on loopback-only ports with the test database. Confirm public-key rendering, both allowed origins, missing-token rejection, provider-backed invalid-token rejection, and no sends. A real successful email/registration smoke test requires a user-controlled mailbox and should be performed separately.
