<?php
/**
 * Search box and filters (plain GET form, works without JavaScript).
 * @var string $bucket  @var \App\Services\JobCriteria $criteria  @var bool $showFinancials  @var array<int,array<string,mixed>> $foremen
 */
use App\Services\JobBucket;
use App\Services\JobCriteria;

$path = JobBucket::definitions()[$bucket]['path'];
$dateLabel = $bucket === JobBucket::DELIVERED ? 'Delivered' : '1st available';
$statuses = (array) \App\Core\Config::get('statuses.statuses', []);
$advanced = $criteria->activeFilterCount() - ($criteria->q !== '' ? 1 : 0);
[$defaultSort, $defaultDir] = [$criteria->linkParams()['sort'] ?? null, $criteria->linkParams()['dir'] ?? null];
?>
<form class="jt-filters card jt-card mb-3" method="get" action="<?= e(url($path)) ?>" role="search" aria-label="Search and filter jobs">
    <?php if ($defaultSort !== null): ?>
        <input type="hidden" name="sort" value="<?= e($defaultSort) ?>">
        <input type="hidden" name="dir" value="<?= e($defaultDir) ?>">
    <?php endif; ?>

    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            <div class="input-group jt-search">
                <span class="input-group-text bg-white"><?= icon('search') ?></span>
                <input type="search" class="form-control" name="q" value="<?= e($criteria->q) ?>" maxlength="100"
                       placeholder="Search job #, customer, route, foreman, trip, phone…" aria-label="Search jobs">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($criteria->hasFilters()): ?>
                <a class="btn btn-outline-secondary" href="<?= e(url($path)) ?>"><?= icon('x') ?> Reset</a>
            <?php endif; ?>
        </div>

        <details class="jt-more-filters mt-3"<?= $criteria->hasAdvancedFilters() ? ' open' : '' ?>>
            <summary><?= icon('sliders') ?> More filters<?= $advanced > 0 ? ' <span class="badge text-bg-primary">' . e($advanced) . '</span>' : '' ?></summary>
            <div class="row g-3 mt-0 pt-2">
                <?php if ($bucket === JobBucket::ALL): ?>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label class="form-label" for="f-status">Status</label>
                        <select class="form-select" id="f-status" name="status">
                            <option value="">Any status</option>
                            <?php foreach ($statuses as $key => $status): ?>
                                <option value="<?= e($key) ?>"<?= $criteria->status === $key ? ' selected' : '' ?>><?= e($status['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-6 col-lg-3">
                    <label class="form-label" for="f-from-date"><?= e($dateLabel) ?> from</label>
                    <input type="date" class="form-control" id="f-from-date" name="from_date" value="<?= e($criteria->dateFrom) ?>">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label" for="f-to-date"><?= e($dateLabel) ?> to</label>
                    <input type="date" class="form-control" id="f-to-date" name="to_date" value="<?= e($criteria->dateTo) ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="f-from">From (city / place)</label>
                    <input type="text" class="form-control" id="f-from" name="from" value="<?= e($criteria->fromLocation) ?>" maxlength="100">
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="f-to">To (city / place)</label>
                    <input type="text" class="form-control" id="f-to" name="to" value="<?= e($criteria->toLocation) ?>" maxlength="100">
                </div>
                <?php if (in_array($bucket, [JobBucket::ALL, JobBucket::TRANSIT, JobBucket::DELIVERED], true)): ?>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label class="form-label" for="f-carrier">Carrier or driver</label>
                        <input type="text" class="form-control" id="f-carrier" name="carrier" value="<?= e($criteria->carrier) ?>" maxlength="100">
                    </div>
                <?php endif; ?>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="f-foreman">Foreman</label>
                    <select class="form-select" id="f-foreman" name="foreman">
                        <option value="">Any foreman</option>
                        <option value="none"<?= $criteria->foremanId === -1 ? ' selected' : '' ?>>No foreman assigned</option>
                        <?php foreach ($foremen as $f): ?>
                            <option value="<?= e($f['id']) ?>"<?= $criteria->foremanId === (int) $f['id'] ? ' selected' : '' ?>><?= e($f['name']) ?><?= (int) $f['active'] === 1 ? '' : ' (inactive)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="f-trip">Trip number</label>
                    <input type="text" class="form-control" id="f-trip" name="trip" value="<?= e($criteria->trip) ?>" maxlength="40">
                </div>
                <?php if ($showFinancials): ?>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label class="form-label" for="f-balance">Balance</label>
                        <select class="form-select" id="f-balance" name="balance">
                            <option value="">Any</option>
                            <option value="due"<?= $criteria->balance === 'due' ? ' selected' : '' ?>>Balance due</option>
                            <option value="paid"<?= $criteria->balance === 'paid' ? ' selected' : '' ?>>Paid in full</option>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="f-per-page">Rows per page</label>
                    <select class="form-select" id="f-per-page" name="per_page">
                        <?php foreach (JobCriteria::PER_PAGE_OPTIONS as $n): ?>
                            <option value="<?= e($n) ?>"<?= $criteria->perPage === $n ? ' selected' : '' ?>><?= e($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Apply filters</button>
                </div>
            </div>
        </details>
    </div>
</form>
