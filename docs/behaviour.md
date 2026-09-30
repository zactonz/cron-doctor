# Behaviour and edge cases

Decisions that are not obvious from the source, and what the plugin does when the environment is missing
something.

## What changes when a job is monitored

Wrapping a job changes three things and nothing else.

1. **Overlapping runs are skipped by default.** Cron will happily start a second copy of a job while the
   first is still running. A monitored job takes a lock first, and a run that cannot take the lock is recorded
   as skipped rather than started. This can be switched to "let both run" per job.
2. **Cron email arrives only on failure.** Cron mails you whenever a job prints anything. A monitored job
   prints nothing when it succeeds and prints the captured output when it fails, so you get mail when
   something is wrong instead of after every run. This is per-job and can be set to every run or never.
3. **The exit code is preserved.** Whatever the command exits with is what the runner exits with, except that
   a job stopped at its time limit exits 124, following the convention of the `timeout` command.

A job whose command already ends in `>/dev/null 2>&1` keeps producing no output, so wrapping it changes
nothing about what is mailed. It still gains an exit code, a duration and overdue detection. Recovering that
output is a separate, explicit action.

## Removing a redirection

"Capture the output" is only offered when the command ends in a redirection that Cron Doctor can prove is
safe to remove. The command is tokenised with a quote-aware scanner, and only the trailing redirections are
considered, and only when every one of them targets `/dev/null`.

The file descriptor assignments are then simulated in order, which is why `cmd >/dev/null 2>&1` is understood
to discard both streams while `cmd 2>&1 >/dev/null` is understood to discard only standard output, leaving
errors going to cron. A redirection inside quotes, such as `echo "done > /dev/null"`, is never treated as a
redirection. A command with an unbalanced quote is never rewritten.

Once a job is monitored, removing the redirection does not touch the crontab at all: the command lives in the
job's own file. The same is true of the PHP path fix. That is deliberate — every fix after the first one
avoids the crontab entirely.

## Cron's `%` rule

In a crontab, the first unescaped `%` in a command ends the command; everything after it becomes the job's
standard input, with each further `%` turned into a newline. `\%` is a literal percent sign.

Cron Doctor implements this exactly. A command such as `/usr/bin/date +\%Y` is understood as `date +%Y`, and
`report.sh%first%second` is understood as running `report.sh` with `"first\nsecond"` on standard input. When a
job is monitored, the command and its standard input are stored separately and the input is fed to the job
from a file, so the behaviour is preserved.

Because the wrapped crontab line contains no `%` at all, monitoring a job with a `%` in its command is safe.

Rewriting a line in place is checked before it is written: the new command and input are encoded, decoded
again, and compared with what was intended. If cron's syntax cannot represent them exactly, the change is
refused rather than written. In practice this cannot be reached from a command that was parsed out of a real
crontab, because any such pair is by definition representable; the check exists so that a future caller
cannot introduce a silent corruption.

## Schedules

The parser follows Vixie cron, which is what cronie implements.

- Fields accept `*`, numbers, `a-b` ranges, `*/n` and `a-b/n` steps, and comma-separated lists. Month and
  weekday accept three-letter names. Sunday is both `0` and `7`.
- The day-of-month and day-of-week fields are combined the way cron combines them: if either field starts
  with `*`, both must match; otherwise either matching is enough. So `0 0 1 * 1` runs on the first of the
  month **and** on every Monday, while `0 0 1 * *` runs only on the first.
- `@yearly`, `@annually`, `@monthly`, `@weekly`, `@daily`, `@midnight` and `@hourly` are expanded.
- `@reboot` is recognised but has no next scheduled time, so such a job is never reported as overdue.
- Syntax the parser does not accept is reported as unreadable rather than guessed at. The job is still listed
  and monitoring still records every run; only the "overdue" judgement is unavailable.

Searching for the next or previous run walks calendar components rather than timestamps, and gives up after a
bounded number of steps, so an impossible schedule such as `0 0 30 2 *` returns nothing instead of looping.

### Daylight saving

Times are interpreted in the server's time zone.

- When the clocks go forward and a scheduled time does not exist, the run resolves to the next real instant.
  A job scheduled at 02:30 on a day where 02:00 becomes 03:00 is reported at 03:30.
- When the clocks go back and a time happens twice, it is counted once.

Both are covered by tests.

## Overdue detection

A job is overdue when all of these hold: it is monitored; its schedule can be parsed; the most recent
scheduled time has passed by more than the grace period; there is no recorded run at or after that scheduled
time; and the job was already being monitored when that scheduled time passed.

The grace period is a fifth of the interval between runs, with a floor of two minutes and a ceiling of an
hour. The last condition is what stops a job reporting as overdue the moment it is first monitored.

## Time limits

Time limits are off by default, because adding one changes behaviour: a job that used to run for an hour
would start being killed.

When a limit is set, the runner enforces it in the best way available:

1. `timeout` if it is installed. It places the job in its own process group and signals the group, so
   background work the job started is stopped too.
2. Otherwise `setsid`, with a watchdog that signals the process group.
3. Otherwise the job process alone is signalled, and the run is recorded with `orphans_possible` set so the
   interface can say that background work may have survived.

In all three cases the job gets SIGTERM, then SIGKILL five seconds later if it is still there, and the run is
recorded with exit code 124.

The watchdog does not poll the job. The runner blocks on `wait`, and a separate process writes a flag file if
the limit is reached. A job with no limit adds no measurable overhead, and a job with one adds none until the
limit is reached.

## Locking

`flock` is used when it is installed, because the kernel releases the lock when the process dies and a stale
lock is therefore impossible. Otherwise the runner creates a lock directory atomically with `mkdir` and writes
its process id inside; a later run that finds the directory checks whether that process is still alive and
breaks the lock if it is not. The limits of that fallback are described in [security.md](security.md).

## Durations

Durations are measured in milliseconds where `date +%s%N` is available, and in whole seconds otherwise, which
is the case on systems with a BSD `date`. A sub-second job on such a system is recorded as taking 0 ms. Both
paths are covered by tests.

## Read-only mode

Where PHP cannot run commands, or `crontab` is not present, Cron Doctor reads the crontab through the cPanel
API and never writes. Every check still runs and every finding still explains the problem; the buttons that
would change something are not shown. This is reported at the top of the dashboard rather than left to be
discovered.

## Uninstalling

Removing the plugin does not change any crontab, and monitored jobs keep running, because the runner lives in
each account's home directory rather than in the plugin directory. That is the reason for putting it there:
under CloudLinux CageFS the plugin directory is not visible to the account's cron jobs at all, and a runner
that disappeared with the plugin would stop every wrapped job on the server.
