<?php
/**
 * name_item.php — stage one or more photos of a single item and ask Claude for
 * 3 names + a category + a description + a per-photo "view" angle. Always JSON.
 *
 * Stage 1 of the item flow. The token identifies the item GROUP; every photo of
 * that item is staged under it as `<token>-<i>.<ext>` with a `<token>.json`
 * sidecar listing each photo {idx, file, orig, ext, view}.
 *
 *  First call (new photos):  POST password, model?, photo[]=<files>
 *                            -> new token, stages each photo, names them all,
 *                               returns {ok, token, count, names[3], category,
 *                                        description, views[count], model}
 *
 *  Add another:              POST password, token=<token>, photo[]=<files>
 *                            -> appends to the same group, re-names all photos.
 *
 *  Re-ask (different model):  POST password, model=sonnet, token=<token>
 *                            -> reuses the staged group, no re-upload.
 *
 * AI is assistive: on any failure ok=false and the page falls back to manual names.
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

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

$model = (($_POST['model'] ?? '') === 'sonnet') ? 'sonnet' : 'haiku';

// token is server-generated; on re-ask / add-another the client sends it back. Sanitize hard.
$token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');

$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (!is_dir(ITEMS_STAGING)) {
    @mkdir(ITEMS_STAGING, 0755, true);
}

$uploaded = uploaded_photos();

if ($uploaded) {
    // ---- new photo(s): brand-new item, or "add another" onto an existing token
    if ($token === '') {
        $token = bin2hex(random_bytes(8));
    }
    $sidecar = ITEMS_STAGING . "/$token.json";
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
        if (!move_uploaded_file($u['tmp_name'], ITEMS_STAGING . "/$file")) {
            fail('could not stage uploaded photo');
        }
        $photos[] = ['idx' => $next, 'file' => $file, 'orig' => $u['name'], 'ext' => $ext, 'view' => null];
        $next++;
    }
} elseif ($token !== '' && is_file(ITEMS_STAGING . "/$token.json")) {
    // ---- re-ask: reuse the already-staged group
    $sidecar = ITEMS_STAGING . "/$token.json";
    $meta    = json_decode((string) file_get_contents($sidecar), true) ?: [];
    $photos  = $meta['photos'] ?? [];
} else {
    fail('no photo uploaded and no token provided');
}

// keep only photos whose staged file still exists, preserving order
$photos = array_values(array_filter($photos, fn($p) => is_file(ITEMS_STAGING . "/" . ($p['file'] ?? ''))));
if (!$photos) {
    fail('staged photos not found for token (they may have expired)');
}
$paths = array_map(fn($p) => ITEMS_STAGING . "/" . $p['file'], $photos);

$result = claude_name_images(
    $paths,
    $model,
    recent_item_names(),
    $anthropic_api_key ?? '',
    $ITEM_CATEGORIES
);

// persist the suggested views back into the sidecar so confirm_item.php is authoritative
foreach ($photos as $i => &$p) {
    $p['view'] = $result['views'][$i] ?? (string) ($i + 1);
}
unset($p);
@file_put_contents(ITEMS_STAGING . "/$token.json", json_encode(['photos' => $photos]));

echo json_encode([
    'ok'          => $result['ok'],
    'token'       => $token,
    'count'       => count($photos),
    'names'       => $result['names'],
    'category'    => $result['category'],
    'description' => $result['description'],
    'views'       => $result['views'],
    'model'       => $model,
    'error'       => $result['error'],
]);
