<?php
/**
 * ai_secure/name_item.php — secure fork of ai/name_item.php (stage 1).
 *
 * Stages one or more photos of a SINGLE financial document (a receipt, a paid
 * bill, or a tax doc) to the NON-PUBLIC secure_bin staging dir, then asks Claude
 * for 3 filename suggestions + a description + a per-photo "view". Always JSON.
 *
 * Differences from ai/name_item.php:
 *   - stages under SECURE_BIN_STAGING (outside the web root), never the public tree
 *   - the prompt names a financial DOCUMENT, not a physical possession
 *   - the routing bucket (receipts|bills_paid|taxes_filed) replaces the free category
 *
 *  First call (new photos):  POST password, bucket, model?, photo[]=<files>
 *  Add another:              POST password, bucket, token=<token>, photo[]=<files>
 *  Re-ask (different model):  POST password, bucket, model=sonnet, token=<token>
 *
 * AI is assistive: on any failure ok=false and the page falls back to a manual name.
 */

header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once "/home/thundergoblin/anthropic_config.php";     // $anthropic_api_key
require_once __DIR__ . "/../ai/item_naming.php";             // transport + slug/parse helpers (no side effects)
require_once __DIR__ . "/secure_config.php";                 // SECURE_BIN_* + secure_bucket_dir()

function fail(string $msg, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
    exit;
}

/** Normalize $_FILES['photo'] (single or photo[]) into a flat list of real uploads. */
function uploaded_photos(): array
{
    if (empty($_FILES['photo'])) {
        return [];
    }
    $f = $_FILES['photo'];
    $out = [];
    if (is_array($f['name'])) {
        for ($i = 0, $c = count($f['name']); $i < $c; $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i]];
        }
    } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $out[] = ['name' => $f['name'], 'tmp_name' => $f['tmp_name'], 'error' => $f['error']];
    }
    return $out;
}

/**
 * Ask Claude to look at all photos of ONE financial document and propose 3
 * filename slugs + a one-line description + a per-photo "view" word.
 * Reuses item_anthropic_vision()/item_parse_json()/item_normalize_views() verbatim.
 *
 * @param string[] $image_paths staged photos of a single document, in order
 * @return array { ok, names[], description, views[], model, error, raw }
 */
function claude_name_secure_docs(array $image_paths, string $model, string $bucket, array $recent_names, string $api_key): array
{
    $out = ['ok' => false, 'names' => [], 'description' => '', 'views' => [],
            'model' => item_model_id($model), 'error' => '', 'raw' => ''];

    $image_paths = array_values($image_paths);
    $n = count($image_paths);
    if ($n === 0) {
        $out['error'] = "no images given";
        return $out;
    }

    // bucket-specific guidance so the suggested name matches how the doc is filed
    $guides = [
        'receipts'    => "a PURCHASE RECEIPT. Name it vendor + what was bought, e.g. " .
                         "\"lawson snacks\", \"jr east suica charge\", \"yodobashi usb cable\".",
        'bills_paid'  => "a PAID BILL / proof of payment. Name it provider + service + period, e.g. " .
                         "\"tokyo gas may\", \"softbank mobile 2026 05\", \"tepco electricity april\".",
        'taxes_filed' => "a TAX DOCUMENT (return, statement, form). Name it jurisdiction + form/type + year, e.g. " .
                         "\"us 1040 2025\", \"japan final return 2025\", \"fbar 2025\".",
        'statements'  => "a BANK / ACCOUNT STATEMENT. Name it institution + account + period, e.g. " .
                         "\"mufg checking 2026 05\", \"wise usd 2026 q1\", \"paypal april 2026\".",
    ];
    $guide = $guides[$bucket] ?? "a financial document. Name it by its issuer and what it is.";

    $examples = $recent_names
        ? "Here are recent filenames Rob has chosen for documents, so you match his style:\n- "
          . implode("\n- ", $recent_names) . "\n\n"
        : "";

    $intro = $n === 1
        ? "You are helping Rob file one financial document he photographed. Look at the photo. It is $guide\n\n"
        : "You are helping Rob file one financial document he photographed. There are $n photos of the SAME " .
          "document (pages / front-back), given in order. It is $guide\n\n";

    $views_key = $n === 1
        ? "  \"views\": [exactly 1 short lowercase word for the photo, e.g. \"front\"]\n"
        : "  \"views\": [exactly $n short lowercase words, ONE PER PHOTO IN ORDER, " .
          "each a single word like \"front\", \"back\", \"page1\", \"page2\", \"detail\"]\n";

    $prompt =
        $intro . $examples .
        "Read any vendor, date, and total visible. Return STRICT JSON only (no prose, no code fence) " .
        "with exactly these keys:\n" .
        "{\n" .
        "  \"names\": [3 short human-readable names, 2-5 words each, specific not generic; " .
        "lowercase is fine; include the vendor and, when clearly legible, the amount or period],\n" .
        "  \"description\": one factual sentence (vendor, what, total + currency, date if visible),\n" .
        $views_key .
        "}";

    $resp = item_anthropic_vision($image_paths, $prompt, $out['model'], $api_key);
    $out['raw'] = $resp['raw'];
    if (!$resp['ok']) {
        $out['error'] = $resp['error'];
        return $out;
    }

    $parsed = item_parse_json($resp['text']);
    if (!isset($parsed['names']) || !is_array($parsed['names'])) {
        $out['error'] = "could not parse JSON from model";
        return $out;
    }

    $out['names']       = array_values(array_filter(array_map('strval', $parsed['names'])));
    $out['description'] = isset($parsed['description']) ? trim((string) $parsed['description']) : '';
    $out['views']       = item_normalize_views($parsed['views'] ?? [], $n);
    $out['ok']          = count($out['names']) > 0;
    return $out;
}

