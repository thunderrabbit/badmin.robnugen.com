<?php

require_once  "/home/thundergoblin/bulletproof/src/bulletproof.php";

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

  // TODO: convert spaces to underscores

  // TODO maybe convert to lowercase??

  return $return_val;
}

$storage_directory = determine_storage_directory($_REQUEST["save_to"],$_REQUEST["sub_dir"]);

$images = new Bulletproof\Image($_FILES);

// We can easily loop through array $_POST['image_name']
// but cannot as easily loop through an array of $_FILES['pictures']  (see commit c1f62fab9585ebeecec5)
foreach($_POST['image_name'] as $key => $image_name)
{
  // prefer image name sent in field, and falls back to name of image file
  $save_image_name = create_image_name($image_name,$_FILES["pictures".$key]);
  if($images["pictures".$key])
  {
    $images->setName($save_image_name)
           ->setStorage($storage_directory);  // no trailing slash

    $upload = $images->upload();
    print_rob($upload->getPath(),0);
  }
  else if(!empty($save_image_name))
  {
    // (There was an image name but no file)
    echo "<br>apparently nothing in images[\"pictures\".$key], but we have filename $save_image_name";
    echo "<br>so what about files?<br>";
    print_rob($_FILES["pictures".$key]);
  }
}

function determine_storage_directory($save_to, $sub_dir)
{
  filter_var($save_to, FILTER_SANITIZE_STRING);
  print_rob($save_to,0);
  filter_var($sub_dir, FILTER_SANITIZE_STRING);
  print_rob($sub_dir,0);
  $location_determination = array(
    // no trailing slash
    "journal" => "/home/thundergoblin/b.robnugen.com/journal/2021",
    "quests" => "/home/thundergoblin/b.robnugen.com/quests/walk-to-niigata/2021/en_route",
    "blog" => "/home/thundergoblin/b.robnugen.com/blog/2021",
  );
  // TODO: note these assume the directory separator is / (slash)
  $out_dir = $location_determination[$save_to] . "/" . $sub_dir; // append $sub_dir to requested location
  $out_dir = rtrim($out_dir, '/');  // remove trailing slash (in case $sub_dir is empty)
  print_rob("plan to save to " . $out_dir);
}
