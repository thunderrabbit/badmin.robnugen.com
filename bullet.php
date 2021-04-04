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

$image = new Bulletproof\Image($_FILES);

  $image->setName("honkey")
        ->setStorage("/home/thundergoblin/b.robnugen.com/blog/");

if($image["pictures"]){
  $upload = $image->upload();

  if($upload){
    echo "hellolololo";
    echo $upload->getFullPath(); // uploads/cat.gif
    echo $image->getName(); // samayo
    echo $image->getMime(); // gif
    echo $image->getLocation(); // avatars
    echo $image->getFullPath(); // avatars/samayo.gif
  }else{
    echo "errrrrored";
    echo $image->getError();
  }
}

  ?>

brace yourself, fool.
