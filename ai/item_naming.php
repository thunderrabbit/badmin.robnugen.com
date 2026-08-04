<?php
/**
 * item_naming.php — helpers for the AI single-item naming flow.
 *
 *  - paths/constants for the leave-Japan item archive
 *  - $ITEM_CATEGORIES (curated, extensible via "add new" in the UI)
 *  - slugify_item()      : human text -> lowercase hyphen slug (file convention)
 *  - dir_slug_item()     : slug -> underscore form (multi-photo folder convention)
 *  - recent_item_names() : last N chosen names from the manifest (style examples)
 *  - item_tag_vocab()    : tags already in use across the catalog sidecars
 *  - item_normalize_tags(): loose tag input -> deduped list of slugs
 *  - item_model_id()     : 'haiku'|'sonnet' -> API model id
 *  - claude_name_image()  : single-photo vision call -> {names:[3], category, description}
 *  - claude_name_images() : multi-photo vision call -> + per-photo views[]
 *
 * No side effects on include (safe to require from anywhere).
 */

// ---- where the item archive lives on b.robnugen.com -------------------------
// (the "home/" segment is intentional: it means where Rob physically lives)
const ITEMS_BASE_DIR  = "/home/thundergoblin/b.robnugen.com/home/tokyo/2026/p1/items";
const ITEMS_STAGING   = ITEMS_BASE_DIR . "/.staging";
const ITEMS_MANIFEST  = ITEMS_BASE_DIR . "/items_manifest.jsonl";
const ITEMS_SIDECARS  = ITEMS_BASE_DIR . "/sidecars";   // catalog sidecars Lemur 13 scoops

// ---- curated starter categories (UI also allows typing a new subdir) --------
$ITEM_CATEGORIES = ["books", "clothes", "music", "magnets", "computer", "heavy"];

/**
 * Lowercase hyphen slug for FILENAMES, e.g. "Catcher and Rye" -> "catcher-and-rye".
 * Sacred-naming rule: keep it human-readable; only [a-z0-9-], no leading/trailing/double hyphens.
 */
function slugify_item(string $text): string
{
    $slug = mb_strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);  // anything not a-z0-9 -> hyphen
    $slug = preg_replace('/-+/', '-', $slug);          // collapse runs of hyphens
    $slug = trim($slug, '-');
    return $slug;
}

/** Folder slug for MULTI-PHOTO items uses underscores (per Rob's example: catcher_and_rye/). */
function dir_slug_item(string $slug): string
{
    return str_replace('-', '_', slugify_item($slug));
}

/**
 * Read the most recent chosen names from the manifest, newest last.
 * Passed to Claude as "name it the way Rob does" style examples.
 */
function recent_item_names(int $limit = 25, string $manifest = ITEMS_MANIFEST): array
{
    if (!is_file($manifest)) {
        return [];
    }
    $lines = @file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }
    $names = [];
    foreach (array_slice($lines, -$limit) as $line) {
        $row = json_decode($line, true);
        if (isset($row['name']) && $row['name'] !== '') {
            $names[] = $row['name'];
        }
    }
    return $names;
}

/**
 * The tags already in use across the catalog sidecars, most-used first
 * (alphabetical within a tie, so chip order is stable between page loads).
 *
 * The sidecars are the source of record for item data, so the vocabulary lives
 * there rather than in a list here: a tag Rob invents once becomes a chip and a
 * prompt hint forever after, with no PHP to edit. Deliberately uncached — Rob
 * also hand-edits sidecars on Lemur 13, and a stale cache would silently hide
 * the very vocabulary this exists to surface. Reading ~30 small files costs a
 * millisecond or two; if the catalog ever passes ~500 items, pull the "tags"
 * line out of the first 400 bytes instead of decoding the long descriptions.
 *
 * Off-host (no archive) this returns [] and the UI falls back to free text.
 */
function item_tag_vocab(int $limit = 40, string $dir = ITEMS_SIDECARS): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $counts = [];
    foreach (@glob("$dir/*.json") ?: [] as $file) {
        $row = json_decode((string) @file_get_contents($file), true);
        if (!is_array($row)) {
            continue;
        }
        foreach ((array) ($row['tags'] ?? []) as $tag) {
            $slug = slugify_item((string) $tag);
            if ($slug !== '') {
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }
    }
    ksort($counts);    // alphabetical, then...
    arsort($counts);   // ...most-used first (PHP 8 sorts are stable, so ties stay alphabetical)
    return array_slice(array_keys($counts), 0, $limit);
}

