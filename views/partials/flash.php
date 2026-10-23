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

<?php
/** One-time secret (a temporary password): kept in the session for this one page view only, never written to the database or logs. */
$secret = \App\Core\Session::flashed('secret');
if (is_array($secret) && isset($secret['label'], $secret['value'])): ?>
    <div class="jt-secret card border-warning mb-3" role="alert">
        <div class="card-body">
            <div class="fw-semibold mb-1 d-flex align-items-center gap-2"><?= icon('key') ?> <?= e($secret['label']) ?></div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <code class="jt-secret-value" id="secretValue"><?= e($secret['value']) ?></code>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="#secretValue"><?= icon('copy') ?> Copy</button>
            </div>
            <div class="small text-body-secondary mt-2">Shown once. Give it to the user in person or over a private channel. They must choose a new password when they sign in. If it is lost, reset the password again.</div>
        </div>
    </div>
<?php endif; ?>
