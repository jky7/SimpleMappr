# Social sign-in checks

Build the application image first. This check needs Docker, Python 3, OpenSSL and access to Docker bridge addresses on a Linux host.

```sh
docker build -t simplemappr-runtime:local .
Tests/smoke/check-social-login --image simplemappr-runtime:local
```

The check creates its own internal Docker network, database, map assets, sessions, signing keys and certificate authority. It uses the fixed Google and Microsoft HTTPS hostnames through private Docker aliases. A temporary localhost forwarder gives the HTTP cookie client access to the application. The operator's deployment and `.env` are untouched. Created containers, volumes, keys and credentials are removed on exit.

To also click both sign-in buttons, follow the actual provider redirects, open My Maps and sign out in Chromium, supply an image built from `Tests/browser`.

```sh
docker build -t simplemappr-browser-contract:local Tests/browser
Tests/smoke/check-social-login --image simplemappr-runtime:local --browser-image simplemappr-browser-contract:local
```

The browser accepts the disposable fixture certificate and maps provider hostnames to the fixture. The application verifies TLS normally and separately rejects a certificate with an incorrect hostname. Authentication traces, screenshots, tokens and profiles are not saved in receipts. Real Google and Microsoft app registration still needs a deployment check with the operator's credentials.

Browser failures report only the provider, check stage and error category. For diagnosis, `--diagnostics-dir /absolute/new-private-directory` retains raw browser failure details in that new mode 0700 directory. Those files can contain disposable authentication values. Keep them outside receipts and delete them after diagnosis.
