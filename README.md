# Overwatch Laravel Agent

Counts what happens inside a Laravel app and sends one small report a minute to
your Overwatch server: request and error rates, slow queries, queue jobs,
sign-in and privilege signals, health checks and installed package versions.

It only counts. It never sends who did something, what they sent, or what an
error said. The report goes one way: nothing in the server's answer changes what
the app does.

- PHP 8.3 or newer, Laravel 11, 12 or 13.
- No runtime dependency beyond Laravel's own components.
- Never throws into your app, and costs a request no more than a cache write.

## Install

```bash
composer require arisultima/overwatch-laravel-agent
php artisan overwatch:install
```

`overwatch:install` publishes `config/overwatch.php` (it never overwrites one you
already have) and prints the lines to add to `.env`. It never writes `.env`
itself:

```dotenv
OVERWATCH_URL=https://overwatch.example.com
OVERWATCH_TOKEN=
# Optional: the name this machine reports under. Leave it out to use the hostname.
OVERWATCH_INSTANCE=
```

`OVERWATCH_TOKEN` is the app token your Overwatch server issued for this app (it
starts with `satapp_`). Until both the URL and the token are set the package
does nothing at all: no listeners, no middleware, no scheduled task.
`OVERWATCH_ENABLED=false` switches it off the same way while keeping the token.

**The scheduler must run.** Reports are sent by `overwatch:report`, which the
package adds to the Laravel schedule once a minute. Make sure the usual cron
entry is in place:

```cron
* * * * * cd /path/to/your-app && php artisan schedule:run >> /dev/null 2>&1
```

Then check the whole path once:

```bash
php artisan overwatch:test --dry-run   # build and print the report, send nothing
php artisan overwatch:test             # send it once and say what the answer means
```

`overwatch:test` never marks anything as sent, so the next scheduled report still
carries the same minutes. When it sends, it exits 0 only when the server took the
report; with `--dry-run` it exits 0 when the package is configured. Both commands
say which setting is missing, and exit 1, when the package is not configured.

If your config is cached, run `php artisan config:cache` again after editing
`.env`.

## Settings

All in `config/overwatch.php`; the defaults suit most apps.

| Setting | Default | What it does |
|---|---|---|
| `enabled` | `OVERWATCH_ENABLED`, true | Off means nothing is recorded or sent. |
| `url`, `token` | `OVERWATCH_URL`, `OVERWATCH_TOKEN` | Where to send, and the app token. |
| `instance` | `OVERWATCH_INSTANCE`, the hostname | The name this machine reports under, lowercased and cut to 100 characters. |
| `cache_store` | `OVERWATCH_CACHE_STORE`, the default store | Where the per-minute counters live. Not `array` or `null`: those keep nothing between processes, and both commands warn about them. |
| `slow_query_ms` | 500 | A query at or over this is counted as slow. |
| `slow_request_ms` | 1000 | A request over this is counted as slow. |
| `privileged_roles` | `root`, `superadmin`, `admin` | A sign-in by an account holding one is counted as privileged. |
| `user_model` | the default auth provider's model | Whose created and deleted events are counted as accounts. |
| `signin_routes` | `login`, `login.store`, `two-factor.login`, `two-factor.login.store` | Route names where a 429 counts as a throttled sign-in. |
| `queues` | the default queue | Queue names on the default connection whose depth is reported, at most 20. |

## What a report holds

One JSON object a minute, gzipped, posted to
`{OVERWATCH_URL}/api/agent/v1/app-report` with the token in the
`Authorization` header. The full contract, every field and every cap, is in
[`docs/protocol.md`](docs/protocol.md).

Everything in it, in plain words:

- **About the report:** the schema number, this package's name and version,
  when the report was built, the instance name of the machine that sent it, the
  window of minutes it covers, and how many minutes were lost since the last
  report the server took (`dropped_minutes`).
- **About the app:** `APP_ENV`, whether debug is on, the PHP and Laravel
  versions, and the app timezone.
