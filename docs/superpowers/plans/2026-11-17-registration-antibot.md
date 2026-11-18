# Registration anti-abuse implementation plan

User approved the three-layer protection proposal and requested notice of Guanlan code changes. Changes are developed on an isolated branch from deployed f8b9263; preserve the glass UI and existing unrelated working-tree changes.

1. Test then implement authenticated visitor-IP forwarding: only the configured Nginx gateway may supply X-Real-IP to the BFF; BFF signs IP/method/path/timestamp, API verifies the signature with a shared secret and a 60-second window. Keep CSRF checks and HttpOnly sessions intact.
2. Test then implement API-side Turnstile validation for code sending and registration. Check success, exact permitted hostname, action=register; reject missing tokens, provider errors and configuration errors when enabled. Secret stays API-side; site key is public.
3. Preserve transactional MySQL sender locks and single-use five-attempt codes. Add rolling 24-hour email limit 10, hourly IP limit 20, configurable global daily cap 500, and a transactional registration IP quota of five per rolling 24-hour bucket. Code lifetime becomes five minutes. No SMTP in retried transactions.
4. Add a managed Turnstile component to the registration form, reset after every consumed submission, and reset on expiry/error; permit challenges.cloudflare.com in script/frame/connect CSP only. Configure exact allowed origins for both domain entry points.
5. Run Node behavior/integration suite, standalone PHP verification tests, real MySQL concurrent quota tests on a new disposable database with fake SMTP; build the production frontend and package API changes. Review the diff and prepare rollback before release. Verify both public domains, absent/invalid token rejection, unchanged login/session behavior, and no email sent during tests.

No production activation until real site/secret keys and gateway trust are configured consistently. Never log secrets or tokens.
