<?php

use Zactonz\CronDoctor\View\Html;
use Zactonz\CronDoctor\View\ScheduleDescriber;

?>
<div class="zcd">
    <p class="zcd-breadcrumb"><a href="index.live.php">&larr; All cron jobs</a></p>

    <header class="zcd-masthead">
        <div>
            <h1><?= Html::text($job->name()) ?></h1>
            <p>
                <span class="zcd-pill zcd-pill-<?= Html::attribute($presenter->statusTone($status)) ?>">
                    <?= Html::text($presenter->statusLabel($status)) ?>
                </span>
                Monitored since <?= Html::text($presenter->moment($job->createdAt())) ?>.
            </p>
        </div>
        <div class="zcd-actions">
            <?php if ($writable): ?>
                <form class="zcd-inline-form" method="post" action="actions.php">
                    <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                    <input type="hidden" name="action" value="run-now">
                    <input type="hidden" name="job" value="<?= Html::attribute($job->id()) ?>">
                    <button type="submit" class="zcd-btn zcd-btn-primary">Run now</button>
                </form>
            <?php endif ?>
        </div>
    </header>

    <?= $template->render('flash', ['flash' => $flash]) ?>

    <?php if ($entry !== null && $entry->findings() !== []): ?>
        <?= $template->render('findings', [
            'findings' => $entry->findings(),
            'token' => $token,
            'fingerprint' => $fingerprint,
            'writable' => $writable,
        ]) ?>
    <?php endif ?>

    <section class="zcd-card">
        <div class="zcd-card-head">
            <div><h2>What runs</h2></div>
        </div>
        <div class="zcd-card-body">
            <dl class="zcd-definitions">
                <dt>Command</dt>
                <dd><code class="zcd-code"><?= Html::text(Html::maskSecrets($job->command())) ?></code></dd>

                <?php if ($job->input() !== null): ?>
                    <dt>Standard input</dt>
                    <dd><code class="zcd-code"><?= Html::text($job->input()) ?></code></dd>
                <?php endif ?>

                <dt>Schedule</dt>
                <dd>
                    <?= Html::text(ScheduleDescriber::describe($schedule)) ?>
                    <code class="zcd-command"><?= Html::text($schedule) ?></code>
                </dd>

                <?php if ($nextRuns !== []): ?>
                    <dt>Next runs</dt>
                    <dd>
                        <?php foreach ($nextRuns as $run): ?>
                            <div><?= Html::text($run->format('j M Y, H:i')) ?></div>
                        <?php endforeach ?>
                    </dd>
                <?php endif ?>

                <dt>Crontab line</dt>
                <dd><code class="zcd-code"><?= Html::text($crontabLine) ?></code></dd>

                <dt>Original line</dt>
                <dd><code class="zcd-code"><?= Html::text(Html::maskSecrets($job->originalLine())) ?></code></dd>
            </dl>

            <?php if ($writable && $commandChanged): ?>
                <p>
                    <form class="zcd-inline-form" method="post" action="actions.php">
                        <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                        <input type="hidden" name="action" value="restore-command">
                        <input type="hidden" name="job" value="<?= Html::attribute($job->id()) ?>">
                        <button type="submit" class="zcd-btn">Restore the original command</button>
                    </form>
                </p>
            <?php endif ?>
        </div>
    </section>

    <section class="zcd-card">
        <div class="zcd-card-head">
            <div>
                <h2>Recent runs<span class="zcd-count"><?= count($runs) ?></span></h2>
                <p>The newest <?= (int) $job->retainRuns() ?> runs are kept.</p>
            </div>
            <?php if ($writable && $runs !== []): ?>
                <form class="zcd-inline-form" method="post" action="actions.php">
                    <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                    <input type="hidden" name="action" value="purge-output">
                    <input type="hidden" name="job" value="<?= Html::attribute($job->id()) ?>">
                    <button type="submit" class="zcd-btn zcd-btn-danger">Delete stored output</button>
                </form>
            <?php endif ?>
        </div>

        <?php if ($runs === []): ?>
            <p class="zcd-empty">No runs recorded yet. The next scheduled run will appear here.</p>
        <?php else: ?>
            <div class="zcd-table-wrap">
                <table class="zcd-table">
                    <thead>
                        <tr>
                            <th scope="col">Started</th>
                            <th scope="col">Took</th>
                            <th scope="col">Exit</th>
                            <th scope="col">Output</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $index => $run): ?>
                            <tr>
                                <td class="zcd-nowrap">
                                    <?= Html::text($presenter->moment($run->startedAt())) ?>
                                    <code class="zcd-command"><?= Html::text($presenter->relative($run->startedAt())) ?></code>
                                </td>
                                <td class="zcd-nowrap"><?= Html::text($presenter->duration($run->durationMs())) ?></td>
                                <td class="zcd-nowrap">
                                    <?php $tone = $run->skipped() ? 'idle' : ($run->succeeded() ? 'good' : 'bad') ?>
                                    <span class="zcd-pill zcd-pill-<?= Html::attribute($tone) ?>">
                                        <?= Html::text($presenter->exitCode($run->exitCode(), $run->timedOut(), $run->skipped())) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($run->hasOutput()): ?>
                                        <button type="button" class="zcd-run-toggle" data-zcd-toggle="zcd-run-<?= (int) $index ?>">
                                            Show <?= Html::text($presenter->bytes($run->outputBytes())) ?>
                                        </button>
                                        <?php if ($run->truncated()): ?>
                                            <code class="zcd-command">truncated</code>
                                        <?php endif ?>
                                    <?php else: ?>
                                        <span class="zcd-command">no output</span>
                                    <?php endif ?>
                                </td>
                            </tr>
                            <?php if ($run->hasOutput()): ?>
                                <tr class="zcd-hidden" id="zcd-run-<?= (int) $index ?>">
                                    <td colspan="4">
                                        <pre class="zcd-output"><?= Html::output((string) $outputs[$run->sequence()]) ?></pre>
                                    </td>
                                </tr>
                            <?php endif ?>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>

    <section class="zcd-card">
        <div class="zcd-card-head">
            <div><h2>Settings</h2></div>
        </div>
        <div class="zcd-card-body">
            <form method="post" action="actions.php">
                <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                <input type="hidden" name="action" value="save-settings">
                <input type="hidden" name="job" value="<?= Html::attribute($job->id()) ?>">

                <div class="zcd-grid">
                    <div class="zcd-field">
                        <label for="zcd-name">Name</label>
                        <input class="zcd-input" type="text" id="zcd-name" name="name" maxlength="80"
                               value="<?= Html::attribute($job->name()) ?>">
                        <span class="zcd-field-help">Shown in this interface and in failure email.</span>
                    </div>

                    <div class="zcd-field">
                        <label for="zcd-timeout">Time limit in seconds</label>
                        <input class="zcd-input" type="number" id="zcd-timeout" name="timeout_seconds" min="0"
                               max="86400" value="<?= (int) $job->timeoutSeconds() ?>">
                        <span class="zcd-field-help">0 means no limit, which is how cron behaves on its own.</span>
                    </div>

                    <div class="zcd-field">
                        <label for="zcd-overlap">When the previous run is still going</label>
                        <select class="zcd-select" id="zcd-overlap" name="on_overlap">
                            <option value="skip" <?= $job->onOverlap() === 'skip' ? 'selected' : '' ?>>Skip this run</option>
                            <option value="allow" <?= $job->onOverlap() === 'allow' ? 'selected' : '' ?>>Let both run</option>
                        </select>
                        <span class="zcd-field-help">Skipping prevents two copies of the same job running at once.</span>
                    </div>

                    <div class="zcd-field">
                        <label for="zcd-emit">Send output to cron email</label>
                        <select class="zcd-select" id="zcd-emit" name="emit">
                            <option value="on-failure" <?= $job->emit() === 'on-failure' ? 'selected' : '' ?>>Only when it fails</option>
                            <option value="always" <?= $job->emit() === 'always' ? 'selected' : '' ?>>Every run</option>
                            <option value="never" <?= $job->emit() === 'never' ? 'selected' : '' ?>>Never</option>
                        </select>
                        <span class="zcd-field-help">Cron only sends mail when a job prints something.</span>
                    </div>

                    <div class="zcd-field">
                        <label for="zcd-retain">Runs to keep</label>
                        <input class="zcd-input" type="number" id="zcd-retain" name="retain_runs" min="1" max="200"
                               value="<?= (int) $job->retainRuns() ?>">
                    </div>

                    <div class="zcd-field">
                        <label for="zcd-output-limit">Output kept per run in bytes</label>
                        <input class="zcd-input" type="number" id="zcd-output-limit" name="output_limit_bytes"
                               min="1024" max="4194304" step="1024" value="<?= (int) $job->outputLimitBytes() ?>">
                        <span class="zcd-field-help">Longer output is trimmed from the middle.</span>
                    </div>
                </div>

                <button type="submit" class="zcd-btn zcd-btn-primary" <?= $writable ? '' : 'disabled' ?>>Save settings</button>
            </form>
        </div>
    </section>

    <?php if ($writable): ?>
        <section class="zcd-card">
            <div class="zcd-card-head">
                <div>
                    <h2>Stop monitoring</h2>
                    <p>Puts the original crontab line back exactly as it was and forgets the recorded runs.</p>
                </div>
                <form class="zcd-inline-form" method="post" action="actions.php">
                    <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="job" value="<?= Html::attribute($job->id()) ?>">
                    <button type="submit" class="zcd-btn zcd-btn-danger"
                            data-zcd-confirm="Put the original crontab line back for <?= Html::attribute($job->name()) ?>?">
                        Stop monitoring
                    </button>
                </form>
            </div>
        </section>
    <?php endif ?>
</div>
