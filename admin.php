<?php
/**
 * VOID — Panel de Administración
 *
 * Acceso: /admin.php?secret=<VOID_ADMIN_SECRET>
 * No requiere sesión — se autentica con el secret de entorno.
 *
 * Funcionalidad:
 *   - Ver solicitudes pendientes / aprobadas / rechazadas
 *   - Aprobar / rechazar solicitudes con un clic
 *   - Ver usuarios registrados
 */

require_once __DIR__ . '/includes/auth.php';
cors();

// ─── Autenticación ────────────────────────────────────────────────────────────
define('ADMIN_SECRET', getenv('VOID_ADMIN_SECRET') ?: 'void-admin-2025-secret');

$secret = $_GET['secret'] ?? ($_COOKIE['void_admin_secret'] ?? '');
$authed = hash_equals(ADMIN_SECRET, $secret);

// Persistir el secret en cookie para no tener que pasarlo en cada acción
if ($authed && !isset($_COOKIE['void_admin_secret'])) {
    setcookie('void_admin_secret', $secret, [
        'expires'  => time() + 86400 * 7,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// ─── Acciones POST (aprobar / rechazar) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authed) {
    $action = $_POST['action']  ?? '';
    $email  = strtolower(trim($_POST['email'] ?? ''));
    $db     = get_db();
    $msg    = '';

    if ($action === 'approve' && $email) {
        $stmt = $db->prepare("UPDATE whitelist SET status = 'approved', reviewed_at = datetime('now') WHERE email = ?");
        $stmt->execute([$email]);

        // Crear usuario si no existe aún
        $wl = $db->prepare("SELECT name, password_hash FROM whitelist WHERE email = ?");
        $wl->execute([$email]);
        $wlRow = $wl->fetch();
        $exists = $db->prepare("SELECT id FROM users WHERE email = ?");
        $exists->execute([$email]);
        if (!$exists->fetch() && $wlRow) {
            $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)")
               ->execute([$wlRow['name'], $email, $wlRow['password_hash']]);
        }
        $msg = "✅ $email aprobado";
    } elseif ($action === 'reject' && $email) {
        $db->prepare("UPDATE whitelist SET status = 'rejected', reviewed_at = datetime('now') WHERE email = ?")
           ->execute([$email]);
        $msg = "❌ $email rechazado";
    }

    // Redirigir para evitar reenvío del form
    $loc = '/admin.php?secret=' . urlencode($secret);
    if ($msg) $loc .= '&msg=' . urlencode($msg);
    header("Location: $loc", true, 303);
    exit;
}

// ─── Cargar datos ─────────────────────────────────────────────────────────────
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

$flashMsg = htmlspecialchars($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VOID — Admin</title>
<style>
  :root {
    --bg:       #0a0a0a;
    --surface:  #111111;
    --border:   #222222;
    --accent:   #ffffff;
    --muted:    #555555;
    --text:     #e0e0e0;
    --green:    #22c55e;
    --red:      #ef4444;
    --yellow:   #eab308;
    --radius:   6px;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: 'Inter', system-ui, sans-serif; font-size: 14px; min-height: 100vh; }
  a { color: var(--accent); text-decoration: none; }

  /* ── Layout ── */
  .header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 14px 24px; display: flex; align-items: center; gap: 12px; }
  .header-logo { font-size: 18px; font-weight: 700; letter-spacing: -0.5px; }
  .header-badge { background: var(--border); color: var(--muted); font-size: 10px; padding: 2px 8px; border-radius: 99px; text-transform: uppercase; letter-spacing: 1px; }
  .container { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }

  /* ── Auth form ── */
  .auth-wrap { display: flex; justify-content: center; align-items: center; min-height: calc(100vh - 57px); }
  .auth-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 32px; width: 340px; }
  .auth-card h2 { margin-bottom: 20px; font-size: 16px; }
  .auth-card input { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 10px 12px; border-radius: var(--radius); outline: none; font-size: 14px; }
  .auth-card input:focus { border-color: #444; }
  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 16px; border-radius: var(--radius); font-size: 13px; font-weight: 500; cursor: pointer; transition: opacity .15s; border: none; }
  .btn:hover { opacity: .85; }
  .btn-primary { background: var(--accent); color: #000; }
  .btn-sm { padding: 5px 12px; font-size: 12px; }
  .btn-approve { background: #16a34a; color: #fff; }
  .btn-reject  { background: #b91c1c; color: #fff; }
  .mt-3 { margin-top: 12px; }
  .w-full { width: 100%; }

  /* ── Flash ── */
  .flash { background: #1a2a1a; border: 1px solid #2a4a2a; color: var(--green); padding: 10px 16px; border-radius: var(--radius); margin-bottom: 20px; font-size: 13px; }

  /* ── Stats ── */
  .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 28px; }
  .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 20px; }
  .stat-value { font-size: 28px; font-weight: 700; line-height: 1; }
  .stat-label { font-size: 11px; color: var(--muted); margin-top: 4px; text-transform: uppercase; letter-spacing: .5px; }
  .stat-pending  .stat-value { color: var(--yellow); }
  .stat-approved .stat-value { color: var(--green); }
  .stat-rejected .stat-value { color: var(--red); }
  .stat-users    .stat-value { color: var(--accent); }

  /* ── Section ── */
  .section-title { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 12px; }

  /* ── Table ── */
  .table-wrap { overflow-x: auto; margin-bottom: 36px; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); padding: 8px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; }
  td { padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #141414; }

  /* ── Badge ── */
  .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
  .badge-pending  { background: #2a2000; color: var(--yellow); }
  .badge-approved { background: #0a2a0a; color: var(--green); }
  .badge-rejected { background: #2a0a0a; color: var(--red); }

  .actions { display: flex; gap: 6px; }
  .text-muted { color: var(--muted); font-size: 12px; }

  @media (max-width: 600px) {
    .stats { grid-template-columns: repeat(2, 1fr); }
  }
</style>
</head>
<body>

<div class="header">
  <span class="header-logo">⚫ VOID</span>
  <span class="header-badge">Admin</span>
</div>

<?php if (!$authed): ?>
<!-- ── Login ── -->
<div class="auth-wrap">
  <div class="auth-card">
    <h2>Panel de administración</h2>
    <form method="GET" action="/admin.php">
      <input type="password" name="secret" placeholder="Admin secret…" autocomplete="current-password" required>
      <button type="submit" class="btn btn-primary w-full mt-3">Entrar</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── Dashboard ── -->
<div class="container">

  <?php if ($flashMsg): ?>
    <div class="flash"><?= $flashMsg ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats">
    <div class="stat-card stat-pending">
      <div class="stat-value"><?= $stats['pending'] ?></div>
      <div class="stat-label">Pendientes</div>
    </div>
    <div class="stat-card stat-approved">
      <div class="stat-value"><?= $stats['approved'] ?></div>
      <div class="stat-label">Aprobados</div>
    </div>
    <div class="stat-card stat-rejected">
      <div class="stat-value"><?= $stats['rejected'] ?></div>
      <div class="stat-label">Rechazados</div>
    </div>
    <div class="stat-card stat-users">
      <div class="stat-value"><?= $stats['users'] ?></div>
      <div class="stat-label">Usuarios</div>
    </div>
  </div>

  <!-- Solicitudes de Whitelist -->
  <div class="section-title">Solicitudes de acceso</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Email</th>
          <th>Estado</th>
          <th>Solicitado</th>
          <th>Revisado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($whitelist)): ?>
          <tr><td colspan="6" class="text-muted" style="text-align:center;padding:24px;">Sin solicitudes</td></tr>
        <?php endif; ?>
        <?php foreach ($whitelist as $r): ?>
          <?php
            $statusBadge = match($r['status']) {
              'approved' => '<span class="badge badge-approved">aprobado</span>',
              'rejected' => '<span class="badge badge-rejected">rechazado</span>',
              default    => '<span class="badge badge-pending">pendiente</span>',
            };
          ?>
          <tr>
            <td><?= htmlspecialchars($r['name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['email']) ?></td>
            <td><?= $statusBadge ?></td>
            <td class="text-muted"><?= htmlspecialchars(substr($r['requested_at'] ?? '', 0, 16)) ?></td>
            <td class="text-muted"><?= htmlspecialchars(substr($r['reviewed_at'] ?? '—', 0, 16)) ?></td>
            <td>
              <div class="actions">
                <?php if ($r['status'] !== 'approved'): ?>
                  <form method="POST" action="/admin.php?secret=<?= urlencode($secret) ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($r['email']) ?>">
                    <button type="submit" class="btn btn-sm btn-approve">Aprobar</button>
                  </form>
                <?php endif; ?>
                <?php if ($r['status'] !== 'rejected'): ?>
                  <form method="POST" action="/admin.php?secret=<?= urlencode($secret) ?>">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($r['email']) ?>">
                    <button type="submit" class="btn btn-sm btn-reject">Rechazar</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Usuarios registrados -->
  <div class="section-title">Usuarios registrados</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Nombre</th>
          <th>Email</th>
          <th>Proveedor</th>
          <th>Registrado</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="5" class="text-muted" style="text-align:center;padding:24px;">Sin usuarios</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="text-muted"><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="badge badge-approved"><?= htmlspecialchars($u['api_provider'] ?? 'gemini') ?></span></td>
            <td class="text-muted"><?= htmlspecialchars(substr($u['created_at'] ?? '', 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>
<?php endif; ?>

</body>
</html>
