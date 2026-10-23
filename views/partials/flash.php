<?php
$flash = alert();
if ($flash !== null):
    $type = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
    $iconName = ['success' => 'check-circle', 'danger' => 'alert-triangle', 'warning' => 'alert-triangle', 'info' => 'info'][$type];
    ?>
    <div class="alert alert-<?= e($type) ?> d-flex align-items-start gap-2 jt-alert" role="alert">
        <?= icon($iconName, 'mt-1 flex-shrink-0') ?>
        <div class="flex-grow-1"><?= e($flash['message']) ?></div>
    </div>
<?php endif; ?>
