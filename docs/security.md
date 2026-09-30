# Security model

This document states what Cron Doctor is trusted to do, what it refuses to do, and why the design is
arranged the way it is. It is written for somebody auditing the source.

## The principal

Everything in the cPanel interface runs as the cPanel user, under cpsrvd. The runner and the alert check run
as the same user, started by that user's own crontab. There is no root component after installation. The
installer is the only part that runs as root, and it only copies files into the Jupiter theme directory and
calls cPanel's own `install_plugin`.

## The central claim

**Cron Doctor grants no privilege the account does not already have.** A cPanel user can already put any
command in their own crontab and have cron run it as themselves. The runner executes commands from that same
account, as that account. Making the command easier to inspect does not widen what it can do.

This is why "a user can make their cron job run an arbitrary command" is out of scope as a vulnerability. It
is the feature cron provides. What would be in scope is one account affecting another, or an account gaining
privileges it did not have.

## Writing the crontab

Reading is done with `crontab -l`. Where PHP cannot run commands, reading falls back to the cPanel API 2
`Cron::fetchcron`, which takes no parameters and changes nothing.

Writing is done in exactly one way: the complete intended crontab is written to a private temporary file
inside the account's own state directory, and installed with `crontab <file>`, invoked through `proc_open`
with an argument array. No shell is involved at any point, so no argument can be interpreted as shell syntax.

### Why the cPanel API is not used for writes

cPanel API 2 offers `Cron::add_line`, `Cron::edit_line` and `Cron::remove_line`. They are not used, for three
reasons.

1. `Cron::remove_line` takes a single parameter, `line`, documented as "the line in the crontab file to
   remove". `Cron::fetchcron` returns both a `linekey` and a `commandnumber` for each entry, and the
   documentation does not say which of those `line` corresponds to. Deleting the wrong cron job is not a
   recoverable mistake, and a plugin should not resolve that ambiguity by guessing.
2. `Cron::add_line` accepts only discrete `minute`, `hour`, `day`, `month` and `weekday` fields, so it cannot
   express `@reboot` or any schedule syntax outside that shape, and cannot preserve a line it did not create.
3. Rewriting the file as a whole preserves `MAILTO`, `PATH`, `SHELL`, comments, blank lines and unusual
   syntax byte for byte, because untouched lines are copied through unchanged.

### Verify, then roll back

Every write follows the same sequence:

1. Read the crontab and compute a SHA-256 fingerprint of it.
2. Refuse if the fingerprint does not match the one the page was rendered with. This is what stops two
   browser tabs, or a concurrent edit in cPanel's own Cron Jobs page, from silently overwriting each other.
3. Refuse if the change would remove every cron job, unless that was explicitly requested.
4. Save a copy of the current crontab to `backups/`.
5. Install the new crontab.
6. Read it back and compare every job and variable line with what was intended. Comment and blank lines are
   allowed to differ, because some versions of `crontab` add a header of their own.
7. On any mismatch, reinstall the previous version and report it. If that restore also fails to verify, the
   message names the backup file so it can be restored from the Backups section.

## The runner

The runner is a POSIX shell script. Before it will run anything it checks that its own directory, the jobs
directory and the job's command file are owned by the user running it and are not writable by group or other.
If any of those checks fail it refuses to run and exits 78, loudly, so cron mails the reason. This is the one
case where the runner deliberately does not run the job: executing a command file that another user can write
would be the one way Cron Doctor could turn an existing weakness into code execution.

Operational problems are treated differently from security problems. If the run history cannot be written,
the job still runs and the runner warns on standard error, because failing to record a run is not a reason to
stop the work.

The job identifier passed on the command line is validated against `^[0-9a-f]{16}$` before it is used to build
any path, so it cannot escape the jobs directory.

Settings are read with `grep` and parameter expansion, never sourced, so a crafted settings file cannot
execute anything. Numeric settings that are not digits fall back to their defaults. There are tests for both.

## Captured output

Output can contain anything a job prints, which in practice includes database errors, tokens and file paths.

- It is written under `umask 077` into a directory created with mode 0700.
- It is never served as a file. It is read by PHP and escaped before display.
- Invalid UTF-8 and control characters are stripped or substituted before escaping, so a job that emits binary
  cannot break the page.
- Each run's output is trimmed to a per-job limit, keeping the beginning and the end with a notice in between.
- Old runs are pruned to a per-job retention count.
- It can be deleted from the interface, per job or for the whole account.

Cron Doctor masks values that look like passwords or tokens in the command shown in the interface. That is a
convenience for screenshots and shoulder-surfing, not a security control: the crontab still contains the real
value, and so does the process list while the job runs. The interface says so.

## The interface

- Every change is a POST. Nothing that changes state can be triggered by a GET.
- Every form carries a token derived with HMAC-SHA-256 from the cPanel session identifier in the request URL
  and a 32-byte secret stored with owner-only permissions in the account's state directory. It is compared
  with `hash_equals`. This is defence in depth: cPanel's own session token in the URL path already prevents a
  cross-origin request from reaching the page at all.
- The PHP binary supplied with a "use the right PHP" fix is checked against the list of interpreters actually
  installed on the server before it is used, with `hash_equals`, rather than trusted from the request.
- Redirects after a POST are chosen from a fixed list of three pages. No URL from the request is ever used as
  a redirect target.
- All output is escaped with `htmlspecialchars` using `ENT_QUOTES | ENT_SUBSTITUTE`. There is no `innerHTML`
  in the JavaScript and no inline event handlers.
- Every change is appended to `audit.log`, which is shown in the interface and rotated at 256 KB.

## Known limits

- **Stale directory locks.** Where `flock` is not installed, locking falls back to an atomic `mkdir` with a
  PID inside. If a run is killed with SIGKILL the lock is left behind; the next run sees the PID is gone and
  breaks it. Two runs starting within the same instant could both break the same stale lock and both proceed.
  The result is one overlapping execution, which is what cron does anyway without Cron Doctor, so the failure
  mode is no worse than the status quo. Where `flock` is present, which is the normal case on Linux, the
  kernel releases the lock when the process dies and this cannot happen.
- **Orphans after a time limit.** Stopping a job that has started background work requires killing the whole
  process group. That needs either `timeout` or `setsid`. Where neither exists, the runner stops the job it
  started, records `orphans_possible` on that run, and the interface says so. Time limits are off by default.
- **Masking is not redaction.** See above.
- **The interface and cron may see different filesystems.** Under CloudLinux CageFS, the cPanel interface runs
  outside the jail and cron runs inside it. Paths inside the account's home directory look the same to both,
  so the "file is not there" check only considers those. System paths are not checked for existence, because
  the interface cannot see what the job will see.