/** 'haiku' | 'sonnet' -> Anthropic model id. Defaults to Haiku. */
function item_model_id(string $model): string
{
    return $model === 'sonnet' ? 'claude-sonnet-4-6' : 'claude-haiku-4-5';
}

/** Guess an image media type for the API image block. */
function item_media_type(string $image_path): string
{
    $info = @getimagesize($image_path);
    if ($info && !empty($info['mime'])) {
        return $info['mime'];   // e.g. image/jpeg, image/png
    }
    return 'image/jpeg';
}

/**
 * Low-level: POST one or more images + a text prompt to Claude's vision API.
 *
 * The caller builds the prompt and parses the returned text — this just does the
 * transport. AI is assistive, so it never throws: failures come back as ok=false.
 *
 * @param string[] $image_paths full paths, sent as image blocks in order
 * @return array { ok: bool, text: string, error: string, raw: string }
 */
function item_anthropic_vision(array $image_paths, string $prompt, string $model_id, string $api_key, int $max_tokens = 500): array
{
    $out = ['ok' => false, 'text' => '', 'error' => '', 'raw' => ''];

    if ($api_key === '' || str_contains($api_key, 'replace-me')) {
        $out['error'] = "Anthropic API key not configured";
        return $out;
    }

    $content = [];
    foreach ($image_paths as $path) {
        if (!is_file($path)) {
            $out['error'] = "image not found: $path";
            return $out;
        }
        $content[] = ['type' => 'image', 'source' => [
            'type'       => 'base64',
            'media_type' => item_media_type($path),
            'data'       => base64_encode((string) file_get_contents($path)),
        ]];
    }
    $content[] = ['type' => 'text', 'text' => $prompt];

    $body = json_encode([
        'model'      => $model_id,
        'max_tokens' => $max_tokens,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'anthropic-version: 2023-06-01',
            'x-api-key: ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => $body,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        $out['error'] = "curl error: $cerr";
        return $out;
    }
    $out['raw'] = $resp;
    if ($http !== 200) {
        $out['error'] = "Anthropic HTTP $http";
        return $out;
    }

    $api  = json_decode($resp, true);
    $text = $api['content'][0]['text'] ?? '';
    if ($text === '') {
        $out['error'] = "empty model response";
        return $out;
    }
    $out['ok']   = true;
    $out['text'] = $text;
    return $out;
}

/** Strip a ```json ... ``` fence the model may have added, then decode to array ([] on failure). */
function item_parse_json(string $text): array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
    $parsed = json_decode(trim($text), true);
    return is_array($parsed) ? $parsed : [];
}

/**
 * Coerce the model's "views" into exactly $n filename-safe single-word slugs.
 * Missing/blank entries fall back to their 1-based position; duplicates get a
 * numeric suffix so per-item filenames never collide (front, front -> front, front-2).
 */
function item_normalize_views($views, int $n): array
{
    $views = is_array($views) ? array_values($views) : [];
    $out   = [];
    for ($i = 0; $i < $n; $i++) {
        $v = isset($views[$i]) ? slugify_item((string) $views[$i]) : '';
        if ($v !== '') { $v = explode('-', $v)[0]; }   // single word only
        $out[] = $v !== '' ? $v : (string) ($i + 1);
    }
    $seen = [];
    foreach ($out as $i => $v) {
        $base = $v; $k = 2;
        while (isset($seen[$v])) { $v = "$base-$k"; $k++; }
        $seen[$v] = true;
        $out[$i]  = $v;
    }
    return $out;
}

/**
 * Coerce loose tag input (model output, or Rob's typing) into filename-safe
 * slugs: blanks and duplicates dropped, at most $max kept. Junk in -> [] out.
 */
function item_normalize_tags($tags, int $max = 4): array
{
    $out = [];
    foreach (is_array($tags) ? $tags : [] as $tag) {
        $slug = slugify_item((string) $tag);
        if ($slug !== '' && !in_array($slug, $out, true)) {
            $out[] = $slug;
        }
        if (count($out) >= $max) { break; }
    }
    return $out;
}

/**
 * Single-photo convenience wrapper around claude_name_images().
 *
 * @return array { ok, names[], category, tags[], description, model, error, raw }
 *  On any failure ok=false and the UI falls back to a manual name field —
 *  AI is assistive, never blocking.
 */
function claude_name_image(string $image_path, string $model, array $recent_names, string $api_key, array $categories, array $tag_vocab = []): array
{
    $out = claude_name_images([$image_path], $model, $recent_names, $api_key, $categories, $tag_vocab);
    unset($out['views']);   // single-photo callers don't use per-photo angles
    return $out;
}

