<?php
/**
 * item_naming.php — helpers for the AI single-item naming flow.
 *
 *  - paths/constants for the leave-Japan item archive
 *  - $ITEM_CATEGORIES (curated, extensible via "add new" in the UI)
 *  - slugify_item()      : human text -> lowercase hyphen slug (file convention)
 *  - dir_slug_item()     : slug -> underscore form (multi-photo folder convention)
 *  - recent_item_names() : last N chosen names from the manifest (style examples)
 *  - item_model_id()     : 'haiku'|'sonnet' -> API model id
 *  - claude_name_image() : vision call -> {names:[3], category, description}
 *
 * No side effects on include (safe to require from anywhere).
 */

// ---- where the item archive lives on b.robnugen.com -------------------------
// (the "home/" segment is intentional: it means where Rob physically lives)
const ITEMS_BASE_DIR  = "/home/thundergoblin/b.robnugen.com/home/tokyo/2026/p1/items";
const ITEMS_STAGING   = ITEMS_BASE_DIR . "/.staging";
const ITEMS_MANIFEST  = ITEMS_BASE_DIR . "/items_manifest.jsonl";

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
 * Ask Claude to look at the photo and propose 3 names + a category + a description.
 *
 * @return array {
 *   ok: bool, names: string[], category: string, description: string,
 *   model: string, error: string, raw: string
 * }
 *  On any failure ok=false and the UI falls back to a manual name field —
 *  AI is assistive, never blocking.
 */
function claude_name_image(string $image_path, string $model, array $recent_names, string $api_key, array $categories): array
{
    $out = ['ok' => false, 'names' => [], 'category' => '', 'description' => '',
            'model' => item_model_id($model), 'error' => '', 'raw' => ''];

    if (!is_file($image_path)) {
        $out['error'] = "image not found: $image_path";
        return $out;
    }
    if ($api_key === '' || str_contains($api_key, 'replace-me')) {
        $out['error'] = "Anthropic API key not configured";
        return $out;
    }

    $b64 = base64_encode((string) file_get_contents($image_path));

    $cat_list = implode(', ', $categories);
    $examples = $recent_names
        ? "Here are recent filenames Rob has chosen, so you match his style:\n- " . implode("\n- ", $recent_names) . "\n\n"
        : "";

    $prompt =
        "You are helping Rob catalog a single physical possession he is photographing " .
        "before leaving Japan. Look at the photo and name the OBJECT (not the scene).\n\n" .
        $examples .
        "Return STRICT JSON only (no prose, no code fence) with exactly these keys:\n" .
        "{\n" .
        "  \"names\": [3 short human-readable names for the object, 2-5 words each, " .
        "specific not generic, e.g. \"blue denim jacket\" not \"jacket\"],\n" .
        "  \"category\": one of [$cat_list] that best fits, or \"other\" if none fit " .
        "(\"heavy\" = large items hard to ship: furniture, appliances, safe),\n" .
        "  \"description\": one factual sentence describing the object\n" .
        "}";

    $body = json_encode([
        'model'      => $out['model'],
        'max_tokens' => 500,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => [
                    'type' => 'base64', 'media_type' => item_media_type($image_path), 'data' => $b64]],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]],
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

    $api = json_decode($resp, true);
    $text = $api['content'][0]['text'] ?? '';
    if ($text === '') {
        $out['error'] = "empty model response";
        return $out;
    }

    // Strip a ```json ... ``` fence if the model added one, then decode.
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
    $parsed = json_decode(trim($text), true);
    if (!is_array($parsed) || !isset($parsed['names']) || !is_array($parsed['names'])) {
        $out['error'] = "could not parse JSON from model";
        return $out;
    }

    $out['names']       = array_values(array_filter(array_map('strval', $parsed['names'])));
    $out['category']    = isset($parsed['category']) ? strtolower(trim((string) $parsed['category'])) : '';
    $out['description'] = isset($parsed['description']) ? trim((string) $parsed['description']) : '';
    $out['ok']          = count($out['names']) > 0;
    return $out;
}
