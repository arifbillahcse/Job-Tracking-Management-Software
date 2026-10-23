<?php
/** Section switcher with live counts. @var string $bucket  @var array<string,int> $tabCounts */
?>
<nav class="jt-tabs" aria-label="Job sections">
    <?php foreach (\App\Services\JobBucket::definitions() as $key => $def): ?>
        <a class="jt-tab jt-tab-<?= e($def['tone']) ?><?= $key === $bucket ? ' active' : '' ?>"
           href="<?= e(url($def['path'])) ?>"<?= $key === $bucket ? ' aria-current="page"' : '' ?>>
            <span><?= e(\App\Services\JobBucket::label($key)) ?></span>
            <span class="jt-tab-count"><?= e($tabCounts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
