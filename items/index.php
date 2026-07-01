<?php
/**
 * items/index.php — "honor all the things" uploader.
 *
 * Photograph a possession and write your own words about it -> Claude suggests a page
 * title, describes what's in the photos, tidies transcription typos in your text
 * (without rewriting your voice), and surfaces any date it can read on the object ->
 * you confirm/edit -> confirm.php files a capture folder that Lemur 13 drains and hands
 * to agent wikiBoo, which creates the Items:<title> page on wiki.robnugen.com.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Honor an item · b.robnugen.com</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, sans-serif; font-size: 18px; line-height: 1.4;
           margin: 0 auto; padding: 14px; max-width: 640px; color: #1a1a1a; background: #fafafa; }
    h1 { font-size: 1.25rem; margin: 0 0 12px; }
    section { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 14px; margin-bottom: 14px; }
    label { display: block; font-weight: 600; margin: 8px 0 4px; }
    input[type=text], input[type=password], textarea, input[type=file] {
      width: 100%; font-size: 1rem; padding: 12px; border: 1px solid #bbb; border-radius: 8px; background: #fff; }
    textarea { min-height: 6em; }
    textarea#desc { min-height: 4em; }
    button { font-size: 1rem; padding: 12px 16px; border: 0; border-radius: 8px; background: #2563eb;
             color: #fff; font-weight: 600; cursor: pointer; width: 100%; margin-top: 14px; }
    button.secondary { background: #e5e7eb; color: #111; }
    button:disabled { opacity: .5; }
    .strip { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .shot { position: relative; width: 96px; }
    .shot img { width: 96px; height: 96px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; display: block; }
    .shot .rm { position: absolute; top: -8px; right: -8px; width: 24px; height: 24px; border-radius: 50%;
                background: #b91c1c; color: #fff; border: 0; font-size: .9rem; line-height: 1; padding: 0; margin: 0; cursor: pointer; }
    .hidden { display: none; }
    .muted { color: #666; font-size: .9rem; } .ok { color: #15803d; } .err { color: #b91c1c; }
    #status { min-height: 1.4em; font-weight: 600; }
    #dateHint { margin: 4px 0 0; }
    a { word-break: break-all; }
  </style>
</head>
<body>
  <a href="/">journal</a> | <a href="/ai/">ai</a> | <a href="/sayonara/">sayonara</a> | <a href="/items/">🏠 items</a> | <a href="/ai_secure/">🔒 secure 🔒</a> | <a href="/cash_balance/">💵 cash</a>
  <h1>🏠 Honor an item</h1>

  <section>
    <label for="password">Password</label>
    <input type="password" id="password" autocomplete="current-password" placeholder="badmin password">
  </section>

  <section id="capture">
    <label for="photo">Photos of the item (one or more angles)</label>
    <input type="file" id="photo" accept="image/*" capture="environment" multiple>
    <div class="strip" id="photoStrip"></div>
    <button id="addBtn" class="secondary hidden">➕ Add another photo</button>

    <label for="text">Your words about this thing</label>
    <textarea id="text" placeholder="Where it came from, what it meant, the memory it holds… write freely; typos get tidied, your voice is kept."></textarea>

    <button id="describeBtn" disabled>Describe it ✨</button>
    <p id="status" class="muted"></p>
  </section>

  <section id="review" class="hidden">
    <label for="title">Page title <span class="muted" id="modelTag"></span></label>
    <input type="text" id="title" placeholder="what to call this thing on the wiki">

    <label for="itemDate">Date of the item (precise or approximate)</label>
    <input type="text" id="itemDate" placeholder="e.g. 2003-04-12, circa 1998, mid-90s">
    <p id="dateHint" class="muted hidden"></p>

    <label for="text2">Your words (typos tidied — edit freely)</label>
    <textarea id="text2" placeholder="your write-up"></textarea>

    <label for="desc">What the photos show (AI, factual — edit if wrong)</label>
    <textarea id="desc" placeholder="factual description of the photos"></textarea>

    <button id="sonnetBtn" class="secondary">Re-ask with Sonnet 🧠</button>
    <button id="confirmBtn">File it ✅</button>
  </section>

  <section id="result" class="hidden">
    <p class="ok">Honored &amp; filed! 🎉 <span id="resName"></span></p>
    <div class="strip" id="resultThumbs"></div>
    <p class="muted">On the next Lemur 13 pull, wikiBoo will create
      <br><code id="resWiki"></code> on wiki.robnugen.com.</p>
    <button id="nextBtn">Next item ➕</button>
  </section>

<script>
const $ = id => document.getElementById(id);
let state = { token: '', model: 'haiku' };
let photos = [];   // File objects, in order
const pw = $('password'), photo = $('photo'), describeBtn = $('describeBtn'), status = $('status');
function setStatus(m, c) { status.textContent = m; status.className = c || 'muted'; }

// ---- photo strip ------------------------------------------------------------
function renderStrip() {
  const strip = $('photoStrip'); strip.innerHTML = '';
  photos.forEach((f, i) => {
    const cell = document.createElement('div'); cell.className = 'shot';
    const img = document.createElement('img'); img.src = URL.createObjectURL(f); cell.appendChild(img);
    const rm = document.createElement('button');
    rm.className = 'rm'; rm.textContent = '×'; rm.title = 'remove';
    rm.onclick = () => { photos.splice(i, 1); changedPhotos(); };
    cell.appendChild(rm);
    strip.appendChild(cell);
  });
  $('addBtn').classList.toggle('hidden', photos.length === 0);
  describeBtn.disabled = photos.length === 0;
}
// any change to the photo set invalidates the staged group
function changedPhotos() { state.token = ''; renderStrip(); }
photo.addEventListener('change', () => { for (const f of photo.files) photos.push(f); photo.value = ''; changedPhotos(); });
$('addBtn').onclick = () => photo.click();

// ---- call describe.php ------------------------------------------------------
async function askDescribe(model) {
  if (!pw.value) { setStatus('Enter the password first.', 'err'); return; }
  if (!photos.length) { setStatus('Add at least one photo.', 'err'); return; }
  state.model = model;
  const fd = new FormData();
  fd.append('password', pw.value);
  fd.append('model', model);
  fd.append('text', $('text').value);
  if (model === 'haiku' || !state.token) { photos.forEach(f => fd.append('photo[]', f)); }
  else { fd.append('token', state.token); }
  describeBtn.disabled = true; $('sonnetBtn').disabled = true;
  setStatus('Asking ' + model + ' about ' + photos.length + ' photo' + (photos.length > 1 ? 's' : '') + '…');
  try {
    const r = await fetch('describe.php', { method: 'POST', body: fd });
    const j = await r.json();
    setStatus(j.ok ? ('Got a description from ' + model + ' ✨') : ('AI: ' + (j.error || 'failed') + ' — you can still fill this in and file.'), j.ok ? 'ok' : 'err');
    state.token = j.token || state.token;
    $('modelTag').textContent = j.model ? '(' + j.model + ')' : '';
    if (j.title) $('title').value = j.title;
    if (j.photo_description) $('desc').value = j.photo_description;
    // cleaned_text: prefer the tidied version, but never wipe what Rob typed
    $('text2').value = j.cleaned_text || $('text').value;
    // detected_date only prefills the date if Rob hasn't typed one himself
    const hint = $('dateHint');
    if (j.detected_date) {
      if (!$('itemDate').value.trim()) $('itemDate').value = j.detected_date;
      hint.textContent = '📅 read from the item: ' + j.detected_date;
      hint.classList.remove('hidden');
    } else { hint.classList.add('hidden'); }
    $('review').classList.remove('hidden');
  } catch (e) {
    setStatus('Network error: ' + e.message + ' — you can still fill this in and file.', 'err');
    $('text2').value = $('text').value;
    $('review').classList.remove('hidden');
  } finally { describeBtn.disabled = false; $('sonnetBtn').disabled = false; }
}
describeBtn.onclick = () => askDescribe('haiku');
$('sonnetBtn').onclick = () => askDescribe('sonnet');

// ---- call confirm.php -------------------------------------------------------
$('confirmBtn').onclick = async () => {
  const title = $('title').value.trim();
  if (!title) { setStatus('A page title is required.', 'err'); return; }
  if (!state.token) { setStatus('Tap “Describe it ✨” first so the photos are staged.', 'err'); return; }
  const fd = new FormData();
  fd.append('password', pw.value);
  fd.append('token', state.token);
  fd.append('title', title);
  fd.append('photo_description', $('desc').value);
  fd.append('text', $('text2').value);
  fd.append('text_raw', $('text').value);
  fd.append('item_date', $('itemDate').value);
  fd.append('model', state.model);
  $('confirmBtn').disabled = true; setStatus('Filing…');
  try {
    const r = await fetch('confirm.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { setStatus('Could not file: ' + (j.error || 'error'), 'err'); $('confirmBtn').disabled = false; return; }
    $('resName').textContent = j.title || title;
    $('resWiki').textContent = 'Items:' + (j.title || title);
    const t = $('resultThumbs'); t.innerHTML = '';
    photos.forEach(f => {
      const cell = document.createElement('div'); cell.className = 'shot';
      const img = document.createElement('img'); img.src = URL.createObjectURL(f); cell.appendChild(img); t.appendChild(cell);
    });
    $('review').classList.add('hidden'); $('result').classList.remove('hidden'); setStatus('');
  } catch (e) { setStatus('Network error filing: ' + e.message, 'err'); $('confirmBtn').disabled = false; }
};

// ---- reset for next item ----------------------------------------------------
$('nextBtn').onclick = () => {
  state = { token: '', model: 'haiku' }; photos = [];
  photo.value = ''; $('text').value = ''; $('text2').value = ''; $('title').value = '';
  $('itemDate').value = ''; $('desc').value = ''; $('dateHint').classList.add('hidden');
  $('resultThumbs').innerHTML = '';
  $('result').classList.add('hidden'); $('review').classList.add('hidden');
  $('confirmBtn').disabled = false; setStatus('');
  renderStrip(); window.scrollTo(0, 0);
};

renderStrip();
</script>
</body>
</html>
