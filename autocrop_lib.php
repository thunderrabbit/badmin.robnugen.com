<?php
/**
 * autocrop_lib.php — find the subject in a photo shot against a plain backdrop.
 *
 * Rob shoots MT3 workers on blue felt. The felt varies a lot in BRIGHTNESS (folds,
 * vignette, a lit foreground) but almost not at all in HUE, while the subjects are
 * red / white / wood. So the subject is found with a chroma-only test that throws
 * luminance away entirely.
 *
 * Approaches that were measured against a hand-cropped reference and FAILED — please
 * don't reach for them again:
 *   - ImageMagick `-fuzz N% -trim`: the vignette defeats it at every fuzz value.
 *   - Euclidean RGB distance from a border-median colour: the brightly lit foreground
 *     felt speckles the entire bottom third of the mask.
 *   - Normalised chromaticity / shadow-invariant residual: same speckle, plus dark
 *     corners make normalised chroma numerically unstable.
 *
 * The chroma test below lands within 6px of the hand crop, and holds that answer
 * across thresholds 30..44 — the plateau is why 36 is a safe default.
 *
 * Pure GD. No side effects on include.
 */

const AC_WORK_WIDTH       = 260;    // detection runs on a downscale this wide
const AC_CHROMA_THRESHOLD = 36.0;   // chroma distance from backdrop => subject
const AC_BORDER_FRAC      = 0.05;   // frame sampled to model the backdrop
const AC_KEEP_FRAC        = 0.15;   // keep blobs >= this fraction of the largest
const AC_MIN_AREA_FRAC    = 0.02;   // smaller than this => detection failed
const AC_MAX_AREA_FRAC    = 0.90;   // larger than this => detection failed

const AC_SUBJECT_FILL     = 0.80;   // subject occupies this much of each output side
const AC_JPEG_QUALITY     = 92;

/**
 * Detect the subject, pad the box out, and crop the file in place.
 *
 * @return array{0:int,1:int,2:int,3:int}|null  the crop box used, or null if no
 *         subject was found — in which case the file is left untouched.
 */
function autocrop_to_subject(string $image_path, float $fill = AC_SUBJECT_FILL): ?array
{
    $bbox = detect_subject_bbox($image_path);
    if ($bbox === null) {
        return null;
    }

    $size = @getimagesize($image_path);
    if (!$size) {
        return null;
    }

    $box = pad_bbox_to_fill($bbox, (int) $size[0], (int) $size[1], $fill);

    return crop_image_in_place($image_path, $box) ? $box : null;
}

/**
 * Grow a subject box so the subject fills $fill of each side of the result, keeping
 * the subject centred.
 *
 * Each side is padded independently, so the output aspect ratio follows the SUBJECT,
 * not the camera — which is what Rob's hand crop does (his 3:4 frame became a 0.63
 * crop). Near an edge the window slides to stay inside the image rather than
 * shrinking, so the subject keeps its margin on the other three sides.
 *
 * @return array{0:int,1:int,2:int,3:int}  [x, y, w, h]
 */
function pad_bbox_to_fill(array $bbox, int $img_w, int $img_h, float $fill = AC_SUBJECT_FILL): array
{
    [$bx, $by, $bw, $bh] = $bbox;

    $out_w = min((int) round($bw / $fill), $img_w);
    $out_h = min((int) round($bh / $fill), $img_h);

    $x = (int) round($bx + $bw / 2 - $out_w / 2);
    $y = (int) round($by + $bh / 2 - $out_h / 2);

    $x = max(0, min($x, $img_w - $out_w));
    $y = max(0, min($y, $img_h - $out_h));

    return [$x, $y, $out_w, $out_h];
}

/**
 * Crop a file to [x, y, w, h], rewriting it in its original format.
 */
function crop_image_in_place(string $image_path, array $box, int $quality = AC_JPEG_QUALITY): bool
{
    $type = @exif_imagetype($image_path);
    $src  = _ac_load($image_path);
    if (!$src) {
        return false;
    }

    $cropped = imagecrop($src, ['x' => $box[0], 'y' => $box[1], 'width' => $box[2], 'height' => $box[3]]);
    imagedestroy($src);
    if (!$cropped) {
        return false;
    }

    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
    }

    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($cropped, $image_path, $quality),
        IMAGETYPE_PNG  => imagepng($cropped, $image_path),
        IMAGETYPE_GIF  => imagegif($cropped, $image_path),
        IMAGETYPE_WEBP => imagewebp($cropped, $image_path, $quality),
        default        => false,
    };
    imagedestroy($cropped);

    return (bool) $ok;
}

/**
 * "Candy Mama" -> "candy-mama". Directory convention.
 *
 * Same rule as slugify_item() in ai/item_naming.php, duplicated on purpose: requiring
 * that file would drag the MT3 flow into the leave-Japan item archive's constants and
 * Claude helpers, and would redeclare the function if both were ever loaded together.
 */
