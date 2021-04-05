<?php

require_once  "/home/thundergoblin/bulletproof/src/bulletproof.php";

function print_rob($object, $exit = true) {
    echo("<pre>");
    if(is_object($object) && method_exists($object, "toArray")) {
        echo "ResultSet => ".print_r($object->toArray(), true);
    } else {
        print_r($object);
    }
    echo("</pre>");
    if($exit) {
        exit;
    }
}

function create_image_name($image_name, $image_info)
{
  $return_val = $image_name ? $image_name : pathinfo($image_info['name'], PATHINFO_FILENAME);
  return $return_val;
}
$images = new Bulletproof\Image($_FILES);

// We can easily loop through array $_POST['image_name']
// but cannot as easily loop through an array of $_FILES['pictures']  (see commit c1f62fab9585ebeecec5)
foreach($_POST['image_name'] as $key => $image_name)
{
  // prefer image name sent in field, and falls back to name of image file
  $save_image_name = create_image_name($image_name,$_FILES["pictures".$key]);
  if($images["pictures".$key]){
    $images->setName($save_image_name)
           ->setStorage("/home/thundergoblin/b.robnugen.com/tmp");  // no trailing slash

    $upload = $images->upload();
    print_rob($upload->getPath(),0);
  } else if(!empty($save_image_name)) {
    // (There was an image name but no file)
    echo "<br>apparently nothing in images[\"pictures\".$key], but we have filename $save_image_name";
    echo "<br>so what about files?<br>";
    print_rob($_FILES["pictures".$key]);
  }
}
print_rob("ai");



  ?>

brace yourself, fool.
