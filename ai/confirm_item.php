<?php
/**
 * confirm_item.php — finalize one named item photo.
 *
 * Stage 2 of the single-item flow. Takes the staged photo + Rob's chosen name,
 * files it on b.robnugen.com, generates the _1000 + thumb, appends a manifest
 * line, and returns the live URL + a markdown embed. Always returns JSON.
 *
 *  POST: password, token, name, category, tags?, description?, model?
 *
 * Phase 1 = single photo, filed FLAT in the category dir:
 *    .../items/<category>/2026-jun-17-<slug>.jpg
 * (multi-photo per-item folders come in Phase 1b.)
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";        // $bulletproof_password_hash
require_once __DIR__ . "/item_naming.php";                        // ITEMS_*, slugify_item
require_once __DIR__ . "/../image_resize_lib.php";                // resize/thumb/url/embed helpers

function fail(string $msg): void
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

$token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
if ($token === '') {
    fail('missing token');
}

$slug = slugify_item($_POST['name'] ?? '');
if ($slug === '') {
    fail('a name is required');
}

// category: slugified; the curated list plus any typed "add new". Default "other".
$category = slugify_item($_POST['category'] ?? '');
if ($category === '') {
    $category = 'other';
}

// tags: free comma/space separated -> array of slugs
$tags = [];
foreach (preg_split('/[,\s]+/', $_POST['tags'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $t) {
    $ts = slugify_item($t);
    if ($ts !== '') { $tags[] = $ts; }
}

$description = trim($_POST['description'] ?? '');
$model       = (($_POST['model'] ?? '') === 'sonnet') ? 'sonnet' : 'haiku';

// ---- locate the staged photo + its sidecar ---------------------------------
$ext = '';
foreach ((glob(ITEMS_STAGING . "/$token.*") ?: []) as $m) {
    $e = strtolower(pathinfo($m, PATHINFO_EXTENSION));
    if (in_array($e, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $staged_path = $m;
        $ext = $e;
        break;
    }
}
if ($ext === '') {
    fail('staged photo not found for token (it may have expired)');
}
$sidecar = ITEMS_STAGING . "/$token.json";
$meta = is_file($sidecar) ? (json_decode((string) file_get_contents($sidecar), true) ?: []) : [];
$orig = $meta['orig'] ?? '';

// ---- build destination ------------------------------------------------------
$category_dir = ITEMS_BASE_DIR . "/" . $category;
$thumb_dir    = $category_dir . "/thumbs/";
if (!is_dir($category_dir) && !@mkdir($category_dir, 0755, true)) {
    fail("could not create category dir: $category");
}
if (!is_dir($thumb_dir) && !@mkdir($thumb_dir, 0755, true)) {
    fail("could not create thumbs dir");
}

$date_prefix = strtolower(date("Y-M-d"));            // 2026-jun-17
$basename    = "$date_prefix-$slug.$ext";
$final_path  = "$category_dir/$basename";

// never clobber a sacred file: uniquify if needed
$n = 2;
while (file_exists($final_path)) {
    $basename   = "$date_prefix-$slug-$n.$ext";
    $final_path = "$category_dir/$basename";
    $n++;
}

if (!@rename($staged_path, $final_path)) {
    fail('could not move staged photo into place');
}

// ---- bake EXIF orientation into the full image so portrait photos stay upright
// (must run BEFORE the resizes: GD's resize ignores EXIF). Mirrors legacy badmin.
correct_image_orientation($final_path);

// ---- generate _1000 + thumb (reused from image_resize_lib) ------------------
$image_1000 = create_1000px_nail($final_path, $category_dir, 0);
$thumb_path = create_thumbnail($final_path, $thumb_dir, 0);

// ---- append manifest line (one per photo, append-only) ----------------------
$rel = $category . "/" . $basename;     // path under the items root
$line = json_encode([
    'item'        => $slug,             // grouping slug (bare slug for single-photo)
    'file'        => $rel,
    'category'    => $category,
    'view'        => null,              // multi-photo angle (Phase 1b); null for single
    'tags'        => $tags,
    'slug'        => $slug,
    'name'        => trim($_POST['name'] ?? ''),
    'description' => $description,
    'model'       => item_model_id($model),
    'captured'    => date('c'),
    'orig'        => $orig,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@file_put_contents(ITEMS_MANIFEST, $line . "\n", FILE_APPEND | LOCK_EX);

// ---- clean staging sidecar (photo already moved) ----------------------------
@unlink($sidecar);

// ---- respond ----------------------------------------------------------------
$url      = urlify($image_1000 ?: $final_path, 'https:');
$markdown = ($image_1000 && $thumb_path) ? embed_markdown_func($image_1000, $thumb_path) : '';

echo json_encode([
    'ok'       => true,
    'file'     => $rel,
    'url'      => $url,
    'full_url' => urlify($final_path, 'https:'),
    'markdown' => $markdown,
]);
