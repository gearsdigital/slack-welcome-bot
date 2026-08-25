# Slack Welcome Bot (WordPress Plugin)

Automatically sends every new Slack workspace member a direct message with your team rules. The rules text is read straight from a WordPress page, so editing that page instantly changes what the next welcome DM says.

## Why a WordPress plugin?

- Runs inside your existing WordPress install — no subdomain, no separate server, no manual file uploads.
- The rules text is read directly from the WordPress database, so there's no network request or caching logic involved.
- Credentials are stored securely in the WordPress options table, not in a file inside the web root.

## Installation

1. Zip up the `slack-welcome-bot` folder (if you haven't already).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → select the ZIP → **Install Now** → **Activate**.

   *Or via FTP:* upload the `slack-welcome-bot` folder to `wp-content/plugins/` and activate it under **Plugins**.

## Setup

### 1. Create a rules page

Create a regular WordPress page (**Pages → Add New**) with your rules text, e.g. "Slack Rules". The page must be **published or private** (not a draft).

Bold, italics, lists, links, and headings are all supported and are converted to the matching Slack format automatically.

**Non-public pages:** both pages with "Private" visibility and password-protected pages can be selected as the rules page — the welcome DM reads the content directly from the WordPress database, regardless of the password or visibility setting. When such a page is selected, the settings page shows a warning about this.

### 2. Create a Slack app

1. Go to https://api.slack.com/apps → **Create New App** → **From scratch**.
2. Under **OAuth & Permissions → Bot Token Scopes**, add:
   - `chat:write`
   - `im:write`
   - `users:read`
3. Install the app to your workspace, then copy the **Bot User OAuth Token** (`xoxb-...`).
4. Copy the **Signing Secret** from **Basic Information → App Credentials**.

### 3. Configure the plugin

WordPress admin → **Settings → Slack Welcome Bot**:

- Enter the **Bot User OAuth Token**
- Enter the **Signing Secret**
- Under **Rules page**, select the page you created in step 1
- Save

The same page shows the **webhook URL** at the top (e.g. `https://your-domain.tld/wp-json/slack-welcome-bot/v1/events`) — you'll need it next.

### 4. Set up the event subscription in Slack

1. In your Slack app: enable **Event Subscriptions**.
2. **Request URL**: paste the webhook URL from step 3.
   - Slack immediately sends a verification request, which the plugin answers automatically — the URL should be marked "Verified" right away.
   - If that fails: check that the WordPress REST API is reachable at all (e.g. open `https://your-domain.tld/wp-json/` in a browser — it should return JSON) and that the signing secret was saved correctly.
3. Under **Subscribe to bot events**, add `team_join`.
4. Save. If the scopes changed, reinstall the app to the workspace.

Done — every new member now automatically gets a DM with the current content of the selected page.

## Troubleshooting

- **Request URL won't verify**: test `https://your-domain.tld/wp-json/`. Some security plugins (e.g. Wordfence, iThemes Security) partially block the REST API — add an exception for `slack-welcome-bot/v1/*` if needed.
- **No DM arrives**: check the PHP error log (errors are written via `error_log()`, usually visible in your hosting control panel or at `wp-content/debug.log` with `WP_DEBUG_LOG` enabled). Verify the bot token scopes and that the app is installed to the workspace.
- **Rules text is missing/outdated**: make sure the selected page is published and that the correct page is chosen in the plugin settings.
- **Duplicate DMs**: shouldn't happen thanks to the built-in event deduplication (WordPress transients); if it does, check the PHP error log for anything unusual.

## Compatibility

Tested and working with **WordPress 7.1 "Mary Lou"** (as of August 2026) and PHP 7.4–8.x. The plugin relies exclusively on stable WordPress APIs that have been unchanged for years (Settings API, REST API, Transients, HTTP API) and isn't affected by the 7.1 change to internal hook-callback ID generation (which briefly caused errors for plugins like WP Rocket), since it doesn't do any manual hook introspection.

## Releasing a new version

The plugin automatically checks this repo's GitHub Releases and shows a normal "update available" notice with an **Update now** button under **Plugins** in the WP backend. Versioning and releases are automated via two GitHub Actions workflows:

1. Write commits following [Conventional Commits](https://www.conventionalcommits.org/) (`fix:`, `feat:`, `feat!:`/`BREAKING CHANGE:` for majors, …) — semantic-release derives the next version from these.
2. Trigger **Actions → Release → Run workflow** manually in the GitHub repo (`.github/workflows/release.yml`).
   - `semantic-release` determines the next version from the commits since the last tag.
   - Writes the version back into the plugin header and `SWB_PLUGIN_VERSION`, and updates `CHANGELOG.md`.
   - Commits, tags (`vX.Y.Z`), and creates the GitHub release automatically.
3. The tag push triggers `.github/workflows/release-asset.yml`, which builds a ZIP of the plugin folder and attaches it as a release asset — that's the file the update checker in WordPress downloads.

No conventional commits since the last tag → semantic-release exits without a new version (no release).

## Tests

- `composer install && composer test` — PHPUnit tests for HTML→Slack conversion and webhook signature verification (`tests/`).
- `npm test` — tests for the release tooling (`bin/`).

Both run automatically via GitHub Actions on every push/PR (`.github/workflows/tests.yml`).

## Security

- Every incoming request is verified against the Slack signature — requests without a valid signature are rejected with HTTP 401.
- The bot token and signing secret are stored in the WordPress options table, not exposed in plaintext on the frontend.
- Slack retries (on timeouts) and duplicate events are detected and ignored.