function ac_slugify(string $text): string
{
    $slug = mb_strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

/** "Candy Mama" -> "candy_mama". Filename convention. */
function ac_file_slug(string $text): string
{
    return str_replace('-', '_', ac_slugify($text));
}

/**
 * Bounding box of the subject, in full-resolution pixels.
 *
 * @return array{0:int,1:int,2:int,3:int}|null  [x, y, w, h], or null if no subject
 *         could be picked out (caller should leave the image uncropped).
 */
function detect_subject_bbox(
    string $image_path,
    int $work_w = AC_WORK_WIDTH,
    float $threshold = AC_CHROMA_THRESHOLD
): ?array {
    $src = _ac_load($image_path);
    if (!$src) {
        return null;
    }

    $full_w = imagesx($src);
    $full_h = imagesy($src);

    // Downscale first: the bilinear averaging doubles as the denoise, so the felt's
    // texture never reaches the mask and no separate blur pass is needed.
    $small = ($full_w > $work_w) ? imagescale($src, $work_w, -1, IMG_BILINEAR_FIXED) : $src;
    if (!$small) {
        imagedestroy($src);
        return null;
    }

    $w = imagesx($small);
    $h = imagesy($small);

    $mask = _ac_chroma_mask($small, $threshold);
    $mask = _ac_open_close($mask, $w, $h);
    $box  = _ac_components_bbox($mask, $w, $h);

    if ($small !== $src) {
        imagedestroy($small);
    }
    imagedestroy($src);

    if ($box === null) {
        return null;
    }

    // Scale the small-image box back up to full resolution.
    $sx = $full_w / $w;
    $sy = $full_h / $h;
    $x0 = (int) floor($box[0] * $sx);
    $y0 = (int) floor($box[1] * $sy);
    $x1 = (int) ceil(($box[2] + 1) * $sx);
    $y1 = (int) ceil(($box[3] + 1) * $sy);

    $x0 = max(0, min($x0, $full_w - 1));
    $y0 = max(0, min($y0, $full_h - 1));
    $x1 = max($x0 + 1, min($x1, $full_w));
    $y1 = max($y0 + 1, min($y1, $full_h));

    // Sanity guard: a subject that fills the frame, or one that is a speck, means the
    // backdrop assumption broke down. Say so rather than emit a nonsense crop.
    $area_frac = (($x1 - $x0) * ($y1 - $y0)) / ($full_w * $full_h);
    if ($area_frac > AC_MAX_AREA_FRAC || $area_frac < AC_MIN_AREA_FRAC) {
        return null;
    }

    return [$x0, $y0, $x1 - $x0, $y1 - $y0];
}

/**
 * Load a JPEG/PNG/GIF/WEBP into a GD handle, or null if unreadable.
 */
function _ac_load(string $path)
{
    if (!is_file($path)) {
        return null;
    }
    $img = match (@exif_imagetype($path)) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_GIF  => @imagecreatefromgif($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        default        => null,
    };
    return $img ?: null;
}

/**
 * Mark every pixel whose chroma is far from the backdrop's chroma.
 *
 * Chroma is (B - Y, R - Y) from the luma Y = .299R + .587G + .114B, i.e. the colour
 * with the brightness divided out. The backdrop's chroma is the MEDIAN over a border
 * frame — median, not mean, so a subject poking into the frame edge can't drag it.
 *
 * @return array flat 0/1 mask, indexed y * $w + $x
 */
function _ac_chroma_mask($img, float $threshold): array
{
    $w = imagesx($img);
    $h = imagesy($img);

    $cb = [];
    $cr = [];
    for ($y = 0, $i = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++, $i++) {
            $rgb = imagecolorat($img, $x, $y);
            $r   = ($rgb >> 16) & 0xFF;
            $g   = ($rgb >> 8) & 0xFF;
            $b   = $rgb & 0xFF;
            $luma = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            $cb[$i] = $b - $luma;
            $cr[$i] = $r - $luma;
        }
    }

    // Sample the border frame for the backdrop model.
    $band = max(3, (int) (min($w, $h) * AC_BORDER_FRAC));
    $bb = [];
    $br = [];
    for ($y = 0; $y < $h; $y++) {
        $edge_row = ($y < $band || $y >= $h - $band);
        for ($x = 0; $x < $w; $x++) {
            if (!$edge_row && $x >= $band && $x < $w - $band) {
                $x = $w - $band - 1;   // skip the interior in one jump
                continue;
            }
            $i = $y * $w + $x;
            $bb[] = $cb[$i];
            $br[] = $cr[$i];
        }
    }
    $bg_cb = _ac_median($bb);
    $bg_cr = _ac_median($br);

    $t2 = $threshold * $threshold;
    $mask = [];
    $n = $w * $h;
    for ($i = 0; $i < $n; $i++) {
        $dcb = $cb[$i] - $bg_cb;
        $dcr = $cr[$i] - $bg_cr;
        $mask[$i] = (($dcb * $dcb + $dcr * $dcr) > $t2) ? 1 : 0;
    }
    return $mask;
}

