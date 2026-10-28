# Enable Google and GitHub sign-in

Each provider you enable lets anyone with that kind of account register their own private SimpleMappr account. The local owner login keeps working and is your recovery login.

Start from a working [installation](private-deployment.md#install) and keep its `.env`.

## 1. Choose the address

Pick the one base URL people will use, for example `https://example.org/simplemappr`. A path prefix works behind a proxy that strips it. Use plain path segments: no `.`/`..`, percent escapes or double slashes.

For a public site, terminate HTTPS at the reverse proxy and keep the app's port on `127.0.0.1`. The app trusts the configured HTTPS URL for Secure cookies, not forwarded headers.

Your callback addresses are the base URL plus:

| Provider | Callback |
| --- | --- |
| Google | `/auth/google/callback` |
| GitHub | `/auth/github/callback` |

Use the same hostname throughout sign-in, because a different hostname has a different cookie and the sign-in can't complete.

## 2. Register with Google

See Google's [OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect) and [web server credential](https://developers.google.com/identity/protocols/oauth2/web-server) guides.

1. In Google Cloud Console, create or pick a project.
2. Set up Google Auth Platform branding and audience. Choose *External* to allow outside accounts, and add test users while testing.
3. Create an OAuth client of type **Web application**.
4. Add the Google callback under **Authorized redirect URIs** (a JavaScript origin alone isn't enough).
5. Save the client ID and secret in your password manager.
6. Before opening the site to others, complete Google's publishing requirements.

SimpleMappr asks only for `openid profile`: no email, Drive or offline access.

## 3. Register with GitHub

In **Settings → Developer settings → OAuth Apps → New OAuth App**:

1. **Application name**: `SimpleMappr`.
2. **Homepage URL**: your base URL.
3. **Authorization callback URL**: your GitHub callback.
4. Leave **Enable Device Flow** off.
5. Register, copy the **Client ID**, generate a **Client secret**, and save both.

Keep **Expire user access tokens** on. SimpleMappr requests an empty scope, uses the token once to read the public profile from `https://api.github.com/user`, and stores no token. A token that comes back with any scope is rejected.

## 4. Configure and restart

Add to `.env` only the providers you want (placeholders shown):

```dotenv
SIMPLEMAPPR_URL='https://example.org/simplemappr'
SIMPLEMAPPR_GOOGLE_CLIENT_ID='replace-with-google-client-id'
SIMPLEMAPPR_GOOGLE_CLIENT_SECRET='replace-with-google-secret'
SIMPLEMAPPR_GITHUB_CLIENT_ID='replace-with-github-client-id'
SIMPLEMAPPR_GITHUB_CLIENT_SECRET='replace-with-github-secret'
```

A provider needs both values or neither; half a pair stops startup. Keep `.env` mode 0600, and keep the secrets out of Git, shell history, screenshots and support messages.

```sh
docker compose up -d --no-build --wait --force-recreate app
docker compose exec app scripts/check-config
```

(Build the image first if yours predates social sign-in.)

The app needs outbound HTTPS to Google, `github.com` and `api.github.com`.

## 5. Test it for real

Open **Sign in**. You should see only the providers you enabled. With each one, sign in, save a map in **My Maps** and reopen it. Then check that the [owner login](private-deployment.md#sign-in-as-the-owner) still opens the owner's maps.

The automated tests use fake providers, so only this manual check proves your real registration and credentials work.

## Testing on localhost

Use `SIMPLEMAPPR_URL='http://localhost:8080'` and register `http://localhost:8080/auth/google/callback` and `http://localhost:8080/auth/github/callback`. Stay on `localhost` and don't switch to `127.0.0.1` mid-flow. Plain HTTP works only for `localhost`, `127.0.0.1` and `[::1]`; every other hostname needs HTTPS.

## How accounts work

- Each provider and the owner login create **separate** accounts. Matching emails never link them. People must return with the same provider and account to see their maps.
- GitHub accounts are keyed by numeric ID, so renaming the GitHub account keeps the maps.
- SimpleMappr stores the verified identity and display name only: no email and no tokens.
- Social accounts are ordinary users. They can't administer citations or see other people's maps, and maps stay private until shared.
- Signing out of SimpleMappr doesn't sign you out of Google or GitHub.

## Rotate or disable a provider

**Rotate**: create a new secret in the provider console, put it in `.env`, recreate the app, test a sign-in, then revoke the old secret. That provider's sessions end, but maps are unaffected.

**Disable**: remove or empty both values and recreate the app. The button disappears and sessions end. The maps remain and come back when the same client is re-enabled.

## Restore and re-enable sign-in

Backups include provider credentials, so protect the whole bundle. If a [restore](backup-restore.md#restore-to-a-new-project) changes the base URL, it clears every provider's credentials in the restored copy (the bundle keeps them) and prints a notice. The owner login still works.

To turn sign-in back on:

1. Register the restored site's callbacks with each provider.
2. In `PROJECT-deployment/deployment.env`, set the final `SIMPLEMAPPR_URL` and the credential pairs.
3. Recreate the app with the project name, `--env-file` and both Compose files from the [restore guide](backup-restore.md#restore-to-a-new-project).
4. Test each provider.

## Troubleshooting

- **Button missing**: both values aren't in the app's environment. Run `scripts/check-config`.
- **Redirect mismatch**: compare the registered callback with your base URL character for character.
- **Rejected or expired flow**: start again in the same browser on the same hostname.
- **GitHub token rejected**: an older grant has scopes. Revoke it on GitHub and try again.

Keep callback query strings out of proxy logs, and share only sanitized errors when asking for help.
