<?php
/**
 * VOID — Panel de Administración Completo
 */

require_once __DIR__ . '/includes/auth.php';
cors();

$secret  = $_POST['secret'] ?? ($_COOKIE['void_admin_secret'] ?? '');
$authed  = $secret && hash_equals(ADMIN_SECRET, $secret);

if ($authed && !isset($_COOKIE['void_admin_secret'])) {
    setcookie('void_admin_secret', $secret, ['expires'=>time()+86400*7,'path'=>'/','httponly'=>true,'samesite'=>'Strict']);
}

// Logout
if (isset($_GET['logout'])) {
    setcookie('void_admin_secret','',['expires'=>time()-1,'path'=>'/']);
    header("Location: /admin",true,302); exit;
}

// ─── Acciones POST ─────────────────────────────────────────────────────────
$msg = ''; $msgType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authed) {
    $action = $_POST['action'] ?? '';
    $email  = strtolower(trim($_POST['email'] ?? ''));
    $userId = (int)($_POST['user_id'] ?? 0);
    try {
        $db = get_db();
        if ($action === 'approve' && $email) {
            $db->prepare("UPDATE whitelist SET status='approved', reviewed_at=NOW() WHERE email=?")->execute([$email]);
            $wl = $db->prepare("SELECT name, password_hash FROM whitelist WHERE email=?"); $wl->execute([$email]); $wlRow = $wl->fetch();
            $ex = $db->prepare("SELECT id FROM users WHERE email=?"); $ex->execute([$email]);
            if (!$ex->fetch() && $wlRow) $db->prepare("INSERT INTO users (name,email,password) VALUES (?,?,?)")->execute([$wlRow['name'],$email,$wlRow['password_hash']]);
            $msg = "$email aprobado";
        } elseif ($action === 'reject' && $email) {
            $db->prepare("UPDATE whitelist SET status='rejected', reviewed_at=NOW() WHERE email=?")->execute([$email]);
            $msg = "$email rechazado"; $msgType = 'warn';
        } elseif ($action === 'ban' && $userId) {
            $db->prepare("UPDATE users SET banned=TRUE WHERE id=?")->execute([$userId]);
            $db->prepare("DELETE FROM sessions WHERE user_id=?")->execute([$userId]);
            $msg = "Usuario #$userId baneado"; $msgType = 'warn';
        } elseif ($action === 'unban' && $userId) {
            $db->prepare("UPDATE users SET banned=FALSE WHERE id=?")->execute([$userId]);
            $msg = "Usuario #$userId desbaneado";
        } elseif ($action === 'delete_user' && $userId) {
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
            $msg = "Usuario #$userId eliminado"; $msgType = 'warn';
        } elseif ($action === 'delete_wl' && $email) {
            $db->prepare("DELETE FROM whitelist WHERE email=?")->execute([$email]);
            $msg = "Solicitud de $email eliminada";
        }
    } catch (Throwable $e) { $msg = 'Error: '.$e->getMessage(); $msgType = 'warn'; }
    header("Location: /admin?tab=".($_POST['_tab']??'overview')."&msg=".urlencode($msg)."&type=".$msgType,true,303); exit;
}

// ─── Datos ─────────────────────────────────────────────────────────────────
$whitelist=$users=$providerStats=$recentActivity=$topUsers=[];
$stats=['pending'=>0,'approved'=>0,'rejected'=>0,'users'=>0,'convs'=>0,'messages'=>0,'banned'=>0,'new_week'=>0,'convs_today'=>0];
$dbError='';

