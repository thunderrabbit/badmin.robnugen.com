<?php
/**
 * cash_balance/diagnose_active.php — TEMPORARY. Delete once the active-currency
 * persistence question is settled.
 *
 * Read-only. Writes nothing, changes nothing. It answers one question: after a toggle
 * that the browser reported as successful, what does the SERVER actually have on disk,
 * and what does cash_active_currency() make of it?
 *
 * Load it right after toggling a currency, and enter the badmin password in the form.
 * The password is POSTed, never a query parameter — a ?password= would be recorded in
 * the Apache access log, browser history, and any Referer header this page sends.
 *
 * The "page generated" clock is the caching check and shows WITHOUT the password:
 * reload twice. If it does not move, you are being served a cached copy and the server
 * never ran this at all — which would also explain a board that renders without its pin.
 */

// this page must never itself be cached, or it cannot answer the caching question
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: text/html; charset=utf-8');
// nothing here should ever leak into a Referer sent onward
header('Referrer-Policy: no-referrer');

date_default_timezone_set("Asia/Tokyo");

require_once "/home/thundergoblin/bulletproof_config.php";
require_once "/home/thundergoblin/secure_config.php";

$generated = date('c');
$authed    = password_verify($_POST['password'] ?? '', $bulletproof_password_hash);
$tried     = isset($_POST['password']);

$report = '';
if ($authed) {
    ob_start();

    $line = str_repeat('-', 68);

    printf("php version   : %s (%s)\n", PHP_VERSION, PHP_SAPI);
    if (function_exists('posix_geteuid')) {
        $u = posix_getpwuid(posix_geteuid());
        printf("running as    : %s (uid %d)\n", $u['name'] ?? '?', posix_geteuid());
    } else {
        printf("running as    : (posix ext not loaded)\n");
    }

    // Which secure_config.php actually got loaded, and does it carry the new helpers?
    // strpos, not str_contains: str_contains is PHP 8.0+ and the server version is one
    // of the things this page exists to find out. It must not fatal before reporting.
    $loaded = array_values(array_filter(
        get_included_files(),
        fn($f) => strpos($f, 'secure_config') !== false
    ));
    printf("config loaded : %s\n", $loaded ? implode(', ', $loaded) : '(none matched)');
    foreach (['cash_active_currency', 'secure_manifest_path', 'account_tags_for'] as $fn) {
        printf("  %-22s %s\n", $fn . '()', function_exists($fn) ? 'present' : 'MISSING (stale config!)');
    }

    echo "$line\n";
    printf("CASH_DIR         : %s\n", CASH_DIR);
    printf("  is_dir         : %s\n", is_dir(CASH_DIR) ? 'yes' : 'NO');
    printf("  writable       : %s\n", is_writable(CASH_DIR) ? 'yes' : 'NO');

    printf("CASH_ACTIVE_FILE : %s\n", CASH_ACTIVE_FILE);
    printf("  is_file        : %s\n", is_file(CASH_ACTIVE_FILE) ? 'yes' : 'NO');
    if (is_file(CASH_ACTIVE_FILE)) {
        printf("  readable       : %s\n", is_readable(CASH_ACTIVE_FILE) ? 'yes' : 'NO');
        printf("  writable       : %s\n", is_writable(CASH_ACTIVE_FILE) ? 'yes' : 'NO');
        printf("  size           : %d bytes\n", filesize(CASH_ACTIVE_FILE));
        printf("  modified       : %s  (%d seconds ago)\n",
            date('c', filemtime(CASH_ACTIVE_FILE)), time() - filemtime(CASH_ACTIVE_FILE));
        printf("  perms/owner    : %04o  uid=%d gid=%d\n",
            fileperms(CASH_ACTIVE_FILE) & 0777, fileowner(CASH_ACTIVE_FILE), filegroup(CASH_ACTIVE_FILE));

        $raw = (string) file_get_contents(CASH_ACTIVE_FILE);
        printf("  raw contents   : %s\n", var_export($raw, true));

        $decoded = json_decode($raw, true);
        printf("  json_decode    : %s\n", json_last_error() === JSON_ERROR_NONE
            ? var_export($decoded, true)
            : 'FAILED - ' . json_last_error_msg());
    }

    echo "$line\n";
    printf("cash_active_currency() returns: %s\n", var_export(cash_active_currency(), true));
    printf("  (that empty-string case is what makes the board render with no pin)\n");

    echo "$line\n";
    // Stale bytecode would explain new behaviour in one file and old behaviour in another.
    if (function_exists('opcache_get_status')) {
        $st = @opcache_get_status(false);
        if (is_array($st)) {
            printf("opcache        : enabled=%s  validate_timestamps=%s  revalidate_freq=%s\n",
                var_export($st['opcache_enabled'] ?? null, true),
                var_export(ini_get('opcache.validate_timestamps'), true),
                var_export(ini_get('opcache.revalidate_freq'), true));
            echo "  if validate_timestamps is 0/off, deployed .php changes are NOT picked up\n";
        } else {
            echo "opcache        : extension present, status unavailable\n";
        }
    } else {
        echo "opcache        : not loaded\n";
    }

    printf("\nmtimes of the deployed pages (are they the files you just scp'd?)\n");
    foreach ([__DIR__ . '/index.php', __DIR__ . '/set_active.php', __FILE__] as $f) {
        printf("  %-22s %s\n", basename($f), is_file($f) ? date('c', filemtime($f)) : 'missing');
    }

    $report = ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <title>🔎 active-currency diagnostic</title>
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; font-size: 17px; line-height: 1.4;
           margin: 0; padding: 14px; max-width: 760px; margin-inline: auto;
           color: #1a1a1a; background: #f4f4f7; }
    section { background: #fff; border: 1px solid #ddd; border-radius: 10px;
              padding: 14px; margin-bottom: 14px; }
    label { display: block; font-weight: 600; margin: 8px 0 4px; }
    input[type=password] { width: 100%; font-size: 1rem; padding: 12px;
                           border: 1px solid #bbb; border-radius: 8px; }
    button { font-size: 1rem; padding: 12px 16px; border: 0; border-radius: 8px;
             background: #2563eb; color: #fff; font-weight: 600; cursor: pointer;
             width: 100%; margin-top: 14px; }
    pre { background: #111; color: #e5e7eb; padding: 12px; border-radius: 8px;
          overflow-x: auto; font-size: .8rem; line-height: 1.35; }
    .clock { font-family: ui-monospace, monospace; font-weight: 600; }
    .muted { color: #666; font-size: .9rem; }
    .err { color: #b91c1c; font-weight: 600; }
  </style>
</head>
<body>
  <h1>🔎 active-currency diagnostic</h1>

  <section>
    <p>page generated: <span class="clock"><?php echo htmlspecialchars($generated); ?></span></p>
    <p class="muted">Reload twice before anything else. If that clock does not move, you are
       being served a cached copy — the server never ran this, which would explain a board
       that loads without its pin. This check needs no password.</p>
  </section>

<?php if (!$authed): ?>
  <section>
<?php if ($tried): ?>
    <p class="err">Invalid password.</p>
<?php endif; ?>
    <form method="post" autocomplete="off">
      <label for="password">Badmin password</label>
      <input type="password" id="password" name="password" autocomplete="current-password">
      <button type="submit">Run the diagnostic</button>
    </form>
    <p class="muted">POSTed, never a query parameter — a <code>?password=</code> would be
       recorded in the access log and browser history.</p>
  </section>
<?php else: ?>
  <section>
    <pre><?php echo htmlspecialchars($report); ?></pre>
  </section>
<?php endif; ?>
</body>
</html>
