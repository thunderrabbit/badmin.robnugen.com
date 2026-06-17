<?php
/**
 * name_item.php — stage a photo and ask Claude for 3 names + category + description.
 *
 * Stage 1 of the single-item flow. Always returns JSON.
 *
 *  First call (new photo):   POST password, model?, photo=<file>
 *                            -> saves photo to .staging/<token>.<ext>,
 *                               writes .staging/<token>.json sidecar (orig, ext),
 *                               returns {ok, token, names[3], category, description, model}
 *
 *  Re-ask (different model):  POST password, model=sonnet, token=<token>
 *                            -> reuses the staged photo, no re-upload.
 *
 * AI is assistive: on any failure ok=false and the page falls back to a manual name.
 */

header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";     // $bulletproof_password_hash
require_once "/home/thundergoblin/anthropic_config.php";       // $anthropic_api_key
require_once __DIR__ . "/item_naming.php";

function fail(string $msg, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
    exit;
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

$model = (($_POST['model'] ?? '') === 'sonnet') ? 'sonnet' : 'haiku';

// token is server-generated; on re-ask the client sends it back. Sanitize hard.
$token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');

$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (!is_dir(ITEMS_STAGING)) {
    @mkdir(ITEMS_STAGING, 0755, true);
}

$staged_path = '';

if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    // ---- new photo: validate, stage it, write sidecar -----------------------
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        fail('upload error code ' . $_FILES['photo']['error']);
    }
    $orig = $_FILES['photo']['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === 'jpe') { $ext = 'jpg'; }
    if (!in_array($ext, $allowed_ext, true)) {
        fail("unsupported image type '$ext' (allowed: " . implode(', ', $allowed_ext) . "). "
           . "If this is an iPhone HEIC, set the camera to 'Most Compatible' (JPEG).");
    }
    $token = bin2hex(random_bytes(8));
    $staged_path = ITEMS_STAGING . "/$token.$ext";
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $staged_path)) {
        fail('could not stage uploaded photo');
    }
    @file_put_contents(ITEMS_STAGING . "/$token.json",
        json_encode(['orig' => $orig, 'ext' => $ext]));
} elseif ($token !== '') {
    // ---- re-ask: find the already-staged photo by token ---------------------
    $matches = glob(ITEMS_STAGING . "/$token.*");
    foreach (($matches ?: []) as $m) {
        if (in_array(strtolower(pathinfo($m, PATHINFO_EXTENSION)), $allowed_ext, true)) {
            $staged_path = $m;
            break;
        }
    }
    if ($staged_path === '') {
        fail('staged photo not found for token (it may have expired)');
    }
} else {
    fail('no photo uploaded and no token provided');
}

$result = claude_name_image(
    $staged_path,
    $model,
    recent_item_names(),
    $anthropic_api_key ?? '',
    $ITEM_CATEGORIES
);

echo json_encode([
    'ok'          => $result['ok'],
    'token'       => $token,
    'names'       => $result['names'],
    'category'    => $result['category'],
    'description' => $result['description'],
    'model'       => $model,
    'error'       => $result['error'],
]);