if ($authed) {
    try {
        $db = get_db();
        // Migraciones nuevas columnas
        foreach (["ALTER TABLE users ADD COLUMN IF NOT EXISTS banned BOOLEAN NOT NULL DEFAULT FALSE"] as $sql) { try{$db->exec($sql);}catch(Exception $e){} }

        $whitelist = $db->query("SELECT id,name,email,status,requested_at,reviewed_at FROM whitelist ORDER BY requested_at DESC")->fetchAll();
        foreach ($whitelist as $r) $stats[$r['status']] = ($stats[$r['status']]??0)+1;

        $users = $db->query("
            SELECT u.id,u.name,u.email,u.api_provider,u.created_at,u.banned,
                   COUNT(DISTINCT c.id) AS conv_count, MAX(s.last_seen) AS last_active
            FROM users u
            LEFT JOIN conversations c ON c.user_id=u.id
            LEFT JOIN sessions s ON s.user_id=u.id
            GROUP BY u.id ORDER BY u.created_at DESC LIMIT 100
        ")->fetchAll();

        $stats['users']  = count($users);
        $stats['banned'] = count(array_filter($users, fn($u)=>$u['banned']));

        $stats['convs']    = (int)$db->query("SELECT COUNT(*) FROM conversations")->fetchColumn();
        $stats['messages'] = (int)$db->query("SELECT COALESCE(SUM(CASE WHEN messages!='[]' AND messages!='' THEN json_array_length(messages::json) ELSE 0 END),0) FROM conversations")->fetchColumn();
        $stats['new_week'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE created_at>NOW()-INTERVAL '7 days'")->fetchColumn();
        $stats['convs_today'] = (int)$db->query("SELECT COUNT(*) FROM conversations WHERE updated_at>NOW()-INTERVAL '24 hours'")->fetchColumn();

        foreach ($db->query("SELECT api_provider,COUNT(*) AS cnt FROM users GROUP BY api_provider ORDER BY cnt DESC")->fetchAll() as $r)
            $providerStats[$r['api_provider']] = (int)$r['cnt'];

        $topUsers = $db->query("
            SELECT u.name,u.email,u.api_provider,COUNT(c.id) AS convs,
                   COALESCE(SUM(CASE WHEN c.messages!='[]' AND c.messages!='' THEN json_array_length(c.messages::json) ELSE 0 END),0) AS msgs
            FROM users u LEFT JOIN conversations c ON c.user_id=u.id
            GROUP BY u.id ORDER BY convs DESC LIMIT 8
        ")->fetchAll();

        $recentActivity = $db->query("
            SELECT u.name,u.email,s.last_seen FROM sessions s
            JOIN users u ON u.id=s.user_id ORDER BY s.last_seen DESC LIMIT 15
        ")->fetchAll();

    } catch (Throwable $e) { $dbError=$e->getMessage(); }
}

$flashMsg  = htmlspecialchars($_GET['msg']??'');
$flashType = in_array($_GET['type']??'',['ok','warn']) ? $_GET['type'] : 'ok';
$activeTab = $_GET['tab']??'overview';
$provColors = ['gemini'=>'var(--blue)','openai'=>'var(--green)','anthropic'=>'var(--purple)'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>VOID — Admin</title>
<link rel="icon" href="favicon.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}@media(pointer:fine){*,*::before,*::after{cursor:none!important}}
:root{
  --bg:#060608;--bg2:#0d0d10;--bg3:#141418;
  --surface:#1a1a20;--surface2:#202028;
  --border:rgba(255,255,255,0.07);--border2:rgba(255,255,255,0.12);
  --accent:#e8ff47;--accent-dim:rgba(232,255,71,0.1);--accent-glow:rgba(232,255,71,0.3);
  --text:#f0f0f0;--text2:#a0a0a8;--muted:#555560;
  --green:#4ade80;--green-dim:rgba(74,222,128,0.1);
  --red:#f87171;--red-dim:rgba(248,113,113,0.1);
  --yellow:#fbbf24;--yellow-dim:rgba(251,191,36,0.1);
  --blue:#60a5fa;--blue-dim:rgba(96,165,250,0.1);
  --purple:#a78bfa;--purple-dim:rgba(167,139,250,0.1);
  --font-head:'Syne',sans-serif;--font-body:'DM Sans',sans-serif;
  --ease:cubic-bezier(0.23,1,0.32,1);--r:8px;
}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:var(--font-body);overflow-x:hidden;-webkit-font-smoothing:antialiased;min-height:100vh}
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:var(--bg)}::-webkit-scrollbar-thumb{background:var(--surface2);border-radius:2px}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");pointer-events:none;z-index:9999;opacity:.35}
#cursor{position:fixed;width:10px;height:10px;background:var(--accent);border-radius:50%;pointer-events:none;z-index:99999;top:0;left:0;transform:translate(-50%,-50%);mix-blend-mode:difference}
#cursor-ring{position:fixed;width:32px;height:32px;border:1px solid rgba(232,255,71,0.5);border-radius:50%;pointer-events:none;z-index:99998;top:0;left:0;transform:translate(-50%,-50%)}
@media(pointer:coarse){#cursor,#cursor-ring{display:none}}
.ico{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
/* Header */
.hdr{position:sticky;top:0;z-index:100;background:rgba(6,6,8,0.92);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:0 28px;height:58px;display:flex;align-items:center;gap:14px}
.logo{font-family:var(--font-head);font-size:19px;font-weight:800;letter-spacing:-.5px;display:flex;align-items:center;gap:9px}
.logo-dot{width:9px;height:9px;background:var(--accent);border-radius:50%;box-shadow:0 0 10px var(--accent-glow)}
.badge-admin{font-family:var(--font-head);font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--accent);background:var(--accent-dim);border:1px solid rgba(232,255,71,0.2);padding:3px 10px;border-radius:99px}
/* Shell */
.shell{display:grid;grid-template-columns:210px 1fr;min-height:calc(100vh - 58px)}
.snav{background:var(--bg2);border-right:1px solid var(--border);padding:20px 0;position:sticky;top:58px;height:calc(100vh - 58px);overflow-y:auto;flex-shrink:0}
.snav-lbl{padding:0 16px;margin-bottom:8px;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted)}
.snav a{display:flex;align-items:center;gap:10px;padding:9px 16px;font-size:13px;color:var(--text2);border-left:2px solid transparent;transition:all .15s;text-decoration:none;margin-bottom:1px}
.snav a:hover{color:var(--text);background:var(--surface)}
.snav a.on{color:var(--accent);background:var(--accent-dim);border-left-color:var(--accent)}
.snav-count{margin-left:auto;background:var(--yellow-dim);color:var(--yellow);font-size:10px;padding:1px 7px;border-radius:99px;font-weight:600}
.snav-div{border-top:1px solid var(--border);margin:14px 0}
/* Main */
.main{padding:30px;overflow-x:hidden}
/* Login */
.login-wrap{display:flex;justify-content:center;align-items:center;min-height:calc(100vh - 58px)}
.login-card{background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);padding:40px;width:360px}
.login-title{font-family:var(--font-head);font-size:20px;font-weight:700;margin-bottom:8px}
.login-sub{color:var(--text2);font-size:13px;margin-bottom:28px}
.field-wrap{position:relative}.field-wrap input{width:100%;background:var(--bg2);border:1px solid var(--border2);color:var(--text);padding:12px 44px 12px 14px;border-radius:var(--r);font-family:var(--font-body);font-size:14px;outline:none;transition:border-color .2s}
.field-wrap input:focus{border-color:var(--accent)}.field-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}
/* Buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:8px 14px;border-radius:var(--r);font-family:var(--font-body);font-size:12px;font-weight:500;border:none;transition:opacity .15s,transform .1s;white-space:nowrap}
.btn:hover{opacity:.82}.btn:active{transform:scale(.97)}
.btn-accent{background:var(--accent);color:#000;width:100%;margin-top:16px;font-size:14px;padding:12px}
.btn-approve{background:var(--green-dim);color:var(--green);border:1px solid rgba(74,222,128,0.2)}
.btn-reject{background:var(--red-dim);color:var(--red);border:1px solid rgba(248,113,113,0.2)}
.btn-ban{background:var(--yellow-dim);color:var(--yellow);border:1px solid rgba(251,191,36,0.2)}
.btn-unban{background:var(--blue-dim);color:var(--blue);border:1px solid rgba(96,165,250,0.2)}
.btn-danger{background:var(--red-dim);color:var(--red);border:1px solid rgba(248,113,113,0.2)}
.btn-ghost{background:var(--surface2);color:var(--text2);border:1px solid var(--border)}
/* Flash */
.flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:var(--r);font-size:13px;margin-bottom:24px;animation:fsIn .3s var(--ease)}
.flash-ok{background:var(--green-dim);border:1px solid rgba(74,222,128,0.2);color:var(--green)}
.flash-warn{background:var(--red-dim);border:1px solid rgba(248,113,113,0.2);color:var(--red)}
@keyframes fsIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
/* Stats */
.sg4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:28px}
.sg3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:28px}
.sg2{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:28px}
.sc{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:18px;position:relative;overflow:hidden}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.sc-y::before{background:linear-gradient(90deg,transparent,var(--yellow),transparent)}.sc-y .sv{color:var(--yellow)}
.sc-g::before{background:linear-gradient(90deg,transparent,var(--green),transparent)}.sc-g .sv{color:var(--green)}
.sc-r::before{background:linear-gradient(90deg,transparent,var(--red),transparent)}.sc-r .sv{color:var(--red)}
.sc-a::before{background:linear-gradient(90deg,transparent,var(--accent),transparent)}.sc-a .sv{color:var(--accent)}
.sc-b::before{background:linear-gradient(90deg,transparent,var(--blue),transparent)}.sc-b .sv{color:var(--blue)}
.sc-p::before{background:linear-gradient(90deg,transparent,var(--purple),transparent)}.sc-p .sv{color:var(--purple)}
.sl{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:8px}
.sv{font-family:var(--font-head);font-size:30px;font-weight:800;line-height:1;margin-bottom:3px}
.ss{font-size:11px;color:var(--muted)}
/* Section */
.sh{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.st{font-family:var(--font-head);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--text2)}
.scount{background:var(--surface2);color:var(--muted);font-size:11px;padding:2px 8px;border-radius:99px}
/* Table */
.tw{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:28px}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);padding:10px 14px;border-bottom:1px solid var(--border);background:var(--bg2);white-space:nowrap}
td{padding:12px 14px;border-bottom:1px solid var(--border);vertical-align:middle;font-size:13px}
tr:last-child td{border-bottom:none}tbody tr{transition:background .15s}tbody tr:hover td{background:var(--bg3)}
.tn{font-weight:500}.te{font-family:monospace;font-size:11px;color:var(--text2)}.td{color:var(--muted);font-size:11px;white-space:nowrap}.te0{text-align:center;padding:36px;color:var(--muted)}.tnum{font-family:var(--font-head);font-weight:700;color:var(--accent)}
/* Badges */
.bdg{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.b-p{background:var(--yellow-dim);color:var(--yellow);border:1px solid rgba(251,191,36,0.2)}
.b-a{background:var(--green-dim);color:var(--green);border:1px solid rgba(74,222,128,0.2)}
.b-r{background:var(--red-dim);color:var(--red);border:1px solid rgba(248,113,113,0.2)}
.b-ban{background:var(--red-dim);color:var(--red);border:1px solid rgba(248,113,113,0.3)}
.b-ok{background:var(--green-dim);color:var(--green);border:1px solid rgba(74,222,128,0.2)}
.b-pv{background:var(--bg3);color:var(--text2);border:1px solid var(--border2)}
/* Card */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:28px}
.card-hd{padding:13px 16px;border-bottom:1px solid var(--border);font-family:var(--font-head);font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted)}
/* Bar chart */
.bars{display:flex;flex-direction:column;gap:10px;padding:18px}
.bar-row{display:flex;align-items:center;gap:10px;font-size:12px}
.bar-lbl{width:88px;color:var(--text2);text-align:right;flex-shrink:0;font-size:11px}
.bar-track{flex:1;height:5px;background:var(--surface2);border-radius:99px;overflow:hidden}
.bar-fill{height:100%;border-radius:99px;transition:width 1.2s var(--ease)}
.bar-val{width:26px;color:var(--muted);font-size:11px;text-align:right;flex-shrink:0}
/* Activity */
.act-list{display:flex;flex-direction:column}
.act-item{display:flex;align-items:center;gap:11px;padding:10px 16px;border-bottom:1px solid var(--border);font-size:12px}
.act-item:last-child{border-bottom:none}
.act-dot{width:6px;height:6px;background:var(--green);border-radius:50%;flex-shrink:0;box-shadow:0 0 5px rgba(74,222,128,.5)}
.act-name{font-weight:500;color:var(--text);min-width:110px;flex-shrink:0}
.act-email{color:var(--muted);font-family:monospace;font-size:11px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.act-time{color:var(--muted);white-space:nowrap;flex-shrink:0}
/* 2-col grid */
.g2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
/* Page heading */
.pg-title{font-family:var(--font-head);font-size:22px;font-weight:800;margin-bottom:3px}
.pg-sub{color:var(--muted);font-size:13px;margin-bottom:24px}
/* Modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);z-index:1000;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);padding:28px;width:360px;max-width:90vw}
.modal-title{font-family:var(--font-head);font-size:16px;font-weight:700;margin-bottom:8px}
.modal-body{color:var(--text2);font-size:13px;margin-bottom:22px;line-height:1.6}
.modal-acts{display:flex;gap:8px;justify-content:flex-end}
/* Mobile bottom nav — base styles BEFORE media queries so @media display:block wins */
.mob-nav{display:none;position:fixed;bottom:0;left:0;right:0;z-index:10000;background:rgba(10,10,14,0.96);backdrop-filter:blur(20px);border-top:1px solid var(--border);padding:6px 0 env(safe-area-inset-bottom,6px)}
.mob-nav-inner{display:flex;align-items:stretch;justify-content:space-around}
.mob-nav-item{display:flex;flex-direction:column;align-items:center;gap:3px;padding:6px 10px;font-size:9px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);text-decoration:none;border-radius:8px;transition:color .15s,background .15s;flex:1;position:relative}
.mob-nav-item svg{width:20px;height:20px;flex-shrink:0}
.mob-nav-item.on{color:var(--accent)}
.mob-nav-item.on svg{filter:drop-shadow(0 0 5px var(--accent-glow))}
.mob-nav-badge{position:absolute;top:4px;right:calc(50% - 16px);background:var(--yellow);color:#000;font-size:8px;font-weight:700;min-width:14px;height:14px;border-radius:99px;display:flex;align-items:center;justify-content:center;padding:0 3px;line-height:1}
/* Responsive */
@media(max-width:860px){.shell{grid-template-columns:1fr}.snav{display:none!important}.sg4{grid-template-columns:repeat(2,1fr)}.g2{grid-template-columns:1fr}.main{padding-bottom:80px}.mob-nav{display:block!important}}
@media(max-width:560px){.main{padding:16px 12px 80px}.sg4,.sg3{grid-template-columns:repeat(2,1fr);gap:8px}.hdr{padding:0 14px}table,thead,tbody,tr{display:block;width:100%}thead{display:none}tbody tr{display:flex;flex-direction:column;gap:4px;padding:12px;border-bottom:1px solid var(--border)}tbody tr:last-child{border-bottom:none}td{display:flex;align-items:flex-start;gap:8px;padding:0;border:none;background:transparent!important}td::before{content:attr(data-label);font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);min-width:72px;flex-shrink:0;padding-top:2px}td.te0{justify-content:center;padding:28px 0}td.te0::before{display:none}.act-name{min-width:80px}.act-email{font-size:10px}.sg2{grid-template-columns:repeat(2,1fr);gap:8px}.pg-title{font-size:17px}.sv{font-size:24px}}
</style>
</head>
<body>
<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
  <symbol id="i-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/><circle cx="12" cy="16" r="1.2" fill="currentColor" stroke="none"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol>
  <symbol id="i-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></symbol>
  <symbol id="i-warn" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol>
  <symbol id="i-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>
  <symbol id="i-spark" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l1.8 7.2L21 12l-7.2 1.8L12 22l-1.8-7.2L3 12l7.2-1.8L12 2z"/></symbol>
  <symbol id="i-chart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></symbol>
  <symbol id="i-act" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></symbol>
  <symbol id="i-ban" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></symbol>
  <symbol id="i-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></symbol>
  <symbol id="i-grid" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></symbol>
  <symbol id="i-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></symbol>
