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

/* ---- accounting tag (which account/source paid) -----------------------------
 * So a future Lemur-13 reconciler can scope-match a document to the right statement
 * (badmin #281). Split like SECURE_CATEGORIES_*: accounts are mostly country-bound, and
 * showing paypay in Adelaide is the same noise as showing an Australian bank in Tokyo.
 *
 *   ACCOUNT_TAGS_SHARED       — usable anywhere; 'unknown' is first and is the default.
 *   ACCOUNT_TAGS_BY_CURRENCY  — that country's own accounts, keyed by currency code.
 *
 * Chip order matters: index.php renders SHARED first, then the active currency's own.
 * Never trust a client value; validate via account_tag_ok().
 */
const ACCOUNT_TAGS_SHARED = ['unknown', 'cash', 'wise', 'paypal'];

const ACCOUNT_TAGS_BY_CURRENCY = [
    'JPY' => ['mufg-bank', 'mufg-card', 'paypay', 'google-wallet'],
    'AUD' => [ /* TODO Rob: fill in once the Australian accounts are open */ ],
];

/** Chips to show for the 📍active currency: shared tags first, then that country's own. */
function account_tags_for(string $cur): array
{
    return array_values(array_unique(array_merge(
        ACCOUNT_TAGS_SHARED,
        ACCOUNT_TAGS_BY_CURRENCY[$cur] ?? []
    )));
}

/** Every tag any currency allows — the validation vocabulary, not a chip row. */
function account_tags_all(): array
{
    $out = ACCOUNT_TAGS_SHARED;
    foreach (ACCOUNT_TAGS_BY_CURRENCY as $tags) {
        $out = array_merge($out, $tags);
    }
    return array_values(array_unique($out));
}

/**
 * True iff $t is an allowed accounting tag, in ANY currency — deliberately wider than
 * the chips on screen. A JPY receipt filed while AUD is active legitimately carries
 * 'paypay', and validating against the active currency alone would silently downgrade
 * it to 'unknown'.
 */
function account_tag_ok(string $t): bool
{
    return in_array($t, account_tags_all(), true);
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
 *     currency code (CASH_CURRENCIES). The single 📍active currency in
 *     cash_active.json picks exactly one list — no union, so a tapped chip
 *     always names one country's budget line.
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
 * Quick-chip categories for the 📍active currency. An unknown code or '' (nothing
 * pinned) yields none, and the page falls back to its search box over the full
 * vocabulary — an empty chip row is a fine outcome, a wrong one is not.
 *
 * No union and no dedup: with one active currency there is exactly one list, which is
 * the point. 'Needs: 🛒 Groceries' appears under both JPY and AUD, so a merged row
 * could never say which country a tapped chip meant.
 *
 * Defensive normalization because secure_categories() may be answering from
 * secure_categories.json, where 'common' is whatever that file happens to hold.
 */
function secure_common_categories(string $cur): array
{
    $chips = secure_categories()['common'][$cur] ?? [];
    return is_array($chips) ? array_values(array_filter(array_map('strval', $chips))) : [];
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
 * The single 📍active currency code, or '' when none is set.
 *
 * Several pages read cash_active.json — the cash board, /ai_secure, name_item.php — and
 * every one of them needs the same normalization: tolerate a missing or hand-edited
 * file, drop codes no longer on the whitelist, and take the FIRST valid entry (state
 * written before single-select may still list several). Centralized so they cannot
 * drift apart; is_string() guards a hand-edited file holding numbers or nested arrays,
 * which would otherwise be a TypeError against cash_currency_ok()'s string parameter.
 *
 * '' is a real answer, not an error: it means Rob has no currency pinned, and callers
 * that need one (filing a receipt) must ask him rather than guess.
 */
function cash_active_currency(): string
{
    if (!is_file(CASH_ACTIVE_FILE)) {
        return '';
    }
    $data   = json_decode((string) file_get_contents(CASH_ACTIVE_FILE), true) ?: [];
    $active = array_values(array_filter(
        $data['active'] ?? [],
        fn($c) => is_string($c) && cash_currency_ok($c)
    ));
    return $active[0] ?? '';
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
