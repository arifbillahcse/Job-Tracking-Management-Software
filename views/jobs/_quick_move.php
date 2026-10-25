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
<form class="d-inline" method="post" action="<?= e(url('/jobs/' . $job['id'] . '/status')) ?>" data-loading>
    <?= csrf_field() ?>
    <input type="hidden" name="to" value="<?= e($to) ?>">
    <button type="submit" class="btn <?= e($size ?? '') ?> btn-<?= e((string) $t['style']) ?>" data-confirm="<?= e($confirm) ?>"><?= icon($to === 'in_transit' ? 'truck' : 'check-circle') ?> <?= e((string) $t['label']) ?></button>
</form>
