<?php
/**
 * confirm_item.php — finalize a named item (one or more photos).
 *
 * Stage 2 of the item flow. Takes the staged photo GROUP + Rob's chosen name,
 * files it on b.robnugen.com, generates a _1000 + thumb per photo, appends one
 * manifest line per photo, and returns the live URL(s) + a markdown embed.
 * Always returns JSON.
 *
 *  POST: password, token, name, category, tags?, description?, model?
 *
 * Single photo  -> filed FLAT in the category dir, view=null (unchanged):
 *    .../items/<category>/2026-jun-17-<slug>.jpg
 * Multiple photos -> a per-item folder, one file per angle:
 *    .../items/<category>/<slug_underscored>/2026-jun-17-<slug>-<view>.jpg
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";        // $bulletproof_password_hash
require_once __DIR__ . "/item_naming.php";                        // ITEMS_*, slugify_item, dir_slug_item
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
$name        = trim($_POST['name'] ?? '');

// ---- load the staged photo group from its sidecar ---------------------------
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$sidecar = ITEMS_STAGING . "/$token.json";
$meta    = is_file($sidecar) ? (json_decode((string) file_get_contents($sidecar), true) ?: []) : [];
$photos  = $meta['photos'] ?? [];

// keep only photos whose staged file still exists, preserving order
$photos = array_values(array_filter($photos, function ($p) use ($allowed_ext) {
    $f = ITEMS_STAGING . "/" . ($p['file'] ?? '');
    return is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed_ext, true);
}));
if (!$photos) {
    fail('staged photos not found for token (they may have expired)');
}
$multi = count($photos) > 1;

// ---- build destination dirs -------------------------------------------------
$category_dir = ITEMS_BASE_DIR . "/" . $category;
if (!is_dir($category_dir) && !@mkdir($category_dir, 0755, true)) {
    fail("could not create category dir: $category");
}

if ($multi) {
    $dest_dir = $category_dir . "/" . dir_slug_item($slug);   // per-item folder, underscores
    $rel_dir  = $category . "/" . dir_slug_item($slug);
    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0755, true)) {
        fail("could not create item dir for: $slug");
    }
} else {
    $dest_dir = $category_dir;
    $rel_dir  = $category;
}
$thumb_dir = $dest_dir . "/thumbs/";
if (!is_dir($thumb_dir) && !@mkdir($thumb_dir, 0755, true)) {
    fail("could not create thumbs dir");
}

$date_prefix = strtolower(date("Y-M-d"));            // 2026-jun-17

// ---- file each photo --------------------------------------------------------
$filed   = [];   // for the response: one entry per photo
$primary = null; // first photo drives the headline URL + markdown

foreach ($photos as $p) {
    $staged_path = ITEMS_STAGING . "/" . $p['file'];
    $ext         = strtolower($p['ext'] ?? pathinfo($staged_path, PATHINFO_EXTENSION));
    $view        = $multi ? (string) ($p['view'] ?? '') : null;

    $stem     = $multi && $view !== '' ? "$date_prefix-$slug-$view" : "$date_prefix-$slug";
    $basename = "$stem.$ext";
    $final    = "$dest_dir/$basename";

    // never clobber a sacred file: uniquify if needed
    $n = 2;
    while (file_exists($final)) {
        $basename = "$stem-$n.$ext";
        $final    = "$dest_dir/$basename";
        $n++;
    }

    if (!@rename($staged_path, $final)) {
        fail('could not move staged photo into place');
    }

    // bake EXIF orientation BEFORE the resizes (GD's resize ignores EXIF)
    correct_image_orientation($final);

    $image_1000 = create_1000px_nail($final, $dest_dir, 0);
    create_500px_nail($final, $dest_dir, 0);
    $thumb_path = create_thumbnail($final, $thumb_dir, 0);

    $rel = $rel_dir . "/" . $basename;     // path under the items root

    // one manifest line per photo, sharing the item slug
    $line = json_encode([
        'item'        => $slug,            // grouping slug (shared across the item's photos)
        'file'        => $rel,
        'category'    => $category,
        'view'        => $view,            // angle for multi-photo; null for single
        'tags'        => $tags,
        'slug'        => $slug,
        'name'        => $name,
        'description' => $description,
        'model'       => item_model_id($model),
        'captured'    => date('c'),
        'orig'        => $p['orig'] ?? '',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @file_put_contents(ITEMS_MANIFEST, $line . "\n", FILE_APPEND | LOCK_EX);

    $url      = urlify($image_1000 ?: $final, 'https:');
    $markdown = ($image_1000 && $thumb_path) ? embed_markdown_func($image_1000, $thumb_path) : '';

    $entry = ['file' => $rel, 'view' => $view, 'url' => $url,
              'full_url' => urlify($final, 'https:'), 'markdown' => $markdown];
    $filed[] = $entry;
    if ($primary === null) { $primary = $entry; }
}

// ---- clean staging sidecar (photos already moved) ---------------------------
@unlink($sidecar);

// ---- respond ----------------------------------------------------------------
echo json_encode([
    'ok'       => true,
    'item'     => $slug,
    'count'    => count($filed),
    'file'     => $primary['file'],
    'url'      => $primary['url'],
    'full_url' => $primary['full_url'],
    'markdown' => $primary['markdown'],
    'photos'   => $filed,
]);
