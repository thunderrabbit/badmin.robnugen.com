<?php
/**
 * sayonara/index.php — #4 list-driven uploader for the Sayonara Sale catalog.
 *
 * Shows the items still needing photos (catalog feed MINUS slugs already in the
 * manifest), lets Rob pick one, shoot photo(s), and file them under that item's
 * slug via upload.php. No AI: the name/slug/category come from the feed.
 */
require_once __DIR__ . "/../ai/item_naming.php";   // ITEMS_BASE_DIR, ITEMS_MANIFEST (no side effects)

$FEED = ITEMS_BASE_DIR . "/sayonara_feed.json";

$feed = is_file($FEED) ? (json_decode((string) file_get_contents($FEED), true) ?: []) : [];

// slugs already photographed = present in the manifest
$uploaded = [];
if (is_file(ITEMS_MANIFEST)) {
    foreach (file(ITEMS_MANIFEST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $row = json_decode($ln, true);
        if (is_array($row) && !empty($row['slug'])) { $uploaded[$row['slug']] = true; }
    }
}
$uploaded_slugs = array_keys($uploaded);
// An item is "photographed" if an upload slug equals its slug, or starts with
// "<slug>-". The /ai/ uploader appends descriptors (the-cosmic-war-paperback-book);
// the #4 uploader files under the exact slug, so it matches outright.
$photographed = function (string $slug) use ($uploaded_slugs): bool {
    foreach ($uploaded_slugs as $u) {
        if ($u === $slug || strncmp($u, $slug . '-', strlen($slug) + 1) === 0) { return true; }
    }
    return false;
};
$pending = array_values(array_filter($feed, fn($it) => !empty($it['slug']) && !$photographed($it['slug'])));
$total   = count($feed);
$done    = $total - count($pending);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sayonara uploader · b.robnugen.com</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, sans-serif; font-size: 18px; line-height: 1.4;
           margin: 0 auto; padding: 14px; max-width: 640px; color: #1a1a1a; background: #fafafa; }
    h1 { font-size: 1.25rem; margin: 0 0 12px; }
    section { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 14px; margin-bottom: 14px; }
    label { display: block; font-weight: 600; margin: 8px 0 4px; }
    input[type=text], input[type=password], input[type=file], input[type=search] {
      width: 100%; font-size: 1rem; padding: 12px; border: 1px solid #bbb; border-radius: 8px; background: #fff; }
    button { font-size: 1rem; padding: 12px 16px; border: 0; border-radius: 8px; background: #2563eb;
             color: #fff; font-weight: 600; cursor: pointer; width: 100%; margin-top: 14px; }
    button.secondary { background: #e5e7eb; color: #111; }
    button:disabled { opacity: .5; }
    .muted { color: #666; font-size: .9rem; }
    .ok { color: #15803d; } .err { color: #b91c1c; }
    #status { min-height: 1.4em; font-weight: 600; }
    .list { display: flex; flex-direction: column; gap: 8px; max-height: 46vh; overflow:auto; margin-top: 8px; }
    .item { display: flex; justify-content: space-between; align-items: center; gap: 10px;
            padding: 12px; border: 1px solid #ccc; border-radius: 8px; background: #fff; cursor: pointer; }
    .item:hover { border-color: #2563eb; }
    .item .cat { font-size: .8rem; color: #777; }
    .item.selected { background: #2563eb; color: #fff; border-color: #2563eb; }
    .item.selected .cat { color: #dbeafe; }
    .strip { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .shot { width: 96px; } .shot img { width: 96px; height: 96px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; }
    .hidden { display: none; }
    .pill { display:inline-block; background:#eef; border-radius: 12px; padding: 2px 10px; font-size:.85rem; }
    a { word-break: break-all; }
  </style>
</head>
<body>
  <a href="/">journal</a> | <a href="/ai/">ai</a> | <a href="/ai_secure/">🔒 secure</a>
  <h1>📦 Sayonara uploader <span class="pill"><?php echo $done; ?>/<?php echo $total; ?> photographed</span></h1>

  <section>
    <label for="password">Password</label>
    <input type="password" id="password" autocomplete="current-password" placeholder="badmin password">
  </section>

  <section id="pickSection">
    <label for="filter">Pick an item to photograph</label>
    <input type="search" id="filter" placeholder="filter by name…">
    <div class="list" id="list"></div>
    <p class="muted" id="emptyNote"><?php
      echo $pending ? ''
         : (empty($feed)
              ? 'No feed loaded — deploy sayonara_feed.json to the items dir.'
              : 'Nothing pending — every catalog item has a photo. 🎉');
    ?></p>
  </section>

  <section id="captureSection" class="hidden">
    <label>Selected: <span id="selName"></span> <span class="muted" id="selCat"></span></label>
    <label for="photo">Photo(s) of this item (front, back, …)</label>
    <input type="file" id="photo" accept="image/*" capture="environment" multiple>
    <div class="strip" id="strip"></div>
    <button id="fileBtn" disabled>Upload &amp; file ✅</button>
    <button id="cancelBtn" class="secondary">↩︎ Back to list</button>
    <p id="status" class="muted"></p>
  </section>

  <section id="resultSection" class="hidden">
    <p class="ok">Filed! 🎉 <span id="resName"></span></p>
    <div class="strip" id="resThumbs"></div>
    <button id="nextBtn">Next item ➕</button>
  </section>

<script>
const PENDING = <?php echo json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const $ = id => document.getElementById(id);
let selected = null;     // {slug,name,category}
let photos = [];         // File[]

const pw = $('password'), status = $('status');
function setStatus(m, c) { status.textContent = m; status.className = c || 'muted'; }

// ---- pending list -----------------------------------------------------------
function renderList() {
  const q = ($('filter').value || '').toLowerCase();
  const list = $('list');
  list.innerHTML = '';
  PENDING.filter(it => !it._done && (it.name || '').toLowerCase().includes(q)).forEach(it => {
    const row = document.createElement('div');
    row.className = 'item' + (selected && selected.slug === it.slug ? ' selected' : '');
    row.innerHTML = '<span>' + escapeHtml(it.name) + '</span><span class="cat">' + escapeHtml(it.category || '') + '</span>';
    row.onclick = () => selectItem(it);
    list.appendChild(row);
  });
}
function escapeHtml(s){ return (s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
$('filter').addEventListener('input', renderList);

function selectItem(it) {
  selected = it;
  photos = [];
  $('selName').textContent = it.name;
  $('selCat').textContent = it.category ? '(' + it.category + ')' : '';
  $('captureSection').classList.remove('hidden');
  $('resultSection').classList.add('hidden');
  renderStrip(); renderList(); setStatus('');
  $('captureSection').scrollIntoView({ behavior: 'smooth' });
}

// ---- photo strip ------------------------------------------------------------
$('photo').addEventListener('change', () => {
  for (const f of $('photo').files) photos.push(f);
  $('photo').value = '';
  renderStrip();
});
function renderStrip() {
  const strip = $('strip'); strip.innerHTML = '';
  photos.forEach(f => {
    const cell = document.createElement('div'); cell.className = 'shot';
    const img = document.createElement('img'); img.src = URL.createObjectURL(f);
    cell.appendChild(img); strip.appendChild(cell);
  });
  $('fileBtn').disabled = photos.length === 0;
}

$('cancelBtn').onclick = () => {
  selected = null; photos = [];
  $('captureSection').classList.add('hidden');
  renderList();
};

// ---- upload -----------------------------------------------------------------
$('fileBtn').onclick = async () => {
  if (!pw.value) { setStatus('Enter the password first.', 'err'); return; }
  if (!selected || !photos.length) { setStatus('Pick an item and add a photo.', 'err'); return; }
  const fd = new FormData();
  fd.append('password', pw.value);
  fd.append('slug', selected.slug);
  photos.forEach(f => fd.append('photo[]', f));
  $('fileBtn').disabled = true;
  setStatus('Uploading ' + photos.length + ' photo' + (photos.length > 1 ? 's' : '') + '…');
  try {
    const r = await fetch('upload.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { setStatus('Could not file: ' + (j.error || 'error'), 'err'); $('fileBtn').disabled = false; return; }
    // mark done + show result
    const it = PENDING.find(p => p.slug === selected.slug); if (it) it._done = true;
    $('resName').textContent = j.name || selected.name;
    const t = $('resThumbs'); t.innerHTML = '';
    (j.photos || []).forEach(p => {
      const cell = document.createElement('div'); cell.className = 'shot';
      const a = document.createElement('a'); a.href = p.url; a.target = '_blank';
      const img = document.createElement('img'); img.src = p.thumb || p.url;
      a.appendChild(img); cell.appendChild(a); t.appendChild(cell);
    });
    $('captureSection').classList.add('hidden');
    $('resultSection').classList.remove('hidden');
    setStatus('');
    selected = null; photos = [];
    updateCount();
  } catch (e) {
    setStatus('Network error: ' + e.message, 'err'); $('fileBtn').disabled = false;
  }
};

$('nextBtn').onclick = () => {
  $('resultSection').classList.add('hidden');
  renderList();
  window.scrollTo(0, 0);
};

function updateCount() {
  const doneNow = PENDING.filter(p => p._done).length;
  // (server count is authoritative on reload; this reflects this session's progress)
  const pill = document.querySelector('.pill');
  if (pill) pill.textContent = (<?php echo $done; ?> + doneNow) + '/' + <?php echo $total; ?> + ' photographed';
}

renderList();
</script>
</body>
</html>
