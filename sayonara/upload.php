<?php
/**
 * sayonara/upload.php — file photo(s) for ONE catalog item chosen from the feed.
 *
 * The #4 "list-driven" uploader. Unlike /ai/, there is no AI naming step: the
 * item's slug/name/category come from the sayonara catalog feed (generated on
 * Lemur 13 from the per-item sidecars and deployed here). The uploaded photo is
 * filed under that slug, sized (_1000) + thumbed, and one manifest line per photo
 * is appended — exactly the schema /ai/ writes, so the Lemur-13 linker can join
 * images back to the catalog by slug.
 *
 *   POST: password, slug, photo[] (1+ files), view[]? (parallel angle words)
 *
 * Single photo  -> .../items/<category>/2026-jun-24-<slug>.jpg
 * Multiple       -> .../items/<category>/<slug_underscored>/2026-jun-24-<slug>-<view>.jpg
 *
 * badmin holds NO mg or Stripe secret; this only reads the feed + writes images
 * and the manifest.
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once __DIR__ . "/../ai/item_naming.php";             // ITEMS_*, slugify_item, dir_slug_item
require_once __DIR__ . "/../image_resize_lib.php";           // resize/thumb/url helpers

const SAYONARA_FEED = ITEMS_BASE_DIR . "/sayonara_feed.json";

function fail(string $msg): void
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

// ---- resolve the item from the catalog feed (slug is authoritative) ---------
$feed = is_file(SAYONARA_FEED)
    ? (json_decode((string) file_get_contents(SAYONARA_FEED), true) ?: [])
    : [];
$by_slug = [];
foreach ($feed as $it) {
    if (!empty($it['slug'])) { $by_slug[$it['slug']] = $it; }
}

$slug = slugify_item($_POST['slug'] ?? '');   // clean catalog slugs are slugify-stable
if ($slug === '' || !isset($by_slug[$slug])) {
    fail('unknown item slug (not in the catalog feed)');
}
$item     = $by_slug[$slug];
$name     = trim((string) ($item['name'] ?? $slug));
$category = slugify_item((string) ($item['category'] ?? 'other'));
if ($category === '') { $category = 'other'; }

// ---- collect uploaded photos ------------------------------------------------
if (empty($_FILES['photo']) || !is_array($_FILES['photo']['tmp_name'] ?? null)) {
    fail('no photos uploaded');
}
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$files = [];
$cnt   = count($_FILES['photo']['tmp_name']);
for ($i = 0; $i < $cnt; $i++) {
    if (($_FILES['photo']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { continue; }
    $tmp = $_FILES['photo']['tmp_name'][$i];
    if (!is_uploaded_file($tmp)) { continue; }
    $orig = (string) ($_FILES['photo']['name'][$i] ?? '');
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) { continue; }
    $view = '';
    if (isset($_POST['view'][$i])) {
        $view = slugify_item((string) $_POST['view'][$i]);
        if ($view !== '') { $view = explode('-', $view)[0]; }   // single word
    }
    $files[] = ['tmp' => $tmp, 'ext' => $ext, 'orig' => $orig, 'view' => $view];
}
if (!$files) {
    fail('no valid photos (need jpg/png/gif/webp)');
}
$multi = count($files) > 1;

// ---- build destination dirs (mirrors /ai/confirm_item.php) ------------------
$category_dir = ITEMS_BASE_DIR . "/" . $category;
if (!is_dir($category_dir) && !@mkdir($category_dir, 0755, true)) {
    fail("could not create category dir: $category");
}
if ($multi) {
    $dest_dir = $category_dir . "/" . dir_slug_item($slug);
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

$date_prefix = strtolower(date("Y-M-d"));   // 2026-jun-24

// ---- file each photo --------------------------------------------------------
$filed   = [];
$primary = null;
$idx     = 0;
foreach ($files as $p) {
    $idx++;
    $view = $multi ? ($p['view'] !== '' ? $p['view'] : (string) $idx) : null;
    $stem = ($multi && $view !== '') ? "$date_prefix-$slug-$view" : "$date_prefix-$slug";
    $ext  = $p['ext'];

    $basename = "$stem.$ext";
    $final    = "$dest_dir/$basename";
    $n = 2;
    while (file_exists($final)) {            // never clobber a sacred file
        $basename = "$stem-$n.$ext";
        $final    = "$dest_dir/$basename";
        $n++;
    }

    if (!@move_uploaded_file($p['tmp'], $final)) {
        fail('could not save uploaded photo');
    }

    correct_image_orientation($final);                       // bake EXIF before resizes
    $image_1000 = create_1000px_nail($final, $dest_dir, 0);
    $thumb_path = create_thumbnail($final, $thumb_dir, 0);

    $rel = $rel_dir . "/" . $basename;

    $line = json_encode([
        'item'        => $slug,
        'file'        => $rel,
        'category'    => $category,
        'view'        => $view,
        'tags'        => [],
        'slug'        => $slug,
        'name'        => $name,
        'description' => '',          // catalog sidecar owns the description
        'model'       => 'list',      // filed via the list-driven #4 uploader (no AI)
        'captured'    => date('c'),
        'orig'        => $p['orig'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @file_put_contents(ITEMS_MANIFEST, $line . "\n", FILE_APPEND | LOCK_EX);

    $entry = [
        'file'     => $rel,
        'view'     => $view,
        'url'      => urlify($image_1000 ?: $final, 'https:'),
        'thumb'    => urlify($thumb_path ?: $final, 'https:'),
        'full_url' => urlify($final, 'https:'),
    ];
    $filed[] = $entry;
    if ($primary === null) { $primary = $entry; }
}

echo json_encode([
    'ok'     => true,
    'item'   => $slug,
    'name'   => $name,
    'count'  => count($filed),
    'url'    => $primary['url'],
    'photos' => $filed,
]);