function _ac_median(array $values): float
{
    if (!$values) {
        return 0.0;
    }
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);
    return ($n % 2) ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2.0;
}

/**
 * Opening (erode, erode, dilate, dilate) then closing (dilate, dilate, erode, erode)
 * with a 3x3 element. The opening wipes speckle off the backdrop; the closing seals
 * the subject back into solid blobs.
 */
function _ac_open_close(array $mask, int $w, int $h): array
{
    $mask = _ac_morph($mask, $w, $h, true);
    $mask = _ac_morph($mask, $w, $h, true);
    $mask = _ac_morph($mask, $w, $h, false);
    $mask = _ac_morph($mask, $w, $h, false);

    $mask = _ac_morph($mask, $w, $h, false);
    $mask = _ac_morph($mask, $w, $h, false);
    $mask = _ac_morph($mask, $w, $h, true);
    $mask = _ac_morph($mask, $w, $h, true);
    return $mask;
}

/**
 * One 3x3 erode ($erode = true) or dilate pass, done separably (1x3 then 3x1) so each
 * pixel costs 6 lookups instead of 9. Off-image neighbours read as background, which
 * makes erode clear the frame edge — wanted, since a subject is never the border.
 */
function _ac_morph(array $mask, int $w, int $h, bool $erode): array
{
    $mid = [];
    for ($y = 0; $y < $h; $y++) {
        $row = $y * $w;
        for ($x = 0; $x < $w; $x++) {
            $l = ($x > 0) ? $mask[$row + $x - 1] : 0;
            $c = $mask[$row + $x];
            $r = ($x < $w - 1) ? $mask[$row + $x + 1] : 0;
            $mid[$row + $x] = $erode ? ($l & $c & $r) : ($l | $c | $r);
        }
    }

    $out = [];
    for ($y = 0; $y < $h; $y++) {
        $row = $y * $w;
        for ($x = 0; $x < $w; $x++) {
            $u = ($y > 0) ? $mid[$row - $w + $x] : 0;
            $c = $mid[$row + $x];
            $d = ($y < $h - 1) ? $mid[$row + $w + $x] : 0;
            $out[$row + $x] = $erode ? ($u & $c & $d) : ($u | $c | $d);
        }
    }
    return $out;
}

/**
 * Label 8-connected blobs and return the bbox of every blob at least AC_KEEP_FRAC of
 * the largest one's size.
 *
 * Keeping the runners-up (not just the biggest blob) is deliberate: the reference
 * photo's figure stands on two detached wooden sandals, and a largest-blob-only rule
 * crops them off.
 *
 * @return array{0:int,1:int,2:int,3:int}|null  [minx, miny, maxx, maxy] inclusive
 */
function _ac_components_bbox(array $mask, int $w, int $h): ?array
{
    $label = [];        // pixel index => blob id
    $boxes = [];        // blob id => [minx, miny, maxx, maxy]
    $sizes = [];        // blob id => pixel count
    $next  = 0;
    $n     = $w * $h;

    for ($start = 0; $start < $n; $start++) {
        if (!$mask[$start] || isset($label[$start])) {
            continue;
        }
        $next++;
        $label[$start] = $next;
        $stack = [$start];
        $minx = $maxx = $start % $w;
        $miny = $maxy = intdiv($start, $w);
        $count = 0;

        while ($stack) {
            $p  = array_pop($stack);
            $px = $p % $w;
            $py = intdiv($p, $w);
            $count++;
            if ($px < $minx) { $minx = $px; }
            if ($px > $maxx) { $maxx = $px; }
            if ($py < $miny) { $miny = $py; }
            if ($py > $maxy) { $maxy = $py; }

            for ($dy = -1; $dy <= 1; $dy++) {
                $ny = $py + $dy;
                if ($ny < 0 || $ny >= $h) {
                    continue;
                }
                for ($dx = -1; $dx <= 1; $dx++) {
                    $nx = $px + $dx;
                    if ($nx < 0 || $nx >= $w) {
                        continue;
                    }
                    $q = $ny * $w + $nx;
                    if ($mask[$q] && !isset($label[$q])) {
                        $label[$q] = $next;
                        $stack[] = $q;
                    }
                }
            }
        }

        $boxes[$next] = [$minx, $miny, $maxx, $maxy];
        $sizes[$next] = $count;
    }

    if (!$sizes) {
        return null;
    }

    $cutoff = max($sizes) * AC_KEEP_FRAC;
    $minx = $miny = PHP_INT_MAX;
    $maxx = $maxy = -1;
    foreach ($sizes as $id => $size) {
        if ($size < $cutoff) {
            continue;
        }
        [$bx0, $by0, $bx1, $by1] = $boxes[$id];
        if ($bx0 < $minx) { $minx = $bx0; }
        if ($by0 < $miny) { $miny = $by0; }
        if ($bx1 > $maxx) { $maxx = $bx1; }
        if ($by1 > $maxy) { $maxy = $by1; }
    }

    return ($maxx < 0) ? null : [$minx, $miny, $maxx, $maxy];
}
