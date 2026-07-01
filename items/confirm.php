<?php
/**
 * items/confirm.php — finalize one honored item (one or more photos).
 *
 * Assembles a self-contained capture folder under ITEM_INGEST_DIR (transit, not
 * web-served): the _1000 photo(s) plus item.json. A cron on Lemur 13 drains the folder
 * (rsync --remove-source-files) and hands it to agent wikiBoo, which creates the
 * Items:<title> page on wiki.robnugen.com.
 *
 *   POST: password, token, title, photo_description?, text?, text_raw?, item_date?, model?
 *
 * The folder is BUILT inside .staging/ (excluded from the drain) and only rename()d into
 * place once complete, so the cron never sees a half-written capture.
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once __DIR__ . "/item_ingest_lib.php";               // ITEM_INGEST_*, slugify_item (via item_naming.php)
require_once __DIR__ . "/../image_resize_lib.php";           // correct_image_orientation, create_1000px_nail

function fail(string $msg): void
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/** Recursively delete a directory (used to clear a stale build dir). */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $p = "$dir/$e";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

$token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
if ($token === '') { fail('missing token'); }

$title = trim($_POST['title'] ?? '');
$slug  = slugify_item($title);
if ($slug === '') { fail('a title is required'); }

$photo_description = trim($_POST['photo_description'] ?? '');
$rob_text          = trim($_POST['text'] ?? '');
$rob_text_raw      = trim($_POST['text_raw'] ?? '');
$item_date         = trim($_POST['item_date'] ?? '');
$model             = (($_POST['model'] ?? '') === 'sonnet') ? 'sonnet' : 'haiku';

// ---- load the staged photo group (staged by describe.php) -------------------
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$sidecar = ITEM_INGEST_STAGING . "/$token.json";
$meta    = is_file($sidecar) ? (json_decode((string) file_get_contents($sidecar), true) ?: []) : [];
$photos  = $meta['photos'] ?? [];
$photos  = array_values(array_filter($photos, function ($p) use ($allowed_ext) {
    $f = ITEM_INGEST_STAGING . "/" . ($p['file'] ?? '');
    return is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed_ext, true);
}));
if (!$photos) { fail('staged photos not found for token (they may have expired)'); }

// ---- build the capture folder inside .staging/, then publish atomically -----
$final_dir = ITEM_INGEST_DIR . "/$token";
if (is_dir($final_dir)) { fail('this item was already filed'); }
$build_dir = ITEM_INGEST_STAGING . "/$token-build";
rrmdir($build_dir);
if (!@mkdir($build_dir, 0755, true)) { fail('could not create build dir'); }

$filed = [];
$i = 1;
foreach ($photos as $p) {
    $staged_path = ITEM_INGEST_STAGING . "/" . $p['file'];
    $ext  = strtolower($p['ext'] ?? pathinfo($staged_path, PATHINFO_EXTENSION));
    $full = "$build_dir/$slug-$i.$ext";
    if (!@rename($staged_path, $full)) { rrmdir($build_dir); fail('could not move staged photo into place'); }

    correct_image_orientation($full);
    $image_1000 = create_1000px_nail($full, $build_dir, 0);   // makes <slug>-<i>_1000.<ext>
    @unlink($full);                                            // ship only the web-sized _1000

    $filed[] = basename($image_1000 ?: $full);
    $i++;
}

// ---- write item.json (what wikiBoo reads to build the Items: page) ----------
$record = [
    'title'             => $title,
    'slug'              => $slug,
    'rob_text'          => $rob_text,
    'rob_text_raw'      => $rob_text_raw,
    'photo_description' => $photo_description,
    'item_date'         => $item_date,   // the item's own date (precise or approximate); NOT the capture time
    'photos'            => $filed,
    'captured'          => date('c'),
    'model'             => item_model_id($model),
];
$ok = @file_put_contents(
    "$build_dir/item.json",
    json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
if ($ok === false) { rrmdir($build_dir); fail('could not write item.json'); }

if (!@rename($build_dir, $final_dir)) { rrmdir($build_dir); fail('could not publish the capture folder'); }
@unlink($sidecar);   // staged photos already moved

echo json_encode([
    'ok'     => true,
    'item'   => $slug,
    'title'  => $title,
    'count'  => count($filed),
    'photos' => $filed,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
