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
      if($image_path && $thumb_path)
      {
        display_embeds($image_path, $thumb_path);   // so I can post from my phone
      }
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
  $basename = basename($image_path);   // cool_filename.png

  $thumb_path = $subdir_for_thumbs . $basename;   // /path/thumbs/cool_filename.png
  copy($image_path,$thumb_path);       // OS make a copy of file
  $thumb_info = getimagesize($thumb_path);  // get deets of file required by \resize()
  $imgWidth = $thumb_info[0];
  $imgHeight = $thumb_info[1];
  $mimeType = basename($thumb_info['mime']);  // basename("image/png") returns "png"

  $success = \Bulletproof\Utils\resize($thumb_path, $mimeType, $imgWidth, $imgHeight, 200, 200, true);
  if($success)
  {
    return $thumb_path;
  }
  else
  {
    return false;
  }
}

function determine_storage_directory(string $save_to, string $sub_dir)
{
  filter_var($save_to, FILTER_SANITIZE_STRING);
  // print_rob($save_to,0);
  filter_var($sub_dir, FILTER_SANITIZE_STRING);
  // print_rob($sub_dir,0);
  $location_determination = array(
    // no trailing slash
    "journal" => "/home/thundergoblin/b.robnugen.com/journal/2021",
    "quests" => "/home/thundergoblin/b.robnugen.com/quests/walk-to-niigata/2021/en_route",
    "plan" => "/home/thundergoblin/b.robnugen.com/quests/walk-to-niigata/2021/route_plans",
    "blog" => "/home/thundergoblin/b.robnugen.com/blog/2021",
    "tmp" => "/home/thundergoblin/b.robnugen.com/tmp",
  );
  // TODO: note these assume the directory separator is / (slash)
  $out_dir = $location_determination[$save_to] . "/" . $sub_dir; // append $sub_dir to requested location
  $out_dir = rtrim($out_dir, '/');  // remove trailing slash (in case $sub_dir is empty)
  return $out_dir;
}

function display_embeds(string $image_path, string $thumb_path)
{
  $alt_text = alttextify($image_path);
  $image_url = urlify($image_path);
  $thumb_url = urlify($thumb_path);

$embed = sprintf("[![%s](%s)](%s)",$alt_text,$thumb_url,$image_url);

    print_rob($embed,0);
}

function alttextify(string $image_path)
{
  return str_replace('_',' ',pathinfo($image_path,PATHINFO_FILENAME));
}

function urlify(string $image_path)
{
  return str_replace('home/thundergoblin','',$image_path);
}
