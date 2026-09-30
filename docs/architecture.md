# Architecture

## Layout

```
index.live.php          the dashboard
job.live.php            one job: history, output, settings
settings.live.php       environment, alerts, backups, activity
actions.php             the only endpoint that changes anything
bootstrap.php           autoloading, cPanel LiveAPI, security headers

src/Cron/               cron syntax: expressions, crontab documents, tokenising
src/Platform/           the server: processes, the crontab, the cPanel API, PHP binaries
src/Job/                monitored jobs: storage, runs, the runner payload, alerts
src/Doctor/             the checks and the findings they produce
src/Http/               request handling, the controller, flash messages
src/Security/           the request token and the audit log
src/View/               escaping, presentation, templates
src/Support/            files, JSON, clock, results

payload/bin/zcd-run     the runner cron executes
payload/bin/zcd-sentinel  the alert check cron executes
payload/lib/sentinel.php  what the alert check runs

tests/unit/             components in isolation
tests/e2e/              real crontabs, real processes, real files
tests/shell/            the runner, exercised as a process
tests/harness/          a stub cPanel and a demo account for the browser
```

## Layers

`src/Cron` knows nothing about cPanel, the filesystem or the account. It parses cron syntax and answers
questions about it. Everything in it is pure, which is why it carries the densest tests.

`src/Platform` is the only part that talks to the server: running processes, reading and writing the crontab,
asking cPanel for the PHP version of each document root. Everything here is behind an interface or takes its
collaborators as constructor arguments, which is how the tests substitute a fake `crontab` command and a fake
cPanel API.

`src/Job` owns the state in the account's home directory: the job registry, the per-job command and settings
files, run history, deploying the runner, and the alert check. `JobManager` is where an operation that spans
both the crontab and the job store lives, so that a failed crontab write can roll back the job record.

`src/Doctor` turns a crontab and its run history into findings. Each check is a class implementing one
interface and is given an `Inspection` holding everything it might need. A check that throws is caught and
reported as one degraded finding rather than taking the page down.

`src/View` and `src/Http` render and route. Templates receive data and do no work beyond formatting.

## Data model

A monitored job is identified by sixteen hexadecimal characters, generated with `random_bytes`. That
identifier appears in the crontab line, names the job's files, and is validated against a fixed pattern
everywhere it is used.

`jobs.json` holds the registry and is written atomically under an exclusive lock. The per-job `.cmd`, `.conf`
and `.in` files are what the runner actually reads; the registry is what the interface reads. They are written
together, and the interface reports a job whose files are missing.

Each run writes two files: `<epoch>-<pid>.json` with the result, and `<epoch>-<pid>.out` with the captured
output. The runner writes the job's rolled-up state **before** the run record, so that any reader which sees a
new run record is guaranteed to see state that is at least as new. Doing it the other way round produced a
visible inconsistency, where the interface could show a failed run next to a zero failure streak.

## Deploying the runner

The plugin directory is owned by root and, under CageFS, is not visible to the account's cron jobs. The runner
is therefore copied into `~/.zactonz/zcd/`, along with the subset of the library the alert check needs. A
`version` file records which plugin version put it there, and the interface refreshes it whenever that does
not match. This is why updating the plugin does not require touching any crontab.

## Testing

There is no PHPUnit and no Composer. The plugin ships with no dependencies, and the tests have none either;
`tests/Framework` is a few hundred lines providing assertions, a runner and the fakes.

The parts that cannot be faked usefully are not faked:

- The runner tests run the real script as a real process, with real locks, real timeouts, real concurrent
  runs and real permission changes, and assert on the files it leaves behind.
- The end-to-end tests run the real `JobManager` against a real file tree and a fake `crontab` command that
  behaves like the real one, including modes that reject a write, corrupt it, or add a header, so the
  verify-and-roll-back path is exercised rather than assumed.
- The browser harness serves the real pages so the interface can be checked against a demo account.
