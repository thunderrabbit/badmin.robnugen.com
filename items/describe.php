<?php
/**
 * items/describe.php — stage one or more photos of a single possession and ask Claude
 * for a page title + a factual photo description + a typo-cleaned copy of Rob's own
 * write-up + any date literally visible on the object. Always returns JSON.
 *
 * Stage 1 of the item-ingest flow (sibling to ../ai/name_item.php, but for the Items:
 * wiki archive rather than the sayonara sale catalog). The token identifies the item
 * GROUP; every photo is staged under it as `<token>-<i>.<ext>` with a `<token>.json`
 * sidecar listing {idx, file, orig, ext}.
 *
 *  First call (new photos):  POST password, model?, text?, photo[]=<files>
 *                            -> new token, stages each photo, returns
 *                               {ok, token, count, title, photo_description,
 *                                cleaned_text, detected_date, model}
 *  Add another:              POST password, token=<token>, text?, photo[]=<files>
 *  Re-ask (Sonnet):          POST password, model=sonnet, text?, token=<token>
 *
 * AI is assistive: on failure ok=false and the page falls back to Rob's manual
 * title/date + his raw (uncleaned) text.
 */

header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";     // $bulletproof_password_hash
require_once "/home/thundergoblin/anthropic_config.php";       // $anthropic_api_key
require_once __DIR__ . "/item_ingest_lib.php";                 // ITEM_INGEST_*, claude_describe_item, item_uploaded_photos

function fail(string $msg, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
    exit;
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

$model = (($_POST['model'] ?? '') === 'sonnet') ? 'sonnet' : 'haiku';
$text  = (string) ($_POST['text'] ?? '');

// token is server-generated; on re-ask / add-another the client sends it back. Sanitize hard.
$token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');

$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (!is_dir(ITEM_INGEST_STAGING)) {
    @mkdir(ITEM_INGEST_STAGING, 0755, true);
}

$uploaded = item_uploaded_photos();

if ($uploaded) {
    // ---- new photo(s): brand-new item, or "add another" onto an existing token
    if ($token === '') {
        $token = bin2hex(random_bytes(8));
    }
    $sidecar = ITEM_INGEST_STAGING . "/$token.json";
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
            fail("unsupported image type '$ext' (allowed: " . implode(', ', $allowed_ext) . "). "
               . "If this is an iPhone HEIC, set the camera to 'Most Compatible' (JPEG).");
        }
        $file = "$token-$next.$ext";
        if (!move_uploaded_file($u['tmp_name'], ITEM_INGEST_STAGING . "/$file")) {
            fail('could not stage uploaded photo');
        }
        $photos[] = ['idx' => $next, 'file' => $file, 'orig' => $u['name'], 'ext' => $ext];
        $next++;
    }
    @file_put_contents($sidecar, json_encode(['photos' => $photos]));
} elseif ($token !== '' && is_file(ITEM_INGEST_STAGING . "/$token.json")) {
    // ---- re-ask: reuse the already-staged group
    $meta   = json_decode((string) file_get_contents(ITEM_INGEST_STAGING . "/$token.json"), true) ?: [];
    $photos = $meta['photos'] ?? [];
} else {
    fail('no photo uploaded and no token provided');
}

// keep only photos whose staged file still exists, preserving order
$photos = array_values(array_filter($photos, fn($p) => is_file(ITEM_INGEST_STAGING . "/" . ($p['file'] ?? ''))));
if (!$photos) {
    fail('staged photos not found for token (they may have expired)');
}
$paths = array_map(fn($p) => ITEM_INGEST_STAGING . "/" . $p['file'], $photos);

$result = claude_describe_item($paths, $model, $text, $anthropic_api_key ?? '');

echo json_encode([
    'ok'                => $result['ok'],
    'token'             => $token,
    'count'             => count($photos),
    'title'             => $result['title'],
    'photo_description' => $result['photo_description'],
    'cleaned_text'      => $result['cleaned_text'],
    'detected_date'     => $result['detected_date'],
    'model'             => $model,
    'error'             => $result['error'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
