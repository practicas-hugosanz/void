<?php
/**
 * VOID — Panel de Administración
 * Acceso: /admin  (sin extensión .php gracias a .htaccess)
 */

require_once __DIR__ . '/includes/auth.php';
cors();

define('ADMIN_SECRET', getenv('VOID_ADMIN_SECRET') ?: 'void-admin-2025-secret');

$secret = $_POST['secret'] ?? ($_COOKIE['void_admin_secret'] ?? '');
$authed = $secret && hash_equals(ADMIN_SECRET, $secret);

if ($authed && !isset($_COOKIE['void_admin_secret'])) {
    setcookie('void_admin_secret', $secret, [
        'expires'  => time() + 86400 * 7,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// ─── Acciones POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authed) {
    $action = $_POST['action'] ?? '';
    $email  = strtolower(trim($_POST['email'] ?? ''));
    $db     = get_db();
    $msg    = '';
    $msgType = 'ok';

    if ($action === 'approve' && $email) {
        $stmt = $db->prepare("UPDATE whitelist SET status = 'approved', reviewed_at = datetime('now') WHERE email = ?");
        $stmt->execute([$email]);
        $wl = $db->prepare("SELECT name, password_hash FROM whitelist WHERE email = ?");
        $wl->execute([$email]);
        $wlRow = $wl->fetch();
        $exists = $db->prepare("SELECT id FROM users WHERE email = ?");
        $exists->execute([$email]);
        if (!$exists->fetch() && $wlRow) {
            $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)")
               ->execute([$wlRow['name'], $email, $wlRow['password_hash']]);
        }
        $msg = "$email aprobado correctamente";
    } elseif ($action === 'reject' && $email) {
        $db->prepare("UPDATE whitelist SET status = 'rejected', reviewed_at = datetime('now') WHERE email = ?")
           ->execute([$email]);
        $msg = "$email rechazado";
        $msgType = 'warn';
    }

    header("Location: /admin?msg=" . urlencode($msg) . "&type=" . $msgType, true, 303);
    exit;
}

// ─── Datos ────────────────────────────────────────────────────────────────────
$whitelist = [];
$users     = [];
$stats     = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'users' => 0];

if ($authed) {
    $db        = get_db();
    $whitelist = $db->query("SELECT id, name, email, status, requested_at, reviewed_at FROM whitelist ORDER BY requested_at DESC")->fetchAll();
    $users     = $db->query("SELECT id, name, email, api_provider, created_at FROM users ORDER BY created_at DESC LIMIT 50")->fetchAll();
    foreach ($whitelist as $r) $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1;
    $stats['users'] = count($users);
}

$flashMsg  = htmlspecialchars($_GET['msg']  ?? '');
$flashType = htmlspecialchars($_GET['type'] ?? 'ok');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VOID — Admin</title>
<link rel="icon" href="favicon.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; cursor: none !important; }
:root {
  --bg:           #060608;
  --bg2:          #0d0d10;
  --bg3:          #141418;
  --surface:      #1a1a20;
  --surface2:     #202028;
  --border:       rgba(255,255,255,0.07);
  --border2:      rgba(255,255,255,0.12);
  --accent:       #e8ff47;
  --accent-dim:   rgba(232,255,71,0.12);
  --accent-glow:  rgba(232,255,71,0.25);
  --text:         #f0f0f0;
  --text2:        #a0a0a8;
  --muted:        #555560;
  --green:        #4ade80;
  --green-dim:    rgba(74,222,128,0.1);
  --red:          #f87171;
  --red-dim:      rgba(248,113,113,0.1);
  --yellow:       #fbbf24;
  --yellow-dim:   rgba(251,191,36,0.1);
  --font-head:    'Syne', sans-serif;
  --font-body:    'DM Sans', sans-serif;
  --ease:         cubic-bezier(0.23, 1, 0.32, 1);
  --r:            8px;
}
html { scroll-behavior: smooth; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-body);
  overflow-x: hidden;
  -webkit-font-smoothing: antialiased;
  min-height: 100vh;
}
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--surface2); border-radius: 2px; }
::-webkit-scrollbar-thumb:hover { background: var(--accent); }
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
  pointer-events: none; z-index: 9999; opacity: 0.35;
}
#cursor {
  position: fixed; width: 10px; height: 10px;
  background: var(--accent); border-radius: 50%;
  pointer-events: none; z-index: 99999; top: 0; left: 0;
  transform: translate(-50%,-50%);
  transition: width .15s var(--ease), height .15s var(--ease), opacity .2s;
  mix-blend-mode: difference;
}
#cursor-ring {
  position: fixed; width: 32px; height: 32px;
  border: 1px solid rgba(232,255,71,0.5); border-radius: 50%;
  pointer-events: none; z-index: 99998; top: 0; left: 0;
  transform: translate(-50%,-50%);
  transition: width .3s, height .3s;
}
.icon { display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; width:18px; height:18px; }
.icon-sm { width:14px; height:14px; }