</svg>
<div id="cursor"></div><div id="cursor-ring"></div>

<!-- Confirm Modal -->
<div class="modal-bg" id="cmodal">
  <div class="modal">
    <div class="modal-title" id="m-title">¿Confirmar?</div>
    <div class="modal-body"  id="m-body">Esta acción no se puede deshacer.</div>
    <div class="modal-acts">
      <button class="btn btn-ghost" onclick="closeMod()">Cancelar</button>
      <form id="m-form" method="POST" action="/admin" style="display:contents">
        <input type="hidden" id="m-action" name="action">
        <input type="hidden" id="m-email"  name="email">
        <input type="hidden" id="m-uid"    name="user_id">
        <input type="hidden" id="m-tab"    name="_tab">
        <button type="submit" class="btn btn-danger" id="m-btn">Confirmar</button>
      </form>
    </div>
  </div>
</div>

<header class="hdr">
  <div class="logo"><span class="logo-dot"></span>VOID</div>
  <span class="badge-admin">Admin</span>
  <div style="flex:1"></div>
  <?php if($authed): ?><a href="/admin?logout=1" class="btn btn-ghost" style="font-size:12px;text-decoration:none"><svg class="ico" style="width:13px;height:13px"><use href="#i-out"/></svg>Salir</a><?php endif; ?>
