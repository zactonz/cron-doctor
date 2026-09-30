<?php

use Zactonz\CronDoctor\Security\Token;
use Zactonz\CronDoctor\View\Html;

?>
<input type="hidden" name="<?= Html::escape(Token::FIELD) ?>" value="<?= Html::attribute($token) ?>">
<input type="hidden" name="fingerprint" value="<?= Html::attribute($fingerprint) ?>">
