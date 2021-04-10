<?php

/* BEGIN if you lose the password  * /
// 1. uncomment this block
// 2. hardcode the desired passcode below
// 3. copy output hash to $bulletproof_password_hash
// 4. erase hardcoded password
// 5. recomment this block
$bulletproof_password="change this to your preferred password";
$bulletproof_password_hash = password_hash($bulletproof_password, PASSWORD_DEFAULT);
echo $bulletproof_password;
echo "<br>";
echo $bulletproof_password_hash;
exit;
/* END if you lose the password  */

$bulletproof_password_hash = '$2y$10$Pv0hJoGGeeV6eDKLDR3gAOFb08lTZwtQ.lt6SMOceIxviLiPnbvZW';   // use single quotes to hide $ from PHP