</header>

<?php if(!$authed): ?>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-title">Panel de administración</div>
    <div class="login-sub">Introduce el secret para continuar</div>
    <form method="POST" action="/admin">
      <div class="field-wrap">
        <input type="password" name="secret" placeholder="Admin secret…" autocomplete="current-password" required autofocus>
        <span class="field-icon"><svg class="ico" style="width:15px;height:15px"><use href="#i-lock"/></svg></span>
      </div>
      <button type="submit" class="btn btn-accent"><svg class="ico" style="width:14px;height:14px"><use href="#i-spark"/></svg>Entrar</button>
    </form>
  </div>
</div>
<?php else: ?>

<div class="shell">
<nav class="snav">
  <div class="snav-lbl" style="margin:0 0 10px">Menú</div>
  <a href="?tab=overview"  class="<?= $activeTab==='overview'  ?'on':'' ?>"><svg class="ico" style="width:15px;height:15px"><use href="#i-grid"/></svg>Resumen</a>
  <a href="?tab=whitelist" class="<?= $activeTab==='whitelist' ?'on':'' ?>"><svg class="ico" style="width:15px;height:15px"><use href="#i-clock"/></svg>Solicitudes<?php if($stats['pending']>0): ?><span class="snav-count"><?= $stats['pending'] ?></span><?php endif; ?></a>
  <a href="?tab=users"     class="<?= $activeTab==='users'     ?'on':'' ?>"><svg class="ico" style="width:15px;height:15px"><use href="#i-users"/></svg>Usuarios</a>
  <a href="?tab=activity"  class="<?= $activeTab==='activity'  ?'on':'' ?>"><svg class="ico" style="width:15px;height:15px"><use href="#i-act"/></svg>Actividad</a>
  <a href="?tab=stats"     class="<?= $activeTab==='stats'     ?'on':'' ?>"><svg class="ico" style="width:15px;height:15px"><use href="#i-chart"/></svg>Estadísticas</a>
  <div class="snav-div"></div>
  <a href="/" style="color:var(--muted)"><svg class="ico" style="width:15px;height:15px"><use href="#i-chat"/></svg>Ir al chat</a>
