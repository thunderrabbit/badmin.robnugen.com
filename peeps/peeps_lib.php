<?php
/**
 * peeps_lib.php — build the static people galleries under b.robnugen.com/peeps/.
 *
 * Pure functions, no side effects on include. rebuild.php does the writing;
 * tools/peeps_selftest.php exercises everything here against a fake tree, and
 * needs neither gd nor mbstring, so it runs on Lemur 13 too.
 *
 * Photos are found by FILENAME, not by any tag or sidecar: badmin already names
 * uploads like 2026_sep_11_rob_and_jess_at_nest_kiosk.jpeg, and that name carries
 * both who is in the photo and when it was taken.
 */

const PEEPS_WEB_ROOT = "/home/thundergoblin/b.robnugen.com";

/**
 * One entry per gallery page. Rebuilt on demand from /peeps/ in badmin, never by cron:
 * Rob prefers to trigger a build once in a while.
 *
 *   search: dirs under the web root to scan, recursively
 *   match:  a filename qualifies when this returns true for its underscore tokens
 */
const PEEPS_PAGES = [
    'jess' => [
        'title'  => 'Rob and Jess',
        'search' => ['journal', 'blog', 'events'],
        // Rob's rule (2026-09-28): photos with "rob" in the name, or "with_jess".
        // Photos of Jess alone are deliberately left out.
        'match'  => 'peeps_match_jess',
    ],
];

const PEEPS_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// adaptive-images/ and thumbs/ hold derived copies of photos found elsewhere.
const PEEPS_SKIP_DIRS = ['adaptive-images', 'thumbs'];

/**
 * Token match, not substring: "jess" must be a whole word, so jesse_ and jessica_
 * (other people, 2005 and 2021) never qualify.
 */
function peeps_match_jess(array $tokens, string $name): bool
{
    if (!in_array('jess', $tokens, true)) {
        return false;
    }
    return in_array('rob', $tokens, true) || strpos($name, 'with_jess') !== false;
}

/**
 * Every qualifying original under $web_root, as paths relative to it.
 * The _1000 resized copies are skipped here; peeps_items() pairs them back up.
 */
function peeps_find(string $web_root, array $page): array
{
    $found = [];
    foreach ($page['search'] as $top) {
        $dir = "$web_root/$top";
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                fn($f) => !($f->isDir() && in_array($f->getFilename(), PEEPS_SKIP_DIRS, true))
            )
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, PEEPS_IMAGE_EXTS, true)) {
                continue;
            }
            $name = strtolower($f->getBasename('.' . $f->getExtension()));
            if (preg_match('/_1000$/', $name)) {
                continue;
            }
            if (($page['match'])(explode('_', $name), $name)) {
                $found[] = substr($f->getPathname(), strlen($web_root) + 1);
            }
        }
    }
    return $found;
}

/**
 * Date from the filename, in any of the three shapes on disk:
 *   2024_mar_16_rest   (badmin's own date prefix)
 *   2024_01_25_rest
 *   20240312_rest
 * Returns [Y-m-d, rest] or null when the name carries no date.
 */
function peeps_parse_name(string $name): ?array
{
    static $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
                      'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    $name = strtolower($name);
    if (preg_match('/^(\d{4})_([a-z]{3})_(\d{2})_(.*)$/', $name, $m) && isset($months[$m[2]])) {
        $y = (int) $m[1]; $mo = $months[$m[2]]; $d = (int) $m[3]; $rest = $m[4];
    } elseif (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(.*)$/', $name, $m)
           || preg_match('/^(\d{4})(\d{2})(\d{2})_(.*)$/', $name, $m)) {
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3]; $rest = $m[4];
    } else {
        return null;
    }
    if (!checkdate($mo, $d, $y)) {
        return null;
    }
    return [sprintf('%04d-%02d-%02d', $y, $mo, $d), $rest];
}

/**
 * One gallery card per original, oldest first.
 *
 * A name without a date (sameer_jess_rob_bella.jpeg) falls back to the file's mtime
 * and says so: "June 2024 (approx.)". The original's mtime, not the _1000 copy's —
 * those were regenerated in bulk long after the photos were taken.
 */
function peeps_items(string $web_root, array $paths): array
{
    $items = [];
    foreach ($paths as $rel) {
        $ext  = pathinfo($rel, PATHINFO_EXTENSION);
        $base = substr($rel, 0, -strlen($ext) - 1);
        $name = basename($base);

        $parsed = peeps_parse_name($name);
        if ($parsed) {
            [$date, $rest] = $parsed;
            $label = date('j F Y', strtotime($date));
        } else {
            $mtime = (int) @filemtime("$web_root/$rel");
            $date  = date('Y-m-d', $mtime);
            $rest  = $name;
            $label = date('F Y', $mtime) . ' (approx.)';
        }

        $small = "{$base}_1000.$ext";
        $items[] = [
            'path'    => $rel,
            'thumb'   => is_file("$web_root/$small") ? $small : $rel,
            'date'    => $date,
            'label'   => $label,
            'caption' => str_replace('_', ' ', $rest),
            'name'    => $name,
        ];
    }
    usort($items, fn($a, $b) => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);
    return $items;
}

function peeps_render(string $title, array $items): string
{
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $cards = [];
    foreach ($items as $it) {
        $cards[] = "  <figure>\n"
                 . "    <a href=\"/{$h($it['path'])}\"><img src=\"/{$h($it['thumb'])}\" alt=\"{$h($it['caption'])}\" loading=\"lazy\"></a>\n"
                 . "    <figcaption><time>{$h($it['label'])}</time> {$h($it['caption'])}</figcaption>\n"
                 . "  </figure>";
    }
    $cards = implode("\n", $cards);
    $title = $h($title);
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title</title>
<style>
  body { margin: 0; padding: 16px; font-family: system-ui, sans-serif; background: #faf8f5; color: #222; }
  h1 { font-weight: 600; margin: 0 0 16px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
  figure { margin: 0; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
  img { display: block; width: 100%; height: 320px; object-fit: cover; }
  figcaption { padding: 8px 12px 12px; font-size: 14px; }
  time { display: block; font-weight: 600; margin-bottom: 2px; }
</style>
</head>
<body>
<h1>$title</h1>
<div class="grid">
$cards
</div>
</body>
</html>

HTML;
}

/** The photo hrefs already on a built page, so a rebuild can report what is new. */
function peeps_existing_paths(string $html): array
{
    preg_match_all('/<a href="\/([^"]+)"><img /', $html, $m);
    return array_map(fn($p) => html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
}
