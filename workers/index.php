<?php
/**
 * The worker roster, newest members welcome. Hand-maintained on purpose: the older
 * photos sit flat in workers/<YYYY>/ under inconsistent filenames (mr_greene_2019_feb_03.jpg
 * puts the name first; super_spoony_bringing_marbles mixes in an action), so scanning
 * disk for names would guess wrong more often than it helped.
 *
 * Display names only — ac_slugify() in autocrop_lib.php derives the directory slug,
 * and every name here round-trips to the intended slug ("Mr McGlue" -> mr-mcglue).
 *
 * Anyone not on this list is still uploadable via "Other…" in the dropdown.
 */
$MT3_WORKERS = [
    "Autosticks",
    "Backpack Jack",
    "Big Brother",
    "Candy Mama",
    "Doctor Sugar",
    "G Choppy",
    "Little Brother",
    "Mr Greene",
    "Mr McGlue",
    "Ms McGlue",
    "Pinky",
    "Reversible Guy",
    "Squarehead",
    "Super Spoony",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MT3 worker uploader · b.robnugen.com</title>
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
    input[type=text], input[type=password], input[type=file], select {
      width: 100%; font-size: 1rem; padding: 12px; border: 1px solid #bbb;
      border-radius: 8px; background: #fff; }
    select { appearance: none; -webkit-appearance: none;
             background-image: linear-gradient(45deg, transparent 50%, #666 50%),
                               linear-gradient(135deg, #666 50%, transparent 50%);
             background-position: calc(100% - 20px) 50%, calc(100% - 14px) 50%;
             background-size: 6px 6px, 6px 6px; background-repeat: no-repeat;
             padding-right: 40px; }
    #nameOther { margin-top: 8px; }
    button { font-size: 1rem; padding: 12px 16px; border: 0; border-radius: 8px;
             background: #2563eb; color: #fff; font-weight: 600; cursor: pointer;
             width: 100%; margin-top: var(--gap); }
    button:disabled { opacity: .5; }
    .row { display: flex; gap: 10px; align-items: center; padding: 8px 0;
           border-top: 1px solid #eee; }
    .row:first-child { border-top: 0; }
    .row img { width: 72px; height: 72px; object-fit: cover; border-radius: 8px;
               border: 1px solid #ddd; flex: none; background: #f0f0f0; }
    .row .meta { min-width: 0; flex: 1; }
    .row .fn { font-family: ui-monospace, monospace; font-size: .85rem;
               word-break: break-all; }
    .hidden { display: none; }
    .muted { color: #666; font-size: .9rem; }
    .ok { color: #15803d; } .err { color: #b91c1c; } .warn { color: #b45309; }
    #status { min-height: 1.4em; font-weight: 600; }
    code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px;
           font-size: .85rem; word-break: break-all; }
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
  <h1>🔧 MT3 worker uploader</h1>

  <section>
    <label for="password">Password</label>
    <input type="password" id="password" autocomplete="current-password" placeholder="badmin password">
  </section>

  <section>
    <label for="worker">Worker name</label>
    <select id="worker">
      <option value="">— pick a worker —</option>
<?php foreach ($MT3_WORKERS as $w): ?>
      <option><?php echo htmlspecialchars($w, ENT_QUOTES); ?></option>
<?php endforeach; ?>
      <option value="__other__">✏️ Other…</option>
    </select>
    <input type="text" id="nameOther" class="hidden" placeholder="New worker name">
    <p class="muted" id="preview">Pick a worker to see where the photos will land.</p>

    <label for="photos">Photos (each is cropped to the object, ~80% of the frame)</label>
    <input type="file" id="photos" accept="image/*" multiple>
    <p class="muted" id="picked"></p>

    <button id="saveBtn" disabled>Save</button>
    <p id="status" class="muted"></p>
  </section>

  <section id="results" class="hidden">
    <div id="rows"></div>
  </section>

<script>
const $ = id => document.getElementById(id);
let files = [];

// The camera hands over sequential machine names (IMG_0021…IMG_0028). Sort by that
// name so NN is deterministic no matter what order the file picker returns.
function sortNatural(list) {
  return [...list].sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }));
}

// Mirrors ac_slugify() / ac_file_slug() in autocrop_lib.php so the preview can't lie.
const dirSlug  = s => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
const fileSlug = s => dirSlug(s).replace(/-/g, '_');

function datePrefix() {
  // Asia/Tokyo, to match date("Y_M_d_") on the server
  const p = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Tokyo', year: 'numeric', month: 'short', day: '2-digit'
  }).formatToParts(new Date()).reduce((o, x) => (o[x.type] = x.value, o), {});
  return `${p.year}_${p.month.toLowerCase()}_${p.day}_`;
}

// The roster covers the normal case; "Other…" swaps in a free-text box for a new worker.
function workerName() {
  const picked = $('worker').value;
  return (picked === '__other__' ? $('nameOther').value : picked).trim();
}

function renderPreview() {
  const name = workerName();
  const ds = dirSlug(name);
  if (!ds) {
    $('preview').textContent = $('worker').value === '__other__'
      ? 'Type the new worker’s name.'
      : 'Pick a worker to see where the photos will land.';
    $('preview').className = 'muted';
    return;
  }
  const year = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tokyo', year: 'numeric' }).format(new Date());
  $('preview').innerHTML =
    `<code>art/marble_track_3/workers/${year}/${ds}/</code><br>` +
    `first file: <code>${datePrefix()}${fileSlug(name)}_01.jpg</code>`;
  $('preview').className = 'muted';
}

function refresh() {
  $('picked').textContent = files.length
    ? `${files.length} photo${files.length > 1 ? 's' : ''}, numbered _01.._${String(files.length).padStart(2, '0')} in filename order`
    : '';
  $('saveBtn').disabled = !(files.length && workerName() && $('password').value);
}

$('photos').onchange = e => { files = sortNatural(e.target.files); refresh(); };
$('worker').onchange = () => {
  const other = $('worker').value === '__other__';
  $('nameOther').classList.toggle('hidden', !other);
  if (other) { $('nameOther').focus(); }
  renderPreview();
  refresh();
};
$('nameOther').oninput = () => { renderPreview(); refresh(); };
$('password').oninput = refresh;

function setStatus(msg, cls) {
  $('status').textContent = msg;
  $('status').className = cls || 'muted';
}

function addRow(file, seq) {
  const row = document.createElement('div');
  row.className = 'row';
  const img = document.createElement('img');
  img.src = URL.createObjectURL(file);
  const meta = document.createElement('div');
  meta.className = 'meta';
  meta.innerHTML = `<div class="fn">${file.name} → _${String(seq).padStart(2, '0')}</div>
                    <div class="st muted">waiting…</div>`;
  row.append(img, meta);
  $('rows').appendChild(row);
  return meta.querySelector('.st');
}

$('saveBtn').onclick = async () => {
  const name = workerName();
  const pw = $('password').value;
  $('saveBtn').disabled = true;
  $('results').classList.remove('hidden');
  $('rows').innerHTML = '';

  const slots = files.map((f, i) => addRow(f, i + 1));
  let saved = 0, uncropped = 0, failed = 0;

  for (let i = 0; i < files.length; i++) {
    const seq = i + 1;
    setStatus(`Uploading ${seq} of ${files.length}…`);
    slots[i].textContent = 'uploading…';
    const fd = new FormData();
    fd.append('password', pw);
    fd.append('name', name);
    fd.append('seq', seq);
    fd.append('photo', files[i]);
    try {
      const r = await fetch('upload_worker.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (!j.ok) {
        slots[i].textContent = j.error || 'failed';
        slots[i].className = 'st err';
        failed++;
        continue;
      }
      saved++;
      if (!j.cropped) { uncropped++; }
      slots[i].innerHTML = j.cropped
        ? `<span class="ok">✓ ${j.file}</span> <a href="${j.url}" target="_blank">open</a>`
        : `<span class="warn">⚠ ${j.file} — no subject found, saved uncropped</span> <a href="${j.url}" target="_blank">open</a>`;
      slots[i].className = 'st';
    } catch (e) {
      slots[i].textContent = 'Network error: ' + e.message;
      slots[i].className = 'st err';
      failed++;
    }
  }

  const bits = [`${saved} saved`];
  if (uncropped) { bits.push(`${uncropped} uncropped`); }
  if (failed) { bits.push(`${failed} failed`); }
  setStatus(bits.join(', '), failed ? 'err' : (uncropped ? 'warn' : 'ok'));
  $('saveBtn').disabled = false;
};

renderPreview();
</script>
</body>
</html>