</nav>
<main class="main">

<?php if($flashMsg): ?><div class="flash flash-<?= $flashType ?>"><svg class="ico" style="width:14px;height:14px"><use href="#i-<?= $flashType==='ok'?'check':'warn' ?>"/></svg><?= $flashMsg ?></div><?php endif; ?>
<?php if($dbError): ?><div class="flash flash-warn"><svg class="ico" style="width:14px;height:14px"><use href="#i-warn"/></svg><?= htmlspecialchars($dbError) ?></div><?php endif; ?>

<?php if($activeTab==='overview'): ?>
<div class="pg-title">Resumen</div>
<div class="pg-sub">Vista general del sistema</div>
<div class="sg4">
  <div class="sc sc-a"><div class="sl">Usuarios</div><div class="sv"><?= $stats['users'] ?></div><div class="ss">+<?= $stats['new_week'] ?> esta semana</div></div>
  <div class="sc sc-a"><div class="sl">Conversaciones</div><div class="sv"><?= $stats['convs'] ?></div><div class="ss"><?= $stats['convs_today'] ?> hoy</div></div>
  <div class="sc sc-a"><div class="sl">Mensajes</div><div class="sv"><?= number_format($stats['messages']) ?></div><div class="ss">en total</div></div>
  <div class="sc sc-a"><div class="sl">Pendientes</div><div class="sv"><?= $stats['pending'] ?></div><div class="ss">solicitudes</div></div>
</div>
<div class="g2">
  <div class="card">
    <div class="card-hd">Actividad reciente</div>
    <div class="act-list">
      <?php if(empty($recentActivity)): ?><div style="padding:32px;text-align:center;color:var(--muted);font-size:13px">Sin actividad</div><?php endif; ?>
      <?php foreach(array_slice($recentActivity,0,8) as $a): ?>
      <div class="act-item"><span class="act-dot"></span><span class="act-name"><?= htmlspecialchars($a['name']) ?></span><span class="act-email"><?= htmlspecialchars($a['email']) ?></span><span class="act-time"><?= substr($a['last_seen']??'',0,16) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-hd">Uso por proveedor</div>
    <div class="bars">
      <?php foreach($providerStats as $p=>$c): ?>
      <div class="bar-row"><span class="bar-lbl"><?= ucfirst($p) ?></span><div class="bar-track"><div class="bar-fill" data-w="<?= round($c/max(1,$stats['users'])*100) ?>" style="width:0;background:<?= $provColors[$p]??'var(--accent)' ?>"></div></div><span class="bar-val"><?= $c ?></span></div>
      <?php endforeach; ?>
      <?php if($stats['banned']>0): ?>
      <div class="bar-row" style="margin-top:8px;padding-top:10px;border-top:1px solid var(--border)"><span class="bar-lbl" style="color:var(--red)">Baneados</span><div class="bar-track"><div class="bar-fill" data-w="<?= round($stats['banned']/max(1,$stats['users'])*100) ?>" style="width:0;background:var(--red)"></div></div><span class="bar-val" style="color:var(--red)"><?= $stats['banned'] ?></span></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<div class="sh"><div class="st">Top usuarios</div></div>
