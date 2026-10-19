# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

n0glemand is a Slack bot built on Laravel 13 (PHP 8.4). It has no user-facing web app: the only page is a static splash at `/`. Everything else is Slack webhooks handled synchronously in a controller, which hand off the real work to queued jobs. When adding structure, mirror Laravel conventions and the patterns below rather than inventing new layouts.

What the bot does:

- **`ping`** in a channel: replies `pong` in the thread.
- **Instagram reel/post/tv URL** in a message: downloads the media with `yt-dlp` and uploads it to the thread.
- **Spotify URL or `spotify:` URI** in a message: looks it up on song.link (`api.song.link/v1-alpha.1/links`) and posts the `pageUrl` in the thread.
- **`channel_created` event**: joins the new public channel.
- **`/n0glemand` slash command**: `help`, and `logs [N]` (tails the last N lines, default 50, max 200, of `storage/logs/laravel.log` as an ephemeral reply).

## Structure

- **Routes** (`routes/web.php`): `GET /` (welcome view), `POST /slack/events` (`slack.events`), `POST /slack/commands` (`slack.commands`). Both Slack routes use `App\Http\Middleware\VerifySlackSignature` (HMAC `v0` signature, 5 minute timestamp tolerance) and are exempt from CSRF in `bootstrap/app.php` (`preventRequestForgery(except: ...)`). Health check at `/up`.
- **`App\Http\Controllers\Slack\EventsController`**: answers `url_verification`, dedupes retries by `event_id` with `Cache::add("slack:event:{id}")` (TTL `services.slack.event_dedupe_ttl`, 10 min), then calls `SlackEventRouter`. Returns 204.
- **`App\Http\Controllers\Slack\CommandsController`**: the `/n0glemand` slash command.
- **`App\Services\Slack\SlackEventRouter`**: routes `event_callback` payloads. `message` events (bot messages and the subtypes `bot_message`, `message_changed`, `message_deleted`, `channel_join`, `channel_leave` are skipped) go to every handler whose `matches()` is true; `channel_created` dispatches `JoinPublicChannel`. Replies go to `thread_ts` if set, otherwise `ts`.
- **Message handlers** (`App\Services\Slack\Handlers`, implementing `Contracts\MessageHandler` with `matches()` and `handle()`): `PingHandler`, `InstagramLinkHandler`, `SpotifyLinkHandler`. They are tagged `slack.handlers` in `App\Providers\SlackServiceProvider`; **a new handler must be added to that tag list**. Handlers only parse and dispatch a job; they unwrap Slack's `<url|label>` link syntax and dedupe URLs per message.
- **Jobs** (`App\Jobs\Slack`, all `ShouldQueue` with `Queueable`, explicit `$tries`, `$timeout`, `backoff()` and a `failed()` that logs): `PostPingReply`, `PostSonglinkToThread` (4xx from song.link is logged and dropped, 5xx is retried), `UploadInstagramMediaToThread` (runs `yt-dlp` via `Process` in a temp dir under `storage/app/slack-ig/<uuid>`, 900 MB limit, uploads with Slack's external upload flow, always deletes the temp dir), `JoinPublicChannel`.
- **`App\Services\Slack\SlackClient`**: thin wrapper over the Slack Web API with the `Http` client (`chat.postMessage`, `files.getUploadURLExternal` + `files.completeUploadExternal`, `conversations.join`, `conversations.list`, `auth.test`). It throws `RuntimeException` when Slack answers `ok: false`. Bound as a singleton in `SlackServiceProvider`.
- **Instagram login** (`App\Services\Instagram`): `InstagramCookieJar` logs in a single account (`INSTAGRAM_USERNAME`/`INSTAGRAM_PASSWORD`) through `InstagramLoginClient` (scrapes the web login page for `csrftoken`, posts to `/accounts/login/ajax/`, classifies the answer as `InstagramLoginStatus`), and writes the cookies in Netscape format (`NetscapeCookieFormatter`) to `storage/app/private/instagram-cookies/instagram.txt` (mode 0600) for `yt-dlp --cookies`. Cookies are reused for `INSTAGRAM_COOKIES_TTL` (default 7 days); a failed login sets a cooldown in the cache (`ig:login:cooldown`, `INSTAGRAM_LOGIN_COOLDOWN`, default 5 min). When `yt-dlp` fails with what looks like an auth error, the job invalidates the cookies and retries once. Without credentials it falls back to `YTDLP_COOKIES_PATH`.
- **Console commands**: `instagram:refresh` (force a fresh Instagram login), `slack:join-all-public {--sleep=1}` (join every non-archived public channel). `routes/console.php` schedules `horizon:snapshot` every five minutes.
- **Models and database**: only the stock `User` model and the default migrations (`users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`). The bot keeps no domain data in the database.
- **Frontend**: one Blade view (`resources/views/welcome.blade.php`), Tailwind v4 through Vite. No JavaScript app.
- **`slack-manifest.yaml`**: the Slack app manifest (bot scopes, the slash command, event subscriptions `message.channels`, `message.groups`, `channel_created`). Its URLs are placeholders (`REPLACE-ME`).

## Configuration and services

- **Queue**: `QUEUE_CONNECTION=redis` with **Horizon** (`config/horizon.php`: one `supervisor-1` on queue `default`, 1-3 processes, 4 in production; job timeout 240 s; prefix `HORIZON_PREFIX`). The Horizon dashboard is only reachable in the `local` environment (`HorizonServiceProvider::gate()`). Redis client `phpredis`.
- **Database**: MySQL in `.env.example` (`n0glemand` on 127.0.0.1:3306); sessions and cache use the `database` driver, so the cache tables must exist for event dedupe and the Instagram cooldown.
- **Slack settings** live in `config/services.php` under `slack` (`SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_SIGNING_SECRET`, `YTDLP_BINARY`, `YTDLP_COOKIES_PATH`, plus fixed values for API base, tolerances and limits) and `instagram`.
- **Sentry**: `sentry/sentry-laravel`, wired into exception handling in `bootstrap/app.php`. The `stack` log channel includes `sentry` (level `SENTRY_LOG_LEVEL`, default `warning`) automatically when `SENTRY_LARAVEL_DSN` is set, unless `LOG_STACK` overrides it.
- **External binary**: production needs `yt-dlp` installed (path via `YTDLP_BINARY`).
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
