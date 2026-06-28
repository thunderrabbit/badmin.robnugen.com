<?php
/**
 * secure_config_sample.php — template for /home/thundergoblin/secure_config.php.
 *
 * Copy ABOVE the web root, exactly like bulletproof_config.php / anthropic_config.php:
 *   cp ai_secure/secure_config_sample.php /home/thundergoblin/secure_config.php
 * Every page requires it by that absolute path. There is NO secret in here — it is
 * paths + whitelists — but a config file has no business inside the served tree
 * (https://badmin.robnugen.com/ai_secure/secure_config.php), so it lives above it.
 * (git-ignored by the *_config.php rule; only this sample is committed.)
 *
 * secure_bin ALSO lives OUTSIDE the public web root (/home/thundergoblin/b.robnugen.com).
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
const SECURE_BUCKETS = ['receipts', 'bills_paid', 'taxes_filed', 'statements'];

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

/* ---- YNAB category hint (which budget category this document belongs to) ----
 * The full YNAB "Group: Subcategory" string is stored verbatim on each filed
 * record (decision: exact match, zero translation for the future YNAB agent /
 * reconciler — badmin #281 family). OPTIONAL: empty means "let the YNAB agent
 * categorize from scratch." It is a HINT, not a path — category_ok() guards
 * vocabulary consistency, not filesystem safety.
 *
 *   SECURE_CATEGORIES_ALL    — full searchable vocabulary (one shared list).
 *   SECURE_CATEGORIES_COMMON — per-currency quick chips, keyed by cash-board
 *     currency code (CASH_CURRENCIES). cash_active.json picks which show; when
 *     several currencies are active, index.php UNIONS them (deduped).
 *
 * index.php shows a shortened display (the part after the group prefix) but
 * stores/validates the WHOLE string. Every COMMON entry is also in ALL so a tapped
 * chip validates.
 *
 * SEED ONLY: hand-mapped from Rob's stated uploads + ynab/Code.gs. The authoritative
 * master lives in the YNAB Google Sheet (gas / NTT / domains / software subs are not
 * all enumerable from Code.gs). Phase 2: a weekly job may write
 * secure_bin/secure_categories.json (mirrored to Lemur where YNAB lives); when present
 * and well-formed, secure_categories() prefers it over these consts. Edit/extend freely.
 */
const SECURE_CATEGORIES_COMMON = [
    // Japan: groceries, book, SOGO, transport, gas, NTT finance
    'JPY' => [
        'Needs: 🛒 Groceries',
        'Business: Book',
        'Wants: SOGO',
        'Needs: 🚈 Train/Bus/Bicycle',
        'Business: Travel',
        'Bills: Gas',
        'Communications: NTT finance',
    ],
    // Australia: hunbun dates, groceries, travel, adelaide metro, training, book
    'AUD' => [
        'Needs: 🛒 Groceries',
        'Wants: 🍽️ Hunbun dates and stuff',
        'Wants: Travel With Hunbun',
        'Needs: 🚈 Train/Bus/Bicycle',
        'Training: Trainings',
        'Business: Book',
    ],
];

/** Full searchable vocabulary (superset of every COMMON entry). Order = search order. */
const SECURE_CATEGORIES_ALL = [
    'Needs: 🛒 Groceries',
    'Needs: 🚈 Train/Bus/Bicycle',
    'Needs: 🏠 Household Items',
    'Needs: 💆 Personal Care',
    'Needs: Lunch',
    'Needs: Hydration',
    'Bills: Gas',
    'Bills: Electricity',
    'Bills: Water',
    'Bills: 📺 TV streaming',
    'Communications: NTT finance',
    'Communications: Mobile phone',
    'Communications: Software subscriptions',
    'Fixed: Health Insurance',
    'Fixed: Japanese Pension',
    'Fixed: Residence Tax',
    'Business: Book',
    'Business: Travel',
    'Business: Office Supplies',
    'Business: Connection Coaching',
    'Domains: robnugen.com',
    'Domains: plasticaddy.com',
    'Solo Practice: Workshop Commute',
    'Solo Practice: Workshop Food',
    'Solo Practice: Workshop Rent',
    'Solo Practice: Workshop Supplies',
    'Training: Trainings',
    'Training: Productivity',
    'Training: Training Transportation',
    'Training: Teal Swan Premium',
    'Wants: SOGO',
    'Wants: 🍽️ Hunbun dates and stuff',
    'Wants: Travel With Hunbun',
    'Wants: Entertainment',
    'Wants: Snacks',
    'Wants: Gifts',
    'Wants: 👥 Rob spending money',
    'Health: Block Therapy',
];

/**
 * Effective category vocabulary: prefers secure_bin/secure_categories.json (the
 * phase-2 auto-update target, mirrored to Lemur) when present and well-formed, else
 * the SECURE_CATEGORIES_* consts. Returns ['all' => [...], 'common' => [cur => [...]]].
 */
function secure_categories(): array
{
    $file = SECURE_BIN_ROOT . "/secure_categories.json";
    if (is_file($file)) {
        $j = json_decode((string) file_get_contents($file), true);
        if (is_array($j) && isset($j['all']) && is_array($j['all']) && $j['all']) {
            return [
                'all'    => array_values(array_filter(array_map('strval', $j['all']))),
                'common' => (isset($j['common']) && is_array($j['common'])) ? $j['common'] : [],
            ];
        }
    }
    return ['all' => SECURE_CATEGORIES_ALL, 'common' => SECURE_CATEGORIES_COMMON];
}

/**
 * Quick-chip categories for the given active currency codes (from cash_active.json),
 * unioned in COMMON order with duplicates removed. Unknown/inactive currencies add nothing.
 */
function secure_common_categories(array $active): array
{
    $common = secure_categories()['common'];
    $out = [];
    foreach ($active as $cur) {
        foreach ($common[$cur] ?? [] as $c) {
            if (!in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
    }
    return $out;
}

/** True iff $c is an allowed category. Empty is NOT ok — callers treat '' as "no category". */
function category_ok(string $c): bool
{
    return $c !== '' && in_array($c, secure_categories()['all'], true);
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
