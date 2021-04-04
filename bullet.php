<?php

require_once  "/home/thundergoblin/bulletproof/src/bulletproof.php";

$image = new Bulletproof\Image($_FILES);

  $image->setName("honkey")
        ->setMime(["png","gif","jpg"])
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
