<?php

use Zactonz\CronDoctor\View\Html;

?>
<div class="zcd">
    <p class="zcd-breadcrumb"><a href="index.live.php">&larr; All cron jobs</a></p>

    <header class="zcd-masthead">
        <div>
            <h1>Settings</h1>
            <p>What Cron Doctor can do on this account, and a way back if a change goes wrong.</p>
        </div>
    </header>

    <?= $template->render('flash', ['flash' => $flash]) ?>

    <section class="zcd-card">
        <div class="zcd-card-head">
            <div><h2>This account</h2></div>
        </div>
        <div class="zcd-card-body">
            <dl class="zcd-definitions">
                <dt>Crontab access</dt>
                <dd>
                    <?php if ($writable): ?>
                        Read and write, using <code><?= Html::text($crontabBinary) ?></code>
                    <?php else: ?>
                        Read only<?= $crontabSource === 'cpanel' ? ', through the cPanel API' : '' ?>
                    <?php endif ?>
                </dd>

                <dt>Cron Doctor folder</dt>
                <dd><code><?= Html::text($baseDirectory) ?></code></dd>

                <dt>Runner</dt>
                <dd>
                    <?= $runnerVersion === null ? 'not installed' : Html::text('version ' . $runnerVersion) ?>
                    <?= $runnerCurrent ? '' : ' (out of date)' ?>
                </dd>

                <dt>Overlap protection</dt>
                <dd>
                    <?php if (($capabilities['flock'] ?? 'none') === 'none'): ?>
                        Directory locks, because the flock command is not available here
                    <?php else: ?>
                        File locks, using <code><?= Html::text($capabilities['flock']) ?></code>
                    <?php endif ?>
                </dd>

                <dt>Time limits</dt>
                <dd>
                    <?php if ($capabilities === []): ?>
                        not reported
                    <?php elseif (($capabilities['timeout_is_reliable'] ?? '1') === '0'): ?>
                        Neither the timeout command nor setsid is available, so a job stopped at its time limit may
                        leave background work running
                    <?php elseif (($capabilities['timeout'] ?? 'none') !== 'none'): ?>
                        Enforced with <code><?= Html::text($capabilities['timeout']) ?></code>
                    <?php else: ?>
                        Enforced with <code><?= Html::text($capabilities['setsid'] ?? 'setsid') ?></code>
                    <?php endif ?>
                </dd>

                <dt>PHP versions found</dt>
                <dd>
                    <?php if ($phpVersions === []): ?>
                        none
                    <?php else: ?>
                        <?= Html::text(implode(', ', array_keys($phpVersions))) ?>
                    <?php endif ?>
                </dd>

                <dt>Server time zone</dt>
                <dd><?= Html::text($timezone) ?></dd>
            </dl>

            <?php if ($writable): ?>
                <p>
                    <form class="zcd-inline-form" method="post" action="actions.php">
                        <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                        <input type="hidden" name="action" value="repair-runner">
                        <button type="submit" class="zcd-btn">Reinstall the runner</button>
                    </form>
                </p>
            <?php endif ?>
        </div>
    </section>

    <section class="zcd-card">
        <div class="zcd-card-head">
            <div>
                <h2>Alerts</h2>
                <p>
                    Cron Doctor mails you when a monitored job fails or has not run when it should have. Failures also
                    reach you through cron's own email whenever a job prints something, but only Cron Doctor can tell
                    that a job did not run at all.
                </p>
            </div>
        </div>
        <div class="zcd-card-body">
            <form method="post" action="actions.php">
                <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                <input type="hidden" name="action" value="save-alerts">

                <div class="zcd-grid">
                    <div class="zcd-field">
                        <label for="zcd-notify">Send alerts to</label>
                        <input class="zcd-input" type="email" id="zcd-notify" name="notify_email" maxlength="254"
                               value="<?= Html::attribute($notifyAddress) ?>" placeholder="you@example.com">
                        <span class="zcd-field-help">Leave this empty to switch alerts off.</span>
                    </div>

                    <div class="zcd-field">
                        <label for="zcd-repeat">Remind me again after</label>
                        <input class="zcd-input" type="number" id="zcd-repeat" name="repeat_hours" min="1" max="336"
                               value="<?= (int) $repeatHours ?>">
                        <span class="zcd-field-help">Hours to wait before repeating an alert for the same problem.</span>
                    </div>
                </div>

                <button type="submit" class="zcd-btn zcd-btn-primary" <?= $writable ? '' : 'disabled' ?>>Save alerts</button>
            </form>

            <p class="zcd-field-help">
                <?php if ($sentinelInstalled): ?>
                    A check runs every fifteen minutes from this crontab line:
                    <code class="zcd-command"><?= Html::text($sentinelSchedule . ' ' . $sentinelPath) ?></code>
                <?php else: ?>
                    No alert check is installed yet. Saving an address adds one line to your crontab; clearing the
                    address removes it again.
                <?php endif ?>
            </p>
        </div>
    </section>

    <section class="zcd-card">
        <div class="zcd-card-head">
            <div>
                <h2>Crontab backups<span class="zcd-count"><?= count($backups) ?></span></h2>
                <p>A copy is saved before every change Cron Doctor makes. The newest thirty are kept.</p>
            </div>
        </div>

        <?php if ($backups === []): ?>
            <p class="zcd-empty">No backups yet. One is written the first time Cron Doctor changes the crontab.</p>
        <?php else: ?>
            <div class="zcd-table-wrap">
                <table class="zcd-table">
                    <thead>
                        <tr>
                            <th scope="col">Taken</th>
                            <th scope="col">Size</th>
                            <th scope="col">File</th>
                            <th scope="col"><span class="zcd-sr">Restore</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td class="zcd-nowrap"><?= Html::text($presenter->moment($backup['time'])) ?></td>
                                <td class="zcd-nowrap"><?= Html::text($presenter->bytes($backup['bytes'])) ?></td>
                                <td><code class="zcd-command"><?= Html::text($backup['name']) ?></code></td>
                                <td>
                                    <?php if ($writable): ?>
                                        <form class="zcd-inline-form" method="post" action="actions.php">
                                            <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                                            <input type="hidden" name="action" value="restore-backup">
                                            <input type="hidden" name="name" value="<?= Html::attribute($backup['name']) ?>">
                                            <button type="submit" class="zcd-btn"
                                                    data-zcd-confirm="Replace the current crontab with this backup?">
                                                Restore
                                            </button>
                                        </form>
                                    <?php endif ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>

    <section class="zcd-card">
        <div class="zcd-card-head">
            <div>
                <h2>Stored output</h2>
                <p>
                    Captured output is kept in your home directory, readable only by you. It can contain anything your
                    jobs print, so delete it if you no longer need it.
                </p>
            </div>
            <?php if ($writable): ?>
                <form class="zcd-inline-form" method="post" action="actions.php">
                    <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                    <input type="hidden" name="action" value="purge-output">
                    <input type="hidden" name="scope" value="all">
                    <button type="submit" class="zcd-btn zcd-btn-danger"
                            data-zcd-confirm="Delete the stored output for every monitored job?">
                        Delete all stored output
                    </button>
                </form>
            <?php endif ?>
        </div>
    </section>

    <section class="zcd-card">
        <div class="zcd-card-head">
            <div>
                <h2>Recent activity</h2>
                <p>Every change Cron Doctor made on this account.</p>
            </div>
        </div>
        <?php if ($audit === []): ?>
            <p class="zcd-empty">Nothing recorded yet.</p>
        <?php else: ?>
            <div class="zcd-table-wrap">
                <table class="zcd-table">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Action</th>
                            <th scope="col">Result</th>
                            <th scope="col">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit as $record): ?>
                            <tr>
                                <td class="zcd-nowrap"><?= Html::text(str_replace(['T', 'Z'], [' ', ' UTC'], $record['at'])) ?></td>
                                <td class="zcd-nowrap"><code class="zcd-command"><?= Html::text($record['action']) ?></code></td>
                                <td class="zcd-nowrap">
                                    <span class="zcd-pill zcd-pill-<?= $record['outcome'] === 'ok' ? 'good' : 'bad' ?>">
                                        <?= $record['outcome'] === 'ok' ? 'done' : 'refused' ?>
                                    </span>
                                </td>
                                <td><?= Html::text($record['message']) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>

    <p class="zcd-footnote">
        <?= Html::text($productName) ?> <?= Html::text($version) ?> ·
        <a href="<?= Html::attribute($homepage) ?>" target="_blank" rel="noreferrer noopener">Documentation</a>
    </p>
</div>
