# Enable Google and GitHub sign-in

This guide is for the person who runs a SimpleMappr installation. Each enabled provider allows people with a supported account to create their own private SimpleMappr account. Your local owner login remains available.

Start with a working [Docker installation](private-deployment.md#start-and-verify). Keep the existing `.env` and owner credentials. Do not run initial owner setup again.

## Choose the address

Choose one address that people use to reach your installation. Set `SIMPLEMAPPR_URL` to its absolute base URL, without a query or fragment. For this hosted installation, use `https://jky.io/simplemappr`. A path prefix is supported behind a proxy that strips the prefix before forwarding to Apache. Use plain path segments and omit `.` or `..` segments, percent escapes, and repeated slashes.

For a public installation, terminate HTTPS at your reverse proxy and forward requests to the app's localhost port. Keep that port bound to `127.0.0.1` and expose only the proxy. The configured HTTPS origin enables Secure session cookies even when the proxy connects to Apache over HTTP. The application does not trust forwarded headers to choose its sign-in address.

Register these exact callback addresses, replacing the example base URL with yours:

| Provider | Callback address |
| --- | --- |
| Google | `https://jky.io/simplemappr/auth/google/callback` |
| GitHub | `https://jky.io/simplemappr/auth/github/callback` |

Use the same hostname in your browser throughout sign-in. A different hostname has a different session cookie and cannot complete the pending sign-in.

## Register Google

Follow Google's [OpenID Connect setup](https://developers.google.com/identity/openid-connect/openid-connect) and [web application credential instructions](https://developers.google.com/identity/protocols/oauth2/web-server).

1. Create or select your project in Google Cloud Console.
2. Configure the Google Auth Platform branding and audience. Choose an external audience to allow accounts outside your organization. Add test users while the app is in testing.
3. Create an OAuth client with the **Web application** type.
4. Add your Google callback under **Authorized redirect URIs**. This is a server callback, so a JavaScript origin entry alone is insufficient.
5. Save the client ID and client secret in your password manager. Use them in the configuration below.
6. Before opening the installation to other people, complete Google's publishing and consent requirements for your chosen audience.

SimpleMappr requests `openid profile`. It does not request Google Drive access, offline access, or email access.

## Register GitHub

Create a GitHub **OAuth App** in **Settings**, **Developer settings**, **OAuth Apps**, then **New OAuth App**.

1. Set **Application name** to `SimpleMappr`.
2. Set **Homepage URL** to `https://jky.io/simplemappr` or your deployment's base URL.
3. Set **Authorization callback URL** to `https://jky.io/simplemappr/auth/github/callback` or the matching callback for your deployment.
4. Leave **Enable Device Flow** off. SimpleMappr uses the browser authorization code flow with PKCE S256.
5. Register the application. Copy its **Client ID**, then generate a **Client secret** and save it in your password manager.

SimpleMappr sends an explicit empty scope. The exchanged token must also have an empty scope. The app calls only the authenticated `https://api.github.com/user` endpoint for the public profile and numeric account ID. It requests no repository, organization, private profile, or email permissions. Google retains `openid profile`.

GitHub documents [OAuth App registration](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app), [the authorization code flow and PKCE](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps), and [the authenticated public profile](https://docs.github.com/en/rest/users/users#get-the-authenticated-user). Keep **Expire user access tokens** enabled. SimpleMappr uses the token only during that callback and stores no access or refresh token.

## Configure and start the providers

Edit the existing private `.env`. Add the origin and the complete credential pair for each provider you want to enable. These are placeholders, not working credentials:

```dotenv
SIMPLEMAPPR_URL='https://jky.io/simplemappr'
SIMPLEMAPPR_GOOGLE_CLIENT_ID='replace-with-google-client-id'
SIMPLEMAPPR_GOOGLE_CLIENT_SECRET='replace-with-google-secret'
SIMPLEMAPPR_GITHUB_CLIENT_ID='replace-with-github-client-id'
SIMPLEMAPPR_GITHUB_CLIENT_SECRET='replace-with-github-secret'
```

Omit both lines for a provider you do not use, or set both values to empty. A partial pair prevents startup. Keep `.env` mode `0600`, outside Git, screenshots, support messages, and browser recordings. Do not put secrets in a shell command, image, or public configuration file.

Recreate the application with the new environment:

```sh
docker compose up -d --no-build --wait --force-recreate app
docker compose exec app scripts/check-config
```

Use the normal build command first if you have not built the image that includes social sign-in. Open **Sign in** at the configured base URL. You should see buttons only for the providers you enabled.

Complete a real sign-in with each provider and confirm that **My Maps** can save and reopen a map. Also confirm that the local owner login still opens the owner's existing maps. Automated tests against a local fake provider verify the application flow and token checks. They do not prove that your real provider registration, credentials, consent, or callback settings work.

The app container needs outbound HTTPS access to Google for token exchange and signing keys, and to `github.com` and `api.github.com` for the GitHub exchange and authenticated profile. It does not call repository APIs or Google data APIs.

## Test on localhost

For development, you can use `SIMPLEMAPPR_URL='http://localhost:8080'` with these registered callbacks:

```text
http://localhost:8080/auth/google/callback
http://localhost:8080/auth/github/callback
```

Open `http://localhost:8080/` in the browser. Do not switch to `127.0.0.1` during the same flow. Plain HTTP is allowed only for the literal loopback hosts `localhost`, `127.0.0.1`, and `[::1]`. Use HTTPS for every other hostname. A network hostname that resolves to your own machine still requires HTTPS.

## Keep accounts separate

People can register by signing in with any enabled provider. Their maps remain private until they explicitly create a sharing link. Social accounts have the ordinary user role and cannot administer citations or access another person's maps.

Google, GitHub, and the local owner login create separate accounts. Matching email addresses do not link accounts or transfer maps. Use the same provider and account to return to your saved maps. SimpleMappr stores the verified provider identity and display name. GitHub ownership follows the stable numeric account ID, so renaming an account does not transfer or lose maps. It does not store the provider email, access token, or refresh token.

Signing out ends the SimpleMappr session. It does not sign you out of Google or GitHub in other tabs.

## Rotate or disable a provider

Create a replacement secret in the provider console before the old secret expires. Update its value in `.env`, then recreate the app with the command above. Test a real sign-in before revoking the old secret in the provider console. Rotation ends existing SimpleMappr sessions for that provider without changing map ownership or the local owner password.

To disable a provider, remove or empty both its client ID and client secret, then recreate the app. Its button disappears and its existing sessions expire. Its saved maps remain in the database. Re-enabling the same client and provider identity restores access after a new sign-in. Rotate the existing app secret and return with the same provider account to reopen your maps.

## Restore and re-enable sign-in

[Deployment backups](private-deployment.md#back-up-a-deployment) contain provider credentials in the protected `deployment.env`. Backup refuses credentials or a canonical URL that differ from the running app. Protect and transfer the whole bundle as sensitive data.

Restore rewrites an absolute base URL, including any path prefix, to its new localhost target. If the base URL changes, restore clears both values for every enabled provider in the restored active configuration and prints a non-secret notice. The original protected bundle retains its credentials and checksums. The restored local owner login remains available for recovery.

To re-enable social sign-in after restore:

1. Choose the restored installation's final base URL and register its callback addresses with each provider.
2. Edit the generated deployment directory's `deployment.env`. Set the final `SIMPLEMAPPR_URL` and the intended provider credential pairs.
3. Recreate the app using the same project name, `--env-file`, and both Compose files shown in the [restore instructions](private-deployment.md#restore-to-an-empty-clone).
4. Test each provider and confirm the expected account can reopen its maps.

## Diagnose a failed sign-in

If a button is missing, check that both provider values are present in the app's recreated environment. `scripts/check-config` reports configuration errors without printing credentials. The native `/_runtime` endpoint does not expose provider credentials or prove that sign-in works.

For a redirect mismatch, compare the complete registered callback with the configured base URL and callback path. For a rejected or expired flow, restart sign-in from the same browser and hostname. For GitHub, also check the OAuth App's callback and current client secret. A token with repository or other scopes is rejected; revoke an older broader grant before trying again.

Keep callback query strings and authorization codes out of proxy access logs. Application errors must not include tokens, secrets, or raw provider responses. Share only a sanitized error description when asking for help.