/**
 * Ask Claude to look at all photos of ONE object and propose 3 names + a category
 * + tags + a description + a per-photo "view" (angle) word, aligned to image order.
 *
 * @param string[] $image_paths the staged photos of a single item, in order
 * @param string[] $tag_vocab   tags already in use, to bias against sprawl (optional)
 * @return array {
 *   ok: bool, names: string[], category: string, tags: string[], description: string,
 *   views: string[] (one per image), model: string, error: string, raw: string
 * }
 */
function claude_name_images(array $image_paths, string $model, array $recent_names, string $api_key, array $categories, array $tag_vocab = []): array
{
    $out = ['ok' => false, 'names' => [], 'category' => '', 'tags' => [], 'description' => '',
            'price_jpy' => null,
            'views' => [], 'model' => item_model_id($model), 'error' => '', 'raw' => ''];

    $image_paths = array_values($image_paths);
    $n = count($image_paths);
    if ($n === 0) {
        $out['error'] = "no images given";
        return $out;
    }

    $cat_list = implode(', ', $categories);
    $examples = $recent_names
        ? "Here are recent filenames Rob has chosen, so you match his style:\n- " . implode("\n- ", $recent_names) . "\n\n"
        : "";

    $intro = $n === 1
        ? "You are helping Rob catalog a single physical possession he is photographing " .
          "before leaving Japan. Look at the photo and name the OBJECT (not the scene).\n\n"
        : "You are helping Rob catalog a single physical possession he is photographing " .
          "before leaving Japan. There are $n photos of the SAME object from different " .
          "angles, given in order. Name the OBJECT (not the scene).\n\n";

    $views_key = $n === 1
        ? "  \"views\": [exactly 1 short lowercase angle word for the photo]\n"
        : "  \"views\": [exactly $n short lowercase angle words, ONE PER PHOTO IN ORDER, " .
          "each a single word like \"front\", \"back\", \"spine\", \"label\", \"detail\"]\n";

    // Bias toward tags already in use so the catalog filter does not sprawl
    // into near-synonyms (nin vs nine-inch-nails vs nine_inch_nails).
    $tags_key = $tag_vocab
        ? "  \"tags\": [2-4 lowercase hyphenated tags for browsing the catalog. STRONGLY PREFER " .
          "tags from this list already in use: " . implode(', ', $tag_vocab) . ". Invent a new " .
          "hyphenated tag only if nothing in the list fits the object],\n"
        : "  \"tags\": [2-4 short lowercase hyphenated tags for browsing the catalog],\n";

    $prompt =
        $intro . $examples .
        "Return STRICT JSON only (no prose, no code fence) with exactly these keys:\n" .
        "{\n" .
        "  \"names\": [3 short human-readable names for the object, 2-5 words each, " .
        "specific not generic, e.g. \"blue denim jacket\" not \"jacket\"],\n" .
        "  \"category\": one of [$cat_list] that best fits, or \"other\" if none fit " .
        "(\"heavy\" = large items hard to ship: furniture, appliances, safe),\n" .
        $tags_key .
        "  \"description\": one factual sentence describing the object,\n" .
        "  \"price_jpy\": estimated typical SECOND-HAND price in Japan in whole yen " .
        "(integer only, no commas or symbols) — what a used one realistically sells for " .
        "quickly on Mercari / Yahoo Auctions. Rob is decluttering, not maximizing, so estimate " .
        "modestly. Use null if you genuinely cannot tell.,\n" .
        $views_key .
        "}";

    $resp = item_anthropic_vision($image_paths, $prompt, $out['model'], $api_key);
    $out['raw'] = $resp['raw'];
    if (!$resp['ok']) {
        $out['error'] = $resp['error'];
        return $out;
    }

    $parsed = item_parse_json($resp['text']);
    if (!isset($parsed['names']) || !is_array($parsed['names'])) {
        $out['error'] = "could not parse JSON from model";
        return $out;
    }

    $out['names']       = array_values(array_filter(array_map('strval', $parsed['names'])));
    $out['category']    = isset($parsed['category']) ? strtolower(trim((string) $parsed['category'])) : '';
    $out['tags']        = item_normalize_tags($parsed['tags'] ?? []);
    $out['description'] = isset($parsed['description']) ? trim((string) $parsed['description']) : '';
    if (array_key_exists('price_jpy', $parsed)) {
        $p = $parsed['price_jpy'];
        $out['price_jpy'] = (is_numeric($p) && $p > 0) ? (int) round($p) : null;
    }
    $out['views']       = item_normalize_views($parsed['views'] ?? [], $n);
    $out['ok']          = count($out['names']) > 0;
    return $out;
}
