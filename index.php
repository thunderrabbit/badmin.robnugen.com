Hello

<form method="POST" action="bullet.php" enctype="multipart/form-data" >
  <input type="hidden" name="MAX_FILE_SIZE" value="5000000"/>
  <select name="save_to">
    <option value="tmp">tmp</option>
    <option value="quests">quests</option>
    <option value="plan">walk plan</option>
    <option value="journal">journal</option>
    <option value="blog">blog</option>
  </select> / <input type="text" name="sub_dir" />

  <div>
    <input type="file" name="pictures1" />
    <input type="text" name="image_name[1]" />
  </div>
  <div>
    <input type="file" name="pictures2" />
    <input type="text" name="image_name[2]" />
  </div>
  <div>
    <input type="file" name="pictures3" />
    <input type="text" name="image_name[3]" />
  </div>
  <div>
    <input type="file" name="pictures4" />
    <input type="text" name="image_name[4]" />
  </div>
  <div>
    <input type="file" name="pictures5" />
    <input type="text" name="image_name[5]" />
  </div>
  <div>
    <input type="file" name="pictures6" />
    <input type="text" name="image_name[6]" />
  </div>
  <div>
    <label for="password">password</label> <input id="password" type="password" name="password" />
  </div>
  <div>
    <input type="submit" value="upload"/>
  </div>
  <div>
    <input type="file" name="pictures7" />
    <input type="text" name="image_name[7]" />
  </div>
  <div>
    <input type="file" name="pictures8" />
    <input type="text" name="image_name[8]" />
  </div>
  <div>
    <input type="file" name="pictures9" />
    <input type="text" name="image_name[9]" />
  </div>
  <div>
    <input type="file" name="pictures10" />
    <input type="text" name="image_name[10]" />
  </div>
  <div>
    <input type="file" name="pictures11" />
    <input type="text" name="image_name[11]" />
  </div>
  <div>
    <input type="file" name="pictures12" />
    <input type="text" name="image_name[12]" />
  </div>
</form>
