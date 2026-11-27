<?php
/**
 * Companies (the jobs' brokers, with their details).
 * @var string $q  @var array<int,array<string,mixed>> $companies  @var array<int,array{broker:string,jobs:int}> $unlinked
 */
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto jt-section-note">A company is the same as a job's <strong>Broker</strong> (matched by name, letter case ignored). Add its address, phone, DOT and MC number here; they are printed on its company invoice and statement.</p>
    <a class="btn btn-primary" href="<?= e(url('/companies/create')) ?>"><?= icon('plus') ?> New company</a>
</div>

<div class="card jt-card mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(url('/companies')) ?>" class="d-flex flex-wrap gap-2 align-items-end">
            <div class="flex-grow-1">
                <label class="form-label" for="co-q">Search</label>
                <input type="text" class="form-control" id="co-q" name="q" maxlength="100" placeholder="Name, city, address, DOT or MC" value="<?= e($q) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('/companies')) ?>">Clear</a>
        </form>
    </div>
</div>

<?php if ($companies === []): ?>
    <div class="card jt-card mb-3">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'users', 'heading' => $q === '' ? 'No companies yet' : 'No company matches', 'message' => $q === '' ? 'Add a company to keep its address, DOT and MC number.' : 'Try another search.',
            'actionUrl' => '/companies/create', 'actionLabel' => 'New company', 'actionIcon' => 'plus',
        ]) ?>
    </div>
<?php else: ?>
    <div class="card jt-card mb-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jt-table table-cards">
                <thead>
                <tr>
                    <th scope="col">Name</th><th scope="col">Address</th><th scope="col">Phone</th><th scope="col">DOT</th><th scope="col">MC</th>
                    <th scope="col" class="text-md-end">Jobs</th><th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($companies as $c): ?>
                    <tr>
                        <td data-label="Name"><strong><?= e($c['name']) ?></strong></td>
                        <td data-label="Address"><?= e(trim((string) ($c['address'] ?? '') . ((($c['address'] ?? '') !== '' && ($c['city'] ?? '') !== '') ? ', ' : '') . (string) ($c['city'] ?? ''))) ?: '—' ?></td>
                        <td data-label="Phone"><?= tel_link($c['phone']) ?></td>
                        <td data-label="DOT"><?= e($c['dot_number'] ?: '—') ?></td>
                        <td data-label="MC"><?= e($c['mc_number'] ?: '—') ?></td>
                        <td data-label="Jobs" class="text-md-end"><?= e($c['job_count']) ?></td>
                        <td class="text-md-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/companies/' . $c['id'] . '/edit')) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($unlinked !== []): ?>
    <div class="card jt-card">
        <div class="card-header bg-transparent fw-semibold">Brokers on jobs with no company details</div>
        <div class="card-body">
            <p class="form-text mt-0">These names are on jobs but have no record here yet. Add the details so they are printed on company invoices.</p>
            <ul class="list-unstyled mb-0">
                <?php foreach ($unlinked as $u): ?>
                    <li class="d-flex flex-wrap gap-2 align-items-center mb-1">
                        <span><?= e($u['broker']) ?></span><span class="text-body-secondary small"><?= e($u['jobs']) ?> job<?= $u['jobs'] === 1 ? '' : 's' ?></span>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(list_url('/companies/create', ['name' => $u['broker']])) ?>">Add details</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
