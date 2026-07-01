<?php
/**
 * items/item_ingest_lib.php — helpers for the "honor all the things" item ingest.
 *
 * Sibling to the sayonara sale flow, but a DIFFERENT purpose: instead of building a
 * for-sale catalog, each item becomes a page in the Items: namespace on
 * wiki.robnugen.com. Rob photographs a possession and writes his own words about it;
 * Claude (1) describes what's in the photos, (2) fixes transcription typos in Rob's
 * text WITHOUT rewriting it, (3) suggests a page title, and (4) surfaces any date it
 * can literally read on the object. Rob's own words carry the honoring/memory heart —
 * the AI never editorializes.
 *
 * The captures land under ITEM_INGEST_DIR on b.robnugen.com as TRANSIT only ("saved
 * briefly"): a cron on Lemur 13 drains them (rsync --remove-source-files), hands each
 * to agent wikiBoo, which creates the Items: page. Nothing here is web-served.
 *
 * Reuses ../ai/item_naming.php for the vision transport + JSON parse + slugify, and
 * ../image_resize_lib.php (via confirm.php) for the _1000 resize. No side effects on
 * include.
 */

require_once __DIR__ . "/../ai/item_naming.php";   // item_anthropic_vision, item_parse_json, item_model_id, slugify_item

// ---- where captures are staged/built on b.robnugen.com (transit, NOT web-served) ----
// Drained by ~/work/rob/items_from_b.rn/pull_items.sh; .staging/ is excluded from the drain.
const ITEM_INGEST_DIR     = "/home/thundergoblin/item_ingest";
const ITEM_INGEST_STAGING = ITEM_INGEST_DIR . "/.staging";

/** Normalize $_FILES['photo'] (single or photo[]) into a flat list of real uploads. */
function item_uploaded_photos(): array
{
    if (empty($_FILES['photo'])) {
        return [];
    }
    $f = $_FILES['photo'];
    $out = [];
    if (is_array($f['name'])) {
        for ($i = 0, $c = count($f['name']); $i < $c; $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i]];
        }
    } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $out[] = ['name' => $f['name'], 'tmp_name' => $f['tmp_name'], 'error' => $f['error']];
    }
    return $out;
}

/**
 * Ask Claude to look at all photos of ONE possession and return a page title, a
 * factual photo description, a typo-cleaned copy of Rob's own write-up, and any date
 * literally visible on the object.
 *
 * AI is assistive, never blocking: on any failure ok=false and the UI falls back to
 * Rob's manual title/date + his raw (uncleaned) text.
 *
 * @param string[] $image_paths staged photos of a single item, in order
 * @param string   $raw_text    Rob's own words as typed (may be empty)
 * @return array {
 *   ok: bool, title: string, photo_description: string, cleaned_text: string,
 *   detected_date: string, model: string, error: string, raw: string
 * }
 */
function claude_describe_item(array $image_paths, string $model, string $raw_text, string $api_key): array
{
    $out = ['ok' => false, 'title' => '', 'photo_description' => '', 'cleaned_text' => '',
            'detected_date' => '', 'model' => item_model_id($model), 'error' => '', 'raw' => ''];

    $image_paths = array_values($image_paths);
    $n = count($image_paths);
    if ($n === 0) {
        $out['error'] = "no images given";
        return $out;
    }

    $raw_text = trim($raw_text);
    $has_text = $raw_text !== '';

    $intro = $n === 1
        ? "You are helping Rob honor and catalog a single physical possession he is " .
          "photographing before he moves out of Japan. Look at the photo and consider the " .
          "OBJECT itself (not the scene around it).\n\n"
        : "You are helping Rob honor and catalog a single physical possession he is " .
          "photographing before he moves out of Japan. There are $n photos of the SAME object " .
          "from different angles, in order. Consider the OBJECT itself (not the scene).\n\n";

    // Rob's own words are sacred — the AI fixes transcription noise only, never rewrites.
    $text_block = $has_text
        ? "Rob wrote the following about this item (dictated/typed, so it may contain " .
          "transcription or spelling errors). Between the fences is his exact text:\n" .
          "<<<ROB_TEXT\n" . $raw_text . "\nROB_TEXT\n\n"
        : "Rob did not write anything about this item yet.\n\n";

    $prompt =
        $intro . $text_block .
        "Return STRICT JSON only (no prose, no code fence) with exactly these keys:\n" .
        "{\n" .
        "  \"title\": a short human-readable page title for the OBJECT, 2-6 words, specific " .
        "not generic (e.g. \"Grandad's brass compass\" not \"compass\"),\n" .
        "  \"photo_description\": 2-4 factual sentences describing what is visible in the " .
        "photos — the object, material, colour, condition, and any text/markings you can read. " .
        "Factual only; do NOT add sentiment, memories, or guesses about its history.,\n" .
        "  \"cleaned_text\": " . ($has_text
            ? "Rob's text above with ONLY obvious transcription/spelling/spacing typos fixed. " .
              "Preserve his exact wording, voice, punctuation style, and line breaks. Do NOT " .
              "rewrite, rephrase, summarize, embellish, translate, or add or remove any content. " .
              "If nothing needs fixing, return his text unchanged."
            : "empty string \"\" (he wrote nothing)") . ",\n" .
        "  \"detected_date\": any date that is LITERALLY written, printed, engraved, or on a " .
        "label VISIBLE in the photos (e.g. a copyright year, a stamped date, a handwritten " .
        "note), copied verbatim as you see it. Do NOT infer a date from the object's style, " .
        "wear, or era. If no date is visibly present, return empty string \"\".\n" .
        "}";

    // cleaned_text can be as long as Rob's write-up, so lift the token cap well above the default.
    $resp = item_anthropic_vision($image_paths, $prompt, $out['model'], $api_key, 2000);
    $out['raw'] = $resp['raw'];
    if (!$resp['ok']) {
        $out['error'] = $resp['error'];
        return $out;
    }

    $parsed = item_parse_json($resp['text']);
    if (!$parsed) {
        $out['error'] = "could not parse JSON from model";
        return $out;
    }

    $out['title']             = isset($parsed['title']) ? trim((string) $parsed['title']) : '';
    $out['photo_description']  = isset($parsed['photo_description']) ? trim((string) $parsed['photo_description']) : '';
    // fall back to Rob's raw text if the model omitted/blanked cleaned_text but he wrote something
    $cleaned                   = isset($parsed['cleaned_text']) ? (string) $parsed['cleaned_text'] : '';
    $out['cleaned_text']       = ($cleaned !== '') ? $cleaned : $raw_text;
    $out['detected_date']      = isset($parsed['detected_date']) ? trim((string) $parsed['detected_date']) : '';
    $out['ok']                 = $out['title'] !== '' || $out['photo_description'] !== '';
    return $out;
}
