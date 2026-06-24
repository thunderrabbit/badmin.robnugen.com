<?php
/**
 * ai_secure/confirm_item.php — secure fork of ai/confirm_item.php (stage 2).
 *
 * Files a named financial document into secure_bin/<bucket>. Differences from
 * ai/confirm_item.php:
 *   - destination is SECURE_BIN_ROOT/<bucket> (outside the web root), never the public tree
 *   - NO derivatives: no EXIF bake, no _1000, no thumbnails (original is archival)
 *   - the manifest lives INSIDE secure_bin, not in the public tree
 *   - the response carries no URL/markdown (the files are not web-served, by design)
 *
 *  POST: password, token, name, description?  (bucket comes from the staged sidecar)
 *
 * Single photo  -> filed FLAT in the bucket dir:
 *    secure_bin/<bucket>/2026-jun-19-<slug>.jpg
 * Multiple photos -> a per-document folder, one file per page/angle:
 *    secure_bin/<bucket>/<slug_underscored>/2026-jun-19-<slug>-<view>.jpg
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once __DIR__ . "/../ai/item_naming.php";             // slugify_item, dir_slug_item (no side effects)
require_once __DIR__ . "/secure_config.php";                 // SECURE_BIN_* + secure_bucket_dir()

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

$description = trim($_POST['description'] ?? '');
$name        = trim($_POST['name'] ?? '');

// ---- load the staged photo group from its sidecar ---------------------------
$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$sidecar = SECURE_BIN_STAGING . "/$token.json";
$meta    = is_file($sidecar) ? (json_decode((string) file_get_contents($sidecar), true) ?: []) : [];
$photos  = $meta['photos'] ?? [];

// bucket is authoritative from the sidecar (set at stage time); validate hard
$bucket     = $meta['bucket'] ?? '';
$bucket_dir = secure_bucket_dir($bucket);
if ($bucket_dir === null) {
    fail('staged group has no valid bucket');
}

// accounting tag is likewise authoritative from the sidecar (badmin #281).
// Re-validate (defends a stale / hand-edited sidecar); fail safe to 'unknown'.
$account_tag = $meta['account_tag'] ?? 'unknown';
if (!account_tag_ok($account_tag)) {
    $account_tag = 'unknown';
}

// keep only photos whose staged file still exists, preserving order
$photos = array_values(array_filter($photos, function ($p) use ($allowed_ext) {
    $f = SECURE_BIN_STAGING . "/" . ($p['file'] ?? '');
    return is_file($f) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed_ext, true);
}));
if (!$photos) {
    fail('staged photos not found for token (they may have expired)');
}
$multi = count($photos) > 1;

// ---- build destination dirs (mode 700; secure_bin is private) ---------------
if (!is_dir($bucket_dir) && !@mkdir($bucket_dir, 0700, true)) {
    fail("could not create bucket dir: $bucket");
}

if ($multi) {
    $dest_dir = $bucket_dir . "/" . dir_slug_item($slug);   // per-document folder, underscores
    $rel_dir  = $bucket . "/" . dir_slug_item($slug);
    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0700, true)) {
        fail("could not create document dir for: $slug");
    }
} else {
    $dest_dir = $bucket_dir;
    $rel_dir  = $bucket;
}

$date_prefix = strtolower(date("Y-M-d"));            // 2026-jun-19

// ---- file each photo (atomic rename, original only) -------------------------
$filed = [];

foreach ($photos as $p) {
    $staged_path = SECURE_BIN_STAGING . "/" . $p['file'];
    $ext         = strtolower($p['ext'] ?? pathinfo($staged_path, PATHINFO_EXTENSION));
    $view        = $multi ? (string) ($p['view'] ?? '') : null;

    $stem     = $multi && $view !== '' ? "$date_prefix-$slug-$view" : "$date_prefix-$slug";
    $basename = "$stem.$ext";
    $final    = "$dest_dir/$basename";

    // never clobber: uniquify if needed
    $n = 2;
    while (file_exists($final)) {
        $basename = "$stem-$n.$ext";
        $final    = "$dest_dir/$basename";
        $n++;
    }

    // atomic: rename within the same filesystem. No resize, no thumbnail — original is kept verbatim.
    if (!@rename($staged_path, $final)) {
        fail('could not move staged photo into place');
    }

    $rel = $rel_dir . "/" . $basename;     // path under secure_bin

    // one record per photo: appended to the manifest AND written as a per-image
    // sidecar (<image-filename>.json) so the Lemur-13 reconciler can pair tag→image.
    $record = [
        'item'        => $slug,
        'file'        => $rel,
        'bucket'      => $bucket,
        'account_tag' => $account_tag,
        'view'        => $view,
        'name'        => $name,
        'description' => $description,
        'captured'    => date('c'),
        'orig'        => $p['orig'] ?? '',
    ];
    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // manifest line, INSIDE secure_bin (never the public tree)
    @file_put_contents(SECURE_BIN_MANIFEST, $json . "\n", FILE_APPEND | LOCK_EX);
    // per-image sidecar next to the filed image, e.g. 2026-jun-19-foo.jpg.json
    @file_put_contents($final . ".json", $json);

    $filed[] = ['file' => $rel, 'view' => $view];
}

// ---- clean staging sidecar (photos already moved) ---------------------------
@unlink($sidecar);

// ---- respond (no URL: secure_bin is not web-served) -------------------------
echo json_encode([
    'ok'     => true,
    'item'   => $slug,
    'bucket' => $bucket,
    'count'  => count($filed),
    'file'   => $filed[0]['file'] ?? '',
    'photos' => $filed,
]);
