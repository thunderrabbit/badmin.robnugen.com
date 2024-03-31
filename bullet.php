<?php
/* Add location at determine_storage_directory */

require_once  "/home/thundergoblin/bulletproof_config.php";
require_once  "/home/thundergoblin/bulletproof/src/bulletproof.php";
require_once  "/home/thundergoblin/bulletproof/src/utils/func.image-resize.php";

// https://www.php.net/manual/en/function.password-verify.php
if (!password_verify($_POST['password'], $bulletproof_password_hash)) {
    echo 'Invalid password.';
    exit;
}

$debug_level = $_REQUEST["debug_level"];if (filter_var($debug_level, FILTER_VALIDATE_INT,
      array("options" => array("min_range"=>0, "max_range"=>5))) === false)
{
    $debug_level = 0;
}
if($debug_level > 0) {  print_rob("debug_level: " . $debug_level,false); }

$save_to = $_REQUEST["save_to"];
filter_var($save_to, FILTER_SANITIZE_STRING);
if($debug_level > 0) {  print_rob("save_to: " . $save_to,false); }

$sub_dir = $_REQUEST["sub_dir"];
filter_var($sub_dir, FILTER_SANITIZE_STRING);
if($debug_level > 0) {  print_rob("sub_dir: " . $sub_dir,false); }

$date_prefix = $_REQUEST["date_prefix"];
filter_var($date_prefix, FILTER_SANITIZE_STRING);
if($debug_level > 0) {  print_rob("date_prefix: " . $date_prefix,false); }

/* arrays which will store specific style of embed info for each image */
$embed_markdowns = array();   // [![2021 apr 12 alt text](//b.robnugen.com/tmp/thumbs/2021_apr_12_alt_text.png)](//b.robnugen.com/tmp/2021_apr_12_alt_text.png)
$embed_titles = array();
$html_img_tag_output = array();

function print_rob($object, bool $exit = true)
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

if($debug_level > 4) {
  print_rob($_POST,false);
  print_rob($_FILES,false);
}

/** Use $image_name for image name or fallback to file name (without extension)
 *
 * @param string $date_prefix is prepended if YYYY dne in name
 * @param string $image_name preferred name (sent by user)
 * @param array $image_info array of file info as sent by <input type="file" />
 *
 */
function create_image_name($date_prefix, $image_name, $image_info)
{
  // if($debug_level >= 4) {  DNE in this context
    // print_rob("in create_imagename",false);
    // print_rob("date_prefix: " . $date_prefix,false);
    // print_rob("image_name: " . $image_name,false);
    // print_rob("image_info: ",false);
    // print_rob($image_info,false);
  // }

  // prefer name typed by user (allows naming files here without renaming on device)
  $return_val = !empty(trim($image_name)) ? $image_name : pathinfo($image_info['name'], PATHINFO_FILENAME);
  // print_rob("line " . __LINE__ . " return_val: " . $return_val,false);

  // convert spaces to underscores

  $return_val = preg_replace('/\s+/', '_', $return_val);   //  https://stackoverflow.com/a/20871407/194309
  // print_rob("line " . __LINE__ . " return_val: " . $return_val,false);

  $return_val = preg_replace('/_+\\./', '.', $return_val);  // /path/long_file_name__.jpg --> /path/long_file_name.jpg

  // TODO maybe convert to lowercase??
  $return_val = prepend_date_prn($date_prefix, $return_val);
  // print_rob("line " . __LINE__ . " return_val: " . $return_val,false);

  // if($debug_level > 0) {  print_rob("create image name: " . $return_val,false); }

  // print_rob("line " . __LINE__ . " return_val: " . $return_val,false);
  return $return_val;
}

/**
 *  Prepend a date to the filename unless it has the 4 digit year already there.
 *
 * @param string $name_prolly_no_date probably does not have a date.
 */
function prepend_date_prn(string $date_prefix, string $name_prolly_no_date)
{
  /*
      best version I can think of now is actually:
      Y4D = get 4 digits from beginning of $date_prefix if exists
      $this year = this year or Y4D
      check if filename already has $this_year prepended
      if so, then leave it,
      if not, then prepend $date_prefix
      */
  $this_year = date("Y");

  // /// PHP 8 version:  if(!empty($name_prolly_no_date) && !str_starts_with($name_prolly_no_date,$this_year))
  if(!empty($name_prolly_no_date) && strpos($name_prolly_no_date,$this_year) === false)
  {
    if($date_prefix) {
      $name_now_has_date = $date_prefix . $name_prolly_no_date;   // e.g. 2021_Apr_05_return_val
    }
    else {
      $name_now_has_date = date("Y_M_d_") . $name_prolly_no_date;   // e.g. 2021_Apr_05_return_val
    }
  } else {
    // Name DID have a date, so use it
    $name_now_has_date = $name_prolly_no_date;
  }
  $name_now_has_date = mb_strtolower($name_now_has_date);       // get rid of capital month from "M" in date
  return $name_now_has_date;
}
$images = new \Bulletproof\Image($_FILES);

$storage_directory = determine_storage_directory($save_to,$sub_dir);

// Create a thumbnail in the `thumbs/` directory where the full sized file was created
$thumb_dirname = $storage_directory . "/thumbs/";   // hardcoding thumbs/, because that is the convention on b.robnugen.com
$thumb_dirname_created = $images->createStorage($thumb_dirname,0755);

