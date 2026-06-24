<?php
/**
 * sayonara/confirm.php — finalize an AI-named sale item (one or more photos).
 *
 * Like /ai/confirm_item.php (files the staged photo group, makes _1000 + thumbs,
 * appends the items manifest) BUT additionally writes a catalog SIDECAR that
 * Lemur 13 scoops to build the /sayonara/items/<slug>/ page. This is the
 * "badmin does everything" path: photograph -> AI name + describe -> image +
 * catalog metadata, all on b.robnugen.com.
 *
 *   POST: password, token, name, category, tags?, description?, model?
 *
 * Sidecar: ITEMS_BASE_DIR/sidecars/<slug>.json  (slug, name, category, description,
 *          images[] = _1000 URLs, thumb, price_jpy=null, quantity, sold=false, …).
 * badmin holds no mg/Stripe secret; price/event/tier are filled later on Lemur 13.
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once __DIR__ . "/../ai/item_naming.php";             // ITEMS_*, slugify_item, dir_slug_item
require_once __DIR__ . "/../image_resize_lib.php";           // resize/thumb/url helpers

const SAYONARA_SIDECARS = ITEMS_BASE_DIR . "/sidecars";

function fail(string $msg): void
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

$token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
if ($token === '') { fail('missing token'); }

$name = trim($_POST['name'] ?? '');
$slug = slugify_item($name);
if ($slug === '') { fail('a name is required'); }

$category = slugify_item($_POST['category'] ?? '');
if ($category === '') { $category = 'other'; }

$tags = [];
foreach (preg_split('/[,\s]+/', $_POST['tags'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $t) {
    $ts = slugify_item($t);
    if ($ts !== '') { $tags[] = $ts; }
}

$description = trim($_POST['description'] ?? '');
$model       = (($_POST['model'] ?? '') === 'sonnet') ? 'sonnet' : 'haiku';

// ---- load the staged photo group (staged by ../ai/name_item.php) -------------
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$sidecar_stage = ITEMS_STAGING . "/$token.json";
$meta   = is_file($sidecar_stage) ? (json_decode((string) file_get_contents($sidecar_stage), true) ?: []) : [];
$photos = $meta['photos'] ?? [];
$photos = array_values(array_filter($photos, function ($p) use ($allowed_ext) {
    $f = ITEMS_STAGING . "/" . ($p['file'] ?? '');
    return is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed_ext, true);
}));
if (!$photos) { fail('staged photos not found for token (they may have expired)'); }
$multi = count($photos) > 1;

// ---- destination dirs (mirrors /ai/confirm_item.php) ------------------------
$category_dir = ITEMS_BASE_DIR . "/" . $category;
if (!is_dir($category_dir) && !@mkdir($category_dir, 0755, true)) { fail("could not create category dir: $category"); }
if ($multi) {
    $dest_dir = $category_dir . "/" . dir_slug_item($slug);
    $rel_dir  = $category . "/" . dir_slug_item($slug);
    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0755, true)) { fail("could not create item dir for: $slug"); }
} else {
    $dest_dir = $category_dir;
    $rel_dir  = $category;
}
$thumb_dir = $dest_dir . "/thumbs/";
if (!is_dir($thumb_dir) && !@mkdir($thumb_dir, 0755, true)) { fail("could not create thumbs dir"); }

$date_prefix = strtolower(date("Y-M-d"));

// ---- file each photo --------------------------------------------------------
$filed = [];
foreach ($photos as $p) {
    $staged_path = ITEMS_STAGING . "/" . $p['file'];
    $ext  = strtolower($p['ext'] ?? pathinfo($staged_path, PATHINFO_EXTENSION));
    $view = $multi ? (string) ($p['view'] ?? '') : null;

    $stem     = $multi && $view !== '' ? "$date_prefix-$slug-$view" : "$date_prefix-$slug";
    $basename = "$stem.$ext";
    $final    = "$dest_dir/$basename";
    $n = 2;
    while (file_exists($final)) { $basename = "$stem-$n.$ext"; $final = "$dest_dir/$basename"; $n++; }

    if (!@rename($staged_path, $final)) { fail('could not move staged photo into place'); }

    correct_image_orientation($final);
    $image_1000 = create_1000px_nail($final, $dest_dir, 0);
    create_500px_nail($final, $dest_dir, 0);   // card-sized; URL derived from the _1000 on the site
    $thumb_path = create_thumbnail($final, $thumb_dir, 0);

    $rel = $rel_dir . "/" . $basename;

    $line = json_encode([
        'item' => $slug, 'file' => $rel, 'category' => $category, 'view' => $view,
        'tags' => $tags, 'slug' => $slug, 'name' => $name, 'description' => $description,
        'model' => item_model_id($model), 'captured' => date('c'), 'orig' => $p['orig'] ?? '',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @file_put_contents(ITEMS_MANIFEST, $line . "\n", FILE_APPEND | LOCK_EX);

    $filed[] = [
        'file'  => $rel,
        'view'  => $view,
        'url'   => urlify($image_1000 ?: $final, 'https:'),   // _1000 (display image)
        'thumb' => urlify($thumb_path ?: $final, 'https:'),
    ];
}

@unlink($sidecar_stage);   // staged photos already moved

// ---- write the catalog sidecar (the thing Lemur 13 scoops) ------------------
if (!is_dir(SAYONARA_SIDECARS) && !@mkdir(SAYONARA_SIDECARS, 0755, true)) {
    fail('could not create sidecars dir');
}
$record = [
    'slug'        => $slug,
    'name'        => $name,
    'category'    => $category,
    'tier'        => '',
    'mechanism'   => '',
    'event'       => '',
    'images'      => array_map(fn($e) => $e['url'], $filed),
    'thumb'       => $filed[0]['thumb'] ?? '',
    'price_jpy'   => null,
    'quantity'    => 1,
    'sold'        => false,
    'captured'    => date('c'),
    'description' => $description,
];
$ok = @file_put_contents(
    SAYONARA_SIDECARS . "/$slug.json",
    json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
if ($ok === false) { fail('filed photos but could not write the catalog sidecar'); }

echo json_encode([
    'ok'      => true,
    'item'    => $slug,
    'count'   => count($filed),
    'url'     => $filed[0]['url'],
    'sidecar' => "sidecars/$slug.json",
    'photos'  => $filed,
]);
