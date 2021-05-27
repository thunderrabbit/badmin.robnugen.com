<?php
date_default_timezone_set ("Asia/Tokyo");

$date_of_month = date("d");
$date_prefix = strtolower(date("Y_M_d_"));

echo $date_of_month;

$day_number = $date_of_month + 15;


// subdir default for quests day-<?php printf('%02d', $day_number); /* leading zero per my preference */

?>

<form method="POST" action="bullet.php" enctype="multipart/form-data" >
  <input type="hidden" name="MAX_FILE_SIZE" value="10000000"/>
  <div>
    <label for="password">password</label> <input id="password" type="password" name="password" required />
  </div>
  <div>
    <input type="submit" value="upload"/>
  </div>
  <select name="save_to">
    <option value="events">events/YYYY</option>
    <option value="journal">journal/YYYY</option>
    <option value="blog">blog/YYYY</option>
    <option value="mt3cons">MT3 construction/YYYY</option>
    <option value="mt3parts">MT3 parts/YYYY</option>
    <option value="quests">quests</option>
    <option value="plan">walk plan</option>
    <option value="tmp">tmp</option>
  </select> / <input type="text" name="sub_dir" value=""/>
            / <input type="text" name="date_prefix" value="<?php echo $date_prefix; /* used if yyyy not found on image name */ ?>"/>

  <div>
    <textarea name="description[1]"></textarea>
    <input type="file" name="pictures1" />
    <input type="text" name="image_name[1]" />
  </div>
  <div>
    <textarea name="description[2]"></textarea>
    <input type="file" name="pictures2" />
    <input type="text" name="image_name[2]" />
  </div>
  <div>
    <textarea name="description[3]"></textarea>
    <input type="file" name="pictures3" />
    <input type="text" name="image_name[3]" />
  </div>
  <div>
    <textarea name="description[4]"></textarea>
    <input type="file" name="pictures4" />
    <input type="text" name="image_name[4]" />
  </div>
  <div>
    <textarea name="description[5]"></textarea>
    <input type="file" name="pictures5" />
    <input type="text" name="image_name[5]" />
  </div>
  <div>
    <textarea name="description[6]"></textarea>
    <input type="file" name="pictures6" />
    <input type="text" name="image_name[6]" />
  </div>
---------------------
  <div>
    <textarea name="description[7]"></textarea>
    <input type="file" name="pictures7" />
    <input type="text" name="image_name[7]" />
  </div>
  <div>
    <textarea name="description[8]"></textarea>
    <input type="file" name="pictures8" />
    <input type="text" name="image_name[8]" />
  </div>
  <div>
    <textarea name="description[9]"></textarea>
    <input type="file" name="pictures9" />
    <input type="text" name="image_name[9]" />
  </div>
  <div>
    <textarea name="description[10]"></textarea>
    <input type="file" name="pictures10" />
    <input type="text" name="image_name[10]" />
  </div>
  <div>
    <textarea name="description[11]"></textarea>
    <input type="file" name="pictures11" />
    <input type="text" name="image_name[11]" />
  </div>
  <div>
    <textarea name="description[12]"></textarea>
    <input type="file" name="pictures12" />
    <input type="text" name="image_name[12]" />
  </div>
</form>
