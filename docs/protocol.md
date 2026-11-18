# Overwatch app report, schema 1

The single contract between the Laravel agent package (`arisultima/overwatch-laravel-agent`) and the Overwatch server that receives it. Both implementations cite this file; when they disagree, this file wins and the other is the bug. The package repo carries a copy as `docs/protocol.md`.

## Transport

- `POST {OVERWATCH_URL}/api/agent/v1/app-report`
- Headers: `Authorization: Bearer satapp_<48 chars>`, `Content-Type: application/json`, `Content-Encoding: gzip`, `User-Agent: overwatch-laravel-agent/<version>`.
- The body is the gzip of one UTF-8 JSON object, described below. The token is never in the URL.
- One attempt per minute. Connect timeout 3 s, total timeout 5 s.

## Size caps (uncompressed JSON bytes)

| Part | Cap |
|---|---|
| Report without `versions` | 65536 |
| `versions` section alone | 196608 |
| Whole body | 262144 |

The package trims to fit in this order: `instances[].errors` beyond 20 groups, then `queue_depths` beyond 20, then the oldest `minutes` (counted into `dropped_minutes`). Every trimmed list sets its `*_truncated` count. The server refuses a body over a cap with 422.

## Text limits

Every text field is a non-empty string once surrounding whitespace is trimmed, and at most this many characters. A text field that is empty, not a string or longer is refused with 422, except in `versions.packages`, see Versions.

| Field | Max characters |
|---|---|
| `package.name`, `package.version` | 255 |
| `reporter` | 100 |
| `app.environment`, `app.php`, `app.laravel`, `app.timezone` | 64 |
| `error` of a failed check, in `checks` and in `instances[].local.storage` | 255 |
| `checks.queue.driver`, `checks.horizon.status` | 64 |
| `queue_depths[].connection` | 64 |
| `queue_depths[].name` | 255 |
| `instances[].name` | 100 |
| `instances[].hostname` | 255 |
| `instances[].local.php` | 64 |
| `instances[].errors[].class`, `instances[].errors[].location` | 512 |
| `instances[].queues[].name` | 255 |
| `versions.packages[]` name and pretty version | 255 |

## Responses and what the package does

| Status | Meaning | Package |
|---|---|---|
| 202 `{"accepted": true}` | taken | mark the reported minutes sent |
| 401 | unknown or revoked token | keep the minutes, log once an hour |
| 422, 413 | schema unknown, body invalid or over a cap | mark sent (a resend cannot succeed), count the refused minutes into the next report's `dropped_minutes`, log a warning with the first error |
| 429, 503 | slow down (Retry-After) | keep the minutes |
| other, timeout, no connection | | keep the minutes |

The package reads nothing else from a response. Kept minutes expire after 60 minutes; those lost are counted into the next report's `dropped_minutes`.

The package may send a minute the server already accepted: when a 202 is lost on the way back, when marking the minutes sent fails, when the report lock cannot be taken (that run marks nothing), and on every `overwatch:test`. So the server stores minutes per (instance, minute) as replace-or-ignore, never add: a minute it already holds is replaced by the resent one or the resent one is ignored, and its counts are never added a second time.

`dropped_minutes` may likewise repeat: it is cleared only once a report carrying it is accepted, so every report until then carries the same count again (plus any new losses). It counts instance-minutes, not wall minutes: one minute lost on three instances is 3.

## Top level

| Field | Type | Notes |
|---|---|---|
| `schema` | int | `1` |
| `package` | object | `{"name": string, "version": string}` |
| `sent_at` | string | ISO 8601 UTC, seconds precision |
| `reporter` | string | instance name of the node that built the report |
| `window` | object | `{"from": string, "to": string}`, ISO 8601 UTC, whole minutes, `to` exclusive. A minute is reported only once it has been over for 10 s (the close grace), so `to` is at most the start of the minute that `sent_at` less 10 s falls in. The server reads `to` as sent and must not infer it from `sent_at` |
| `dropped_minutes` | int | closed instance-minutes not delivered since the last accepted report, whether or not they had counts: expired unsent, trimmed, or in a refused report; repeated in every report until one is accepted |
| `app` | object | see App |
| `checks` | object | the health checks, see Checks |
| `queue_depths` | array | see Queues |
| `queue_depths_truncated` | int | |
| `hygiene` | object | see Hygiene |
| `instances` | array | 1 to 50, see Instances |
| `versions` | object or null | see Versions; null when not due |

There is no app name or id anywhere: the token says which app it is.

## App

```json
{"environment": "production", "debug": false, "php": "8.4.27", "laravel": "13.4.0", "timezone": "Asia/Kuala_Lumpur"}
```

## Checks

Exactly the field names of the `app_health` pull check, so one evaluator reads both. Every check is either `{"ok": true, ...fields}` or `{"ok": false, "error": string}`, where `error` is the short class name of what was thrown (for example `QueryException`), never the exception message.

