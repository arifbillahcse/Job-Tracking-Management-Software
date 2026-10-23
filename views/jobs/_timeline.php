<?php
/** Newest first. @var array<int,array<string,mixed>> $timeline */
$labelOf = static fn (string $status): string => (string) \App\Core\Config::get('statuses.statuses.' . $status . '.label', $status);
?>
<ol class="jt-timeline">
    <?php foreach ($timeline as $event): ?>
        <li class="jt-timeline-item<?= $event['type'] === 'status' ? ' jt-timeline-' . e((string) \App\Core\Config::get('statuses.statuses.' . $event['to'] . '.badge', 'booked')) : ' jt-timeline-created' ?>">
            <div class="jt-timeline-head">
                <?php if ($event['type'] === 'created'): ?>
                    <strong>Job created</strong>
                <?php else: ?>
                    <strong><?= e($labelOf($event['from'])) ?></strong> <?= icon('arrow-right') ?> <strong><?= e($labelOf($event['to'])) ?></strong>
                <?php endif; ?>
            </div>
            <div class="jt-subtle">
                <?= e(fmt_datetime($event['at'])) ?><?= !empty($event['by']) ? ' &middot; ' . e($event['by']) : '' ?>
            </div>
            <?php if ($event['type'] === 'status'): ?>
                <?php $d = $event['details']; ?>
                <?php if (!empty($d['carrier_name'])): ?>
                    <div class="small mt-1">Carrier <?= e($d['carrier_name']) ?> &middot; driver <?= e($d['driver_name'] ?? '') ?> &middot; <?= e($d['driver_phone'] ?? '') ?></div>
                <?php endif; ?>
                <?php if (!empty($d['delivered_on'])): ?>
                    <div class="small mt-1">Delivered on <?= e(fmt_date((string) $d['delivered_on'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($d['cleared'])): ?>
                    <div class="small mt-1 text-body-secondary">Cleared on return to Booked:
                        <?= e(implode(', ', array_filter([
                            $d['cleared']['carrier_name'] ?? null,
                            $d['cleared']['driver_name'] ?? null,
                            $d['cleared']['driver_phone'] ?? null,
                        ]))) ?></div>
                <?php endif; ?>
                <?php if (!empty($event['note'])): ?>
                    <blockquote class="jt-note mt-1 mb-0"><?= e($event['note']) ?></blockquote>
                <?php endif; ?>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
