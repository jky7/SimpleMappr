# Enable Google and Microsoft sign-in

This guide is for the person who runs a SimpleMappr installation. Each enabled provider allows people with a supported account to create their own private SimpleMappr account. Your local owner login remains available.

Start with a working [Docker installation](private-deployment.md#start-and-verify). Keep the existing `.env` and owner credentials. Do not run initial owner setup again.

## Choose the address

Choose one address that people use to reach your installation. Set `SIMPLEMAPPR_URL` to its absolute origin, without a path, query, or fragment. For example, use `https://maps.example.org`.

For a public installation, terminate HTTPS at your reverse proxy and forward requests to the app's localhost port. Keep that port bound to `127.0.0.1` and expose only the proxy. The configured HTTPS origin enables Secure session cookies even when the proxy connects to Apache over HTTP. The application does not trust forwarded headers to choose its sign-in address.

Register these exact callback addresses, replacing the example origin with yours:

| Provider | Callback address |
| --- | --- |
| Google | `https://maps.example.org/auth/google/callback` |
| Microsoft | `https://maps.example.org/auth/microsoft/callback` |

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

## Register Microsoft

Use a Microsoft Entra app registration. The application accepts personal Microsoft accounts and accounts from Microsoft Entra organizations through the `common` authority. Organization policies can still require administrator approval.

1. Open **App registrations** in the Microsoft Entra admin center and select **New registration**.
2. Choose the supported account type that includes both any Microsoft Entra tenant and personal Microsoft accounts.
3. Copy the **Application (client) ID** after registration.
4. Open **Authentication** and add a **Web** platform with your Microsoft callback address. Keep the implicit grant and hybrid flow options disabled. SimpleMappr uses the authorization code flow.
5. Open **Certificates & secrets**, then **Client secrets**. Create a secret and record its expiry date.
6. Copy the secret **Value**, not its secret ID, before leaving the page. Store the value in your password manager.
7. Under **API permissions**, keep the sign-in scopes `openid` and `profile`. Remove an unused default `User.Read` permission if present. SimpleMappr does not call Microsoft Graph or request `offline_access`.

Microsoft documents [app registration and supported account types](https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app), [Web redirect configuration](https://learn.microsoft.com/en-us/entra/identity-platform/how-to-add-redirect-uri), and [client secret creation](https://learn.microsoft.com/en-us/entra/identity-platform/how-to-add-credentials#add-a-client-secret).

## Configure and start the providers

Edit the existing private `.env`. Add the origin and the complete credential pair for each provider you want to enable. These are placeholders, not working credentials:

```dotenv
SIMPLEMAPPR_URL='https://maps.example.org'
SIMPLEMAPPR_GOOGLE_CLIENT_ID='replace-with-google-client-id'
SIMPLEMAPPR_GOOGLE_CLIENT_SECRET='replace-with-google-secret'
SIMPLEMAPPR_MICROSOFT_CLIENT_ID='replace-with-microsoft-client-id'
SIMPLEMAPPR_MICROSOFT_CLIENT_SECRET='replace-with-microsoft-secret'
```

Omit both lines for a provider you do not use, or set both values to empty. A partial pair prevents startup. Keep `.env` mode `0600`, outside Git, screenshots, support messages, and browser recordings. Do not put secrets in a shell command, image, or public configuration file.

Recreate the application with the new environment:

```sh
docker compose up -d --no-build --wait --force-recreate app
docker compose exec app scripts/check-config
```

Use the normal build command first if you have not built the image that includes social sign-in. Open **Sign in** at the configured origin. You should see buttons only for the providers you enabled.

Complete a real sign-in with each provider and confirm that **My Maps** can save and reopen a map. Also confirm that the local owner login still opens the owner's existing maps. Automated tests against a local fake provider verify the application flow and token checks. They do not prove that your real provider registration, credentials, consent, or callback settings work.

The app container needs outbound HTTPS access to the enabled providers for token exchange and signing keys. Sign-in does not need Microsoft Graph or Google data APIs.

## Test on localhost

For development, you can use `SIMPLEMAPPR_URL='http://localhost:8080'` with these registered callbacks:

```text
http://localhost:8080/auth/google/callback
http://localhost:8080/auth/microsoft/callback
```

Open `http://localhost:8080/` in the browser. Do not switch to `127.0.0.1` during the same flow. Plain HTTP is allowed only for the literal loopback hosts `localhost`, `127.0.0.1`, and `[::1]`. Use HTTPS for every other hostname. A network hostname that resolves to your own machine still requires HTTPS.

## Keep accounts separate

People can register by signing in with any enabled provider. Their maps remain private until they explicitly create a sharing link. Social accounts have the ordinary user role and cannot administer citations or access another person's maps.

Google, Microsoft, and the local owner login create separate accounts. Matching email addresses do not link accounts or transfer maps. Use the same provider and account to return to your saved maps. SimpleMappr stores the verified provider identity and display name. It does not store the provider email, access token, or refresh token.

Signing out ends the SimpleMappr session. It does not sign you out of Google or Microsoft in other tabs.

## Rotate or disable a provider

Create a replacement secret in the provider console before the old secret expires. Update its value in `.env`, then recreate the app with the command above. Test a real sign-in before revoking the old secret in the provider console. Rotation ends existing SimpleMappr sessions for that provider without changing map ownership or the local owner password.

To disable a provider, remove or empty both its client ID and client secret, then recreate the app. Its button disappears and its existing sessions expire. Its saved maps remain in the database. Re-enabling the same client and provider identity restores access after a new sign-in. Registering a different OAuth client can produce different account identifiers, so a new client is not a substitute for secret rotation.

## Restore and re-enable sign-in

[Deployment backups](private-deployment.md#back-up-a-deployment) contain provider credentials in the protected `deployment.env`. Backup refuses credentials or a canonical URL that differ from the running app. Protect and transfer the whole bundle as sensitive data.

Restore rewrites an absolute origin to its new localhost target. If the origin changes, restore clears both values for every enabled provider in the restored active configuration and prints a non-secret notice. The original protected bundle retains its credentials and checksums. The restored local owner login remains available for recovery.

To re-enable social sign-in after restore:

1. Choose the restored installation's final origin and register its callback addresses with each provider.
2. Edit the generated deployment directory's `deployment.env`. Set the final `SIMPLEMAPPR_URL` and the intended provider credential pairs.
3. Recreate the app using the same project name, `--env-file`, and both Compose files shown in the [restore instructions](private-deployment.md#restore-to-an-empty-clone).
4. Test each provider and confirm the expected account can reopen its maps.

## Diagnose a failed sign-in

If a button is missing, check that both provider values are present in the app's recreated environment. `scripts/check-config` reports configuration errors without printing credentials. The native `/_runtime` endpoint does not expose provider credentials or prove that sign-in works.

For a redirect mismatch, compare the complete registered callback with the configured origin and callback path. For a rejected or expired flow, restart sign-in from the same browser and hostname. For Microsoft, also check the secret's expiry and the allowed account types.

Keep callback query strings and authorization codes out of proxy access logs. Application errors must not include tokens, secrets, or raw provider responses. Share only a sanitized error description when asking for help.
