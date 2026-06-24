<?php
/**
 * image_resize_lib.php — shared image-processing + URL/embed helpers.
 *
 * Extracted verbatim from bullet.php so both the legacy bulk uploader
 * (bullet.php) and the new single-item AI flow (ai/confirm_item.php) call
 * the SAME resize/thumbnail/embed code. No behavior change for bullet.php.
 *
 * Depends on the external Bulletproof library at /home/thundergoblin/bulletproof/.
 */

require_once "/home/thundergoblin/bulletproof/src/bulletproof.php";
require_once "/home/thundergoblin/bulletproof/src/utils/func.image-resize.php";

function print_rob($object, bool $exit = true)
{
    echo "<pre>";
    if(is_object($object) && method_exists($object, "toArray"))
    {
        echo "ResultSet => ".print_r($object->toArray(), true);
    } else {
        print_r($object);
    }
    echo "</pre>";
    if($exit) {
        exit;
    }
}

/**
 * @param string $image_path full system path of actual full-sized image
 *               (in the location you want it to stay permanently)
 *               e.g. `/users/rob/b.robnugen.com/subject/path/year/topic/cool_filename.jpeg`
 *
 * @param string $subdir_for_thumbs name of sub directory to be created adjacent
 *               to the actual full-sized image
 *               e.g. `thumbs/`
 *
 * @side_effect Creates thumbnail image
 *               e.g. `/users/rob/b.robnugen.com/subject/path/year/topic/thumbs/cool_filename.jpeg`
 */
function create_thumbnail(string $image_path, string $subdir_for_thumbs, int $debug_level): string
{
  $basename = basename($image_path);   // cool_filename.png

  $thumb_path = $subdir_for_thumbs . $basename;   // /path/thumbs/cool_filename.png

  if($debug_level >= 2) {print_rob($image_path . " --> " . $thumb_path,false);}

  copy($image_path,$thumb_path);       // OS make a copy of file
  return resize_image($thumb_path, 200, 200);
}

function create_1000px_nail(string $image_path, string $storage_directory, int $debug_level): string
{
  $basename = basename($image_path);   // cool_filename.png

  // Get the extension of the file
  $ext = pathinfo($basename, PATHINFO_EXTENSION);

  // Create a new variable $px_1000_name by inserting _1000 before the extension
  $px_1000_name = pathinfo($basename, PATHINFO_FILENAME) . "_1000." . $ext;

  if($debug_level >= 5) {print_rob("px_1000_name: " . $px_1000_name,false);}

  $thumb_path = $storage_directory . "/" . $px_1000_name;   // /path/cool_filename_1000.png
  if($debug_level >= 4) {print_rob("px_1000_full_path: " . $thumb_path,false);}

  copy($image_path,$thumb_path);       // OS make a copy of file
  if($debug_level >= 2) {print_rob("success copied px 1000 path",false); }
  return resize_image($thumb_path, 1000, 1000);
}

function create_500px_nail(string $image_path, string $storage_directory, int $debug_level): string
{
  $basename = basename($image_path);   // cool_filename.png

  $ext = pathinfo($basename, PATHINFO_EXTENSION);
  $px_500_name = pathinfo($basename, PATHINFO_FILENAME) . "_500." . $ext;

  $thumb_path = $storage_directory . "/" . $px_500_name;   // /path/cool_filename_500.png
  if($debug_level >= 4) {print_rob("px_500_full_path: " . $thumb_path,false);}

  copy($image_path,$thumb_path);       // OS make a copy of file
  return resize_image($thumb_path, 500, 500);
}

function resize_image(string $image_path, int $maxWidth, int $maxHeight): string
{
  $size_deets = getimagesize($image_path);  // get deets of file required by \resize()
  $imgWidth = $size_deets[0];
  $imgHeight = $size_deets[1];
  $mimeType = basename($size_deets['mime']);  // basename("image/png") returns "png"

  $success = \Bulletproof\Utils\resize($image_path, $mimeType, $imgWidth, $imgHeight, $maxWidth, $maxHeight, true);
  if($success)
  {
    return $image_path;
  }
  else
  {
    return "";
  }
}

/**
 * Bake EXIF orientation into the pixels of a JPEG and re-save it upright.
 *
 * Faithful copy of Bulletproof's correctImageOrientation() (bulletproof.php:465),
 * which the legacy badmin uploader runs inside upload(). The ai-flow bypasses
 * upload(), so it must call this on the full image BEFORE generating the _1000 +
 * thumbnail (GD's resize() ignores EXIF, so the source must already be corrected).
 *
 * No-op for non-JPEG, missing exif data, or Orientation 1.
 */
function correct_image_orientation(string $filename): void
{
    if (!function_exists('exif_read_data') || @exif_imagetype($filename) !== IMAGETYPE_JPEG) {
        return;   // EXIF orientation only applies to JPEG
    }
    $exif = @exif_read_data($filename);
    if (!$exif || !isset($exif['Orientation'])) {
        return;
    }
    $orientation = (int) $exif['Orientation'];
    if ($orientation === 1) {
        return;
    }
    $img = @imagecreatefromjpeg($filename);
    if (!$img) {
        return;
    }
    $deg = 0;
    switch ($orientation) {
        case 3: $deg = 180; break;
        case 6: $deg = 270; break;
        case 8: $deg = 90;  break;
    }
    if ($deg) {
        $img = imagerotate($img, $deg, 0);
    }
    imagejpeg($img, $filename, 95);   // re-save upright (GD drops the EXIF tag)
    imagedestroy($img);
}

// calling this _func just to distinguish from the variable $embed_markdowns
function embed_markdown_func(string $image_path, string $thumb_path): string
{
    $alt_text = alttextify($image_path);
    $image_url = urlify($image_path);
    $thumb_url = urlify($thumb_path);

    $embed = sprintf("[![%s](%s)](%s)",$alt_text,$thumb_url,$image_url);

    return $embed;
}

function create_html_img_tag(string $image_path, string $thumb_path): string
{
    $alt_text = alttextify($image_path);
    $thumb_url = urlify($thumb_path);

    $embed = sprintf("<br><img src='%s' alt='%s' />",$thumb_url,$alt_text);

    return $embed;
}

function alttextify(string $image_path): string
{
  return str_replace('_',' ',pathinfo($image_path,PATHINFO_FILENAME));
}

/**
 * Convert a full system path to a URL path.
 *
 * @param string $image_path Full system path of the image.
 * @param string $prefix 'https:' is useful for emacs
 * @return string URLified path.
 */
function urlify(string $image_path, string $prefix = ""): string
{
  return $prefix . str_replace('home/thundergoblin','',$image_path);
}
