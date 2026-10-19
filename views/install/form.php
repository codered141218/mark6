<?php /** First-run installer. Variables: $db, $envMode, $samples, $zip, $pgsql */ ?>
<div class="login-page">
  <form class="login-card wide" method="post" action="<?= url('/install') ?>">
    <?= csrf_field() ?>
    <div class="brand-logo">M6</div>
    <h1>Install Mark6 Retail Suite</h1>
    <?php if ($m = flash('error')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>
    <?php if (!$pgsql): ?><div class="alert alert-error">PHP <b>pdo_pgsql</b> extension is missing. Install it (e.g. <code>docker-php-ext-install pdo_pgsql</code> or <code>apt install php-pgsql</code>) and reload.</div><?php endif; ?>
    <?php if (!$zip): ?><div class="alert alert-warn">PHP <b>zip</b> extension is off: Excel exports will use the older .xls format. Enable <code>extension=zip</code> in php.ini for .xlsx.</div><?php endif; ?>

    <?php if ($envMode): ?>
      <p class="muted">Database: <b><?= e($db['username']) ?>@<?= e($db['host']) ?>:<?= e($db['port']) ?>/<?= e($db['database']) ?></b> (schema <?= e($db['schema'] ?? 'public') ?>), from the environment variables.
        The tables are created in that database when you click Install.</p>
    <?php else: ?>
      <p class="muted">Enter your PostgreSQL / Supabase connection. On Supabase use the <b>Session pooler</b> host from Project Settings → Database (works on IPv4 networks).</p>
      <div class="form-grid">
        <label class="field" style="grid-column:span 2"><span class="field-label">Host</span><input class="input" name="host" value="<?= e(old('host', $db['host'] ?? '127.0.0.1')) ?>" placeholder="aws-0-ap-southeast-1.pooler.supabase.com"></label>
        <label class="field"><span class="field-label">Port</span><input class="input" name="port" value="<?= e(old('port', $db['port'] ?? 5432)) ?>"></label>
        <label class="field"><span class="field-label">Database</span><input class="input" name="database" value="<?= e(old('database', $db['database'] ?? 'postgres')) ?>"></label>
        <label class="field"><span class="field-label">Username</span><input class="input" name="username" value="<?= e(old('username', $db['username'] ?? 'postgres')) ?>" placeholder="postgres.xxxxxxxx on Supabase"></label>
        <label class="field"><span class="field-label">Password</span><input class="input" type="password" name="password" value=""></label>
        <label class="field"><span class="field-label">Schema</span><input class="input" name="schema" value="<?= e(old('schema', $db['schema'] ?? 'public')) ?>"><span class="field-hint">Tables are created here (default public)</span></label>
        <label class="field"><span class="field-label">SSL</span>
          <select class="input" name="sslmode"><?= options(['prefer' => 'Prefer (local or cloud)', 'require' => 'Require (Supabase)', 'disable' => 'Disable (local only)', 'verify-full' => 'Verify certificate'], old('sslmode', $db['sslmode'] ?? 'prefer')) ?></select></label>
      </div>
      <label class="checkbox mt"><input type="checkbox" name="create_db" value="1"> Create the database if it does not exist (self-hosted PostgreSQL only; Supabase already has one)</label>
    <?php endif; ?>

    <h3 class="mt-lg">Administrator account</h3>
    <p class="muted small">Username is <b>admin</b>.</p>
    <div class="form-grid">
      <label class="field"><span class="field-label">Admin password</span><input class="input" type="password" name="admin_password" required minlength="6"></label>
      <label class="field"><span class="field-label">Confirm password</span><input class="input" type="password" name="admin_password_confirm" required></label>
      <label class="field"><span class="field-label">Manager PIN (4-8 digits)</span><input class="input" name="admin_pin" value="<?= e(old('admin_pin', '1234')) ?>" inputmode="numeric"></label>
    </div>

    <h3 class="mt-lg">Sample data</h3>
    <label class="field"><span class="field-label">Start with</span>
      <select class="input" name="sample"><?= options($samples, old('sample', 'none')) ?></select>
      <span class="field-hint">A sample catalog adds categories, items with barcodes, pack sizes and lots, suppliers and opening stock so you can try the POS right away. You can delete it later.</span></label>
    <button class="btn btn-primary btn-lg btn-block mt-lg" type="submit" <?= $pgsql ? '' : 'disabled' ?>>Install</button>
  </form>
</div>
