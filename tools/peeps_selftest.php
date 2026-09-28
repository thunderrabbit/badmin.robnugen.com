<?php
/**
 * peeps_selftest.php — regression check for peeps/peeps_lib.php.
 *
 * Runs anywhere, including Lemur 13 (no gd or mbstring needed):
 *
 *   php tools/peeps_selftest.php
 *
 * Fixtures, captured from b.rn on 2026-09-28:
 *   jess_files.txt     every *jess* file under journal/ blog/ events/, thumbs/ and
 *                      adaptive-images/ copies included, jesse/jessica decoys included
 *   jess_expected.html the hand-built page Rob approved ("It's perfect.")
 *
 * The files are recreated as empty stubs in a temp tree, so the build must reproduce
 * the approved page byte for byte from filenames alone.
 */

require_once __DIR__ . "/../peeps/peeps_lib.php";

date_default_timezone_set("Asia/Tokyo");

$failures = 0;

function check(string $what, $got, $want): void
{
    global $failures;
    $ok = $got === $want;
    if (!$ok) { $failures++; }
    printf("%s %s%s\n", $ok ? "  ok" : "FAIL", $what,
        $ok ? "" : "\n     got  " . var_export($got, true) . "\n     want " . var_export($want, true));
}

// --- peeps_parse_name: the three date shapes on disk, and none
check("parse Y_mon_d",   peeps_parse_name("2024_mar_16_rob_and_jess"), ["2024-03-16", "rob_and_jess"]);
check("parse Y_m_d",     peeps_parse_name("2024_01_25_jess_rob"),      ["2024-01-25", "jess_rob"]);
check("parse Ymd",       peeps_parse_name("20240312_silent_disco"),    ["2024-03-12", "silent_disco"]);
check("parse no date",   peeps_parse_name("sameer_jess_rob_bella"),    null);
check("parse bad month", peeps_parse_name("2024_foo_16_rob"),          null);
check("parse bad day",   peeps_parse_name("2024_02_31_rob"),           null);

// --- peeps_match_jess: whole-word jess, plus rob or with_jess
$m = fn($n) => peeps_match_jess(explode('_', $n), $n);
check("match rob+jess",    $m("2024_mar_16_rob_and_jess_at_park"), true);
check("match with_jess",   $m("2026_mar_24_with_jess_on_the_beach"), true);
check("skip jess alone",   $m("2024_oct_06_yoga_barn_jess_tree"), false);
check("skip jesse",        $m("eric_jesse_jgf_rob"), false);
check("skip jessbun",      $m("2024_oct_17_jessbun_at_spring"), false);

// --- full build against the fixture tree
$fixtures = __DIR__ . "/peeps_fixtures";
$root     = sys_get_temp_dir() . "/peeps_selftest_" . getmypid();
foreach (file("$fixtures/jess_files.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $rel) {
    @mkdir(dirname("$root/$rel"), 0777, true);
    touch("$root/$rel");
}
// The one undated photo: its mtime on b.rn is 2024-06-24.
touch("$root/journal/2024/sameer_jess_rob_bella.jpeg", strtotime("2024-06-24 12:00 UTC"));

$page  = PEEPS_PAGES['jess'];
$items = peeps_items($root, peeps_find($root, $page));
$html  = peeps_render($page['title'], $items);

check("31 photos", count($items), 31);
check("oldest first", $items[0]['path'], "journal/2024/2024_jan_bali/2024_01_25_jess_rob_after_water_ceremony.jpg");
check("undated label", $items[16]['label'], "June 2024 (approx.)");
check("byte-identical to approved page", $html, file_get_contents("$fixtures/jess_expected.html"));

// --- peeps_existing_paths reads back what peeps_render wrote
$paths = array_column($items, 'path');
check("existing paths round-trip", peeps_existing_paths($html), $paths);

exec("rm -rf " . escapeshellarg($root));

echo $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
