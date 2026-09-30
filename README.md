# Zactonz Cron Doctor

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg) ![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)

A cPanel plugin that tells you whether your cron jobs actually run.

cPanel gives you a raw crontab and mails you whatever a job prints. That is enough to add a job and not much
else. It will not tell you that a job has been failing since March, that two copies are running on top of each
other, that a job calls `/usr/bin/php` instead of the PHP version the site is set to, or that WordPress cron is
being triggered twice.

Cron Doctor wraps each job you choose in a small runner that takes a lock, applies an optional time limit,
captures the output and records the exit code and duration. It then shows the last run, alerts you when a job
fails or does not run at all, and offers one-click fixes for the common mistakes. The original crontab line is
kept, so monitoring can always be undone.

Documentation: [developers.zactonz.com/cpanel-whm/cron-doctor](https://developers.zactonz.com/cpanel-whm/cron-doctor/)

## What it does

- **Records every run** of a monitored job: exit code, duration, and the output, with a size limit.
- **Stops overlapping runs.** A job will not start while the previous run is still going. Skipped runs are
  recorded, so an overlap is visible rather than silent.
- **Mails you only when something is wrong.** Cron normally mails you after every run that prints anything.
  A monitored job stays quiet on success and reports its captured output when it fails.
- **Notices when a job has not run at all**, which cron itself can never tell you, and emails you about it.
- **Fixes the PHP path.** It finds jobs calling `php` or `/usr/bin/php` and offers to switch them to the
  EasyApache binary the site is actually set to use.
- **Finds the usual problems**: duplicated WordPress cron, WordPress still running its own scheduler
  alongside a real cron job, scripts that no longer exist, output thrown away to `/dev/null`, an empty
  `MAILTO`, Windows line endings, passwords on the command line, and jobs that take nearly as long as the
  gap between runs.
- **Keeps a way back.** The crontab is backed up before every change, every change is logged, and any backup
  can be restored in one click.

## Requirements

| Component | Minimum |
|---|---|
| Operating system | Any that cPanel itself supports: AlmaLinux OS, CloudLinux, Rocky Linux or Ubuntu |
| cPanel & WHM | 102 or newer, Jupiter theme |
| PHP | 7.4 or newer for the interface, with `mbstring` and `json` |
| Shell | Any POSIX shell for the runner; `/bin/sh` is used by default |
| Access | Root, over SSH or WHM Terminal, to install |

There is no operating-system-specific code in the plugin. It needs a POSIX shell and PHP, and uses `flock`,
`timeout` and `setsid` when they are present. If cPanel runs on the server, so does this. cPanel's own
[system requirements](https://docs.cpanel.net/installation-guide/system-requirements/) are the authority on
which distributions and versions are currently supported.

Verified on cPanel 11.138 (x86_64).

The interface needs PHP's `proc_open` to be available to change a crontab. Where it is not, Cron Doctor runs
in read-only mode: every check still runs and every finding explains what to change by hand, but nothing is
written.

`flock`, `timeout` and `setsid` are used when present and are not required. What the plugin does without them
is described in [docs/behaviour.md](docs/behaviour.md).

The runner is checked with `shellcheck` and parsed by both `dash` and `bash` on every commit, and the test
suite runs against PHP 7.4 through 8.3.

## Install

Run as `root`:

```bash
cd /root && curl -fsSL https://github.com/zactonz/cron-doctor/releases/latest/download/cron-doctor.tar.gz | tar -xz && bash cron-doctor/install.sh
```

That always fetches the current release. To check the download before running it:

```bash
cd /root && curl -fsSLO https://github.com/zactonz/cron-doctor/releases/latest/download/cron-doctor.tar.gz && curl -fsSLO https://github.com/zactonz/cron-doctor/releases/latest/download/cron-doctor.tar.gz.sha256 && sha256sum -c cron-doctor.tar.gz.sha256 && tar -xzf cron-doctor.tar.gz && bash cron-doctor/install.sh
```

The installer copies the plugin into cPanel's Jupiter theme directory, registers it, and restarts the cPanel
interface. Log in to any cPanel account and look for **Cron Doctor** under **Advanced**. If the icon does not
appear straight away, log out and back in so the theme cache refreshes.

To install from a clone instead:

```bash
git clone https://github.com/zactonz/cron-doctor.git && cd cron-doctor && bash install.sh
```

### Update

Download the new release and run `install.sh` again. It replaces the plugin files and removes any left by the
previous version. Monitored jobs, recorded runs and settings live in each account's home directory and are
untouched. The runner in each home directory is refreshed automatically the next time that account opens Cron
Doctor.

### Uninstall

```bash
bash uninstall.sh
```

This removes the plugin and its cPanel registration. **It does not change any crontab.** Jobs that were being
monitored keep working, because the runner lives in the account's own home directory rather than in the plugin
directory. To put the original crontab lines back, use **Stop monitoring** on each job before uninstalling.

## How monitoring works

A cron line like this:

```
*/5 * * * * /usr/bin/php /home/demo/public_html/cron.php >/dev/null 2>&1
```

becomes this:

```
*/5 * * * * /home/demo/.zactonz/zcd/bin/zcd-run 4f2c9a10b7d3e185
```

The command itself is not rewritten into the crontab. It is written verbatim to
`~/.zactonz/zcd/jobs/4f2c9a10b7d3e185.cmd` and executed from that file. This matters for three reasons:

1. **No quoting or escaping problems.** The command never passes through another round of shell quoting.
2. **No `%` corruption.** Cron turns an unescaped `%` in a command into a newline. Because the wrapped line
   contains no `%`, a command containing `date +%Y` cannot be damaged by being monitored. Any standard input
   supplied after a `%` is preserved and fed to the job exactly as cron would.
3. **Nothing is interpreted twice.** The runner executes a file, never a string it has assembled, so there is
   no second round of shell evaluation for anything to be injected into.

The schedule stays in the crontab, so changing it in cPanel's own **Cron Jobs** page keeps working, and
unwrapping a job uses whatever schedule is current rather than restoring an old one.

## The checks

| Check | Severity | What it means |
|---|---|---|
| `crontab.unreadable-line` | Needs attention | A line is not a comment, a setting or a cron job. Cron usually refuses to load the whole crontab, silently stopping every job in it. |
| `crontab.carriage-returns` | Needs attention | The crontab has Windows line endings. Cron passes the carriage return to the shell and the command fails. |
| `target.missing` | Needs attention | A path in the command does not exist in the account. |
| `run.failing` | Needs attention | The last run, or the last several, exited non-zero. |
| `run.overdue` | Needs attention | The job was due and there is no record of it running. |
| `mail.disabled` | Needs attention | `MAILTO` is empty, so cron will never send mail. |
| `environment.permissions` | Needs attention | Other users can read the Cron Doctor folder, which holds captured output. |
| `interpreter.generic` | Worth fixing | The job runs `php` or `/usr/bin/php` rather than the site's EasyApache binary. |
| `wordpress.duplicate` | Worth fixing | More than one line drives WordPress cron for the same installation. |
| `wordpress.internal-cron` | Worth fixing | A real cron job drives WordPress cron but `DISABLE_WP_CRON` is not set, so tasks run twice. |
| `output.discarded` | Worth fixing | Everything the job prints, errors included, goes to `/dev/null`. |
| `run.overlapping` | Worth fixing | Runs are being skipped because the previous one is still going. |
| `run.timeout` | Worth fixing | The last run was stopped at its time limit. |
| `run.orphans` | Worth fixing | A stopped job may have left background work running on this server. |
| `command.quotes` | Worth fixing | The command has a quote that is never closed. |
| `crontab.duplicate` | Worth fixing | The same schedule and command appear twice. |
| `environment.read-only` | Worth fixing | Commands cannot be run, so nothing will be written. |
| `environment.runner-stale` | Worth fixing | The runner in the home directory does not match the installed plugin. |
| `interpreter.mismatch` | Suggestion | The job uses a different PHP version from the site it belongs to. |
| `wordpress.frequent` | Suggestion | WordPress cron is triggered more often than most sites need. |
| `output.recoverable` | Suggestion | Output is still being discarded and could be captured. |
| `run.slow` | Suggestion | A run takes nearly as long as the gap between runs. |
| `command.secret` | Suggestion | A password or token appears on the command line. |
| `schedule.reboot` | Suggestion | `@reboot` has no next scheduled time, so it cannot be judged overdue. |
| `schedule.unreadable` | Worth fixing | The schedule uses syntax Cron Doctor does not parse. |
| `job.unmonitored` | Suggestion | Jobs that are not being recorded. |
| `environment.timeout` | Suggestion | Time limits cannot be enforced cleanly on this server. |

## Where things are kept

The repository and the documentation use the name `cron-doctor`; the installed plugin and everything it
writes on a server use the short form `zcd`. That keeps the line it puts in a crontab readable, because
account owners see that line in cPanel's own Cron Jobs page.

Everything belonging to an account lives in `~/.zactonz/zcd`, created with owner-only permissions:

```
bin/zcd-run           the runner, executed by cron
bin/zcd-sentinel      the alert check, executed by cron
lib/                  a copy of the library the alert check needs
jobs/<id>.cmd         the command, verbatim
jobs/<id>.conf        the settings for that job
jobs/<id>.in          standard input for that job, when it has any
runs/<id>/            one JSON record and one output file per run
state/<id>.json       the last result and the current failure streak
backups/              a copy of the crontab from before each change
audit.log             every change made through the interface
config.json           the alert address and reminder interval
```

Nothing is stored outside the account's home directory, and none of it is inside a document root.

## Security

The full model is in [docs/security.md](docs/security.md). In short:

- Cron Doctor runs as the cPanel user and grants no privilege that user does not already have. Anything the
  runner executes, the account could already have put in its own crontab.
- The crontab is read with `crontab -l` or, where commands cannot be run, with the cPanel API. It is only ever
  written by installing a complete file with the `crontab` command, invoked through `proc_open` with an
  argument list rather than a shell string.
- The API 2 `Cron` functions are not used for writes. `Cron::remove_line` takes a single `line` integer whose
  meaning is ambiguous between a file line number and a command number, and deleting the wrong cron job is not
  a recoverable mistake.
- Every write is read back and compared with what was intended. A mismatch restores the previous version and
  reports it. If the restore also fails, the message names the backup file.
- A write is refused if the crontab changed after the page was loaded, so two tabs or a concurrent edit in
  cPanel's own Cron Jobs page cannot silently overwrite each other.
- The runner refuses to run if its directory or command file is not owned by the running user, or is writable
  by group or other.
- Captured output is written with owner-only permissions, never served as a file, and escaped when displayed.
  Passwords and tokens are masked in the command shown in the interface.

## Development

The plugin has no runtime dependencies. Tests need PHP 8.0 or newer and `shellcheck`.

```bash
bash tools/lint.sh
```

That runs, in order: PHP syntax over every file, `shellcheck` plus `dash` and `bash` parsing of every shell
script, the source policy check, the PHP test suite, and the runner's own tests.

```bash
php tests/run.php            # PHP tests, optionally filtered: php tests/run.php Cron
sh tests/shell/runner-tests.sh   # the runner, exercised as a real process
php tools/build-css.php      # rebuild assets/zcd.css from the readable source
php tools/lint.php           # source policy only
```

The runner's tests adapt to the machine they run on. Where `flock`, `timeout` or `setsid` is missing they
assert the degraded behaviour the runner actually promises and skip what cannot be tested, reporting the skip
rather than passing quietly. `ZCD_TEST_NO_TOOLS=1` stops the harness borrowing those commands from Homebrew,
so the minimal-server path can be exercised on a developer machine:

```bash
env -i HOME="$HOME" PATH=/usr/bin:/bin ZCD_TEST_NO_TOOLS=1 sh tests/shell/runner-tests.sh
```

The source policy check enforces the house standard: no comments anywhere in shipped code, no inline style
attributes, no `eval`, `unserialize`, `system`, `exec`, `shell_exec`, `passthru` or backticks, and a
stylesheet that matches its readable source. Explanation belongs here and in `docs/`, not in the code.

### Trying the interface without a cPanel server

```bash
php -S 127.0.0.1:8733 -t . tests/harness/serve.php
```

This serves the real pages against a demo account in the system temporary directory, with a stub cPanel
LiveAPI and a fake `crontab` command. Visit `/harness/reset` to rebuild the demo account. The harness is the
only thing that defines the `ZCD_CPANEL_LIVEAPI`, `ZCD_CRONTAB_BINARY` and `ZCD_PHP_ROOT` constants; on a real
server they are unset and the defaults apply.

## Contributing

Bug reports and pull requests are welcome. Please run `bash tools/lint.sh` before opening one. Read the code
before running it on a production server; it is provided as is, without warranty.

## License

[Apache License 2.0](LICENSE). Use it on your own servers, modify it, and redistribute or white-label it under
the terms of that license.

Built by [Zactonz Technologies](https://zactonz.com).
