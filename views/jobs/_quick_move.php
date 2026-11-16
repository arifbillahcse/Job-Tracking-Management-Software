<?php
/**
 * One-click status change (no dialog): "Move to In Transit" or "Mark as Delivered". Today's date is saved by the server.
 * @var array<string,mixed> $job  @var array<string,mixed> $t one transition from statuses.php  @var string $size '' or 'btn-sm'
 */
$to = (string) $t['to'];
$what = $to === 'in_transit' ? 'loaded date' : 'delivery date';
$confirm = 'Move job ' . $job['job_number'] . ' to ' . (string) \App\Core\Config::get('statuses.statuses.' . $to . '.label', $to)
    . '? Today (' . fmt_date(\App\Core\Clock::today()) . ') will be saved as the ' . $what . '.';
?>
<?php if ($to === 'in_transit' && ($job['status'] ?? '') === 'booked' && \App\Services\CarrierList::required()): ?>
    <a class="btn <?= e($size ?? '') ?> btn-<?= e((string) $t['style']) ?>" href="<?= e(url('/jobs/' . $job['id'] . '?do=in_transit')) ?>" title="<?= e((string) $t['label']) ?>: choose the carrier"><?= icon('truck') ?> <?= !empty($short) ? 'In Transit' : e((string) $t['label']) ?></a>
<?php else: ?>
<form class="d-inline" method="post" action="<?= e(url('/jobs/' . $job['id'] . '/status')) ?>" data-loading>
    <?= csrf_field() ?>
    <input type="hidden" name="to" value="<?= e($to) ?>">
    <button type="submit"<?= !empty($short) ? ' title="' . e((string) $t['label']) . '"' : '' ?> class="btn <?= e($size ?? '') ?> btn-<?= e((string) $t['style']) ?>" data-confirm="<?= e($confirm) ?>"><?= icon($to === 'in_transit' ? 'truck' : 'check-circle') ?> <?php if (!empty($short)): ?><?= $to === 'in_transit' ? 'In Transit' : 'Delivered' ?><?php else: ?><?= e((string) $t['label']) ?><?php endif; ?></button>
</form>
<?php endif; ?>