<div class="tw"><table>
  <thead><tr><th>Nombre</th><th>Email</th><th>Proveedor</th><th>Convs</th><th>Mensajes</th></tr></thead>
  <tbody>
    <?php if(empty($topUsers)): ?><tr><td colspan="5" class="te0">Sin datos</td></tr><?php endif; ?>
    <?php foreach($topUsers as $u): ?>
    <tr><td class="tn" data-label="Nombre"><?= htmlspecialchars($u['name']) ?></td><td class="te" data-label="Email"><?= htmlspecialchars($u['email']) ?></td><td data-label="Proveedor"><span class="bdg b-pv"><svg class="ico" style="width:10px;height:10px"><use href="#i-spark"/></svg><?= htmlspecialchars($u['api_provider']??'—') ?></span></td><td data-label="Convs"><span class="tnum"><?= (int)$u['convs'] ?></span></td><td data-label="Mensajes"><span class="tnum"><?= (int)$u['msgs'] ?></span></td></tr>
    <?php endforeach; ?>
  </tbody>
</table></div>

<?php elseif($activeTab==='whitelist'): ?>
<div class="pg-title">Solicitudes de acceso</div>
<div class="pg-sub"><?= $stats['pending'] ?> pendientes · <?= $stats['approved'] ?> aprobadas · <?= $stats['rejected'] ?> rechazadas</div>
<div class="sg3">
  <div class="sc sc-a"><div class="sl">Pendientes</div><div class="sv"><?= $stats['pending'] ?></div></div>
  <div class="sc sc-a"><div class="sl">Aprobadas</div><div class="sv"><?= $stats['approved'] ?></div></div>
  <div class="sc sc-a"><div class="sl">Rechazadas</div><div class="sv"><?= $stats['rejected'] ?></div></div>
</div>
<div class="tw"><table>
  <thead><tr><th>Nombre</th><th>Email</th><th>Estado</th><th>Solicitado</th><th>Revisado</th><th>Acciones</th></tr></thead>
  <tbody>
    <?php if(empty($whitelist)): ?><tr><td colspan="6" class="te0">Sin solicitudes</td></tr><?php endif; ?>
    <?php foreach($whitelist as $r):
      $sc=match($r['status']){'approved'=>'b-a','rejected'=>'b-r',default=>'b-p'};
      $si=match($r['status']){'approved'=>'check','rejected'=>'x',default=>'clock'};
      $sl=match($r['status']){'approved'=>'Aprobado','rejected'=>'Rechazado',default=>'Pendiente'};
    ?>
    <tr>
      <td class="tn" data-label="Nombre"><?= htmlspecialchars($r['name']??'—') ?></td>
      <td class="te" data-label="Email"><?= htmlspecialchars($r['email']) ?></td>
      <td data-label="Estado"><span class="bdg <?= $sc ?>"><svg class="ico" style="width:10px;height:10px"><use href="#i-<?= $si ?>"/></svg><?= $sl ?></span></td>
      <td class="td" data-label="Solicitado"><?= substr($r['requested_at']??'',0,16) ?></td>
      <td class="td" data-label="Revisado"><?= substr($r['reviewed_at']??'—',0,16) ?></td>
      <td data-label="Acciones"><div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if($r['status']!=='approved'): ?><form method="POST" action="/admin"><input type="hidden" name="action" value="approve"><input type="hidden" name="email" value="<?= htmlspecialchars($r['email']) ?>"><input type="hidden" name="_tab" value="whitelist"><button type="submit" class="btn btn-approve"><svg class="ico" style="width:11px;height:11px"><use href="#i-check"/></svg>Aprobar</button></form><?php endif; ?>
        <?php if($r['status']!=='rejected'): ?><form method="POST" action="/admin"><input type="hidden" name="action" value="reject"><input type="hidden" name="email" value="<?= htmlspecialchars($r['email']) ?>"><input type="hidden" name="_tab" value="whitelist"><button type="submit" class="btn btn-reject"><svg class="ico" style="width:11px;height:11px"><use href="#i-x"/></svg>Rechazar</button></form><?php endif; ?>
        <button class="btn btn-ghost" onclick="openMod('delete_wl','<?= addslashes($r['email']) ?>',0,'whitelist','Eliminar solicitud','¿Eliminar la solicitud de <?= addslashes(htmlspecialchars($r['email'])) ?>?')"><svg class="ico" style="width:11px;height:11px"><use href="#i-trash"/></svg></button>
      </div></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>

