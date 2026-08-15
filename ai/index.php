<?php
require_once __DIR__ . "/item_naming.php";   // $ITEM_CATEGORIES (no side effects on include)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Name an item · b.robnugen.com</title>
  <style>
    :root { --gap: 14px; }
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, sans-serif; font-size: 18px;
           line-height: 1.4; margin: 0; padding: var(--gap); max-width: 640px;
           margin-inline: auto; color: #1a1a1a; background: #fafafa; }
    h1 { font-size: 1.25rem; margin: 0 0 var(--gap); }
    section { background: #fff; border: 1px solid #ddd; border-radius: 10px;
              padding: var(--gap); margin-bottom: var(--gap); }
    label { display: block; font-weight: 600; margin: 8px 0 4px; }
    input[type=text], input[type=password], textarea, input[type=file] {
      width: 100%; font-size: 1rem; padding: 12px; border: 1px solid #bbb;
      border-radius: 8px; background: #fff; }
    textarea { min-height: 3em; }
    button { font-size: 1rem; padding: 12px 16px; border: 0; border-radius: 8px;
             background: #2563eb; color: #fff; font-weight: 600; cursor: pointer;
             width: 100%; margin-top: var(--gap); }
    button.secondary { background: #e5e7eb; color: #111; }
    button:disabled { opacity: .5; }
    .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
    .chip { display: inline-block; padding: 10px 14px; border: 1px solid #bbb;
            border-radius: 20px; background: #fff; font-size: .95rem; cursor: pointer; }
    .chip.selected { background: #2563eb; color: #fff; border-color: #2563eb; }
    .chip.name { border-style: dashed; }
    .strip { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .shot { position: relative; width: 96px; }
    .shot img { width: 96px; height: 96px; object-fit: cover; border-radius: 8px;
                border: 1px solid #ddd; display: block; }
    .shot .view { font-size: .75rem; text-align: center; color: #444; margin-top: 2px;
                  word-break: break-word; }
    .shot .rm { position: absolute; top: -8px; right: -8px; width: 24px; height: 24px;
                border-radius: 50%; background: #b91c1c; color: #fff; border: 0;
                font-size: .9rem; line-height: 1; padding: 0; margin: 0; cursor: pointer; }
    .hidden { display: none; }
    .muted { color: #666; font-size: .9rem; }
    .ok { color: #15803d; } .err { color: #b91c1c; }
    #status { min-height: 1.4em; font-weight: 600; }
    a { word-break: break-all; }
  </style>
</head>
<body>
  <a href="/">journal</a>
  | <a href="/ai/">ai</a>
  | <a href="/sayonara/">sayonara</a>
  | <a href="/items/">🏠 items</a>
  | <a href="/ai_secure/">🔒 secure 🔒</a>
  | <a href="/cash_balance/">💵 cash</a>
  | <a href="/workers/">🔧 workers</a>
  <h1>📦 Name an item</h1>

  <section>
    <label for="password">Password</label>
    <input type="password" id="password" autocomplete="current-password" placeholder="badmin password">
  </section>

  <section id="capture">
    <label for="photo">Photos of the item (one or more angles)</label>
    <input type="file" id="photo" accept="image/*" capture="environment" multiple>
    <div class="strip" id="photoStrip"></div>
    <button id="addBtn" class="secondary hidden">➕ Add another photo</button>
    <button id="nameBtn" disabled>Name it ✨</button>
    <p id="status" class="muted"></p>
  </section>

  <section id="review" class="hidden">
    <p id="aiDesc" class="muted"></p>

    <label>Suggested names <span class="muted" id="modelTag"></span></label>
    <div class="chips" id="nameChips"></div>

    <label for="name">Name (tap a suggestion or edit)</label>
    <input type="text" id="name" placeholder="human-readable name">

    <label>Category</label>
    <div class="chips" id="catChips"></div>
    <input type="text" id="catNew" placeholder="…or type a new category">

    <label for="tags">Tags (optional, comma separated)</label>
    <input type="text" id="tags" placeholder="sell, ship, keep, fragile">

    <button id="sonnetBtn" class="secondary">Re-ask with Sonnet 🧠</button>
    <button id="confirmBtn">Confirm &amp; file ✅</button>
  </section>

  <section id="result" class="hidden">
    <p class="ok">Filed! 🎉</p>
    <div class="strip" id="resultThumbs"></div>
    <p><a id="resultUrl" href="#" target="_blank"></a></p>
    <label for="resultMd">Markdown embed</label>
    <textarea id="resultMd" readonly></textarea>
    <button id="nextBtn">Next item ➕</button>
  </section>

<script>
const CATEGORIES = <?php echo json_encode($ITEM_CATEGORIES); ?>;
const $ = id => document.getElementById(id);
let state = { token: '', model: 'haiku', category: '' };
let photos = [];   // File objects, in order
let views  = [];   // Claude-suggested angle per photo (parallel to photos)

const pw = $('password'), photo = $('photo'), nameBtn = $('nameBtn'), status = $('status');

function setStatus(msg, cls) { status.textContent = msg; status.className = cls || 'muted'; }

// ---- photo strip ------------------------------------------------------------
function renderStrip() {
  const strip = $('photoStrip');
  strip.innerHTML = '';
  photos.forEach((f, i) => {
    const cell = document.createElement('div');
    cell.className = 'shot';
    const img = document.createElement('img');
    img.src = URL.createObjectURL(f);
    cell.appendChild(img);
    if (views[i]) {
      const v = document.createElement('div');
      v.className = 'view'; v.textContent = views[i];
      cell.appendChild(v);
    }
    const rm = document.createElement('button');
    rm.className = 'rm'; rm.textContent = '×'; rm.title = 'remove';
    rm.onclick = () => { photos.splice(i, 1); views.splice(i, 1); changedPhotos(); };
    cell.appendChild(rm);
    strip.appendChild(cell);
  });
  $('addBtn').classList.toggle('hidden', photos.length === 0);
  nameBtn.disabled = photos.length === 0;
}

// any change to the photo set invalidates the staged group + suggestions
function changedPhotos() {
  state.token = '';
  renderStrip();
}

photo.addEventListener('change', () => {
  for (const f of photo.files) { photos.push(f); views.push(''); }
  photo.value = '';   // allow re-picking the same file / adding more
  changedPhotos();
});
$('addBtn').onclick = () => photo.click();

// ---- category chips ---------------------------------------------------------
function renderCats() {
  $('catChips').innerHTML = '';
  CATEGORIES.forEach(c => {
    const el = document.createElement('span');
    el.className = 'chip' + (state.category === c ? ' selected' : '');
    el.textContent = c;
    el.onclick = () => { state.category = c; $('catNew').value = ''; renderCats(); };
    $('catChips').appendChild(el);
  });
}
$('catNew').addEventListener('input', () => { state.category = ''; renderCats(); });

// ---- name suggestion chips --------------------------------------------------
function renderNames(names) {
  $('nameChips').innerHTML = '';
  (names || []).forEach(n => {
    const el = document.createElement('span');
    el.className = 'chip name';
    el.textContent = n;
    el.onclick = () => { $('name').value = n; };
    $('nameChips').appendChild(el);
  });
  if (names && names[0]) $('name').value = names[0];
}

// ---- call name_item.php -----------------------------------------------------
async function askNames(model) {
  if (!pw.value) { setStatus('Enter the password first.', 'err'); return; }
  if (!photos.length) { setStatus('Add at least one photo.', 'err'); return; }
  state.model = model;
  const fd = new FormData();
  fd.append('password', pw.value);
  fd.append('model', model);
  if (model === 'haiku' || !state.token) {
    photos.forEach(f => fd.append('photo[]', f));   // (re)stage the whole group
  } else {
    fd.append('token', state.token);                // re-ask reuses the staged group
  }
  nameBtn.disabled = true; $('sonnetBtn').disabled = true;
  setStatus('Asking ' + model + ' about ' + photos.length + ' photo' + (photos.length > 1 ? 's' : '') + '…');
  try {
    const r = await fetch('name_item.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { setStatus('AI: ' + (j.error || 'failed') + ' — you can still type a name.', 'err'); }
    else { setStatus('Got suggestions from ' + model + ' ✨', 'ok'); }
    state.token = j.token || state.token;
    views = j.views || [];
    renderStrip();
    $('modelTag').textContent = j.model ? '(' + j.model + ')' : '';
    $('aiDesc').textContent = j.description || '';
    renderNames(j.names);
    // preselect AI's category if it matches the curated list
    if (j.category && CATEGORIES.includes(j.category)) { state.category = j.category; $('catNew').value = ''; }
    else if (j.category) { $('catNew').value = j.category; state.category = ''; }
    renderCats();
    $('review').classList.remove('hidden');
  } catch (e) {
    setStatus('Network error: ' + e.message + ' — type a name to file manually.', 'err');
    $('review').classList.remove('hidden'); renderNames([]); renderCats();
  } finally {
    nameBtn.disabled = false; $('sonnetBtn').disabled = false;
  }
}

nameBtn.onclick = () => askNames('haiku');
$('sonnetBtn').onclick = () => askNames('sonnet');

// ---- call confirm_item.php --------------------------------------------------
$('confirmBtn').onclick = async () => {
  const name = $('name').value.trim();
  if (!name) { setStatus('A name is required.', 'err'); return; }
  if (!state.token) { setStatus('Tap “Name it ✨” first so the photos are staged.', 'err'); return; }
  const category = state.category || $('catNew').value.trim();
  if (!category) { setStatus('Pick or type a category.', 'err'); return; }
  const fd = new FormData();
  fd.append('password', pw.value);
  fd.append('token', state.token);
  fd.append('name', name);
  fd.append('category', category);
  fd.append('tags', $('tags').value);
  fd.append('description', $('aiDesc').textContent);
  fd.append('model', state.model);
  $('confirmBtn').disabled = true; setStatus('Filing…');
  try {
    const r = await fetch('confirm_item.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { setStatus('Could not file: ' + (j.error || 'error'), 'err'); $('confirmBtn').disabled = false; return; }
    renderResult(j);
    $('review').classList.add('hidden'); $('result').classList.remove('hidden');
    setStatus('');
  } catch (e) {
    setStatus('Network error filing: ' + e.message, 'err'); $('confirmBtn').disabled = false;
  }
};

function renderResult(j) {
  const thumbs = $('resultThumbs');
  thumbs.innerHTML = '';
  (j.photos || [{ url: j.url, view: null }]).forEach(p => {
    const cell = document.createElement('div');
    cell.className = 'shot';
    const a = document.createElement('a');
    a.href = p.url; a.target = '_blank';
    const img = document.createElement('img');
    img.src = p.url;
    a.appendChild(img); cell.appendChild(a);
    if (p.view) {
      const v = document.createElement('div');
      v.className = 'view'; v.textContent = p.view;
      cell.appendChild(v);
    }
    thumbs.appendChild(cell);
  });
  $('resultUrl').textContent = j.url; $('resultUrl').href = j.url;
  // markdown: stack each photo's embed when there are several
  $('resultMd').value = (j.photos && j.photos.length > 1)
    ? j.photos.map(p => p.markdown || p.url).join("\n")
    : (j.markdown || j.url);
}

// ---- reset for next item ----------------------------------------------------
$('nextBtn').onclick = () => {
  state = { token: '', model: 'haiku', category: '' };
  photos = []; views = [];
  photo.value = '';
  $('name').value = ''; $('tags').value = ''; $('catNew').value = ''; $('aiDesc').textContent = '';
  $('nameChips').innerHTML = ''; $('resultThumbs').innerHTML = '';
  $('result').classList.add('hidden'); $('review').classList.add('hidden');
  $('confirmBtn').disabled = false; setStatus('');
  renderStrip();
  window.scrollTo(0, 0);
};

renderStrip();
renderCats();
</script>
</body>
</html>
