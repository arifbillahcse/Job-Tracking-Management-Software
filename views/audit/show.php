<?php
/** @var array<string,mixed> $entry  @var array<int,array{field:string,old:?string,new:?string}> $changes */
?>
<div class="jt-page-actions">
    <a class="btn btn-outline-secondary" href="<?= e(url('/audit')) ?>">Back to audit log</a>
    <?php if ($entry['entity'] !== null && $entry['entity_id'] !== null): ?>
        <a class="btn btn-outline-secondary" href="<?= e(list_url('/audit', ['entity' => $entry['entity'], 'entity_id' => $entry['entity_id']])) ?>">All entries for this record</a>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold"><?= e(\App\Services\AuditService::label((string) $entry['action'])) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-4 text-body-secondary fw-normal">Entry</dt><dd class="col-8">#<?= e($entry['id']) ?></dd>
                    <dt class="col-4 text-body-secondary fw-normal">When</dt><dd class="col-8"><?= e(fmt_datetime($entry['created_at'])) ?></dd>
                    <dt class="col-4 text-body-secondary fw-normal">Who</dt>
                    <dd class="col-8"><?= $entry['user_name'] !== null ? e($entry['user_name']) . ' <span class="text-body-secondary text-break">(' . e($entry['user_email']) . ')</span>' : '<span class="text-body-secondary">Not signed in</span>' ?></dd>
                    <dt class="col-4 text-body-secondary fw-normal">Code</dt><dd class="col-8"><code><?= e($entry['action']) ?></code></dd>
                    <dt class="col-4 text-body-secondary fw-normal">Record</dt>
                    <dd class="col-8"><?= $entry['entity'] === null ? '—' : e($entry['entity']) . ($entry['entity_id'] !== null ? ' #' . e($entry['entity_id']) : '') ?></dd>
                    <dt class="col-4 text-body-secondary fw-normal">IP address</dt><dd class="col-8"><?= e($entry['ip']) ?></dd>
                    <dt class="col-4 text-body-secondary fw-normal">Browser</dt><dd class="col-8 text-break small"><?= e($entry['user_agent']) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-7">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">What changed</div>
            <?php if ($changes === []): ?>
                <div class="card-body text-body-secondary">No field details were recorded for this action.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 jt-table table-cards">
                        <thead><tr><th scope="col">Field</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
                        <tbody>
                        <?php foreach ($changes as $change): ?>
                            <tr>
                                <td data-label="Field"><code><?= e($change['field']) ?></code></td>
                                <td data-label="Before" class="text-break"><?= $change['old'] === null ? '<span class="text-body-secondary">—</span>' : ($change['old'] === '' ? '<span class="text-body-secondary">(empty)</span>' : e($change['old'])) ?></td>
                                <td data-label="After" class="text-break"><?= $change['new'] === null ? '<span class="text-body-secondary">—</span>' : ($change['new'] === '' ? '<span class="text-body-secondary">(empty)</span>' : e($change['new'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-transparent small text-body-secondary">Passwords, tokens and secrets are never stored in the audit log.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
