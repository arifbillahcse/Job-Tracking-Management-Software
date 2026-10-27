<?php
/** @var array<string,string> $values */
$val = static fn (string $key): string => old_or($key, $values[$key] ?? '');
?>
<div class="row">
    <div class="col-12 col-xl-7">
        <form class="card jt-card" method="post" action="<?= e(url('/settings/storage-fee')) ?>" novalidate data-loading data-unsaved>
            <?= csrf_field() ?>
<?= \App\Core\View::partial('settings/_storage_fields', ['values' => $values, 'val' => $val]) ?>
            <div class="card-footer bg-transparent d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save storage fee settings</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/settings/storage-fee')) ?>">Reset</a>
            </div>
        </form>
    </div>
    <div class="col-12 col-xl-5 mt-3 mt-xl-0">
        <div class="card jt-card">
            <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('info') ?> Good to know</div>
            <div class="card-body small text-body-secondary">
                <ul class="mb-0 ps-3">
                    <li>Only the storage fee rules are on this page. Every change is recorded in the audit log with the old and new value.</li>
                    <li>Saving re-calculates the automatic fee of every open job at once. A fee typed on a job, and the fee of a delivered job, are not changed.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
