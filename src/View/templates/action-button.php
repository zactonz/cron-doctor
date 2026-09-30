<?php

use Zactonz\CronDoctor\View\Html;

?>
<?php if ($action->name() === 'view-job'): ?>
    <a class="zcd-btn" href="job.live.php?job=<?= Html::attribute((string) $action->parameters()['job']) ?>">
        <?= Html::text($action->label()) ?>
    </a>
<?php else: ?>
    <form class="zcd-inline-form" method="post" action="actions.php">
        <?= $template->render('form-token', ['token' => $token, 'fingerprint' => $fingerprint]) ?>
        <input type="hidden" name="action" value="<?= Html::attribute($action->name()) ?>">
        <?php foreach ($action->parameters() as $key => $value): ?>
            <input type="hidden" name="<?= Html::attribute($key) ?>" value="<?= Html::attribute($value) ?>">
        <?php endforeach ?>
        <button type="submit" class="zcd-btn <?= $action->primary() ? 'zcd-btn-primary' : '' ?>">
            <?= Html::text($action->label()) ?>
        </button>
    </form>
<?php endif ?>
