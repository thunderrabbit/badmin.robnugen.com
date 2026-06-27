<?php
/**
 * secure_config_sample.php — template for ai_secure/secure_config.php.
 *
 * Copy to secure_config.php (git-ignored by the *_config.php rule). There is NO
 * secret in here; it is a config only so the secure-bin root can be overridden
 * per environment and so the path lives in exactly one place.
 *
 * secure_bin lives OUTSIDE the public web root (/home/thundergoblin/b.robnugen.com).
 * Nothing under SECURE_BIN_ROOT is ever web-served — that is the whole point.
 *
 * One-time server setup (as the thundergoblin user):
 *   mkdir -p /home/thundergoblin/secure_bin/{.staging,receipts,bills_paid,taxes_filed,cash}
 *   chmod 700 /home/thundergoblin/secure_bin /home/thundergoblin/secure_bin/*
 */

const SECURE_BIN_ROOT     = "/home/thundergoblin/secure_bin";
const SECURE_BIN_STAGING  = SECURE_BIN_ROOT . "/.staging";
const SECURE_BIN_MANIFEST = SECURE_BIN_ROOT . "/secure_manifest.jsonl";

/** The only destinations ai_secure will ever write to. Keys are the routing buckets. */
const SECURE_BUCKETS = ['receipts', 'bills_paid', 'taxes_filed'];

/**
 * Accounting tag: which account/source paid for the captured document, so a future
 * Lemur-13 reconciler can scope-match it to the right statement (badmin #281).
 * Chip order matters — index.php renders the row in this order; 'unknown' is the
 * first chip and the default. Never trust a client value; validate via account_tag_ok().
 */
const ACCOUNT_TAGS = ['unknown', 'cash', 'wise', 'mufg-bank', 'google-wallet', 'paypay', 'paypal', 'mufg-card'];

/** True iff $t is an allowed accounting tag. */
function account_tag_ok(string $t): bool
{
    return in_array($t, ACCOUNT_TAGS, true);
}

/**
 * Whitelist a bucket key and return its absolute dir, or null if not allowed.
 * Never concatenate user input into a path without passing it through here.
 */
function secure_bucket_dir(string $bucket): ?string
{
    return in_array($bucket, SECURE_BUCKETS, true)
        ? SECURE_BIN_ROOT . "/" . $bucket
        : null;
}

/* ---- cash balance board (multi-currency wallet snapshots) -------------------
 * A separate, DETERMINISTIC capability (no AI): /cash_balance records point-in-time
 * cash-on-hand per currency. Unlike receipts (transit, deleted after pull), these
 * files are PERSISTENT — the board reads the latest per currency on load — and are
 * mirrored to Lemur 13. See ynab issue #276 / ET note #16, stream 4 "balance checkpoints".
 */
const CASH_DIR         = SECURE_BIN_ROOT . "/cash";
const CASH_ACTIVE_FILE = CASH_DIR . "/cash_active.json";   // {"active":["IDR"],"updated":"..."}

/**
 * Stale-nag threshold (days). Applies ONLY to currencies the user has marked
 * 📍active in cash_active.json — a currency for a country you're not currently in
 * shows neutral age and never nags.
 */
const CASH_STALE_DAYS = 14;

/**
 * Ordered currency list for the wallet board. Order = on-screen row order.
 * code => flag emoji. Drag-to-reorder is a future nice-to-have; this is the
 * source of truth for now.
 */
const CASH_CURRENCIES = [
    'JPY' => '🇯🇵', 'AUD' => '🇦🇺', 'USD' => '🇺🇸',
    'IDR' => '🇮🇩', 'NZD' => '🇳🇿', 'MYR' => '🇲🇾',   // Bali / New Zealand / Malaysia
];

/** True iff $cur is an allowed currency (whitelist; never trust the client). */
function cash_currency_ok(string $cur): bool
{
    return array_key_exists($cur, CASH_CURRENCIES);
}

/**
 * Whitelisted absolute path to a currency's append-only snapshot file, or null.
 * Mirror of secure_bucket_dir(): never concatenate client input into a path
 * without passing through here. Code comes from the whitelist; filename is fixed-format.
 */
function cash_snapshot_path(string $cur): ?string
{
    return cash_currency_ok($cur) ? CASH_DIR . "/cash_{$cur}_snapshots.jsonl" : null;
}
