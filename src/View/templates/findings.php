<?php

use Zactonz\CronDoctor\View\Html;

?>
<section class="zcd-card">
    <div class="zcd-card-head">
        <div>
            <h2>What Cron Doctor found<span class="zcd-count"><?= count($findings) ?></span></h2>
            <p>Ordered by how much they matter. Every fix keeps the original crontab line.</p>
        </div>
    </div>
    <?php if ($findings === []): ?>
        <p class="zcd-empty">Nothing to report. Every cron job looks healthy.</p>
    <?php else: ?>
        <ul class="zcd-findings">
            <?php foreach ($findings as $finding): ?>
                <li class="zcd-finding zcd-finding-<?= Html::attribute($finding->severity()) ?>">
                    <div class="zcd-finding-text">
                        <p class="zcd-finding-title"><?= Html::text($finding->title()) ?></p>
                        <p class="zcd-finding-detail"><?= Html::text($finding->detail()) ?></p>
                        <?php if ($finding->lineNumber() !== null): ?>
                            <span class="zcd-finding-where">
                                Crontab line <?= (int) ($finding->lineNumber() + 1) ?>
                            </span>
                        <?php endif ?>
                    </div>
                    <?php if ($finding->action() !== null && $writable): ?>
                        <div class="zcd-finding-action">
                            <?= $template->render('action-button', [
                                'action' => $finding->action(),
                                'token' => $token,
                                'fingerprint' => $fingerprint,
                            ]) ?>
                        </div>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>
