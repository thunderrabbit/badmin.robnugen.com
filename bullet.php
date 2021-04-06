<?php

require_once  "/home/thundergoblin/bulletproof/src/bulletproof.php";
require_once  "/home/thundergoblin/bulletproof/src/utils/func.image-resize.php";

function print_rob($object, $exit = true)
{
    echo("<pre>");
    if(is_object($object) && method_exists($object, "toArray"))
    {
        echo "ResultSet => ".print_r($object->toArray(), true);
    } else {
        print_r($object);
    }
    echo("</pre>");
    if($exit) {
        exit;
    }
}

/** Use $image_name for image name or fallback to file name (without extension)
 *
 * @param string $image_name preferred name (sent by user)
 * @param array $image_info array of file info as sent by <input type="file" />
 *
 */
function create_image_name($image_name, $image_info)
{
  // prefer name typed by user (allows naming files here without renaming on device)
  $return_val = $image_name ? $image_name : pathinfo($image_info['name'], PATHINFO_FILENAME);

  // convert spaces to underscores
  $return_val = preg_replace('/\s+/', '_', $return_val);   //  https://stackoverflow.com/a/20871407/194309

  // TODO maybe convert to lowercase??

  $return_val = prepend_date_prn($return_val);

  return $return_val;
}

function prepend_date_prn($return_val)
{
  $this_year = date("Y");

  // /// PHP 8 version:  if(!empty($return_val) && !str_starts_with($return_val,$this_year))
  if(!empty($return_val) && strpos($return_val,$this_year) === false)
  {
    $return_val = date("Y_M_d_") . $return_val;   // e.g. 2021_Apr_05_return_val
  }
  $return_val = mb_strtolower($return_val);       // get rid of capital month from "M" in date
  return $return_val;
}
$images = new \Bulletproof\Image($_FILES);

$storage_directory = determine_storage_directory($_REQUEST["save_to"],$_REQUEST["sub_dir"]);
print_rob($storage_directory,0);

// Create a thumbnail in the `thumbs/` directory where the full sized file was created
$thumb_dirname = $storage_directory . "/thumbs/";   // hardcoding thumbs/, because that is the convention on b.robnugen.com
$thumb_dirname_created = $images->createStorage($thumb_dirname,0755);

// We can easily loop through array $_POST['image_name']
// but cannot as easily loop through an array of $_FILES['pictures']  (see commit c1f62fab9585ebeecec5)
foreach($_POST['image_name'] as $key => $image_name)
{
  // prefer image name sent in field, and falls back to name of image file
  $save_image_name = create_image_name($image_name,$_FILES["pictures".$key]);
  if($images["pictures".$key])    // Accessing the key of the image actually tells $images what image to work with
  {
    $images->setName($save_image_name)             // name of full-sized image
           ->setStorage($storage_directory,0755);  // 0755 = permissions of directories

    $upload = $images->upload();                   // upload full-sized image
    if($upload && $thumb_dirname_created)
    {
      $image_path = $upload->getPath();              // full path of full-sized image so we can create embed code
      $thumb_path = create_thumbnail($image_path,$thumb_dirname_created);
    }
  }
  else if(!empty($save_image_name))
  {
    // (There was an image name but no file)
    echo "<br>apparently nothing in images[\"pictures\".$key], but we have filename $save_image_name";
    echo "<br>so what about files?<br>";
    print_rob($_FILES["pictures".$key]);
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
 *               (not yet sure about ending slash)
 *
 * @side_effect Creates thumbnail image
 *               e.g. `/users/rob/b.robnugen.com/subject/path/year/topic/thumbs/cool_filename.jpeg`
 *
 *
 */
function create_thumbnail(string $image_path, string $subdir_for_thumbs)
{
  $basename = basename($image_path);   // https://www.php.net/manual/en/function.pathinfo.php

  $thumb_path = $subdir_for_thumbs . $basename;
  // print_rob(__FUNCTION__ . ": " . $image_path,0);
  // print_rob(__FUNCTION__ . ": " . $thumb_path,0);
  copy($image_path,$thumb_path);
  $gistp = getimagesize($thumb_path);
  $imgWidth = $gistp[0];
  $imgHeight = $gistp[1];
  $mimeType = basename($gistp['mime']);  // basename("image/png") returns "png"

  // hardcoding png just gets past the censors; resize somehow figures out the correct mime type
  \Bulletproof\Utils\resize($thumb_path, $mimeType, $imgWidth, $imgHeight, 200, 200, true);
  // return $image_path;
}

function determine_storage_directory($save_to, $sub_dir)
{
  filter_var($save_to, FILTER_SANITIZE_STRING);
  // print_rob($save_to,0);
  filter_var($sub_dir, FILTER_SANITIZE_STRING);
  // print_rob($sub_dir,0);
  $location_determination = array(
    // no trailing slash
    "journal" => "/home/thundergoblin/b.robnugen.com/journal/2021",
    "quests" => "/home/thundergoblin/b.robnugen.com/quests/walk-to-niigata/2021/en_route",
    "blog" => "/home/thundergoblin/b.robnugen.com/blog/2021",
    "tmp" => "/home/thundergoblin/b.robnugen.com/tmp",
  );
  // TODO: note these assume the directory separator is / (slash)
  $out_dir = $location_determination[$save_to] . "/" . $sub_dir; // append $sub_dir to requested location
  $out_dir = rtrim($out_dir, '/');  // remove trailing slash (in case $sub_dir is empty)
  return $out_dir;
}
