<?php
require_once __DIR__ . "/secure_config.php";   // SECURE_BUCKETS + category helpers (no side effects on include)

// The 📍active cash currencies (from the cash board) drive which quick category chips
// show. Read exactly as cash_balance/index.php does; tolerate a missing/malformed file.
$active_data       = is_file(CASH_ACTIVE_FILE)
    ? (json_decode((string) file_get_contents(CASH_ACTIVE_FILE), true) ?: [])
    : [];
$active            = array_values(array_filter($active_data['active'] ?? [], 'cash_currency_ok'));
$common_categories = secure_common_categories($active);   // union-deduped quick chips
$all_categories    = secure_categories()['all'];          // full searchable vocabulary
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>🔒 Name a secure document · b.robnugen.com</title>
  <style>
    :root { --gap: 14px; }
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, sans-serif; font-size: 18px;
           line-height: 1.4; margin: 0; padding: var(--gap); max-width: 640px;
           margin-inline: auto; color: #1a1a1a; background: #f4f4f7; }
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
    .catresults { margin-top: 6px; }
    .catresults .row { padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px;
                       background: #fff; margin-top: 6px; cursor: pointer; font-size: .95rem; }
    .catresults .row:active { background: #eef; }
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
    code { background: #eef; padding: 2px 6px; border-radius: 4px; word-break: break-all; }
    #status { min-height: 1.4em; font-weight: 600; }
  </style>
</head>
<body>
  <a href="/">journal</a>
  | <a href="/ai/">ai</a>
  | <a href="/sayonara/">sayonara</a>
  | <a href="/ai_secure/">🔒 secure 🔒</a>
  | <a href="/cash_balance/">💵 cash</a>
  <h1>🔒 Name a secure document</h1>
  <p class="muted">Filed outside the public web — receipts, paid bills, tax docs. Never web-served.</p>

  <section>
    <label for="password">Password</label>
    <input type="password" id="password" autocomplete="current-password" placeholder="badmin password">
  </section>

  <section id="capture">
    <label>Which bucket?</label>
    <div class="chips" id="bucketChips"></div>

    <label>Which account paid?</label>
    <div class="chips" id="accountChips"></div>

    <label>Which category? <span class="muted">(optional — YNAB agent refines)</span></label>
    <div class="chips" id="categoryChips"></div>
    <input type="text" id="categorySearch" placeholder="🔍 search all categories…" autocomplete="off">
    <div class="catresults" id="categoryResults"></div>

    <label for="photo">Photo(s) of the document (pages / front-back of ONE document)</label>
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

    <button id="sonnetBtn" class="secondary">Re-ask with Sonnet 🧠</button>
    <button id="confirmBtn">Confirm &amp; file 🔒</button>
  </section>

  <section id="result" class="hidden">
    <p class="ok">Filed to secure_bin 🔒</p>
    <div id="resultFiles"></div>
    <button id="nextBtn">Next document ➕</button>
  </section>

  <p class="muted"><a href="/cash_balance/">💵 Update cash balances →</a></p>

<script>
const BUCKETS = <?php echo json_encode(SECURE_BUCKETS); ?>;
const ACCOUNT_TAGS = <?php echo json_encode(ACCOUNT_TAGS); ?>;
const COMMON_CATEGORIES = <?php echo json_encode($common_categories); ?>;   // active-currency quick chips
const ALL_CATEGORIES    = <?php echo json_encode($all_categories); ?>;      // full searchable vocabulary
const $ = id => document.getElementById(id);
let state = { token: '', model: 'haiku', bucket: '', accountTag: 'unknown', category: '' };
let photos = [];   // File objects, in order
let views  = [];   // Claude-suggested view per photo (parallel to photos)

const pw = $('password'), photo = $('photo'), nameBtn = $('nameBtn'), status = $('status');

function setStatus(msg, cls) { status.textContent = msg; status.className = cls || 'muted'; }

// ---- bucket chips (required, chosen before naming) --------------------------
function renderBuckets() {
  $('bucketChips').innerHTML = '';
  BUCKETS.forEach(b => {
    const el = document.createElement('span');
    el.className = 'chip' + (state.bucket === b ? ' selected' : '');
    el.textContent = b;
    el.onclick = () => { state.bucket = b; state.token = ''; renderBuckets(); };
    $('bucketChips').appendChild(el);
  });
}

// ---- account tag chips (which account paid; default 'unknown') ---------------
// The tag rides on the next name_item.php call (which always rewrites the sidecar),
// so it does NOT invalidate the staged group the way changing the bucket does.
function renderAccountChips() {
  $('accountChips').innerHTML = '';
  ACCOUNT_TAGS.forEach(t => {
    const el = document.createElement('span');
    el.className = 'chip' + (state.accountTag === t ? ' selected' : '');
    el.textContent = t;
    el.onclick = () => { state.accountTag = t; renderAccountChips(); };
    $('accountChips').appendChild(el);
  });
}

// ---- category chips + search (optional YNAB hint) ---------------------------
// Quick chips come from the 📍active cash currency(ies); the search box reaches the
// whole vocabulary. Single-select with NONE allowed (tap the selected chip to clear).
// Chips show a short label (text after "Group: "); the full string is the stored value.
// Like accountTag, the category just rides the next name_item call — it does NOT
// invalidate the staged group, so changing it never re-stages photos.
function catLabel(c) { const i = c.indexOf(': '); return i === -1 ? c : c.slice(i + 2); }

function renderCategories() {
  const wrap = $('categoryChips');
  wrap.innerHTML = '';
  const chips = COMMON_CATEGORIES.slice();
  // a search-picked category that isn't a quick chip still shows (selected) so it's clearable
  if (state.category && !chips.includes(state.category)) chips.unshift(state.category);
  if (!chips.length) {
    const hint = document.createElement('span');
    hint.className = 'muted';
    hint.textContent = 'No quick categories for your active currency — search below.';
    wrap.appendChild(hint);
  }
  chips.forEach(c => {
    const el = document.createElement('span');
    el.className = 'chip' + (state.category === c ? ' selected' : '');
    el.textContent = catLabel(c);
    el.title = c;
    el.onclick = () => { state.category = (state.category === c ? '' : c); renderCategories(); };
    wrap.appendChild(el);
  });
}

function renderCatResults(q) {
  const box = $('categoryResults');
  box.innerHTML = '';
  q = (q || '').trim().toLowerCase();
  if (!q) return;
  ALL_CATEGORIES.filter(c => c.toLowerCase().includes(q)).slice(0, 8).forEach(c => {
    const row = document.createElement('div');
    row.className = 'row';
    row.textContent = c;                       // full string in results, to disambiguate
    row.onclick = () => {
      state.category = c;
      $('categorySearch').value = '';
      renderCatResults('');
      renderCategories();
    };
    box.appendChild(row);
  });
}

$('categorySearch').addEventListener('input', e => renderCatResults(e.target.value));

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
  if (!state.bucket) { setStatus('Pick a bucket first.', 'err'); return; }
  if (!photos.length) { setStatus('Add at least one photo.', 'err'); return; }
  state.model = model;
  const fd = new FormData();
  fd.append('password', pw.value);
  fd.append('bucket', state.bucket);
  fd.append('account_tag', state.accountTag);
  fd.append('category', state.category);
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
    $('review').classList.remove('hidden');
  } catch (e) {
    setStatus('Network error: ' + e.message + ' — type a name to file manually.', 'err');
    $('review').classList.remove('hidden'); renderNames([]);
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
  if (!state.token) { setStatus('Tap "Name it ✨" first so the photos are staged.', 'err'); return; }
  const fd = new FormData();
  fd.append('password', pw.value);
  fd.append('token', state.token);
  fd.append('name', name);
  fd.append('description', $('aiDesc').textContent);
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
  const box = $('resultFiles');
  box.innerHTML = '';
  (j.photos || [{ file: j.file, view: null }]).forEach(p => {
    const row = document.createElement('p');
    const c = document.createElement('code');
    c.textContent = p.file + (p.view ? '  (' + p.view + ')' : '');
    row.appendChild(c);
    box.appendChild(row);
  });
}

// ---- reset for next document ------------------------------------------------
$('nextBtn').onclick = () => {
  // keep the bucket selected — Rob usually files a run of the same kind.
  // reset the account tag + category: each document is a deliberate choice (no stale carry-over).
  state = { token: '', model: 'haiku', bucket: state.bucket, accountTag: 'unknown', category: '' };
  photos = []; views = [];
  photo.value = '';
  $('name').value = ''; $('aiDesc').textContent = '';
  $('nameChips').innerHTML = ''; $('resultFiles').innerHTML = '';
  $('categorySearch').value = ''; renderCatResults('');
  $('result').classList.add('hidden'); $('review').classList.add('hidden');
  $('confirmBtn').disabled = false; setStatus('');
  renderAccountChips();
  renderCategories();
  renderStrip();
  window.scrollTo(0, 0);
};

renderBuckets();
renderAccountChips();
renderCategories();
renderStrip();
</script>
</body>
</html>
