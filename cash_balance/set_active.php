<?php
/**
 * cash_balance/set_active.php — toggle a currency's 📍active flag.
 *
 * "Active" = a currency you're currently using (the country you're in). Only active
 * currencies get a stale nag on the board; the rest show neutral age and never nag.
 * State lives in secure_bin/cash/cash_active.json — REWRITTEN (not appended), and it
 * rides the Lemur mirror alongside the snapshot ledgers.
 *
 *  POST: password, currency, active ("1" = mark active, anything else = unmark)
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once __DIR__ . "/../ai_secure/secure_config.php";    // CASH_* + cash_currency_ok()

function fail(string $msg): void
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

$currency = $_POST['currency'] ?? '';
if (!cash_currency_ok($currency)) {
    fail('unknown currency');
}
$want_active = ($_POST['active'] ?? '') === '1';

if (!is_dir(CASH_DIR) && !@mkdir(CASH_DIR, 0700, true)) {
    fail('could not create cash dir');
}

// load current active set (defensive: tolerate a missing / malformed file, drop stale codes)
$cur    = is_file(CASH_ACTIVE_FILE)
    ? (json_decode((string) file_get_contents(CASH_ACTIVE_FILE), true) ?: [])
    : [];
$active = array_values(array_filter($cur['active'] ?? [], 'cash_currency_ok'));

// remove this currency, then add it back iff we want it active (idempotent toggle)
$active = array_values(array_diff($active, [$currency]));
if ($want_active) {
    $active[] = $currency;
}

$out = json_encode(
    ['active' => $active, 'updated' => date('c')],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if (@file_put_contents(CASH_ACTIVE_FILE, $out . "\n", LOCK_EX) === false) {
    fail('could not write active state');
}

echo json_encode(['ok' => true, 'active' => $active]);
