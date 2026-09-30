# Reporting a security issue

If you believe you have found a security problem in Zactonz Cron Doctor, please report it privately
to **security@zactonz.com** rather than opening a public issue.

Please include:

- the version of the plugin, from the footer of any Cron Doctor page or the `version` file in
  `~/.zactonz/zcd/`;
- the cPanel and operating system versions;
- what an attacker would be able to do, and the steps to reproduce it.

You will get an acknowledgement within three working days. Once a fix is ready, a release is published and
the advisory is added to the changelog with credit, unless you prefer otherwise.

## Scope

In scope: anything that lets one account read or change another account's crontab, captured output or Cron
Doctor state; anything that lets a cPanel user run commands as another user or as root; anything that
corrupts a crontab in a way the plugin does not detect and roll back.

Out of scope: the fact that a cPanel user can run arbitrary commands from their own crontab. That is what
cron is for, and Cron Doctor deliberately grants no privilege the account does not already have. See
[docs/security.md](docs/security.md) for the full model.
