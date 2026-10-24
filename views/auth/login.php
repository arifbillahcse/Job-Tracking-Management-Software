<h2 class="h4 mb-1">Welcome back</h2>
<p class="text-body-secondary mb-4">Sign in to manage your jobs.</p>

<form method="post" action="<?= e(url('/login')) ?>" novalidate data-loading>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input type="text" class="form-control<?= has_error('username') ? ' is-invalid' : '' ?>" id="username" name="username"
               value="<?= e(old('username')) ?>" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required>
        <?= error_text('username') ?>
    </div>

    <div class="mb-4">
        <label for="password" class="form-label">Password</label>
        <div class="input-group">
            <input type="password" class="form-control<?= has_error('password') ? ' is-invalid' : '' ?>" id="password" name="password"
                   maxlength="1000" autocomplete="current-password" required>
            <button type="button" class="btn btn-outline-secondary" data-toggle-password="#password" aria-label="Show password">Show</button>
        </div>
        <?= error_text('password') ?>
    </div>

    <button type="submit" class="btn btn-primary w-100">Sign in</button>
</form>
