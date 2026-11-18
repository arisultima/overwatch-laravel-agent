# Changelog

## Unreleased

First release.

- Supports PHP 8.3 or newer on Laravel 12.71 or newer, or 13.40 or newer, the
  oldest releases Composer installs without a security advisory. Laravel 11 is
  not supported: it is out of security support and Composer blocks every 11.x
  release for its advisories.
- Counts requests (by status class, slow, a duration histogram), slow queries,
  queue jobs, reported exceptions (grouped by class and first in-app frame) and
  sign-in, privilege and account signals into per-machine, per-minute buckets in
  the app's cache.
- Keeps distinct counts of failed sign-in accounts and addresses as keyed hashes
  only, at most 1000 per set per minute.
- Sends one schema 1 report a minute from the scheduler, with health checks,
  queue depths, hygiene flags and package versions, capped by size; keeps unsent
  minutes for 60 minutes and counts the rest as dropped.
- `overwatch:install` publishes the config and prints the `.env` lines; it never
  writes `.env`.
- `overwatch:test` builds the report, prints it, sends it once (or not, with
  `--dry-run`) and says what the answer means, without marking anything sent.
- Hooks in `Overwatch::*` for sign-in flows that raise no events.
