<?php
/**
 * cash_balance/save_balance.php — append one wallet-balance snapshot for a currency.
 *
 * DETERMINISTIC, no AI. The currency is chosen by the board's flag/row and sent
 * explicitly; the amount is a plain number (NEVER inferred from decimals). Each save
 * APPENDS one JSON line to secure_bin/cash/cash_<CUR>_snapshots.jsonl — persistent,
 * mirrored to Lemur 13 (see ynab pull_b_secure_bin.sh). No staging, no sidecar, no unlink.
 *
 *  POST: password, currency, amount
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once __DIR__ . "/../ai_secure/secure_config.php";    // CASH_* + cash_snapshot_path()

function fail(string $msg): void
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

// currency -> whitelisted append-only path (null if not an allowed currency)
$currency = $_POST['currency'] ?? '';
$path     = cash_snapshot_path($currency);
if ($path === null) {
    fail('unknown currency');
}

// amount: a plain number. The flag already fixed the currency; decimals are NOT a hint.
$raw = $_POST['amount'] ?? '';
if (!is_numeric($raw)) {
    fail('amount must be a number');
}
$amount = (float) $raw;
if (!is_finite($amount)) {
    fail('amount must be finite');
}

// NOTE: web user == thundergoblin == owner of the 700 dir, so this write works.
// If ownership ever moves to www-data, both this append AND the board's on-load read break.
if (!is_dir(CASH_DIR) && !@mkdir(CASH_DIR, 0700, true)) {
    fail('could not create cash dir');
}

$ts     = date('c');   // ISO 8601 with Tokyo offset
$record = [
    'currency'   => $currency,
    'amount'     => $amount,
    'ts'         => $ts,
    'source'     => 'manual_typed',   // enum per ET note #16; 'api_verified' reserved for Wise
    'confidence' => 0.98,             // a typed balance is a strong prior, not gospel (might be mistyped)
];
$json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// append-only, locked — mirrors the manifest idiom in ai_secure/confirm_item.php
if (@file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
    fail('could not write snapshot');
}

echo json_encode(['ok' => true, 'currency' => $currency, 'amount' => $amount, 'ts' => $ts]);
