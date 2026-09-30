<?php

use Zactonz\CronDoctor\View\Html;

?>
<?php if ($flash !== null): ?>
    <div class="zcd-notice <?= $flash['successful'] ? 'zcd-notice-good' : 'zcd-notice-bad' ?>" role="status">
        <?= Html::text($flash['message']) ?>
    </div>
<?php endif ?>
