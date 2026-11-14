# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

n0glemand is a Slack bot built on Laravel 13 (PHP 8.4). It has no user-facing web app: the only page is a static splash at `/`. Everything else is Slack webhooks handled synchronously in a controller, which hand off the real work to queued jobs. When adding structure, mirror Laravel conventions and the patterns below rather than inventing new layouts.

What the bot does:

- **`ping`** in a channel: replies `pong` in the thread.
- **Instagram reel/post/tv URL** in a message: downloads the media with `yt-dlp` and uploads it to the thread. The scheme is optional (`instagram.com/reel/ID` works) and the job gets a canonical `https://www.instagram.com/<type>/<id>/` URL: `/reels/` becomes `/reel/` because yt-dlp rejects `/reels/` as an unsupported URL.
- **Track or album link of a music service** in a message: posts one reply in the thread with a `https://song.link/<canonical URL>` line per distinct link, built locally (song.link's public API now answers 401 `PUBLIC_API_ACCESS_DEPRECATED`, so none is called). Services and the types song.link resolves (checked with curl, 2026-11-10): Spotify track/album (URL or `spotify:` URI), Apple Music album/song on any storefront (an album URL with `?i=` is the track), YouTube Music `watch?v=` only, Tidal track/album (`tidal.com`, `listen.tidal.com`, `/browse/`), Deezer track/album, Amazon Music albums/tracks on any `music.amazon.<tld>` (`?trackAsin=` is the track) and SoundCloud track or `sets/` URLs. Playlists, artists, shows, episodes and profiles are ignored: song.link answers them with `https://undefined` or a not-found page. Links already on song.link/album.link/odesli, and service URLs embedded in another URL, are ignored.
- **`channel_created` event**: joins the new public channel.
- **`/n0glemand` slash command**: `help`, `logs [N]` (tails the last N lines, default 50, max 200, of the newest `storage/logs/laravel*.log` as an ephemeral reply), and the admin-only `instagram status` (credentials set, cookie file age, login cooldown; no secrets), `instagram check` (queues `CheckInstagramFromSlack`, which runs the session check below and posts the result to `response_url`) and `instagram refresh` (acks at once, queues `RefreshInstagramCookiesFromSlack`, which forces a login and posts the outcome, e.g. `checkpoint_required`, to Slack's `response_url`). Admins are the Slack user IDs in `services.slack.admin_user_ids` (env `SLACK_ADMIN_USER_IDS`, comma separated, default `U0KA85C21`); anyone else gets an ephemeral refusal. **Direct messages to the bot** (`message.im`, App Home messages tab on, `im:history`) are handled only by `AdminDirectMessages`: admins send `instagram status|check|refresh` (optionally with a leading `/n0glemand`) and get the answer in the DM, a bare 4 to 8 digit message is an Instagram checkpoint code, everyone else gets a short refusal, and no channel handler (ping etc.) ever runs on a DM.

## Structure

- **Routes** (`routes/web.php`): `GET /` (welcome view), `POST /slack/events` (`slack.events`), `POST /slack/commands` (`slack.commands`). Both Slack routes use `App\Http\Middleware\VerifySlackSignature` (HMAC `v0` signature, 5 minute timestamp tolerance) and are exempt from CSRF in `bootstrap/app.php` (`preventRequestForgery(except: ...)`). Health check at `/up`.
- **`App\Http\Controllers\Slack\EventsController`**: answers `url_verification`, dedupes retries by `event_id` with `Cache::add("slack:event:{id}")` (TTL `services.slack.event_dedupe_ttl`, 10 min), then calls `SlackEventRouter`; if routing throws it forgets that key so Slack's retry is routed again. Returns 204.
- **`App\Http\Controllers\Slack\CommandsController`**: the `/n0glemand` slash command.
- **`App\Services\Slack\SlackEventRouter`**: routes `event_callback` payloads. `message` events with `channel_type` `im` go to `AdminDirectMessages` only. Other `message` events (bot messages and the subtypes `bot_message`, `message_changed`, `message_deleted`, `channel_join`, `channel_leave` are skipped) go to every handler whose `matches()` is true; `channel_created` dispatches `JoinPublicChannel`. Replies go to `thread_ts` if set, otherwise `ts`.
- **Message handlers** (`App\Services\Slack\Handlers`, implementing `Contracts\MessageHandler` with `matches()` and `handle()`): `PingHandler`, `InstagramLinkHandler`, `MusicLinkHandler` (one `PostSonglinkToThread` job per message carrying every canonical URL; the canonical form strips tracking params and keeps what identifies the item). They are tagged `slack.handlers` in `App\Providers\SlackServiceProvider`; **a new handler must be added to that tag list**. Handlers only parse and dispatch a job; they unwrap Slack's `<url|label>` link syntax and dedupe URLs per message. Each dispatch goes through `DispatchesOncePerEvent::dispatchOnce()` (cache key `slack:dispatch:{event_id}:{sha1(action)}`, released if the dispatch throws), so a Slack retry after a partial dispatch skips the jobs already queued and only dispatches the one that failed; the router passes the event id to handlers as `_event_id`.
- **Jobs** (`App\Jobs\Slack`, all `ShouldQueue` with `Queueable`, explicit `$tries`, `$timeout`, `backoff()` and a `failed()` that logs): `PostPingReply`, `PostSonglinkToThread` (takes a list of URLs, one Slack post; no HTTP call besides it; a failed post is retried), `UploadInstagramMediaToThread` (runs `yt-dlp` once per attempt via `Process` in a temp dir under `storage/app/slack-ig/<uuid>`, 900 MB limit, uploads with Slack's external upload flow, always deletes the temp dir; **it never logs in**: it uses the cookie file that exists, and on an auth failure of the first attempt, when credentials are set and no login cooldown is active, it invalidates the cookies (`markInvalid`, only if the file is still the one it used), queues `RefreshInstagramLogin` and `release(120)`s itself once, no loop; its 240 s `$timeout` covers one yt-dlp run (40 s) and the upload (40 s) with at least 30 s to spare, 140 s worst case, see the budget test), `JoinPublicChannel`, `RefreshInstagramLogin` (queue `instagram`, `ShouldBeUnique` with `uniqueFor` 900 (above its timeout) so many failing reels make one login, 1 try, 800 s: lock wait 16 s plus a browser login of 50 s plus the code wait of 600 s plus the 10 s stop grace plus 30 s to spare; skips while the login cooldown is active or without credentials; logs `instagram.refresh.*`), `RefreshInstagramCookiesFromSlack` (queue `instagram`, 1 try, 800 s, the same budget; forced login, posts the outcome to the command's `response_url`), `CheckInstagramFromSlack` (1 try, 120 s: 4 HTTP hops of 8 s plus a 40 s yt-dlp confirm with 30 s to spare, see the budget test; the session check, posts to `response_url`). Both take an optional `dmChannel` instead and then post with `chat.postMessage` into that DM (`Concerns\RepliesToSlack`).
- **`App\Services\Slack\SlackClient`**: thin wrapper over the Slack Web API with the `Http` client (`chat.postMessage`, `files.getUploadURLExternal` + `files.completeUploadExternal`, `conversations.join`, `conversations.list`, `auth.test`). It throws `RuntimeException` when Slack answers `ok: false`. Bound as a singleton in `SlackServiceProvider`.
- **Instagram login** (`App\Services\Instagram`): `InstagramCookieJar` logs in a single account (`INSTAGRAM_USERNAME`/`INSTAGRAM_PASSWORD`) through `InstagramLoginClient` (scrapes the web login page for `csrftoken`, posts to `/accounts/login/ajax/`, classifies the answer as `InstagramLoginStatus`), and writes the cookies in Netscape format (`NetscapeCookieFormatter`) to `storage/app/private/instagram-cookies/instagram.txt` (mode 0600) for `yt-dlp --cookies`. `INSTAGRAM_COOKIES_TTL` (default 7 days) is how old the file may be before the session check logs in again (the download job uses the file at any age); a failed login sets a cooldown in the cache (`ig:login:cooldown`, `INSTAGRAM_LOGIN_COOLDOWN`, default 5 min). Logins run under the cache lock `ig:login` (TTL `INSTAGRAM_LOGIN_LOCK_TTL`, default 720 s, must stay above `INSTAGRAM_BROWSER_TIMEOUT` plus `INSTAGRAM_CODE_WAIT` plus 10 s), and the file is written to a temp name and renamed into place. Logins happen only in `RefreshInstagramLogin`, `RefreshInstagramCookiesFromSlack`, `instagram:refresh` and the session check, never in the download job: `currentCookiesPath()` returns the file as it is (any age, no login, no touch). `markInvalid()` deletes the file and sets the cache flag `ig:login:force_fresh`, which the next login passes to the driver as `forceFresh` (the flag clears on a successful login). The flag is set at most once per `INSTAGRAM_FORCE_FRESH_INTERVAL` (default 6 h): yt-dlp's auth-looking errors also come from private or deleted reels and rate limits, so otherwise the browser reuses the profile session while Instagram accepts it. Without credentials the job falls back to `YTDLP_COOKIES_PATH`.
- **Instagram login drivers** (`App\Services\Instagram\InstagramLogin` contract, `login(username, password, forceFresh = false): InstagramLoginResult`, never throws; bound in `AppServiceProvider` by `INSTAGRAM_LOGIN_DRIVER`, `http` default and for any other value): `InstagramLoginClient` is the `http` one above, `InstagramBrowserLoginClient` the `browser` one. It runs `node resources/scripts/instagram-login/login.mjs` (Playwright, headless Chromium) through `Process` with the credentials in the environment (`INSTAGRAM_LOGIN_USERNAME`/`INSTAGRAM_LOGIN_PASSWORD`, never argv) and reads the last JSON line of stdout: `ok` (with cookies), `invalid_credentials`, `checkpoint_required`, `two_factor_required`, `unknown`. Non-zero exit without a result line, a timeout, garbage output, an unknown status or `ok` without a `sessionid` all become `Unknown`. Only cookies of instagram.com reach `NetscapeCookieFormatter`. The script keeps a persistent profile in `storage/app/private/instagram-browser` (git-ignored, mode 0700: it holds a live session), uses a desktop user agent (the headless shell's says `HeadlessChrome`), dismisses the cookie-consent dialog, and reuses a live `sessionid` from the profile without typing the password, unless `INSTAGRAM_FORCE_FRESH=1` (the jar's `forceFresh` after `markInvalid`): then it drops the profile's session cookies (`sessionid`, `ds_user_id`, `csrftoken`, ...) first and keeps the device cookies, so a dead session is not handed back. A challenge is handled by waiting for a human, see Instagram checkpoint (with `INSTAGRAM_CODE_WAIT=0`, or on a challenge page the script cannot work with, it still returns `checkpoint_required`). Config: `INSTAGRAM_BROWSER_DIR` (folder with `node_modules/playwright` and `browsers/`, default `resources/scripts/instagram-login`; production keeps it outside the releases), `INSTAGRAM_NODE_BINARY` (default `node`), `INSTAGRAM_BROWSER_TIMEOUT` (default 50 s; the script stops itself 8 s earlier; the login lock TTL `INSTAGRAM_LOGIN_LOCK_TTL` and the refresh jobs' 800 s timeouts are sized for it and the code wait, and the tests fail if the timeout grows past them). The script's own dependency is `playwright` only (`resources/scripts/instagram-login/package.json` and lockfile, installed with `npm ci` there; Chromium with `PLAYWRIGHT_BROWSERS_PATH=<dir>/browsers npx playwright install --only-shell chromium`). Tests fake `Process` and `Http`: no browser runs in the suite.
- **Instagram session check** (`App\Services\Instagram\InstagramSessionCheck`, command `instagram:check [--notify]`): validates the cookies as they are with one authenticated `GET /api/v1/accounts/current_user/` (the file the download job uses: the login's cookie file when credentials are set, else only `YTDLP_COOKIES_PATH`), classified as `InstagramCheckStatus` ok / cookies_invalid (401, 403, redirect, no or expired `sessionid`) / checkpoint_required / inconclusive (5xx, transport error) / no_cookies / login_failed. It does not force a login, because a daily login risks `checkpoint_required` and production may run on an exported cookie file it cannot recreate; a login is tried only when credentials are set and the login file is missing or older than `INSTAGRAM_COOKIES_TTL` (as the job would; no cooldown active). Not yt-dlp on a known reel: that one can be deleted and its errors are free text. Logs `slack.ig.healthcheck.ok` (info) or `slack.ig.healthcheck.failed` (warning). With `--notify` a failure DMs every admin user ID with `chat.postMessage` (channel = user ID, needs only `chat:write`); success is silent. Scheduled daily at 09:00 `Europe/Copenhagen` with `--notify`. The request sends the web app's headers (Referer, Origin, Sec-Fetch-*, `X-IG-App-ID`, `X-CSRFToken` from the cookie) and follows redirects by hand (max 3, instagram.com only, same cookies), logging every Location as `slack.ig.healthcheck.redirect`; only a redirect to `/accounts/login` counts as rejected cookies, `/challenge` as a checkpoint, any other unfollowable redirect is `inconclusive` with its Location in the message. With `INSTAGRAM_CHECK_URL` (a stable reel) set, an invalid or inconclusive HTTP verdict is re-checked with `yt-dlp --skip-download` on it using the same cookie file, and a success there wins.
- **Instagram checkpoint** (`InstagramCheckpointChallenge`, `login.mjs`): when the browser login meets a challenge or a two-factor page and `INSTAGRAM_CODE_WAIT` (default 600 s, 0 turns it off) is above 0, the script chooses SMS or email (`INSTAGRAM_CHALLENGE_METHOD`, default `sms`) and presses send, prints an event line (`{"event":"code_required","method":"sms|email|app"}`, or `{"event":"approval_required"}` for a "was this you" screen) and keeps the browser open, polling the file `code` in the browser profile folder. `InstagramBrowserLoginClient` starts the process with `Process::start` and an output callback, so the event reaches `InstagramCheckpointChallenge` while the login runs: it sets the cache key `ig:checkpoint:awaiting` (`code` or `approval`, TTL the wait plus 60 s) and DMs every admin ("Instagram wants a code (SMS), reply here with the code within 10 minutes", or to approve in the app). An admin answers with a bare 4 to 8 digit DM, `instagram code <digits>` in a DM or `/n0glemand instagram code <digits>`: `handOverCode()` accepts digits only and only while a login waits for a code (otherwise it says why), and writes the code file atomically with mode 0600. The script types the code, deletes the file and ends `ok`, `invalid_code` or `timeout` (approval: waits for the page to move on); when the login ends the admins get the outcome DM and the awaiting key and any unused code file are cleared. The process timeout is the browser timeout plus the code wait. The session check logs in with `waitForCode: false` (its job has 120 s), and a challenge ends that login as `checkpoint_required`. That residual `checkpoint_required` (no wait, or an unknown challenge page) queues `AnnounceInstagramCheckpoint`: one DM per 30 minutes asking an admin to confirm it in a browser and send `instagram refresh`.
- **Console commands**: `instagram:refresh` (force a fresh Instagram login; `--if-older-than=<hours>` instead queues `RefreshInstagramLogin` when the cookie file is missing or older, and does nothing otherwise), `slack:join-all-public {--sleep=1}` (join every non-archived public channel), `slack:prune-ig-downloads` (delete `storage/app/slack-ig` dirs older than an hour, left behind when a worker kills a job on timeout). `routes/console.php` schedules `horizon:snapshot` every five minutes, `instagram:refresh --if-older-than=72` daily at 09:30 `Europe/Copenhagen`, after the 09:00 check, so the admins are awake for a code request (`onOneServer`, `withoutOverlapping`), the prune hourly, and `slack:join-all-public --sleep=1` daily at 03:00 (`withoutOverlapping(120)`, in the background) as a safety net for missed `channel_created` events and unarchived channels; the command skips channels that `conversations.list` reports with `is_member` true (no join call), treats `already_in_channel` as success, logs `slack.join_all.failed` (error, once per failed channel) and one `slack.join_all.summary` (info: joined / already_in / failed), so nothing reaches Sentry when nothing needs joining.
- **Models and database**: only the stock `User` model and the default migrations (`users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`). The bot keeps no domain data in the database.
- **Frontend**: one Blade view (`resources/views/welcome.blade.php`), Tailwind v4 through Vite. No JavaScript app.
- **`slack-manifest.yaml`**: the Slack app manifest (bot scopes, the slash command, event subscriptions `message.channels`, `message.groups`, `channel_created`). Its URLs are placeholders (`REPLACE-ME`).

## Configuration and services

- **Queue**: `QUEUE_CONNECTION=redis` with **Horizon** (`config/horizon.php`: `supervisor-1` on queue `default`, 1-3 processes, 4 in production, job timeout 240 s; `supervisor-instagram` on queue `instagram`, exactly 1 process, `tries` 1, timeout 900 s, for the logins so two never run at once and a long login never blocks a download; prefix `HORIZON_PREFIX`). `queue.connections.redis.retry_after` (`REDIS_QUEUE_RETRY_AFTER`) is 1000 s: it must stay above that supervisor's 900 s, which stays above the login jobs' 800 s `$timeout` (lock wait, browser login, the 600 s code wait), or Redis hands a login that waits for its code out a second time; the login jobs also have 1 try and `RefreshInstagramLogin` is unique for 900 s, and the budget tests fail when the chain breaks. The price is that a `default` job whose worker died is retried after 1000 s instead of 300 s. The Horizon dashboard is only reachable in the `local` environment (`HorizonServiceProvider::gate()`). Redis client `phpredis`.
- **Database**: MySQL in `.env.example` (`n0glemand` on 127.0.0.1:3306); sessions and cache use the `database` driver, so the cache tables must exist for event dedupe and the Instagram cooldown.
- **Slack settings** live in `config/services.php` under `slack` (`SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_SIGNING_SECRET`, `YTDLP_BINARY`, `YTDLP_COOKIES_PATH`, plus fixed values for API base, tolerances and limits) and `instagram`.
- **Sentry**: `sentry/sentry-laravel`, wired into exception handling in `bootstrap/app.php`. The `stack` log channel writes `daily` in production and `single` elsewhere, and includes `sentry` (level `SENTRY_LOG_LEVEL`, default `warning`) automatically when `SENTRY_LARAVEL_DSN` is set, unless `LOG_STACK` overrides it.
- **External binary**: production needs `yt-dlp` installed (path via `YTDLP_BINARY`). The `browser` login driver also needs Node and Playwright's Chromium (see Instagram login drivers).
- **Logging**: every step logs a structured event name (`slack.event.received`, `slack.router.dispatched`, `slack.ig.ytdlp_failed`, `instagram.login.failed`, ...). Follow that `area.thing.what` naming for new log lines.

## Commands

- `composer run dev`: runs `php artisan serve`, `queue:listen`, `pail` (logs), and `npm run dev` (Vite) concurrently via `concurrently`. Use this over starting processes individually. The site is also always available via Herd at `http://n0glemand.test` (no need to run a server for Herd access).
- `composer run test`: clears config then runs `php artisan test` (Pest).
- `php artisan test --compact` / `php artisan test --compact --filter=testName`: preferred for focused runs.
- `npm run build` / `npm run dev`: Vite build or dev server for `resources/css/app.css` + `resources/js/app.js` (Tailwind v4 via `@tailwindcss/vite`).
- `vendor/bin/pint --dirty --format agent`: **must run after any PHP edit** before finalizing (see Pint rules below). Do not use `--test`.
- `php artisan pail`: tail application logs.

## Testing setup

- Pest v5 (PHPUnit 13) + `pestphp/pest-plugin-laravel`. `tests/Pest.php` binds `Tests\TestCase` to the `Feature` suite; `RefreshDatabase` is commented out, so opt in per file when a test hits the DB.
- `phpunit.xml` forces tests onto `sqlite` `:memory:`, `array` cache/session, `sync` queue, and `null` broadcast. Do not write tests that assume a real queue/broadcast driver; the suite needs neither MySQL nor Redis.
- Tests live in `tests/Feature/Slack` and `tests/Feature/Instagram`. They fake the outside world with `Http::fake()`, `Queue::fake()` and `Process::fake()`, and sign Slack requests themselves; follow that pattern, never call Slack, song.link or Instagram for real.
- Create tests with `php artisan make:test --pest {Name}` (add `--unit` for the Unit suite). Most new tests should be Feature tests.

## Tooling in this repo

- **Laravel Boost** MCP server is installed (`boost.json`): use its tools (`search-docs`, `database-query`, `database-schema`, `get-absolute-url`, `browser-logs`, etc.) in preference to manual shell/file alternatives. See Boost rules below.
- **Skills** enabled via `boost.json`: `laravel-best-practices`, `testing-best-practices`, `configuring-horizon`, `tailwindcss-development`, `infer-conventions` (Boost writes them to `.claude/skills`, which is not in git). Activate them proactively when working in those domains.
- `.npmrc` sets `ignore-scripts=true`.
- `composer update` runs `php artisan boost:update` afterwards (`post-update-cmd`), which regenerates `boost.json` and the Boost block below and stamps the PHP version of whatever binary ran it. Production runs PHP 8.4: if it wrote another version, set it back before committing. The app deploys on Forge, not Laravel Cloud: keep `"cloud": false` and no `deploying-to-cloud` skill in `boost.json` if an update adds them again.

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
