<?php
/**
 * sayonara/index.php — #4 AI uploader for the Sayonara Sale catalog.
 *
 * Upload photo(s) of an item -> Claude suggests a name + category + description
 * (reusing ../ai/name_item.php) -> Rob confirms/edits -> confirm.php files the
 * images AND writes a catalog sidecar that Lemur 13 scoops to build the item page.
 */
require_once __DIR__ . "/../ai/item_naming.php";   // $ITEM_CATEGORIES (no side effects)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sayonara item uploader · b.robnugen.com</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, sans-serif; font-size: 18px; line-height: 1.4;
           margin: 0 auto; padding: 14px; max-width: 640px; color: #1a1a1a; background: #fafafa; }
    h1 { font-size: 1.25rem; margin: 0 0 12px; }
    section { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 14px; margin-bottom: 14px; }
    label { display: block; font-weight: 600; margin: 8px 0 4px; }
    input[type=text], input[type=password], textarea, input[type=file] {
      width: 100%; font-size: 1rem; padding: 12px; border: 1px solid #bbb; border-radius: 8px; background: #fff; }
    textarea { min-height: 5em; }
    button { font-size: 1rem; padding: 12px 16px; border: 0; border-radius: 8px; background: #2563eb;
             color: #fff; font-weight: 600; cursor: pointer; width: 100%; margin-top: 14px; }
    button.secondary { background: #e5e7eb; color: #111; }
    button:disabled { opacity: .5; }
    .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
    .chip { display: inline-block; padding: 10px 14px; border: 1px solid #bbb; border-radius: 20px;
            background: #fff; font-size: .95rem; cursor: pointer; }
    .chip.selected { background: #2563eb; color: #fff; border-color: #2563eb; }
    .chip.name { border-style: dashed; }
    .strip { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .shot { width: 96px; } .shot img { width: 96px; height: 96px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; }
    .hidden { display: none; }
    .muted { color: #666; font-size: .9rem; } .ok { color: #15803d; } .err { color: #b91c1c; }
    #status { min-height: 1.4em; font-weight: 600; }
    a { word-break: break-all; }
  </style>
</head>
<body>
  <a href="/">journal</a> | <a href="/ai/">ai</a> | <a href="/sayonara/">sayonara</a> | <a href="/ai_secure/">🔒 secure 🔒</a> | <a href="/cash_balance/">💵 cash</a>
  <h1>📦 Sayonara item uploader</h1>

  <section>
    <label for="password">Password</label>
    <input type="password" id="password" autocomplete="current-password" placeholder="badmin password">
  </section>

  <section id="capture">
    <label for="photo">Photos of the item (one or more angles)</label>
    <input type="file" id="photo" accept="image/*" capture="environment" multiple>
    <div class="strip" id="photoStrip"></div>
    <button id="addBtn" class="secondary hidden">➕ Add another photo</button>
    <button id="nameBtn" disabled>Name &amp; describe it ✨</button>
    <p id="status" class="muted"></p>
  </section>

  <section id="review" class="hidden">
    <label>Suggested names <span class="muted" id="modelTag"></span></label>
    <div class="chips" id="nameChips"></div>

    <label for="name">Name (tap a suggestion or edit)</label>
    <input type="text" id="name" placeholder="human-readable name">

    <label>Category</label>
    <div class="chips" id="catChips"></div>
    <input type="text" id="catNew" placeholder="…or type a new category">

    <label for="desc">Description (editable — this becomes the item page text)</label>
    <textarea id="desc" placeholder="what it is, why a new owner would be happy, condition…"></textarea>

    <label for="price">Suggested used price ¥ (verify / edit — blank = decide later)</label>
    <input type="text" id="price" inputmode="numeric" placeholder="e.g. 1500">

    <button id="sonnetBtn" class="secondary">Re-ask with Sonnet 🧠</button>
    <button id="confirmBtn">Confirm &amp; file ✅</button>
  </section>

  <section id="result" class="hidden">
    <p class="ok">Filed into the catalog! 🎉 <span id="resName"></span></p>
    <div class="strip" id="resultThumbs"></div>
    <p class="muted">Now on Lemur 13: <code>bare</code>
      <br><code>./scoop_sayonara.sh</code> →
      <br><code>./sayonara_generate.pl</code> →
      <br><code>git add content/sayonara/ data/sayonara/</code> →
      <br><code>git commit -m monkey</code> →
      <br><code>git push</code> →
      <br><code>bbfr</code></p>
    <button id="nextBtn">Next item ➕</button>
  </section>

<script>
const CATEGORIES = <?php echo json_encode($ITEM_CATEGORIES); ?>;
const $ = id => document.getElementById(id);
let state = { token: '', model: 'haiku', category: '' };
let photos = [], views = [];
const pw = $('password'), photo = $('photo'), nameBtn = $('nameBtn'), status = $('status');
function setStatus(m, c) { status.textContent = m; status.className = c || 'muted'; }

function renderStrip() {
  const strip = $('photoStrip'); strip.innerHTML = '';
  photos.forEach((f, i) => {
    const cell = document.createElement('div'); cell.className = 'shot';
    const img = document.createElement('img'); img.src = URL.createObjectURL(f); cell.appendChild(img);
    if (views[i]) { const v = document.createElement('div'); v.textContent = views[i]; v.style.fontSize = '.75rem'; v.style.textAlign='center'; cell.appendChild(v); }
    strip.appendChild(cell);
  });
  $('addBtn').classList.toggle('hidden', photos.length === 0);
  nameBtn.disabled = photos.length === 0;
}
function changedPhotos() { state.token = ''; renderStrip(); }
photo.addEventListener('change', () => { for (const f of photo.files) { photos.push(f); views.push(''); } photo.value=''; changedPhotos(); });
$('addBtn').onclick = () => photo.click();

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

function renderNames(names) {
  $('nameChips').innerHTML = '';
  (names || []).forEach(n => {
    const el = document.createElement('span'); el.className = 'chip name'; el.textContent = n;
    el.onclick = () => { $('name').value = n; }; $('nameChips').appendChild(el);
  });
  if (names && names[0]) $('name').value = names[0];
}

async function askNames(model) {
  if (!pw.value) { setStatus('Enter the password first.', 'err'); return; }
  if (!photos.length) { setStatus('Add at least one photo.', 'err'); return; }
  state.model = model;
  const fd = new FormData();
  fd.append('password', pw.value); fd.append('model', model);
  if (model === 'haiku' || !state.token) { photos.forEach(f => fd.append('photo[]', f)); }
  else { fd.append('token', state.token); }
  nameBtn.disabled = true; $('sonnetBtn').disabled = true;
  setStatus('Asking ' + model + ' about ' + photos.length + ' photo' + (photos.length>1?'s':'') + '…');
  try {
    const r = await fetch('../ai/name_item.php', { method: 'POST', body: fd });
    const j = await r.json();
    setStatus(j.ok ? ('Got suggestions from ' + model + ' ✨') : ('AI: ' + (j.error||'failed') + ' — you can still type a name.'), j.ok ? 'ok' : 'err');
    state.token = j.token || state.token;
    views = j.views || []; renderStrip();
    $('modelTag').textContent = j.model ? '(' + j.model + ')' : '';
    if (j.description) $('desc').value = j.description;
    if (j.price_jpy != null) $('price').value = j.price_jpy;
    renderNames(j.names);
    if (j.category && CATEGORIES.includes(j.category)) { state.category = j.category; $('catNew').value = ''; }
    else if (j.category) { $('catNew').value = j.category; state.category = ''; }
    renderCats();
    $('review').classList.remove('hidden');
  } catch (e) {
    setStatus('Network error: ' + e.message + ' — type a name to file manually.', 'err');
    $('review').classList.remove('hidden'); renderNames([]); renderCats();
  } finally { nameBtn.disabled = false; $('sonnetBtn').disabled = false; }
}
nameBtn.onclick = () => askNames('haiku');
$('sonnetBtn').onclick = () => askNames('sonnet');

$('confirmBtn').onclick = async () => {
  const name = $('name').value.trim();
  if (!name) { setStatus('A name is required.', 'err'); return; }
  if (!state.token) { setStatus('Tap “Name & describe it ✨” first so the photos are staged.', 'err'); return; }
  const category = state.category || $('catNew').value.trim();
  if (!category) { setStatus('Pick or type a category.', 'err'); return; }
  const fd = new FormData();
  fd.append('password', pw.value); fd.append('token', state.token);
  fd.append('name', name); fd.append('category', category);
  fd.append('description', $('desc').value); fd.append('model', state.model);
  fd.append('price_jpy', $('price').value.replace(/[^0-9]/g, ''));
  $('confirmBtn').disabled = true; setStatus('Filing…');
  try {
    const r = await fetch('confirm.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { setStatus('Could not file: ' + (j.error||'error'), 'err'); $('confirmBtn').disabled = false; return; }
    $('resName').textContent = name;
    const t = $('resultThumbs'); t.innerHTML = '';
    (j.photos || []).forEach(p => {
      const cell = document.createElement('div'); cell.className = 'shot';
      const a = document.createElement('a'); a.href = p.url; a.target = '_blank';
      const img = document.createElement('img'); img.src = p.thumb || p.url; a.appendChild(img); cell.appendChild(a); t.appendChild(cell);
    });
    $('review').classList.add('hidden'); $('result').classList.remove('hidden'); setStatus('');
  } catch (e) { setStatus('Network error filing: ' + e.message, 'err'); $('confirmBtn').disabled = false; }
};

$('nextBtn').onclick = () => {
  state = { token: '', model: 'haiku', category: '' }; photos = []; views = [];
  photo.value=''; $('name').value=''; $('catNew').value=''; $('desc').value=''; $('price').value=''; $('nameChips').innerHTML=''; $('resultThumbs').innerHTML='';
  $('result').classList.add('hidden'); $('review').classList.add('hidden'); $('confirmBtn').disabled = false; setStatus('');
  renderStrip(); window.scrollTo(0, 0);
};

renderStrip(); renderCats();
</script>
</body>
</html>
