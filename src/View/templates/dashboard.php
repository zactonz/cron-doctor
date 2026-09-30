<?php

use Zactonz\CronDoctor\View\Html;
use Zactonz\CronDoctor\View\ScheduleDescriber;

?>
<div class="zcd">
    <header class="zcd-masthead">
        <div>
            <h1>Cron Doctor</h1>
            <p>
                Every cron job on this account, with the result of its last run. Monitoring wraps a job in a small
                runner that records what happened; the original crontab line is always kept.
            </p>
        </div>
        <div class="zcd-summary">
            <div class="zcd-stat">
                <span class="zcd-stat-value"><?= (int) $summary['total'] ?></span>
                <span class="zcd-stat-label">Jobs</span>
            </div>
            <div class="zcd-stat">
                <span class="zcd-stat-value"><?= (int) $summary['monitored'] ?></span>
                <span class="zcd-stat-label">Monitored</span>
            </div>
            <div class="zcd-stat <?= $summary['failing'] > 0 ? 'zcd-stat-bad' : '' ?>">
                <span class="zcd-stat-value"><?= (int) $summary['failing'] ?></span>
                <span class="zcd-stat-label">Failing</span>
            </div>
            <div class="zcd-stat <?= $summary['overdue'] > 0 ? 'zcd-stat-bad' : '' ?>">
                <span class="zcd-stat-value"><?= (int) $summary['overdue'] ?></span>
                <span class="zcd-stat-label">Overdue</span>
            </div>
        </div>
    </header>

    <?= $template->render('flash', ['flash' => $flash]) ?>

    <?php if (!$writable): ?>
        <div class="zcd-notice zcd-notice-info">
            Cron Doctor is in read-only mode on this account, so it will not change the crontab. Every check still
            runs and each finding explains what to change by hand in the Cron Jobs page.
        </div>
    <?php endif ?>

    <?= $template->render('findings', [
        'findings' => $findings,
        'token' => $token,
        'fingerprint' => $fingerprint,
        'writable' => $writable,
    ]) ?>

    <section class="zcd-card">
        <div class="zcd-card-head">
            <div>
                <h2>Cron jobs<span class="zcd-count"><?= count($entries) ?></span></h2>
                <p>Times are shown in the server time zone, <?= Html::text($timezone) ?>.</p>
            </div>
            <a class="zcd-btn" href="settings.live.php">Settings and backups</a>
        </div>

        <?php if ($entries === []): ?>
            <p class="zcd-empty">
                This account has no cron jobs yet. Add one in the Cron Jobs page and it will appear here.
            </p>
        <?php else: ?>
            <div class="zcd-table-wrap">
                <table class="zcd-table">
                    <thead>
                        <tr>
                            <th scope="col">Status</th>
                            <th scope="col">Job</th>
                            <th scope="col">Schedule</th>
                            <th scope="col">Last run</th>
                            <th scope="col">Took</th>
                            <th scope="col">Exit</th>
                            <th scope="col"><span class="zcd-sr">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $status = $entry->status($now);
                            $job = $entry->job();
                            $last = $entry->lastCompleted();
                            $command = $entry->command();
                            ?>
                            <tr>
                                <td>
                                    <span class="zcd-pill zcd-pill-<?= Html::attribute($presenter->statusTone($status)) ?>">
                                        <?= Html::text($presenter->statusLabel($status)) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="zcd-job-name">
                                        <?php if ($job !== null): ?>
                                            <a href="job.live.php?job=<?= Html::attribute($job->id()) ?>">
                                                <?= Html::text($entry->displayName()) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= Html::text($entry->displayName()) ?>
                                        <?php endif ?>
                                    </span>
                                    <code class="zcd-command"><?= Html::text(Html::shorten(Html::maskSecrets($command), 110)) ?></code>
                                </td>
                                <td>
                                    <?= Html::text(ScheduleDescriber::describe($entry->line()->schedule())) ?>
                                    <code class="zcd-command"><?= Html::text($entry->line()->schedule()) ?></code>
                                </td>
                                <td class="zcd-nowrap">
                                    <?= $last === null ? '—' : Html::text($presenter->relative($last->startedAt())) ?>
                                </td>
                                <td class="zcd-nowrap">
                                    <?= $last === null ? '—' : Html::text($presenter->duration($last->durationMs())) ?>
                                </td>
                                <td class="zcd-nowrap">
                                    <?= $last === null
                                        ? '—'
                                        : Html::text($presenter->exitCode($last->exitCode(), $last->timedOut(), $last->skipped())) ?>
                                </td>
                                <td>
                                    <div class="zcd-actions">
                                        <?php if ($job !== null): ?>
                                            <a class="zcd-btn" href="job.live.php?job=<?= Html::attribute($job->id()) ?>">Details</a>
                                        <?php elseif ($writable): ?>
                                            <form class="zcd-inline-form" method="post" action="actions.php">
                                                <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                                                <input type="hidden" name="action" value="monitor">
                                                <input type="hidden" name="line" value="<?= (int) $entry->line()->number() ?>">
                                                <button type="submit" class="zcd-btn zcd-btn-primary">Monitor</button>
                                            </form>
                                        <?php endif ?>
                                    </div>
                                </td>
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