- **Health checks,** run by the machine that sends the report: database latency,
  cache, storage (writable, and the free bytes on that machine's disk), pending
  migrations, the default queue (driver, pending jobs, the age of the oldest
  waiting job, failed jobs), the scheduler, Redis latency when Redis is
  configured, and Horizon's supervisor count and status when it is installed.
- **Queue depths:** for each queue name in the `queues` setting, the connection
  name, the queue name, pending jobs and the age of the oldest waiting one.
- **Hygiene flags:** debug on in production, HTTPS in `APP_URL`, secure session
  cookie, maintenance mode, config and routes cached, app key set.
- **Per machine (instance):** its instance name, **its hostname, always**, even
  when `OVERWATCH_INSTANCE` gives it another name, when it last stamped its
  local probes and whether it has gone quiet, and from that stamp its PHP version
  and its storage check (writable and free bytes on its own disk).
- **Per machine, per closed minute:** requests by status class, with 401, 403,
  419 and 429 also counted on their own (a 401 is how a failed API token or a
  missing session shows), slow requests, a request duration histogram, slow
  queries, jobs processed and failed, exceptions reported, and the sign-in
  counters: sign-ins, privileged sign-ins, failed sign-ins (and those naming an
  account that does not exist), lockouts, throttled sign-ins, failed two-factor
  codes, password reset links and resets, role and permission changes, accounts
  created and deleted, impersonations and authorisation checks denied.
- **Distinct counts per minute:** how many different accounts and client
  addresses failed to sign in, the most failures from one address and against one
  account, and how many different accounts signed in. Only a keyed hash of each
  address or account is kept in your cache, never the value, and only the counts
  are sent.
- **Error groups per machine:** the exception class, the first frame in your own
  code (as a path relative to the app root, with its line), a fingerprint of the
  two and how often it happened. An in-app error recorder that uses the same
  fingerprint can match its own captured errors to these groups.
- **Queues per machine:** for each queue name its jobs ran on, the jobs
  processed and failed and the slowest job in milliseconds.
- **Versions:** a hash of `composer.lock` and every installed Composer package
  as name, version and whether it is a dev package, at most every 6 hours or as
  soon as `composer.lock` changes.

Lists cut to fit the size caps say how many entries were left out.

### What it never sends

Email addresses, user ids or names, client IP addresses, URLs or paths with
query strings, exception messages, stack traces, request or job payloads, and
environment values other than those named in the protocol. The package's
privacy test runs a noisy app full of such data and fails if any of it reaches a
report.

## When the server is away

One attempt a minute, 3 seconds to connect and 5 seconds in all, no retry and no
redirect. Unsent minutes are kept and go with the next report the server takes;
those older than 60 minutes are counted in the report's `dropped_minutes`. A
report the server refuses for good (422 or 413) is not sent again: its minutes
are counted as dropped and a warning is logged. A refused token (401) is logged
once an hour. `overwatch:report` always exits 0, so a failed send never fails
your scheduler.

## Several servers

Each machine is an instance with its own counters. The name is the hostname;
set `OVERWATCH_INSTANCE` on each machine when hostnames are not stable
(containers, autoscaling groups). Give every machine its own value: machines
sharing one name, for example a value baked into an image, are merged into one
instance and their counts mixed. Names are lowercased, because Overwatch
compares them without case: `Web-1` and `web-1` are the same instance.

- With a shared cache store (the same Redis for every machine), one server at a
  time runs the scheduled report and sends every machine's minutes in it.
- Without a shared store, every machine that runs the scheduler reports for
  itself. A machine that runs no scheduler sends nothing.

## Hooks for flows without events

The package listens for Laravel's own auth events, Fortify's failed two-factor
event and the role events of `spatie/laravel-permission`. Where your app does
these things without raising those events, call the matching hook where it
happens:

```php
use Arisultima\OverwatchAgent\Overwatch;

Overwatch::twoFactorFailed();        // a wrong two-factor code
Overwatch::passwordResetRequested(); // a password reset link sent
Overwatch::privilegeChanged();       // a role or permission given or taken away
Overwatch::impersonated();           // someone started acting as another account
```

Each counts once in the current minute, does nothing while the package is off,
and never throws. Do not call one where the event is already raised, or it
counts twice.

## Known limits

- **Octane and other long-running servers:** request durations are measured
  from `LARAVEL_START` when it is defined, else from the package's middleware.
  In a worker that serves many requests neither may mark the true start of each
  request, so check the durations and slow request counts before trusting them.
- Requests stopped by global middleware that runs before the package's (for
  example maintenance mode) are still counted as requests.
- An exception class with its own `report()` method, or one the app stops
  earlier with `reportable()->stop()`, is not counted.
- `spatie/laravel-permission` raises its role and permission events only when
  `permission.events_enabled` is on; with it off, call
  `Overwatch::privilegeChanged()` yourself.
- Accounts created or deleted through a subclass of the configured user model
  are not counted.
- On a database or file cache store, under a sign-in attack each failed sign-in
  rewrites the distinct sets, up to about 80 KB per failure. Use a Redis cache
  store for the counters on any app that faces the internet.
- Each distinct set holds at most 1000 entries per machine per minute; past that
  the count stops at 1000.
- Health probes time out only as fast as your app's own database, cache and
  Redis driver settings allow.
- Distinct counts are per machine. A server that adds them up across machines
  may count one account or address more than once, so treat a total as "up to".

## Testing

```bash
composer install
vendor/bin/pest
```

The Redis tests run against `127.0.0.1:6379` (or `REDIS_HOST` and `REDIS_PORT`)
and are skipped when no Redis server answers.

## Licence

MIT. See [LICENSE](LICENSE).
