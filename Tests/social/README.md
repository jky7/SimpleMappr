# Social sign-in checks

Build the application image first. This check needs Docker, Python 3, OpenSSL and access to Docker bridge addresses on a Linux host.

```sh
docker build -t simplemappr-runtime:local .
Tests/smoke/check-social-login --image simplemappr-runtime:local
```

The check builds a throwaway network, database, keys and certificate authority, aliases the real Google and GitHub hostnames to local fixtures, and removes everything on exit. Your deployment and `.env` aren't touched. It runs both providers at the root and behind a `/simplemappr` prefix-stripping proxy, checking callbacks, sessions and redirects.

To also click both sign-in buttons, follow the actual provider redirects, open My Maps and sign out in Chromium, supply an image built from `Tests/browser`.

```sh
docker build -t simplemappr-browser-contract:local Tests/browser
Tests/smoke/check-social-login --image simplemappr-runtime:local --browser-image simplemappr-browser-contract:local
```

The browser trusts the fixture certificate; the app still verifies TLS and rejects a wrong hostname. No tokens, traces or screenshots of sign-in are saved. This doesn't test your real provider registration (see [docs/social-login.md](../../docs/social-login.md#5-test-it-for-real)).

Failures report only the provider, stage and error category. Add `--diagnostics-dir /absolute/new-private-directory` to keep raw details (mode 0700). They can contain test credentials, so delete them afterwards.
