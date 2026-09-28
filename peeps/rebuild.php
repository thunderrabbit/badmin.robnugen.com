<?php
/**
 * peeps/rebuild.php — rebuild one static gallery under b.robnugen.com/peeps/.
 *
 *  POST: password, page (a key of PEEPS_PAGES)
 *
 * Writes <web root>/peeps/<page>/index.html only when the page actually changed,
 * keeping the previous build beside it as index.prev.html. Those galleries live
 * only on b.rn (no git), so that one backup is the whole undo story.
 */

date_default_timezone_set("Asia/Tokyo");
header('Content-Type: application/json');

require_once "/home/thundergoblin/bulletproof_config.php";   // $bulletproof_password_hash
require_once __DIR__ . "/peeps_lib.php";

function fail(string $msg): void
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!password_verify($_POST['password'] ?? '', $bulletproof_password_hash)) {
    fail('Invalid password.');
}

$key = $_POST['page'] ?? '';
if (!isset(PEEPS_PAGES[$key])) {
    fail('unknown page');
}
$page = PEEPS_PAGES[$key];

$items = peeps_items(PEEPS_WEB_ROOT, peeps_find(PEEPS_WEB_ROOT, $page));
$html  = peeps_render($page['title'], $items);

$dir  = PEEPS_WEB_ROOT . "/peeps/$key";
$path = "$dir/index.html";
$old  = is_file($path) ? file_get_contents($path) : '';

$now_paths = array_column($items, 'path');
$was_paths = peeps_existing_paths($old);
$result = [
    'ok'      => true,
    'url'     => "https://b.robnugen.com/peeps/$key/",
    'count'   => count($items),
    'added'   => array_values(array_diff($now_paths, $was_paths)),
    'removed' => array_values(array_diff($was_paths, $now_paths)),
    'changed' => $html !== $old,
];

if ($result['changed']) {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        fail("could not create $dir");
    }
    // Write beside, then rename: a visitor never sees a half-written page.
    $tmp = "$path.tmp";
    if (file_put_contents($tmp, $html) === false) {
        fail("could not write $tmp");
    }
    if ($old !== '' && !copy($path, "$dir/index.prev.html")) {
        @unlink($tmp);
        fail('could not back up the previous page');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        fail("could not replace $path");
    }
}

echo json_encode($result, JSON_UNESCAPED_SLASHES);
