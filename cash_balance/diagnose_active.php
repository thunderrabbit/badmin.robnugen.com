<?php
/**
 * cash_balance/diagnose_active.php — TEMPORARY. Delete once the active-currency
 * persistence question is settled.
 *
 * Read-only. Writes nothing, changes nothing. It answers one question: after a toggle
 * that the browser reported as successful, what does the SERVER actually have on disk,
 * and what does cash_active_currency() make of it?
 *
 * Load it right after toggling a currency:
 *   https://badmin.robnugen.com/cash_balance/diagnose_active.php?password=<badmin password>
 *
 * The "page generated" clock at the top is the caching check: reload twice. If the clock
 * does not move, you are being served a cached copy and the server never ran this at all
 * — which would also explain a board that renders without its pin.
 */

// this page must never itself be cached, or it cannot answer the caching question
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: text/plain; charset=utf-8');

date_default_timezone_set("Asia/Tokyo");

require_once "/home/thundergoblin/bulletproof_config.php";
require_once "/home/thundergoblin/secure_config.php";

if (!password_verify($_GET['password'] ?? '', $bulletproof_password_hash)) {
    echo "Invalid password. Pass ?password=<badmin password>\n";
    exit;
}

$line = str_repeat('-', 68);

printf("page generated: %s   <- reload twice; if this does not move, you are cached\n", date('c'));
echo "$line\n";

printf("php version   : %s (%s)\n", PHP_VERSION, PHP_SAPI);
if (function_exists('posix_geteuid')) {
    $u = posix_getpwuid(posix_geteuid());
    printf("running as    : %s (uid %d)\n", $u['name'] ?? '?', posix_geteuid());
} else {
    printf("running as    : (posix ext not loaded)\n");
}

// Which secure_config.php actually got loaded, and does it carry the new helpers?
// strpos, not str_contains: str_contains is PHP 8.0+ and the server version is exactly
// one of the things this page exists to find out. It must not fatal before reporting.
$loaded = array_values(array_filter(get_included_files(), fn($f) => strpos($f, 'secure_config') !== false));
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