/* Header */
.admin-header {
  position: sticky; top: 0; z-index: 100;
  background: rgba(6,6,8,0.85);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
  padding: 0 32px; height: 60px;
  display: flex; align-items: center; gap: 16px;
}
.header-logo {
  font-family: var(--font-head);
  font-size: 20px; font-weight: 800; letter-spacing: -0.5px;
  display: flex; align-items: center; gap: 10px;
}
.logo-dot {
  width: 10px; height: 10px;
  background: var(--accent); border-radius: 50%;
  box-shadow: 0 0 12px var(--accent-glow);
}
.admin-badge {
  font-family: var(--font-head);
  font-size: 9px; font-weight: 700; letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--accent);
  background: var(--accent-dim);
  border: 1px solid rgba(232,255,71,0.2);
  padding: 3px 10px; border-radius: 99px;
}

/* Layout */
.wrap { max-width: 1080px; margin: 0 auto; padding: 40px 24px 80px; }

/* Login */
.login-wrap { display:flex; justify-content:center; align-items:center; min-height:calc(100vh - 60px); }
.login-card {
  background: var(--surface); border: 1px solid var(--border2);
  border-radius: var(--r); padding: 40px; width: 360px;
}
.login-title { font-family: var(--font-head); font-size: 20px; font-weight: 700; margin-bottom: 8px; }
.login-sub   { color: var(--text2); font-size: 13px; margin-bottom: 28px; }
.field-wrap  { position: relative; }
.field-wrap input {
  width: 100%; background: var(--bg2); border: 1px solid var(--border2);
  color: var(--text); padding: 12px 44px 12px 14px;
  border-radius: var(--r); font-family: var(--font-body); font-size: 14px;
  outline: none; transition: border-color .2s;
}
.field-wrap input:focus { border-color: var(--accent); }
.field-icon {
  position: absolute; right: 14px; top: 50%;
  transform: translateY(-50%); color: var(--muted); pointer-events: none;
}
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  padding: 11px 20px; border-radius: var(--r);
  font-family: var(--font-body); font-size: 13px; font-weight: 500;
  border: none; transition: opacity .15s, transform .15s;
}
.btn:hover { opacity: .85; }
.btn:active { transform: scale(.98); }
.btn-accent { background: var(--accent); color: #000; width: 100%; margin-top: 16px; }
.btn-approve {
  background: var(--green-dim); color: var(--green);
  border: 1px solid rgba(74,222,128,0.2); padding: 6px 12px; font-size: 12px;
}
.btn-reject {
  background: var(--red-dim); color: var(--red);
  border: 1px solid rgba(248,113,113,0.2); padding: 6px 12px; font-size: 12px;
}

/* Flash */
.flash {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: var(--r);
  font-size: 13px; margin-bottom: 28px;
  animation: fadeIn .3s var(--ease);
}
.flash-ok   { background: var(--green-dim); border: 1px solid rgba(74,222,128,0.2); color: var(--green); }
.flash-warn { background: var(--red-dim);   border: 1px solid rgba(248,113,113,0.2); color: var(--red); }
@keyframes fadeIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }

/* Stats */
.stats-grid {
  display: grid; grid-template-columns: repeat(4,1fr);
  gap: 12px; margin-bottom: 40px;
}
.stat-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r); padding: 20px 24px;
  position: relative; overflow: hidden;
}
.stat-card::before {
  content: ''; position: absolute; top:0; left:0; right:0; height:1px;
}
.stat-pending::before  { background: linear-gradient(90deg,transparent,var(--yellow),transparent); }
.stat-approved::before { background: linear-gradient(90deg,transparent,var(--green),transparent); }
.stat-rejected::before { background: linear-gradient(90deg,transparent,var(--red),transparent); }
.stat-users::before    { background: linear-gradient(90deg,transparent,var(--accent),transparent); }
.stat-icon { margin-bottom: 12px; }
.stat-pending  .stat-icon { color: var(--yellow); }
.stat-approved .stat-icon { color: var(--green); }
.stat-rejected .stat-icon { color: var(--red); }
.stat-users    .stat-icon { color: var(--accent); }
.stat-value { font-family:var(--font-head); font-size:36px; font-weight:800; line-height:1; margin-bottom:4px; }
.stat-pending  .stat-value { color: var(--yellow); }
.stat-approved .stat-value { color: var(--green); }
.stat-rejected .stat-value { color: var(--red); }
.stat-users    .stat-value { color: var(--accent); }
.stat-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; }