<?php elseif($activeTab==='users'): ?>
<div class="pg-title">Usuarios registrados</div>
<div class="pg-sub"><?= $stats['users'] ?> usuarios · <?= $stats['banned'] ?> baneados</div>
<div class="tw"><table>
  <thead><tr><th>#</th><th>Nombre</th><th>Email</th><th>Proveedor</th><th>Convs</th><th>Último acceso</th><th>Estado</th><th>Acciones</th></tr></thead>
  <tbody>
    <?php if(empty($users)): ?><tr><td colspan="8" class="te0">Sin usuarios</td></tr><?php endif; ?>
    <?php foreach($users as $u): $bn=(bool)$u['banned']; ?>
    <tr style="<?= $bn?'opacity:.55':'' ?>">
      <td class="td" data-label="ID"><?= (int)$u['id'] ?></td>
      <td class="tn" data-label="Nombre"><span style="display:inline-flex;align-items:center;gap:7px"><svg class="ico" style="width:13px;height:13px;color:var(--muted)"><use href="#i-user"/></svg><?= htmlspecialchars($u['name']) ?></span></td>
      <td class="te" data-label="Email"><?= htmlspecialchars($u['email']) ?></td>
      <td data-label="Proveedor"><span class="bdg b-pv"><svg class="ico" style="width:10px;height:10px"><use href="#i-spark"/></svg><?= htmlspecialchars($u['api_provider']??'gemini') ?></span></td>
      <td data-label="Convs"><span class="tnum"><?= (int)$u['conv_count'] ?></span></td>
      <td class="td" data-label="Último acceso"><?= substr($u['last_active']??$u['created_at']??'',0,16) ?></td>
      <td data-label="Estado"><?php if($bn): ?><span class="bdg b-ban"><svg class="ico" style="width:10px;height:10px"><use href="#i-ban"/></svg>Baneado</span><?php else: ?><span class="bdg b-ok"><svg class="ico" style="width:10px;height:10px"><use href="#i-check"/></svg>Activo</span><?php endif; ?></td>
      <td data-label="Acciones"><div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if(!$bn): ?>
        <button class="btn btn-ban" onclick="openMod('ban','',<?= (int)$u['id'] ?>,'users','Banear usuario','¿Banear a <?= addslashes(htmlspecialchars($u['name'])) ?>? Se cerrarán sus sesiones.')"><svg class="ico" style="width:11px;height:11px"><use href="#i-ban"/></svg>Banear</button>
        <?php else: ?>
        <form method="POST" action="/admin"><input type="hidden" name="action" value="unban"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="_tab" value="users"><button type="submit" class="btn btn-unban"><svg class="ico" style="width:11px;height:11px"><use href="#i-refresh"/></svg>Desbanear</button></form>
        <?php endif; ?>
        <button class="btn btn-danger" onclick="openMod('delete_user','',<?= (int)$u['id'] ?>,'users','Eliminar usuario','¿Eliminar a <?= addslashes(htmlspecialchars($u['name'])) ?> y todos sus datos? Irreversible.')"><svg class="ico" style="width:11px;height:11px"><use href="#i-trash"/></svg></button>
      </div></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>

<?php elseif($activeTab==='activity'): ?>
<div class="pg-title">Actividad reciente</div>
<div class="pg-sub">Últimas <?= count($recentActivity) ?> sesiones</div>
<div class="card">
  <div class="card-hd">Sesiones activas</div>
  <div class="act-list">
    <?php if(empty($recentActivity)): ?><div style="padding:36px;text-align:center;color:var(--muted);font-size:13px">Sin actividad registrada</div><?php endif; ?>
    <?php foreach($recentActivity as $a): ?>
    <div class="act-item"><span class="act-dot"></span><span class="act-name"><?= htmlspecialchars($a['name']) ?></span><span class="act-email"><?= htmlspecialchars($a['email']) ?></span><span class="act-time"><?= substr($a['last_seen']??'',0,16) ?></span></div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif($activeTab==='stats'): ?>
<div class="pg-title">Estadísticas</div>
<div class="pg-sub">Métricas globales del sistema</div>
<div class="sg4">
  <div class="sc sc-a"><div class="sl">Usuarios totales</div><div class="sv"><?= $stats['users'] ?></div><div class="ss">+<?= $stats['new_week'] ?> últimos 7 días</div></div>
  <div class="sc sc-a"><div class="sl">Conversaciones</div><div class="sv"><?= $stats['convs'] ?></div><div class="ss"><?= $stats['convs_today'] ?> en 24h</div></div>
  <div class="sc sc-a"><div class="sl">Mensajes</div><div class="sv"><?= number_format($stats['messages']) ?></div><div class="ss">intercambiados</div></div>
  <div class="sc sc-a"><div class="sl">Baneados</div><div class="sv"><?= $stats['banned'] ?></div><div class="ss">de <?= $stats['users'] ?> usuarios</div></div>