// We can easily loop through array $_POST['image_name']
// but cannot as easily loop through an array of $_FILES['pictures']  (see commit c1f62fab9585ebeecec5)
if($debug_level >= 4) {print_rob("before foreach POST[image_name]",false);}
foreach($_POST['image_name'] as $key => $image_name)
{
  $key=intval($key);                  // weak-ass security
  htmlspecialchars($image_name);      // weak-ass security
  if($debug_level >= 5) {print_rob("received image_name: " . $image_name,false);}
  $description = $_POST['description'][$key];
  htmlspecialchars($description);
  // prefer image name sent in field, and falls back to name of image file
  $save_image_name = create_image_name($date_prefix,$image_name,$_FILES["pictures".$key]);
  if($debug_level >= 4) {print_rob("save_image_name: " . $save_image_name,false);}
  if($debug_level >= 5) {print_rob("storage_directory: " . $storage_directory,false);}
  if($images["pictures".$key])    // Accessing the key of the image actually tells $images what image to work with
  {
    $images->setName($save_image_name)             // name of full-sized image
           ->setStorage($storage_directory,0755);  // 0755 = permissions of directories

    $upload = $images->upload();                   // upload full-sized image
    if($upload && $thumb_dirname_created)
    {
      $image_path = $upload->getPath();              // full path of full-sized image so we can create embed code
      if($debug_level >= 4) {print_rob("image_path: " . $image_path,false);}
      $thumb_path = create_thumbnail($image_path,$thumb_dirname_created);
      if($image_path && $thumb_path)
      {
        if(!empty($description))
        {
          $embed_markdowns[] = "";    // gives some <br> around description
          $embed_markdowns[] = $description;
          $embed_markdowns[] = "";    // gives some <br> around description
        }
        $embed_markdowns[] = embed_markdown_func($image_path, $thumb_path);   // so I can post from my phone
        $html_img_tag_output[] = create_html_img_tag($image_path, $thumb_path);   // so I can get a preview
      }$html_img_tag_output = array();
    }
    else
    {
      print_rob("apparently images->upload() returned falsey value",false);
      print_rob("upload",false);
      print_rob($upload,false);
      print_rob("thumb_dirname_created",false);
      print_rob($thumb_dirname_created,false);
    }
  }
  else if(!empty($save_image_name))
  {
    // (There was an image name but no file)
    echo "<br>apparently nothing in images[\"pictures\".$key], but we have filename $save_image_name";
    echo "<br>so what about files?<br>";
    print_rob($_FILES);
  }
}  // end foreach($_POST['image_name'] as $key => $image_name)

$encode_markdown = urlencode(implode("\n",$embed_markdowns));

echo "<h1><a href='https://badmin.robnugen.com'>https://badmin.robnugen.com</a></h1><br><br>";

echo "<h1><a href='https://quick.robnugen.com/poster?text=$encode_markdown'>Post as markdown</a></h1><br><br>";

// Y U NO print image html???
print_r(implode("\n",$html_img_tag_output));

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
  $this_year = date("Y");
  $location_determination = array(
    // no trailing slash
    "events" => "/home/thundergoblin/b.robnugen.com/events/" . $this_year,
    "walk_and_talk" => "/home/thundergoblin/b.robnugen.com/blog/" . $this_year . "/walk_and_talk",
    "journal" => "/home/thundergoblin/b.robnugen.com/journal/" . $this_year,
    "blog" => "/home/thundergoblin/b.robnugen.com/blog/" . $this_year,
    "mt3cons" => "/home/thundergoblin/b.robnugen.com/art/marble_track_3/construction/" . $this_year,
    "mt3parts" => "/home/thundergoblin/b.robnugen.com/art/marble_track_3/track/parts/" . $this_year,
    "quests" => "/home/thundergoblin/b.robnugen.com/quests/walk-to-niigata/2021/en_route",
    "plan" => "/home/thundergoblin/b.robnugen.com/quests/walk-to-niigata/2021/route_plans",
    "tmp" => "/home/thundergoblin/b.robnugen.com/tmp",
  );
  // TODO: note these assume the directory separator is / (slash)
  $out_dir = $location_determination[$save_to] . "/" . $sub_dir; // append $sub_dir to requested location
  $out_dir = rtrim($out_dir, '/');  // remove trailing slash (in case $sub_dir is empty)

  // convert spaces to underscores
  $return_val = preg_replace('/\s+/', '_', $out_dir);   //  https://stackoverflow.com/a/20871407/194309

  return $return_val;
}

function process_paths(string $image_path, string $thumb_path)
{
  $alt_text = alttextify($image_path);
  $image_url = urlify($image_path);
  $thumb_url = urlify($thumb_path);
  return array($alt_text, $image_url, $thumb_url);
}

// calling this _func just to distinguish from the variable $embed_markdowns
function embed_markdown_func(string $image_path, string $thumb_path)
{
    list($alt_text, $image_url, $thumb_url) = process_paths($image_path, $thumb_path);

    $embed = sprintf("[![%s](%s)](%s)",$alt_text,$thumb_url,$image_url);

    return $embed;
}

function create_html_img_tag(string $image_path, string $thumb_path)
{
    list($alt_text, $image_url, $thumb_url) = process_paths($image_path, $thumb_path);

    $embed = sprintf("<br><img src='%s' alt='%s' />",$thumb_url,$alt_text);

    return $embed;
}

function alttextify(string $image_path)
{
  return str_replace('_',' ',pathinfo($image_path,PATHINFO_FILENAME));
}

function urlify(string $image_path)
{
  return str_replace('home/thundergoblin','',$image_path);
}
