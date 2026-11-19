<?php
/** The Driver Tracking / Job Tracking buttons at the very top of every page (addresses come from Settings; empty = hidden). */
$driver = \App\Services\TrackingLinks::driver();
$job = \App\Services\TrackingLinks::job();
if ($driver === '' && $job === '') {
    return;
}
?>
<nav class="jt-tracklinks" aria-label="Tracking links">
    <?php if ($driver !== ''): ?><a class="jt-btn-amber" href="<?= e($driver) ?>"><span class="jt-btn-amber-ic" aria-hidden="true">&#128506;&#65039;</span>Driver Tracking</a><?php endif; ?>
    <?php if ($job !== ''): ?><a class="jt-btn-amber" href="<?= e($job) ?>"><span class="jt-btn-amber-ic" aria-hidden="true">&#128205;</span>Job Tracking</a><?php endif; ?>
</nav>
