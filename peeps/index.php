<?php
require_once __DIR__ . "/peeps_lib.php";   // PEEPS_PAGES
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>👥 Peeps galleries · b.robnugen.com</title>
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
    input[type=password] { width: 100%; font-size: 1rem; padding: 12px;
                           border: 1px solid #bbb; border-radius: 8px; background: #fff; }
    button { font-size: 1rem; padding: 12px 16px; border: 0; border-radius: 8px;
             background: #2563eb; color: #fff; font-weight: 600; cursor: pointer;
             width: 100%; margin-top: var(--gap); }
    button:disabled { opacity: .5; }
    .muted { color: #666; font-size: .9rem; }
    .ok { color: #15803d; } .err { color: #b91c1c; }
    #status { min-height: 1.4em; font-weight: 600; }
    ul { padding-left: 1.2em; margin: 6px 0; }
    li { font-family: ui-monospace, monospace; font-size: .85rem; word-break: break-all; }
    a { word-break: break-all; }
  </style>
</head>
<body>
  <p style="background:#fde68a;color:#92400e;padding:8px 12px;margin:0 0 12px;font-weight:600">yo Rob remember to move this to badmint, since 30 Sep 2026, at Basil's house in Modbury</p>
  <a href="/">journal</a>
  | <a href="/ai/">ai</a>
  | <a href="/sayonara/">sayonara</a>
  | <a href="/items/">🏠 items</a>
  | <a href="/ai_secure/">🔒 secure 🔒</a>
  | <a href="/cash_balance/">💵 cash</a>
  | <a href="/workers/">🔧 workers</a>
  | <a href="/peeps/">👥 peeps</a>
  <h1>👥 Peeps galleries</h1>
  <p class="muted">Rebuilds a static page on b.robnugen.com from the photo filenames on disk.
     Upload through the journal form as usual, then tap Rebuild.</p>

  <!-- A real form with a username field so password managers offer AutoFill (see
       cash_balance/index.php). method=post keeps the password out of the access log;
       the form never navigates — the button's fetch does the work. -->
  <form action="/peeps/" method="post" onsubmit="return false">
    <section>
      <input type="text" name="username" value="badmin" autocomplete="username" readonly hidden>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" placeholder="badmin password">
    </section>

<?php foreach (PEEPS_PAGES as $key => $page): ?>
    <section>
      <label><?php echo htmlspecialchars($page['title'], ENT_QUOTES); ?></label>
      <a href="https://b.robnugen.com/peeps/<?php echo $key; ?>/">b.robnugen.com/peeps/<?php echo $key; ?>/</a>
      <button type="button" data-page="<?php echo $key; ?>">Rebuild <?php echo htmlspecialchars($page['title'], ENT_QUOTES); ?></button>
      <p class="status muted"></p>
      <div class="detail"></div>
    </section>
<?php endforeach; ?>
  </form>

<script>
const esc = s => s.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

function list(title, paths) {
  if (!paths.length) return '';
  return `<p class="muted">${title}</p><ul>${paths.map(p => `<li>${esc(p)}</li>`).join('')}</ul>`;
}

document.querySelectorAll('button[data-page]').forEach(btn => {
  const box    = btn.closest('section');
  const status = box.querySelector('.status');
  const detail = box.querySelector('.detail');

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    status.className = 'status muted';
    status.textContent = 'Rebuilding…';
    detail.innerHTML = '';

    const fd = new FormData();
    fd.append('password', document.getElementById('password').value);
    fd.append('page', btn.dataset.page);
    try {
      const r = await fetch('rebuild.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'rebuild failed');
      status.className = 'status ok';
      status.textContent = j.changed
        ? `✅ Rebuilt: ${j.count} photos (${j.added.length} new, ${j.removed.length} gone).`
        : `✅ Already up to date: ${j.count} photos.`;
      detail.innerHTML = list('Added', j.added) + list('Removed', j.removed);
    } catch (e) {
      status.className = 'status err';
      status.textContent = '❌ ' + e.message;
    } finally {
      btn.disabled = false;
    }
  });
});
</script>
</body>
</html>
