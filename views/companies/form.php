<?php
/**
 * @var array<string,mixed>|null $company  @var string $action  @var string $prefill  @var int $jobCount
 */
use App\Services\CompanyService;

$val = static fn (string $key, string $fallback = ''): string => old_or($key, (string) ($company[$key] ?? $fallback));
?>
<div class="row">
    <div class="col-12 col-xl-6">
        <form class="card jt-card" method="post" action="<?= e(url($action)) ?>" novalidate data-loading data-unsaved>
            <?= csrf_field() ?>
            <div class="card-header bg-transparent fw-semibold"><?= $company === null ? 'New company' : 'Company details' ?></div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="name" class="form-label">Name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control<?= has_error('name') ? ' is-invalid' : '' ?>" id="name" name="name" value="<?= e($val('name', $prefill)) ?>" maxlength="100" required aria-describedby="name-help">
                    <div class="form-text" id="name-help">The same name as the Broker on the jobs (letter case does not matter).</div>
                    <?= error_text('name') ?>
                </div>
                <?php foreach (CompanyService::FIELDS as $key => [$label, $max]): ?>
                    <div class="mb-3">
                        <label for="<?= e($key) ?>" class="form-label"><?= e($label) ?> <span class="text-body-secondary fw-normal">(optional)</span></label>
                        <input type="<?= $key === 'phone' ? 'tel' : 'text' ?>" class="form-control<?= has_error($key) ? ' is-invalid' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($val($key)) ?>" maxlength="<?= e($max) ?>"<?= $key === 'phone' ? ' inputmode="tel"' : '' ?>>
                        <?= error_text($key) ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($company !== null): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rename_jobs" name="rename_jobs" value="1" checked>
                        <label class="form-check-label" for="rename_jobs">If I change the name, also change the Broker on its <?= e($jobCount) ?> job<?= $jobCount === 1 ? '' : 's' ?></label>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2">
                <a class="btn btn-outline-secondary" href="<?= e(url('/companies')) ?>">Cancel</a>
                <button type="submit" class="btn btn-primary"><?= $company === null ? 'Add company' : 'Save changes' ?></button>
            </div>
        </form>
        <?php if ($company !== null): ?>
            <form class="mt-3" method="post" action="<?= e(url('/companies/' . $company['id'] . '/delete')) ?>" data-loading>
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-danger" data-confirm="Remove the details of <?= e($company['name']) ?>? Its jobs and invoices keep the broker name.">Remove these details</button>
            </form>
        <?php endif; ?>
    </div>
</div>
