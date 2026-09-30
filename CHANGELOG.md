# Changelog

All notable changes to Zactonz Cron Doctor are recorded here. This project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0

First release.

### Added

- A cPanel interface listing every cron job on the account with the result of its last run.
- Optional monitoring for any cron job. Monitoring replaces the command in the crontab with a small runner
  that records the exit code, duration and output of every run. The original crontab line is stored and can
  be restored at any time.
- Overlap protection. A monitored job will not start while its previous run is still going, and the skipped
  run is recorded so the overlap is visible.
- Optional per-job time limits, enforced with `timeout` or `setsid` so background work started by the job is
  stopped as well.
- Output capture with a size limit, kept in the account's home directory with owner-only permissions.
- Cron email that arrives only when a job fails, instead of after every run.
- Email alerts for jobs that fail or that have not run when they should have, with a configurable reminder
  interval and a notice when a job recovers.
- Checks for: a generic PHP path such as `/usr/bin/php`, a PHP version that does not match the site, a script
  that no longer exists, duplicated WordPress cron, WordPress still running its own scheduler, output sent to
  `/dev/null`, an empty `MAILTO`, Windows line endings, identical duplicate lines, unreadable crontab lines,
  credentials on the command line, and runs that take nearly as long as the gap between them.
- One-click fixes for the PHP path and for recovering discarded output.
- A crontab backup before every change, with one-click restore, and an activity log of every change made.
- A read-only mode that still runs every check on accounts where commands cannot be run.

### Security

- The crontab is never written through the cPanel API 2 `Cron` functions, whose `remove_line` parameter is
  ambiguous between a file line number and a command number.
- Every crontab write is verified by reading the crontab back and comparing it with what was intended. A
  mismatch restores the previous version automatically.
- Writes are refused if the crontab changed after the page was loaded.
- The runner refuses to run if its directory or command file is owned by another user or writable by anyone
  other than the owner.