/* Section */
.section-head { display:flex; align-items:center; gap:10px; margin-bottom:16px; }
.section-title {
  font-family:var(--font-head); font-size:13px; font-weight:700;
  text-transform:uppercase; letter-spacing:1.5px; color:var(--text2);
}
.section-count {
  background:var(--surface2); color:var(--muted);
  font-size:11px; padding:2px 8px; border-radius:99px;
}

/* Table */
.table-wrap {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r); overflow:hidden; margin-bottom:40px;
}
table { width:100%; border-collapse:collapse; }
th {
  text-align:left; font-size:11px; text-transform:uppercase;
  letter-spacing:1px; color:var(--muted);
  padding:12px 16px; border-bottom:1px solid var(--border);
  background:var(--bg2); white-space:nowrap;
}
td { padding:14px 16px; border-bottom:1px solid var(--border); vertical-align:middle; font-size:13px; }
tr:last-child td { border-bottom:none; }
tbody tr { transition:background .15s; }
tbody tr:hover td { background:var(--bg3); }
.td-name  { font-weight:500; }
.td-email { font-family:monospace; font-size:12px; color:var(--text2); }
.td-date  { color:var(--muted); font-size:12px; white-space:nowrap; }
.td-empty { text-align:center; padding:40px; color:var(--muted); }

/* Badges */
.status-badge {
  display:inline-flex; align-items:center; gap:5px;
  padding:4px 10px; border-radius:99px;
  font-size:11px; font-weight:600;
  text-transform:uppercase; letter-spacing:.5px; white-space:nowrap;
}
.s-pending  { background:var(--yellow-dim); color:var(--yellow); border:1px solid rgba(251,191,36,0.2); }
.s-approved { background:var(--green-dim);  color:var(--green);  border:1px solid rgba(74,222,128,0.2); }
.s-rejected { background:var(--red-dim);    color:var(--red);    border:1px solid rgba(248,113,113,0.2); }
.provider-badge {
  display:inline-flex; align-items:center; gap:5px;
  padding:3px 9px; border-radius:99px;
  font-size:11px; color:var(--text2);
  background:var(--bg3); border:1px solid var(--border2);
}
.actions { display:flex; gap:6px; }

@media (max-width:700px) {
  .stats-grid { grid-template-columns:repeat(2,1fr); }
  .wrap { padding:24px 16px 60px; }
  td, th { padding:10px 12px; }
}
</style>
</head>
<body>

<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
  <symbol id="ico-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/>
    <circle cx="12" cy="16" r="1.2" fill="currentColor" stroke="none"/>
  </symbol>
  <symbol id="ico-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
    <polyline points="20 6 9 17 4 12"/>
  </symbol>
  <symbol id="ico-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
  </symbol>
  <symbol id="ico-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
  </symbol>
  <symbol id="ico-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
    <path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
  </symbol>
  <symbol id="ico-warn" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
  </symbol>
  <symbol id="ico-info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/>
    <line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
  </symbol>
  <symbol id="ico-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
  </symbol>
  <symbol id="ico-spark" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 2l1.8 7.2L21 12l-7.2 1.8L12 22l-1.8-7.2L3 12l7.2-1.8L12 2z"/>
  </symbol>
</svg>

<div id="cursor"></div>
<div id="cursor-ring"></div>

<header class="admin-header">
  <div class="header-logo">
    <span class="logo-dot"></span>VOID
  </div>
  <span class="admin-badge">Admin</span>
</header>

