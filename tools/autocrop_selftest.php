<?php
/**
 * autocrop_selftest.php — regression check for autocrop_lib.php.
 *
 * Must run ON THE HOST: local PHP on Lemur 13 has neither gd nor mbstring, so
 * autocrop_lib.php cannot execute there at all.
 *
 *   ssh b.rn 'cd ~/badmin.robnugen.com && php tools/autocrop_selftest.php'
 *
 * The fixture is deliberately NOT in git (a 1.2MB photo). Put it on the host once:
 *   scp ~/Downloads/original.jpg b.rn:~/autocrop_fixtures/
 *
 * Expected values come from an independent numpy implementation cross-checked
 * against Rob's hand crop of the same photo (964x1529, subject at 81.1% x 80.5%).
 */

require_once __DIR__ . "/../autocrop_lib.php";

const FIXTURE   = "/home/thundergoblin/autocrop_fixtures/original.jpg";
const TOLERANCE = 30;   // px, per edge

$fixture = $argv[1] ?? FIXTURE;

if (!is_file($fixture)) {
    fwrite(STDERR, "fixture not found: $fixture\n"
                 . "scp ~/Downloads/original.jpg b.rn:~/autocrop_fixtures/\n");
    exit(2);
}

$failures = 0;

function check(string $what, $got, $want, $tol = 0): void
{
    global $failures;
    $ok = is_numeric($want) ? (abs($got - $want) <= $tol) : ($got === $want);
    if (!$ok) { $failures++; }
    printf("%s %-34s got %-16s want %s%s\n",
        $ok ? "  ok" : "FAIL", $what, (string) $got, (string) $want,
        $tol ? " (±$tol)" : "");
}

// ---- detection --------------------------------------------------------------
$t0   = microtime(true);
$bbox = detect_subject_bbox($fixture);
$detect_secs = microtime(true) - $t0;

if ($bbox === null) {
    fwrite(STDERR, "FAIL detect_subject_bbox returned null on the fixture\n");
    exit(1);
}

[$bx, $by, $bw, $bh] = $bbox;
echo "subject bbox: ($bx,$by)-(" . ($bx + $bw) . "," . ($by + $bh) . ")  {$bw}x{$bh}\n";
check('bbox left',   $bx,       535, TOLERANCE);
check('bbox top',    $by,       719, TOLERANCE);
check('bbox right',  $bx + $bw, 1316, TOLERANCE);
check('bbox bottom', $by + $bh, 1949, TOLERANCE);

// ---- padding ----------------------------------------------------------------
$size = getimagesize($fixture);
$box  = pad_bbox_to_fill($bbox, (int) $size[0], (int) $size[1]);
echo "crop box:     x={$box[0]} y={$box[1]} {$box[2]}x{$box[3]}\n";
check('crop width',  $box[2], 964,  TOLERANCE);
check('crop height', $box[3], 1529, TOLERANCE);
check('subject fills width %',  round($bw / $box[2] * 100), 80, 2);
check('subject fills height %', round($bh / $box[3] * 100), 80, 2);

// ---- end to end on a scratch copy -------------------------------------------
$tmp = sys_get_temp_dir() . "/autocrop_selftest_" . getmypid() . ".jpg";
copy($fixture, $tmp);
$used = autocrop_to_subject($tmp);
check('autocrop_to_subject cropped', $used !== null, true);
if ($used !== null) {
    $after = getimagesize($tmp);
    check('cropped file width',  $after[0], $box[2]);
    check('cropped file height', $after[1], $box[3]);
}
unlink($tmp);

// ---- slugs ------------------------------------------------------------------
check('ac_slugify',  ac_slugify('Candy Mama'),  'candy-mama');
check('ac_file_slug', ac_file_slug('Candy Mama'), 'candy_mama');
check('ac_slugify strips junk', ac_slugify('  Candy++Mama!! '), 'candy-mama');
check('ac_slugify of non-ascii', ac_slugify('キャンディ'), '');

printf("\ndetect %.2fs, peak mem %.1f MB\n", $detect_secs, memory_get_peak_usage(true) / 1048576);
echo $failures ? "$failures FAILURE(S)\n" : "all checks passed\n";
exit($failures ? 1 : 0);
