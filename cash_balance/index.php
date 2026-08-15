<?php
require_once "/home/thundergoblin/secure_config.php";   // CASH_* (above web root, no side effects on include)

/* Apache serves this host with "Cache-Control: max-age=600". This page renders MUTABLE
 * server state — the 📍active currency and the latest balances — so a reload inside that
 * ten-minute window returns the pre-toggle HTML from the browser cache and the server is
 * never asked. That reads exactly like "the currency didn't save", while the file on disk
 * is perfectly correct: the toggle is live JS, the reload is a cached document.
 *
 * Apache's directive still rides along on the response. Per RFC 7234 multiple
 * Cache-Control headers combine into one comma-separated list, and no-store in that list
 * wins, so this is sufficient without touching the vhost. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/**
 * Latest snapshot for one currency = the last VALID JSON line of its append-only file.
 * Files are tiny (one line per manual entry), so reading the whole file is fine here.
 * Returns ['amount'=>float, 'ts'=>string] or null when nothing is recorded yet.
 *
 * NOTE: PHP runs as thundergoblin, the owner of the 700 cash dir, so this read works.
 * If ownership ever moves (e.g. www-data), this read AND save_balance.php break together.
 */
function cash_latest(string $cur): ?array
{
    $path = cash_snapshot_path($cur);
    if ($path === null || !is_file($path)) {
        return null;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {   // last valid line wins (tolerate trailing junk)
        $rec = json_decode($lines[$i], true);
        if (is_array($rec) && isset($rec['amount'], $rec['ts'])) {
            return ['amount' => $rec['amount'], 'ts' => $rec['ts']];
        }
    }
    return null;
}

$board = [];
foreach (CASH_CURRENCIES as $code => $flag) {
    $latest  = cash_latest($code);
    $board[] = [
        'code'   => $code,
        'flag'   => $flag,
        'amount' => $latest['amount'] ?? null,
        'ts'     => $latest['ts'] ?? null,
    ];
}

// At most ONE currency is active (see set_active.php). '' means none is pinned.
$active_code = cash_active_currency();
$active      = $active_code === '' ? [] : [$active_code];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>💵 Cash balances · b.robnugen.com</title>
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
    input[type=text], input[type=password], input[inputmode] {
      width: 100%; font-size: 1rem; padding: 12px; border: 1px solid #bbb;
      border-radius: 8px; background: #fff; }
    button { font-size: 1rem; padding: 12px 16px; border: 0; border-radius: 8px;
             background: #2563eb; color: #fff; font-weight: 600; cursor: pointer; }
    button.secondary { background: #e5e7eb; color: #111; }
    .muted { color: #666; font-size: .9rem; }
    .ok { color: #15803d; } .err { color: #b91c1c; }
    #status { min-height: 1.4em; font-weight: 600; }

    .row { display: flex; align-items: center; gap: 10px; padding: 10px 0;
           border-top: 1px solid #eee; }
    .row:first-child { border-top: 0; }
    .row .flag { font-size: 1.6rem; line-height: 1; }
    .row .code { font-weight: 600; width: 2.6em; }
    .row .bal  { flex: 1; font-variant-numeric: tabular-nums; font-size: 1.15rem;
                 cursor: pointer; }
    .row .bal.empty { color: #999; font-style: italic; font-size: 1rem; }
    .row .age  { color: #666; font-size: .8rem; white-space: nowrap; }
    .stale { background: #fde68a; color: #92400e; border-radius: 10px;
             padding: 1px 7px; font-size: .72rem; font-weight: 700; white-space: nowrap; }
    .pin { background: none; border: 0; font-size: 1.3rem; padding: 4px; cursor: pointer;
           filter: grayscale(1) opacity(.35); }
    .pin.on { filter: none; }
    /* inline editor */
    .edit { display: flex; gap: 8px; flex: 1; align-items: center; }
    .edit input { flex: 1; }
    .edit button { padding: 10px 12px; }
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
  <h1>💵 Cash balances</h1>
  <p class="muted">Point-in-time cash on hand, per currency. Tap a balance to update it.
     Tap 📍 to set the one currency you're currently using — it gets the “stale” nudge,
     and 🔒 secure stamps it onto every receipt you scan.</p>

  <section>
    <label for="password">Password</label>
    <input type="password" id="password" autocomplete="current-password" placeholder="badmin password">
  </section>

  <section id="board"></section>
  <p id="status" class="muted"></p>

<script>
const BOARD      = <?php echo json_encode($board); ?>;
const ACTIVE     = new Set(<?php echo json_encode($active); ?>);   // holds at most one code

const STALE_DAYS = <?php echo json_encode(CASH_STALE_DAYS); ?>;
const $ = id => document.getElementById(id);
const pw = $('password'), status = $('status');
let editing = null;   // currency code currently in inline-edit mode, or null

function setStatus(msg, cls) { status.textContent = msg; status.className = cls || 'muted'; }

function fmtAmount(code, amt) {
  if (amt == null) return 'no balance yet';
  try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: code }).format(amt); }
  catch (e) { return amt.toLocaleString() + ' ' + code; }
}

function ageDays(ts) { return ts ? (Date.now() - new Date(ts).getTime()) / 86400000 : Infinity; }

function fmtAge(ts) {
  if (!ts) return '';
  const mins = Math.floor((Date.now() - new Date(ts).getTime()) / 60000);
  if (mins < 1)  return 'just now';
  if (mins < 60) return mins + 'm ago';
  const hrs = Math.floor(mins / 60);
  if (hrs < 24)  return hrs + 'h ago';
  const days = Math.floor(hrs / 24);
  if (days < 30) return days + 'd ago';
  return Math.floor(days / 30) + 'mo ago';
}

function rowFor(c) {
  const row = document.createElement('div');
  row.className = 'row';

  const flag = document.createElement('span'); flag.className = 'flag'; flag.textContent = c.flag;
  const code = document.createElement('span'); code.className = 'code'; code.textContent = c.code;
  row.append(flag, code);

  if (editing === c.code) {
    const wrap = document.createElement('div'); wrap.className = 'edit';
    const inp = document.createElement('input');
    inp.inputMode = 'decimal'; inp.placeholder = c.code + ' amount';
    if (c.amount != null) inp.value = c.amount;
    const save = document.createElement('button'); save.textContent = 'Save';
    const cancel = document.createElement('button'); cancel.className = 'secondary'; cancel.textContent = '×';
    save.onclick   = () => saveBalance(c.code, inp.value);
    cancel.onclick = () => { editing = null; render(); };
    inp.onkeydown  = e => { if (e.key === 'Enter') saveBalance(c.code, inp.value); };
    wrap.append(inp, save, cancel);
    row.append(wrap);
    setTimeout(() => inp.focus(), 0);
  } else {
    const bal = document.createElement('span');
    bal.className = 'bal' + (c.amount == null ? ' empty' : '');
    bal.textContent = fmtAmount(c.code, c.amount);
    bal.onclick = () => { editing = c.code; render(); };
    row.append(bal);

    const age = document.createElement('span'); age.className = 'age'; age.textContent = fmtAge(c.ts);
    row.append(age);

    // stale nudge ONLY for active currencies (a country you're not in never nags)
    if (ACTIVE.has(c.code) && ageDays(c.ts) > STALE_DAYS) {
      const s = document.createElement('span'); s.className = 'stale'; s.textContent = 'stale';
      row.append(s);
    }
  }

  // Single-select: tapping an inactive pin moves 📍 here from wherever it was. The server
  // returns the authoritative set, so the previously-active pin clears itself on re-render.
  const pin = document.createElement('button');
  pin.className = 'pin' + (ACTIVE.has(c.code) ? ' on' : '');
  pin.textContent = '📍';
  pin.title = ACTIVE.has(c.code) ? 'active — tap to unmark' : 'make this the active currency';
  pin.onclick = () => toggleActive(c.code, !ACTIVE.has(c.code));
  row.append(pin);

  return row;
}

function render() {
  const b = $('board');
  b.innerHTML = '';
  BOARD.forEach(c => b.append(rowFor(c)));
}

async function saveBalance(code, rawAmount) {
  if (!pw.value) { setStatus('Enter the password first.', 'err'); return; }
  const amount = (rawAmount || '').trim();
  if (amount === '' || isNaN(Number(amount))) { setStatus('Enter a number.', 'err'); return; }
  setStatus('Saving ' + code + '…');
  const fd = new FormData();
  fd.append('password', pw.value);
  fd.append('currency', code);
  fd.append('amount', amount);
  try {
    const r = await fetch('save_balance.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { setStatus('Could not save: ' + (j.error || 'error'), 'err'); return; }
    const c = BOARD.find(x => x.code === code);
    c.amount = j.amount; c.ts = j.ts;     // update the row in place
    editing = null; render();
    setStatus(code + ' updated ✓', 'ok');
  } catch (e) {
    setStatus('Network error: ' + e.message, 'err');
  }
}

async function toggleActive(code, makeActive) {
  if (!pw.value) { setStatus('Enter the password first.', 'err'); return; }
  const fd = new FormData();
  fd.append('password', pw.value);
  fd.append('currency', code);
  fd.append('active', makeActive ? '1' : '0');
  try {
    const r = await fetch('set_active.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { setStatus('Could not update active: ' + (j.error || 'error'), 'err'); return; }
    ACTIVE.clear(); j.active.forEach(x => ACTIVE.add(x));   // authoritative set from server
    render();
    setStatus('', 'muted');
  } catch (e) {
    setStatus('Network error: ' + e.message, 'err');
  }
}

render();
</script>
</body>
</html>