<?php if (!$authed): ?>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-title">Panel de administración</div>
    <div class="login-sub">Introduce el secret para continuar</div>
    <form method="POST" action="/admin">
      <div class="field-wrap">
        <input type="password" name="secret" placeholder="Admin secret…" autocomplete="current-password" required autofocus>
        <span class="field-icon"><svg class="icon icon-sm"><use href="#ico-lock"/></svg></span>
      </div>
      <button type="submit" class="btn btn-accent">
        <svg class="icon icon-sm"><use href="#ico-spark"/></svg>
        Entrar
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<div class="wrap">

  <?php if ($flashMsg): ?>
    <div class="flash flash-<?= $flashType ?>">
      <svg class="icon icon-sm"><use href="#ico-<?= $flashType === 'ok' ? 'check' : 'warn' ?>"/></svg>
      <?= $flashMsg ?>
    </div>
  <?php endif; ?>

  <div class="stats-grid">
    <div class="stat-card stat-pending">
      <div class="stat-icon"><svg class="icon"><use href="#ico-clock"/></svg></div>
      <div class="stat-value"><?= $stats['pending'] ?></div>
      <div class="stat-label">Pendientes</div>
    </div>
    <div class="stat-card stat-approved">
      <div class="stat-icon"><svg class="icon"><use href="#ico-check"/></svg></div>
      <div class="stat-value"><?= $stats['approved'] ?></div>
      <div class="stat-label">Aprobados</div>
    </div>
    <div class="stat-card stat-rejected">
      <div class="stat-icon"><svg class="icon"><use href="#ico-close"/></svg></div>
      <div class="stat-value"><?= $stats['rejected'] ?></div>
      <div class="stat-label">Rechazados</div>
    </div>
    <div class="stat-card stat-users">
      <div class="stat-icon"><svg class="icon"><use href="#ico-users"/></svg></div>
      <div class="stat-value"><?= $stats['users'] ?></div>
      <div class="stat-label">Usuarios</div>
    </div>
  </div>

  <div class="section-head">
    <div class="section-title">Solicitudes de acceso</div>
    <span class="section-count"><?= count($whitelist) ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nombre</th><th>Email</th><th>Estado</th><th>Solicitado</th><th>Revisado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($whitelist)): ?>
          <tr><td colspan="6" class="td-empty">
            <svg class="icon" style="margin-bottom:8px;display:block;margin-inline:auto"><use href="#ico-info"/></svg>
            Sin solicitudes aún
          </td></tr>
        <?php endif; ?>
        <?php foreach ($whitelist as $r):
          $sc = match($r['status']) { 'approved'=>'s-approved', 'rejected'=>'s-rejected', default=>'s-pending' };
          $si = match($r['status']) { 'approved'=>'check', 'rejected'=>'close', default=>'clock' };
          $sl = match($r['status']) { 'approved'=>'Aprobado', 'rejected'=>'Rechazado', default=>'Pendiente' };
        ?>
          <tr>
            <td class="td-name"><?= htmlspecialchars($r['name'] ?? '—') ?></td>
            <td class="td-email"><?= htmlspecialchars($r['email']) ?></td>
            <td>
              <span class="status-badge <?= $sc ?>">
                <svg class="icon icon-sm"><use href="#ico-<?= $si ?>"/></svg><?= $sl ?>
              </span>
            </td>
            <td class="td-date"><?= htmlspecialchars(substr($r['requested_at'] ?? '', 0, 16)) ?></td>
            <td class="td-date"><?= htmlspecialchars(substr($r['reviewed_at'] ?? '—', 0, 16)) ?></td>
            <td>
              <div class="actions">
                <?php if ($r['status'] !== 'approved'): ?>
                  <form method="POST" action="/admin">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($r['email']) ?>">
                    <button type="submit" class="btn btn-approve">
                      <svg class="icon icon-sm"><use href="#ico-check"/></svg>Aprobar
                    </button>
                  </form>
                <?php endif; ?>
                <?php if ($r['status'] !== 'rejected'): ?>
                  <form method="POST" action="/admin">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($r['email']) ?>">
                    <button type="submit" class="btn btn-reject">
                      <svg class="icon icon-sm"><use href="#ico-close"/></svg>Rechazar
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="section-head">
    <div class="section-title">Usuarios registrados</div>
    <span class="section-count"><?= count($users) ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Nombre</th><th>Email</th><th>Proveedor</th><th>Registrado</th></tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="5" class="td-empty">
            <svg class="icon" style="margin-bottom:8px;display:block;margin-inline:auto"><use href="#ico-users"/></svg>
            Sin usuarios aún
          </td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="td-date"><?= (int)$u['id'] ?></td>
            <td class="td-name">
              <span style="display:inline-flex;align-items:center;gap:8px;">
                <svg class="icon icon-sm" style="color:var(--muted)"><use href="#ico-user"/></svg>
                <?= htmlspecialchars($u['name']) ?>
              </span>
            </td>
            <td class="td-email"><?= htmlspecialchars($u['email']) ?></td>
            <td>
              <span class="provider-badge">
                <svg class="icon icon-sm"><use href="#ico-spark"/></svg>
                <?= htmlspecialchars($u['api_provider'] ?? 'gemini') ?>
              </span>
            </td>
            <td class="td-date"><?= htmlspecialchars(substr($u['created_at'] ?? '', 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>
<?php endif; ?>

<script>
const cursor = document.getElementById('cursor');
const ring   = document.getElementById('cursor-ring');
let mx=0,my=0,rx=0,ry=0;
document.addEventListener('mousemove', e => { mx=e.clientX; my=e.clientY; });
(function loop(){
  rx += (mx-rx)*.18; ry += (my-ry)*.18;
  cursor.style.transform = `translate(${mx}px,${my}px) translate(-50%,-50%)`;
  ring.style.transform   = `translate(${rx}px,${ry}px) translate(-50%,-50%)`;
  requestAnimationFrame(loop);
})();
</script>
</body>
</html>
