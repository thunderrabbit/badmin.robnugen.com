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

$images = new Bulletproof\Image($_FILES);

print_rob($_REQUEST,0);
// We can easily loop through array $_POST['image_name']
// but cannot as easily loop through an array of $_FILES['pictures']  (see commit c1f62fab9585ebeecec5)
foreach($_POST['image_name'] as $key => $image_name)
{
  print_rob("key " . $key,0);
  print_rob("image name: ". $image_name,0);
  print_rob($_FILES["pictures".$key],0);
  print_rob("about to get key " . "pictures".$key,0);
  if($images["pictures".$key]){
    print_rob("key be pictures".$key,0);
    $images->setName($image_name)
          ->setStorage("/home/thundergoblin/b.robnugen.com/tmp");  // no trailing slash

    $upload = $images->upload();
    print_rob("hellolololo" . __LINE__,0);
    print_rob($upload->getPath(),0);
  }
}
print_rob("ai");


if($images["pictures"]){
  $upload = $images->upload();

  if($upload){
    echo $upload->getFullPath(); // uploads/cat.gif
    echo $image->getName(); // samayo
    echo $image->getMime(); // gif
    echo $image->getLocation(); // avatars
    echo $image->getFullPath(); // avatars/samayo.gif
  }else{
    echo "errrrrored";
    echo $images->getError();
  }
} else {
  echo "<br>apparently nothing in image['pictures']";
  echo "<br>so what about files?<br>";
  print_rob($_FILES);
}

  ?>

brace yourself, fool.
