<?php
/**
 * reorient_existing.php — one-off / maintenance.
 *
 * Re-orient an already-uploaded full image (bake in its EXIF orientation) and
 * regenerate its _1000 + thumbnail from the corrected source. Useful for items
 * uploaded before the orientation fix landed in confirm_item.php.
 *
 * Usage (on b.rn, CLI only):
 *   php ai/reorient_existing.php /home/thundergoblin/b.robnugen.com/.../full.jpg
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . "/../image_resize_lib.php";

$full = $argv[1] ?? '';
if ($full === '' || !is_file($full)) {
    fwrite(STDERR, "not found: $full\n");
    exit(1);
}

$dir    = dirname($full);
$before = getimagesize($full);

correct_image_orientation($full);
$one   = create_1000px_nail($full, $dir, 0);
$thumb = create_thumbnail($full, $dir . "/thumbs/", 0);

$after = getimagesize($full);
printf("full  before: %dx%d   after: %dx%d\n", $before[0], $before[1], $after[0], $after[1]);
echo "_1000: " . ($one ?: "FAILED") . "\n";
echo "thumb: " . ($thumb ?: "FAILED") . "\n";
