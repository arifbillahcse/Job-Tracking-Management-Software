<?php
/**
 * Drivers (the jobs' driver names, with their details).
 * @var string $q  @var array<int,array<string,mixed>> $drivers  @var array<int,array{driver:string,jobs:int}> $unlinked
 */
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto jt-section-note">A driver is the same as the <strong>Driver name</strong> on a job (matched by name, letter case ignored). Add the phone and licence number here; picking the driver on a job then fills in the phone, and the licence is printed on the driver statement and invoice.</p>
    <a class="btn btn-primary" href="<?= e(url('/drivers/create')) ?>"><?= icon('plus') ?> New driver</a>
</div>

<div class="card jt-card mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(url('/drivers')) ?>" class="d-flex flex-wrap gap-2 align-items-end">
            <div class="flex-grow-1">
                <label class="form-label" for="dr-q">Search</label>
                <input type="text" class="form-control" id="dr-q" name="q" maxlength="100" placeholder="Name, phone or license" value="<?= e($q) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('/drivers')) ?>">Clear</a>
        </form>
    </div>
</div>

<?php if ($drivers === []): ?>
    <div class="card jt-card mb-3">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'users', 'heading' => $q === '' ? 'No drivers yet' : 'No driver matches', 'message' => $q === '' ? 'Add a driver to keep the phone and licence number.' : 'Try another search.',
            'actionUrl' => '/drivers/create', 'actionLabel' => 'New driver', 'actionIcon' => 'plus',
        ]) ?>
    </div>
<?php else: ?>
    <div class="card jt-card mb-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jt-table table-cards">
                <thead>
                <tr><th scope="col">Name</th><th scope="col">Phone</th><th scope="col">License</th><th scope="col" class="text-md-end">Jobs</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
                </thead>
                <tbody>
                <?php foreach ($drivers as $d): ?>
                    <tr>
                        <td data-label="Name"><strong><?= e($d['name']) ?></strong></td>
                        <td data-label="Phone"><?= tel_link($d['phone']) ?></td>
                        <td data-label="License"><?= e($d['license'] ?: '—') ?></td>
                        <td data-label="Jobs" class="text-md-end"><?= e($d['job_count']) ?></td>
                        <td class="text-md-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/drivers/' . $d['id'] . '/edit')) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($unlinked !== []): ?>
    <div class="card jt-card">
        <div class="card-header bg-transparent fw-semibold">Drivers on jobs with no details</div>
        <div class="card-body">
            <p class="form-text mt-0">These names are on jobs but have no record here yet. Add the details to keep their phone and licence.</p>
            <ul class="list-unstyled mb-0">
                <?php foreach ($unlinked as $u): ?>
                    <li class="d-flex flex-wrap gap-2 align-items-center mb-1">
                        <span><?= e($u['driver']) ?></span><span class="text-body-secondary small"><?= e($u['jobs']) ?> job<?= $u['jobs'] === 1 ? '' : 's' ?></span>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(list_url('/drivers/create', ['name' => $u['driver']])) ?>">Add details</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