// ---- request handling -------------------------------------------------------
if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

$bucket = $_POST['bucket'] ?? '';
if (secure_bucket_dir($bucket) === null) {
    fail('invalid or missing bucket (allowed: ' . implode(', ', SECURE_BUCKETS) . ')');
}

// accounting tag (badmin #281): which account paid. Never trust the client value;
// anything off the allowlist fails safe to the default 'unknown' rather than blocking.
$account_tag = $_POST['account_tag'] ?? 'unknown';
if (!account_tag_ok($account_tag)) {
    $account_tag = 'unknown';
}

// YNAB category hint (optional): the full "Group: Subcategory" string, validated
// against the vocabulary. Empty / off-list fails safe to '' = "no hint, the YNAB
// agent categorizes from scratch". Like account_tag, it rides the sidecar rewrite.
$category = trim($_POST['category'] ?? '');
if (!category_ok($category)) {
    $category = '';
}

$model = (($_POST['model'] ?? '') === 'sonnet') ? 'sonnet' : 'haiku';

// token is server-generated; on re-ask / add-another the client sends it back. Sanitize hard.
$token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');

$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];   // PDF naming is a flagged follow-up

if (!is_dir(SECURE_BIN_STAGING)) {
    @mkdir(SECURE_BIN_STAGING, 0700, true);
}

$uploaded = uploaded_photos();

if ($uploaded) {
    // ---- new photo(s): brand-new document, or "add another" onto an existing token
    if ($token === '') {
        $token = bin2hex(random_bytes(8));
    }
    $sidecar = SECURE_BIN_STAGING . "/$token.json";
    $meta    = is_file($sidecar) ? (json_decode((string) file_get_contents($sidecar), true) ?: []) : [];
    $photos  = $meta['photos'] ?? [];

    $next = 0;
    foreach ($photos as $p) {
        $next = max($next, ((int) ($p['idx'] ?? -1)) + 1);
    }

    foreach ($uploaded as $u) {
        if ($u['error'] !== UPLOAD_ERR_OK) {
            fail('upload error code ' . $u['error']);
        }
        if (!is_uploaded_file($u['tmp_name'])) {
            fail('invalid upload');
        }
        $ext = strtolower(pathinfo($u['name'], PATHINFO_EXTENSION));
        if ($ext === 'jpe') { $ext = 'jpg'; }
        if (!in_array($ext, $allowed_ext, true)) {
            fail("unsupported image type '$ext' (allowed: " . implode(', ', $allowed_ext) . ").");
        }
        $file = "$token-$next.$ext";
        if (!move_uploaded_file($u['tmp_name'], SECURE_BIN_STAGING . "/$file")) {
            fail('could not stage uploaded photo');
        }
        $photos[] = ['idx' => $next, 'file' => $file, 'orig' => $u['name'], 'ext' => $ext, 'view' => null];
        $next++;
    }
} elseif ($token !== '' && is_file(SECURE_BIN_STAGING . "/$token.json")) {
    // ---- re-ask: reuse the already-staged group
    $sidecar = SECURE_BIN_STAGING . "/$token.json";
    $meta    = json_decode((string) file_get_contents($sidecar), true) ?: [];
    $photos  = $meta['photos'] ?? [];
} else {
    fail('no photo uploaded and no token provided');
}

// keep only photos whose staged file still exists, preserving order
$photos = array_values(array_filter($photos, fn($p) => is_file(SECURE_BIN_STAGING . "/" . ($p['file'] ?? ''))));
if (!$photos) {
    fail('staged photos not found for token (they may have expired)');
}
$paths = array_map(fn($p) => SECURE_BIN_STAGING . "/" . $p['file'], $photos);

$result = claude_name_secure_docs(
    $paths,
    $model,
    $bucket,
    recent_item_names(25, SECURE_BIN_MANIFEST),
    $anthropic_api_key ?? ''
);

// persist suggested views + the chosen bucket back into the sidecar so confirm_item.php is authoritative
foreach ($photos as $i => &$p) {
    $p['view'] = $result['views'][$i] ?? (string) ($i + 1);
}
unset($p);
@file_put_contents(SECURE_BIN_STAGING . "/$token.json", json_encode(['bucket' => $bucket, 'account_tag' => $account_tag, 'category' => $category, 'photos' => $photos]));

echo json_encode([
    'ok'          => $result['ok'],
    'token'       => $token,
    'bucket'      => $bucket,
    'category'    => $category,
    'count'       => count($photos),
    'names'       => $result['names'],
    'description' => $result['description'],
    'views'       => $result['views'],
    'model'       => $model,
    'error'       => $result['error'],
]);