</div>
<div class="g2">
  <div class="card"><div class="card-hd">Uso por proveedor de IA</div><div class="bars">
    <?php foreach($providerStats as $p=>$c): ?><div class="bar-row"><span class="bar-lbl"><?= ucfirst($p) ?></span><div class="bar-track"><div class="bar-fill" data-w="<?= round($c/max(1,$stats['users'])*100) ?>" style="width:0;background:<?= $provColors[$p]??'var(--accent)' ?>"></div></div><span class="bar-val"><?= $c ?></span></div><?php endforeach; ?>
    <?php if(empty($providerStats)): ?><div style="text-align:center;color:var(--muted);font-size:13px;padding:16px 0">Sin datos</div><?php endif; ?>
  </div></div>
  <div class="card"><div class="card-hd">Estado del whitelist</div><div class="bars">
    <?php $wt=max(1,$stats['pending']+$stats['approved']+$stats['rejected']); ?>
    <div class="bar-row"><span class="bar-lbl" style="color:var(--green)">Aprobados</span><div class="bar-track"><div class="bar-fill" data-w="<?= round($stats['approved']/$wt*100) ?>" style="width:0;background:var(--green)"></div></div><span class="bar-val"><?= $stats['approved'] ?></span></div>
    <div class="bar-row"><span class="bar-lbl" style="color:var(--yellow)">Pendientes</span><div class="bar-track"><div class="bar-fill" data-w="<?= round($stats['pending']/$wt*100) ?>" style="width:0;background:var(--yellow)"></div></div><span class="bar-val"><?= $stats['pending'] ?></span></div>
    <div class="bar-row"><span class="bar-lbl" style="color:var(--red)">Rechazados</span><div class="bar-track"><div class="bar-fill" data-w="<?= round($stats['rejected']/$wt*100) ?>" style="width:0;background:var(--red)"></div></div><span class="bar-val"><?= $stats['rejected'] ?></span></div>
  </div></div>
</div>
<?php endif; ?>

</main>
</div>
<?php endif; ?>

<?php if($authed): ?>
<nav class="mob-nav" id="mob-nav">
  <div class="mob-nav-inner">
    <a href="?tab=overview"  class="mob-nav-item <?= $activeTab==='overview'  ?'on':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Resumen
    </a>
    <a href="?tab=whitelist" class="mob-nav-item <?= $activeTab==='whitelist' ?'on':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <?php if($stats['pending']>0): ?><span class="mob-nav-badge"><?= $stats['pending'] ?></span><?php endif; ?>
      Solicitudes
    </a>
    <a href="?tab=users"     class="mob-nav-item <?= $activeTab==='users'     ?'on':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
      Usuarios
    </a>
    <a href="?tab=activity"  class="mob-nav-item <?= $activeTab==='activity'  ?'on':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Actividad
    </a>
    <a href="?tab=stats"     class="mob-nav-item <?= $activeTab==='stats'     ?'on':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>
      Stats
    </a>
  </div>
</nav>
<?php endif; ?>

<script>
const cursor=document.getElementById('cursor'),ring=document.getElementById('cursor-ring');
let mx=0,my=0,rx=0,ry=0,_cursorActive=false;

function _startCursor(){
  if(_cursorActive)return;
  _cursorActive=true;
  document.addEventListener('mousemove',e=>{
    mx=e.clientX;my=e.clientY;
    cursor.style.transform=`translate(${mx}px,${my}px) translate(-50%,-50%)`;
  });
  (function loop(){rx+=(mx-rx)*.18;ry+=(my-ry)*.18;ring.style.transform=`translate(${rx}px,${ry}px) translate(-50%,-50%)`;requestAnimationFrame(loop);})();
  cursor.style.opacity='1';ring.style.opacity='1';
}
function _stopCursor(){
  _cursorActive=false;
  if(cursor)cursor.style.opacity='0';
  if(ring)ring.style.opacity='0';
}

const mq=window.matchMedia('(pointer:fine)');
if(mq.matches)_startCursor();else _stopCursor();
mq.addEventListener('change',e=>{ if(e.matches)_startCursor(); else _stopCursor(); });

function openMod(action,email,uid,tab,title,body){
  document.getElementById('m-title').textContent=title;
  document.getElementById('m-body').textContent=body;
  document.getElementById('m-action').value=action;
  document.getElementById('m-email').value=email;
  document.getElementById('m-uid').value=uid;
  document.getElementById('m-tab').value=tab;
  const btn=document.getElementById('m-btn');
  btn.className='btn '+(action==='ban'?'btn-ban':action.startsWith('delete')?'btn-danger':'btn-danger');
  btn.textContent=action==='ban'?'Banear':action.startsWith('delete')?'Eliminar':'Confirmar';
  document.getElementById('cmodal').classList.add('open');
}
function closeMod(){document.getElementById('cmodal').classList.remove('open');}
document.getElementById('cmodal').addEventListener('click',e=>{if(e.target===e.currentTarget)closeMod();});

// Animate bars
setTimeout(()=>{
  document.querySelectorAll('.bar-fill[data-w]').forEach(el=>{el.style.width=el.dataset.w+'%';});
},120);

// Convertir act-time a hora de España
document.querySelectorAll('.act-time').forEach(el => {
  const raw = el.textContent.trim();
  if (!raw) return;
  const d = new Date(raw.replace(' ', 'T') + 'Z');
  if (isNaN(d)) return;
  el.textContent = d.toLocaleString('es-ES', {
    timeZone: 'Europe/Madrid',
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  });
});
</script>
</body>
</html>
