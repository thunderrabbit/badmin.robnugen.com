<?php
/**
 * upload_worker.php — file ONE MT3 worker photo: auto-crop it, name it, resize it.
 *
 * One photo per request. The batch lives in the browser, which sorts the files by
 * their camera filename and POSTs them one at a time with a sequence number. That
 * keeps this endpoint stateless, gives each photo its own memory arena (a 4032x3024
 * source is ~48MB as a GD buffer, against a 128MB limit), stays clear of the host's
 * max_file_uploads of 20, and lets one bad photo fail without taking the batch down.
 *
 * POST: password, name, seq, photo
 * ->    {ok:true, file, url, thumb_url, cropped, box}  |  {ok:false, error}
 */

header('Content-Type: application/json');
date_default_timezone_set("Asia/Tokyo");

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once __DIR__ . "/../image_resize_lib.php";           // resize + orientation + urlify
require_once __DIR__ . "/../autocrop_lib.php";               // detection + crop + slugs

const WORKERS_BASE_DIR = "/home/thundergoblin/b.robnugen.com/art/marble_track_3/workers";

function fail(string $msg): void
{
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

// ---- name -> directory slug + filename slug ---------------------------------
$name = trim($_POST['name'] ?? '');
if ($name === '') {
    fail('A worker name is required.');
}
$dir_slug  = ac_slugify($name);        // candy-mama
$file_slug = ac_file_slug($name);      // candy_mama
if ($dir_slug === '') {
    // ac_slugify keeps only [a-z0-9-], so a name with no ASCII letters vanishes
    fail("The name \"$name\" has no letters or digits that survive slugging — try a romaji name.");
}

$seq = filter_var($_POST['seq'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
if ($seq === false) {
    fail('seq must be a number from 1 to 999.');
}

// ---- the upload -------------------------------------------------------------
$photo = $_FILES['photo'] ?? null;
if (!$photo || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    fail('No photo uploaded.');
}
if ($photo['error'] !== UPLOAD_ERR_OK) {
    fail('Upload error code ' . $photo['error'] . '.');
}
if (!is_uploaded_file($photo['tmp_name'])) {
    fail('Invalid upload.');
}

$ext = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
if ($ext === 'jpe' || $ext === 'jpeg') {
    $ext = 'jpg';
}
$allowed_ext = ['jpg', 'png', 'gif', 'webp'];
if (!in_array($ext, $allowed_ext, true)) {
    fail("Unsupported image type '$ext' (allowed: " . implode(', ', $allowed_ext) . "). "
       . "If this is an iPhone HEIC, set the camera to 'Most Compatible' (JPEG).");
}

// ---- destination ------------------------------------------------------------
$dest_dir  = WORKERS_BASE_DIR . "/" . date("Y") . "/" . $dir_slug;
$thumb_dir = $dest_dir . "/thumbs/";
if (!is_dir($thumb_dir) && !@mkdir($thumb_dir, 0755, true)) {
    fail("Could not create $thumb_dir");
}

$date_prefix = strtolower(date("Y_M_d_"));                            // 2026_aug_16_
$basename    = sprintf('%s%s_%02d.%s', $date_prefix, $file_slug, $seq, $ext);
$final       = "$dest_dir/$basename";

// Rob guarantees no collisions within a batch; this only makes a mistake cost an
// error message instead of an overwritten photo.
if (file_exists($final)) {
    fail("$basename already exists — nothing was overwritten.");
}

if (!@move_uploaded_file($photo['tmp_name'], $final)) {
    fail("Could not move the upload into $dest_dir");
}

// ---- process ----------------------------------------------------------------
// EXIF orientation must be baked in BEFORE anything reads pixels, or the crop gets
// computed on a sideways image (GD ignores the EXIF tag).
correct_image_orientation($final);

$box = autocrop_to_subject($final);   // null => subject not found, left uncropped

$image_1000 = create_1000px_nail($final, $dest_dir, 0);
create_500px_nail($final, $dest_dir, 0);
$thumb_path = create_thumbnail($final, $thumb_dir, 0);

echo json_encode([
    'ok'        => true,
    'file'      => $basename,
    'dir'       => str_replace(WORKERS_BASE_DIR . "/", '', $dest_dir),
    'url'       => urlify($final, 'https:'),
    'url_1000'  => $image_1000 ? urlify($image_1000, 'https:') : '',
    'thumb_url' => $thumb_path ? urlify($thumb_path, 'https:') : '',
    'cropped'   => $box !== null,
    'box'       => $box,
], JSON_UNESCAPED_SLASHES);
