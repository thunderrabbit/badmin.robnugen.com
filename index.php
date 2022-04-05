<?php
date_default_timezone_set ("Asia/Tokyo");

$date_of_month = date("d");
$date_prefix = strtolower(date("Y_M_d_"));

echo $date_of_month;

$day_number = $date_of_month + 15;


//  Plan to set the subdir based on select, a la https://stackoverflow.com/a/12661801/194309
// This was the subdir default for quests day-<?php printf('%02d', $day_number); /* leading zero per my preference */

?>

<form method="POST" action="bullet.php" enctype="multipart/form-data" >
  <input type="hidden" name="MAX_FILE_SIZE" value="10000000"/>
  <div>
    <label for="password">password</label> <input id="password" type="password" name="password" required />
    <label for="debug_level">debug_level</label> <input id="debug_level" type="text" name="debug_level" value="" placeholder="0 - 5"/>
  </div>
  <div>
    <input type="submit" value="upload"/>
  </div>
  <select name="save_to">
    <option value="journal">journal/YYYY</option>
    <option value="events">events/YYYY</option>
    <option value="walk_and_talk">blog/YYYY/walk_and_talk</option>
    <option value="blog">blog/YYYY</option>
    <option value="tmp">tmp</option>
    <option value="mt3cons">MT3 construction/YYYY</option>
    <option value="mt3parts">MT3 parts/YYYY</option>
    <option value="quests">quests</option>
    <option value="plan">walk plan</option>
  </select> / <input type="text" name="sub_dir" placeholder="day-12" value=""/>
            / <input type="text" name="date_prefix" placeholder="if YYYY not found in file name" value="<?php echo $date_prefix; /* used if yyyy not found on image name */ ?>"/>

  <div>
    <textarea name="description[1]" placeholder="describe image 1"></textarea>
    <input type="file" name="pictures1" />
    <input type="text" name="image_name[1]" placeholder="name image 1" />
  </div>
  <div>
    <textarea name="description[2]" placeholder="describe image 2"></textarea>
    <input type="file" name="pictures2" />
    <input type="text" name="image_name[2]" placeholder="name image 2" />
  </div>
  <div>
    <textarea name="description[3]" placeholder="describe image 3"></textarea>
    <input type="file" name="pictures3" />
    <input type="text" name="image_name[3]" placeholder="name image 3" />
  </div>
  <div>
    <textarea name="description[4]" placeholder="describe image 4"></textarea>
    <input type="file" name="pictures4" />
    <input type="text" name="image_name[4]" placeholder="name image 4" />
  </div>
  <div>
    <textarea name="description[5]" placeholder="describe image 5"></textarea>
    <input type="file" name="pictures5" />
    <input type="text" name="image_name[5]" placeholder="name image 5" />
  </div>
  <div>
    <textarea name="description[6]" placeholder="describe image 6"></textarea>
    <input type="file" name="pictures6" />
    <input type="text" name="image_name[6]" placeholder="name image 6" />
  </div>
---------------------
  <div>
    <textarea name="description[7]" placeholder="describe image 7"></textarea>
    <input type="file" name="pictures7" />
    <input type="text" name="image_name[7]" placeholder="name image 7" />
  </div>
  <div>
    <textarea name="description[8]" placeholder="describe image 8"></textarea>
    <input type="file" name="pictures8" />
    <input type="text" name="image_name[8]" placeholder="name image 8" />
  </div>
  <div>
    <textarea name="description[9]" placeholder="describe image 9"></textarea>
    <input type="file" name="pictures9" />
    <input type="text" name="image_name[9]" placeholder="name image 9" />
  </div>
  <div>
    <textarea name="description[10]" placeholder="describe image 10"></textarea>
    <input type="file" name="pictures10" />
    <input type="text" name="image_name[10]" placeholder="name image 10" />
  </div>
  <div>
    <textarea name="description[11]" placeholder="describe image 11"></textarea>
    <input type="file" name="pictures11" />
    <input type="text" name="image_name[11]" placeholder="name image 11" />
  </div>
  <div>
    <textarea name="description[12]" placeholder="describe image 12"></textarea>
    <input type="file" name="pictures12" />
    <input type="text" name="image_name[12]" placeholder="name image 12" />
  </div>
</form>
