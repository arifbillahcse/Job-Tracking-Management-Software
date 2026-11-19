<?php
/**
 * One-click status change (no dialog): "Move to In Transit" or "Mark as Delivered". Today's date is saved by the server.
 * @var array<string,mixed> $job  @var array<string,mixed> $t one transition from statuses.php  @var string $size '' or 'btn-sm'
 */
$to = (string) $t['to'];
$what = $to === 'in_transit' ? 'loaded date' : 'delivery date';
$confirm = 'Move job ' . $job['job_number'] . ' to ' . (string) \App\Core\Config::get('statuses.statuses.' . $to . '.label', $to)
    . ($to === 'staged' ? '?' : '? Today (' . fmt_date(\App\Core\Clock::today()) . ') will be saved as the ' . $what . '.');
$form = (string) ($t['form'] ?? '');
$shortLabel = ['staged' => 'Stage', 'in_transit' => 'In Transit'][$to] ?? 'Delivered';
$shortIcon = $to === 'in_transit' ? 'truck' : ($to === 'staged' ? 'inbox' : 'check-circle');
?>
<?php if ($form === 'dispatch'): ?>
    <a class="btn <?= e($size ?? '') ?> btn-<?= e((string) $t['style']) ?>" href="<?= e(url('/jobs/' . $job['id'] . '?do=' . $to)) ?>" title="<?= e((string) $t['label']) ?>: choose the carrier"><?= icon($shortIcon) ?> <?= !empty($short) ? e($shortLabel) : e((string) $t['label']) ?></a>
<?php else: ?>
<form class="d-inline" method="post" action="<?= e(url('/jobs/' . $job['id'] . '/status')) ?>" data-loading>
    <?= csrf_field() ?>
    <input type="hidden" name="to" value="<?= e($to) ?>">
    <button type="submit"<?= !empty($short) ? ' title="' . e((string) $t['label']) . '"' : '' ?> class="btn <?= e($size ?? '') ?> btn-<?= e((string) $t['style']) ?>" data-confirm="<?= e($confirm) ?>"><?= icon($shortIcon) ?> <?php if (!empty($short)): ?><?= e($shortLabel) ?><?php else: ?><?= e((string) $t['label']) ?><?php endif; ?></button>
</form>
<?php endif; ?>
