<?php
/**
 * cash_balance/set_active.php — set which single currency is 📍active.
 *
 * "Active" = the currency you're currently using (the country you're in). Only the
 * active currency gets a stale nag on the board; the rest show neutral age and never
 * nag. State lives in secure_bin/cash/cash_active.json — REWRITTEN (not appended),
 * and it rides the Lemur mirror alongside the snapshot ledgers.
 *
 * SINGLE-SELECT: at most one currency is active, because Rob is in one country at a
 * time. /ai_secure reads it to stamp each scanned receipt with a currency and to pick
 * the account + category chips, all of which need one unambiguous answer. Marking a
 * currency active therefore REPLACES whatever was active before.
 *
 * The stored shape is unchanged — {"active":[...],"updated":"..."} — so existing
 * readers keep working; the list simply never holds more than one entry now.
 *
 *  POST: password, currency, active ("1" = make this the active one, anything else = unmark)
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once "/home/thundergoblin/secure_config.php";    // CASH_* + cash_currency_ok() (above web root)

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

// load current active state (defensive: tolerate a missing / malformed file, drop stale
// codes). A file written before single-select may still list several — the first valid
// entry wins and the rest are dropped, rather than letting stale state leak forward.
$cur    = is_file(CASH_ACTIVE_FILE)
    ? (json_decode((string) file_get_contents(CASH_ACTIVE_FILE), true) ?: [])
    : [];
$active = array_slice(array_values(array_filter($cur['active'] ?? [], 'cash_currency_ok')), 0, 1);

// Marking replaces the whole set; unmarking clears ONLY this currency. Unmarking must
// not be a blanket reset: the client sends active=0 for the pin it tapped, and a stale
// or hand-made request naming a different currency should never unmark the real one.
if ($want_active) {
    $active = [$currency];
} else {
    $active = array_values(array_diff($active, [$currency]));
}

$out = json_encode(
    ['active' => $active, 'updated' => date('c')],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if (@file_put_contents(CASH_ACTIVE_FILE, $out . "\n", LOCK_EX) === false) {
    fail('could not write active state');
}

echo json_encode(['ok' => true, 'active' => $active]);