| Key | Present | Fields when ok |
|---|---|---|
| `database` | always | `latency_ms` int |
| `cache` | always | none |
| `storage` | always | `writable` bool, `free_bytes` int or null (the reporting node's disk) |
| `migrations` | always | `pending` int (probed at most every 10 minutes, cached between) |
| `queue` | always | `driver` string, `pending` int or null, `oldest_pending_seconds` int or null, `failed` int or null (default connection, default queue) |
| `scheduler` | always | `last_run_seconds_ago` 0, `instrumented` true (the report itself is the scheduler running) |
| `redis` | when a Redis connection is configured | `latency_ms` int |
| `horizon` | when Horizon is installed | `supervisors` int, `status` string |

## Queues

`queue_depths`: one row per configured queue name the package can size, at most 20:

```json
{"connection": "redis", "name": "default", "pending": 3, "oldest_pending_seconds": 12}
```

`pending` and `oldest_pending_seconds` are null where the driver cannot tell (for example `sync`).

## Hygiene

All booleans, read at report time on the reporting node.

| Field | True means |
|---|---|
| `debug_in_production` | `APP_DEBUG` on while `APP_ENV` is `production` |
| `https` | `APP_URL` starts with `https://` |
| `secure_session_cookie` | `session.secure` is true |
| `maintenance_mode` | the app is down for maintenance |
| `config_cached` | the config is cached |
| `routes_cached` | the routes are cached |
| `app_key_set` | `APP_KEY` is set |

## Instances

One object per machine whose counts are in this report.

| Field | Type | Notes |
|---|---|---|
| `name` | string | `OVERWATCH_INSTANCE`, else the hostname; at most 100 chars; once per report, compared without case (a repeat is refused with 422) |
| `hostname` | string | `gethostname()` of that machine |
| `stamped_at` | string or null | when that machine last stamped its local probes |
| `quiet` | bool | no stamp for 10 minutes |
| `local` | object or null | `{"storage": {checks.storage shape}, "php": string}` from the last stamp |
| `minutes` | array | at most 60, oldest first, each `at` once (a repeat is refused with 422), see Minute |
| `minutes_truncated` | int | |
| `errors` | array | groups in the window, at most 20, largest first |
| `errors_truncated` | int | groups left out |
| `queues` | array | per queue name in the window: `{"name": string, "processed": int, "failed": int, "slowest_ms": int}`, at most 20 |

### Minute

```json
{"at": "2026-11-18T13:05:00Z", "counters": {...}, "histogram": {...}, "distinct": {...}}
```

`counters`: every key below, always present, int, 0 when nothing happened.

| Key | Counted |
|---|---|
| `http.requests` | requests that reached the app |
| `http.2xx` `http.3xx` `http.4xx` `http.5xx` | by status class |
| `http.401` `http.403` `http.419` `http.429` | those statuses |
| `http.slow` | requests over 1000 ms |
| `db.slow_queries` | queries over the slow threshold (default 500 ms) |
| `jobs.processed` `jobs.failed` | queue jobs finished on this machine |
| `errors.total` | exceptions reported |
| `auth.login` | successful sign-ins |
| `auth.login_privileged` | successful sign-ins by an account holding a privileged role |
| `auth.login_failed` | failed sign-ins |
| `auth.login_failed_unknown` | failed sign-ins naming an account that does not exist |
| `auth.lockouts` | lockout events |
| `auth.login_throttled` | 429 on the sign-in routes |
| `auth.two_factor_failed` | wrong 2FA codes |
| `auth.password_reset_requested` | reset links sent |
| `auth.password_reset` | passwords reset |
| `auth.privilege_changes` | roles or permissions attached or detached |
| `auth.accounts_created` `auth.accounts_deleted` | user model created or deleted |
| `auth.impersonations` | impersonation started |
| `auth.gate_denials` | authorisation checks denied |

`histogram`: request durations in ms, by upper bound: `le_50`, `le_100`, `le_250`, `le_500`, `le_1000`, `le_2500`, `le_5000`, `gt_5000`, all int.

`distinct`: sizes of keyed-hash sets inside that minute, all int.

| Key | Meaning |
|---|---|
| `auth.login_failed_accounts` | different accounts named in failed sign-ins |
| `auth.login_failed_sources` | different client addresses of failed sign-ins |
| `auth.login_failed_max_per_source` | most failed sign-ins from one address |
| `auth.login_failed_max_per_account` | most failed sign-ins naming one account |
| `auth.login_accounts` | different accounts that signed in |

### Error group

```json
{"fingerprint": "9f2c...64 hex", "class": "Illuminate\\Database\\QueryException", "location": "app/Services/Billing.php:88", "count": 4}
```

`fingerprint` is `sha256(class . '|' . location)`, hex, the same value `sat/diagnostics` computes for the same exception, so the two can be matched. `location` is the first frame inside the app (not `vendor/`), relative to the app root; `"unknown"` when there is none. No message, trace, request, user or input.

## Versions

Sent at most every 6 hours, or at once when the lock hash changes; otherwise `null`.

```json
{"lock_hash": "sha256 hex of composer.lock", "packages": [["laravel/framework", "v13.4.0", false]], "packages_truncated": 0}
```

`packages`: `[name, pretty version, is_dev]`, at most 1500, sorted by name. A row that is not such a triple, or has a field empty, null, of the wrong type or past its text limit, is skipped and counted with `packages_truncated`; it never refuses the report. More than 1500 rows, `packages` not a list or `lock_hash` not 64 lowercase hex characters is refused with 422.

## Never in a report

Email addresses, user ids or names, client IP addresses, URLs or paths with query strings, exception messages, stack traces, request or job payloads, environment values other than those named above. The package's privacy test enforces this.

## Full example (abridged: web-1 shows one minute, worker-1's minutes are left out)

```json
{
  "schema": 1,
  "package": {"name": "arisultima/overwatch-laravel-agent", "version": "0.1.0"},
  "sent_at": "2026-11-18T13:07:12Z",
  "reporter": "worker-1",
  "window": {"from": "2026-11-18T13:05:00Z", "to": "2026-11-18T13:07:00Z"},
  "dropped_minutes": 0,
  "app": {"environment": "production", "debug": false, "php": "8.4.27", "laravel": "13.4.0", "timezone": "UTC"},
  "checks": {
    "database": {"ok": true, "latency_ms": 2},
    "cache": {"ok": true},
    "storage": {"ok": true, "writable": true, "free_bytes": 37580963840},
    "migrations": {"ok": true, "pending": 0},
    "queue": {"ok": true, "driver": "redis", "pending": 3, "oldest_pending_seconds": 12, "failed": 0},
    "scheduler": {"ok": true, "last_run_seconds_ago": 0, "instrumented": true},
    "redis": {"ok": true, "latency_ms": 1},
    "horizon": {"ok": true, "supervisors": 1, "status": "running"}
  },
  "queue_depths": [{"connection": "redis", "name": "default", "pending": 3, "oldest_pending_seconds": 12}],
  "queue_depths_truncated": 0,
  "hygiene": {"debug_in_production": false, "https": true, "secure_session_cookie": true, "maintenance_mode": false, "config_cached": true, "routes_cached": true, "app_key_set": true},
  "instances": [
    {
      "name": "web-1",
      "hostname": "web-1",
      "stamped_at": "2026-11-18T13:06:41Z",
      "quiet": false,
      "local": {"storage": {"ok": true, "writable": true, "free_bytes": 21474836480}, "php": "8.4.27"},
      "minutes": [
        {
          "at": "2026-11-18T13:05:00Z",
          "counters": {"http.requests": 412, "http.2xx": 398, "http.3xx": 6, "http.4xx": 7, "http.5xx": 1, "http.401": 0, "http.403": 2, "http.419": 1, "http.429": 0, "http.slow": 3, "db.slow_queries": 1, "jobs.processed": 0, "jobs.failed": 0, "errors.total": 1, "auth.login": 5, "auth.login_privileged": 1, "auth.login_failed": 9, "auth.login_failed_unknown": 4, "auth.lockouts": 1, "auth.login_throttled": 0, "auth.two_factor_failed": 0, "auth.password_reset_requested": 0, "auth.password_reset": 0, "auth.privilege_changes": 0, "auth.accounts_created": 0, "auth.accounts_deleted": 0, "auth.impersonations": 0, "auth.gate_denials": 2},
          "histogram": {"le_50": 300, "le_100": 70, "le_250": 30, "le_500": 6, "le_1000": 3, "le_2500": 2, "le_5000": 1, "gt_5000": 0},
          "distinct": {"auth.login_failed_accounts": 3, "auth.login_failed_sources": 2, "auth.login_failed_max_per_source": 7, "auth.login_failed_max_per_account": 5, "auth.login_accounts": 5}
        }
      ],
      "minutes_truncated": 0,
      "errors": [{"fingerprint": "9f2c4b0d1e7a3c5f8b6d2e9a0c1f4b7d3e5a8c2f6b9d0e1a4c7f3b5d8e2a6c9f", "class": "Illuminate\\Database\\QueryException", "location": "app/Services/Billing.php:88", "count": 1}],
      "errors_truncated": 0,
      "queues": []
    },
    {
      "name": "worker-1",
      "hostname": "worker-1",
      "stamped_at": "2026-11-18T13:06:58Z",
      "quiet": false,
      "local": {"storage": {"ok": true, "writable": true, "free_bytes": 37580963840}, "php": "8.4.27"},
      "minutes": [],
      "minutes_truncated": 0,
      "errors": [],
      "errors_truncated": 0,
      "queues": [{"name": "default", "processed": 42, "failed": 0, "slowest_ms": 1830}]
    }
  ],
  "versions": null
}
```
