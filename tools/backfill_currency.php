<?php
/**
 * tools/backfill_currency.php — one-off: give every record filed before per-receipt
 * currency existed a currency, and move the shared manifest into a per-currency ledger.
 *
 * Run ON THE SERVER as thundergoblin — secure_bin is mode 700 and lives above the web
 * root, so nothing else can read it:
 *
 *   php tools/backfill_currency.php                         # dry run, prints a plan
 *   php tools/backfill_currency.php --only=receipts,bills_paid --write
 *   php tools/backfill_currency.php --only=taxes_filed --currency=USD --write
 *
 * SCOPE IS DELIBERATELY OPT-IN. Everything in receipts/ and bills_paid/ predates the move
 * to Australia, so JPY is right for all of it. taxes_filed/ and statements/ are NOT safe
 * to assume: name_item.php's own prompt offers "us 1040 2025" and "wise usd 2026 q1" as
 * examples, and stamping those JPY would invent the exact wrong-currency error this whole
 * change exists to prevent. So --only is required for --write, and the dry run breaks the
 * count down per bucket so the decision is made looking at real numbers.
 *
 * Idempotent: a record that already carries a currency is skipped, so a re-run after a
 * partial failure resumes instead of double-writing.
 */

if (PHP_SAPI !== 'cli') {          // it sits under the web root; never let it be fetched
    http_response_code(404);
    exit;
}

require_once "/home/thundergoblin/secure_config.php";

$opts     = getopt('', ['only::', 'currency::', 'write']);
$write    = isset($opts['write']);
$currency = strtoupper($opts['currency'] ?? 'JPY');
$only     = array_values(array_filter(array_map('trim', explode(',', $opts['only'] ?? ''))));

if (!cash_currency_ok($currency)) {
    fwrite(STDERR, "refusing: --currency={$currency} is not on the cash board\n");
    exit(1);
}
foreach ($only as $b) {
    if (secure_bucket_dir($b) === null) {
        fwrite(STDERR, "refusing: --only={$b} is not a bucket\n");
        exit(1);
    }
}
if ($write && !$only) {
    fwrite(STDERR, "refusing: --write needs --only=<buckets>. Run the dry run first.\n");
    exit(1);
}

/** Insert 'currency' straight after 'file' so backfilled records read like new ones. */
function with_currency(array $rec, string $currency): array
{
    $out = [];
    foreach ($rec as $k => $v) {
        $out[$k] = $v;
        if ($k === 'file') {
            $out['currency'] = $currency;
        }
    }
    if (!isset($out['currency'])) {      // no 'file' key: append rather than drop it
        $out['currency'] = $currency;
    }
    return $out;
}

$encode = fn(array $r) => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ---- per-image sidecars, grouped by bucket ---------------------------------
$plan = [];      // bucket => ['todo' => [paths], 'done' => int]

foreach (SECURE_BUCKETS as $bucket) {
    $dir = secure_bucket_dir($bucket);
    $plan[$bucket] = ['todo' => [], 'done' => 0];
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'json') {
            continue;
        }
        $rec = json_decode((string) file_get_contents($f->getPathname()), true);
        if (!is_array($rec)) {
            continue;
        }
        if (isset($rec['currency']) && $rec['currency'] !== '') {
            $plan[$bucket]['done']++;
        } else {
            $plan[$bucket]['todo'][] = $f->getPathname();
        }
    }
}

echo $write ? "WRITING (currency={$currency})\n\n" : "DRY RUN — nothing will be changed\n\n";
echo "per-image sidecars:\n";
foreach ($plan as $bucket => $p) {
    $n        = count($p['todo']);
    $selected = in_array($bucket, $only, true);
    $mark     = $selected ? '->' : '  ';
    $note     = '';
    if ($n > 0 && !$selected) {
        $note = in_array($bucket, ['taxes_filed', 'statements'], true)
            ? '   (CHECK: a US return or a Wise USD statement is not ' . $currency . ')'
            : '   (not selected)';
    }
    printf("%s %-12s %4d to stamp, %4d already done%s\n", $mark, $bucket, $n, $p['done'], $note);
}

// ---- the shared manifest ----------------------------------------------------
$legacy = SECURE_BIN_ROOT . "/secure_manifest.jsonl";
$target = secure_manifest_path($currency);

echo "\nmanifest:\n";
if (!is_file($legacy)) {
    echo "   no legacy secure_manifest.jsonl — nothing to move\n";
} else {
    $lines = file($legacy, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    printf("   secure_manifest.jsonl (%d lines) -> %s\n", count($lines), basename($target));
    if (is_file($target)) {
        echo "   TARGET EXISTS — lines will be APPENDED to it\n";
    }
}

if (!$write) {
    echo "\nRe-run with --only=<buckets> --write to apply.\n";
    exit;
}

// ---- apply ------------------------------------------------------------------
$stamped = 0;
foreach ($only as $bucket) {
    foreach ($plan[$bucket]['todo'] as $path) {
        $rec = json_decode((string) file_get_contents($path), true);
        if (!is_array($rec)) {
            continue;
        }
        if (file_put_contents($path, $encode(with_currency($rec, $currency))) === false) {
            fwrite(STDERR, "could not write $path\n");
            exit(1);
        }
        $stamped++;
    }
}
echo "\nstamped $stamped sidecar(s)\n";

if (is_file($legacy)) {
    $out = [];
    foreach (file($legacy, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $rec   = json_decode($line, true);
        $out[] = (is_array($rec) && !(isset($rec['currency']) && $rec['currency'] !== ''))
            ? $encode(with_currency($rec, $currency))
            : $line;                       // already stamped, or unparseable — keep verbatim
    }
    if (file_put_contents($target, implode("\n", $out) . "\n", FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, "could not write $target\n");
        exit(1);
    }
    // Renamed, not deleted: if anything above was wrong, the original is still right here.
    rename($legacy, $legacy . '.migrated');
    printf("wrote %d line(s) to %s, kept the original as secure_manifest.jsonl.migrated\n",
        count($out), basename($target));
}
