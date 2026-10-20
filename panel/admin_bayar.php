<?php
/**
 * admin_bayar.php — panel konfirmasi manual (fallback bila listener HP mati)
 * Kepemilikan dana TIDAK berubah: panel hanya menandai status invoice.
 */
require_once __DIR__ . '/../lib/paycore.php';
session_start();

// ---- kredensial (GANTI) ----
$CONFIG = array(
    'db_dsn'  => 'mysql:host=localhost;dbname=pay;charset=utf8mb4',
    'db_user' => 'pay',
    'db_pass' => 'GANTI',
    'qris'    => '',
);

$err = '';
try {
    $pdo = new PDO($CONFIG['db_dsn'], $CONFIG['db_user'], $CONFIG['db_pass'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
              PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $e) { $err = 'DB gagal'; }
$core = $err ? null : new PayCore($pdo, $CONFIG['qris']);

// login sederhana (GANTI: pakai password_hash produksi)
if (isset($_POST['login_key']) && hash_equals('GANTI_KEY_ADMIN', $_POST['login_key'])) $_SESSION['admin'] = true;
if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }

if (!empty($_SESSION['admin']) && isset($_POST['confirm'])) {
    $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=? AND status='pending'")
        ->execute(array((int) $_POST['confirm']));
}
if (!empty($_SESSION['admin']) && $core) $core->expirePending(60);

$rows = (!$err && !empty($_SESSION['admin'])) ? $pdo->query(
    "SELECT id, ext_id, amount, status, created_at FROM invoices WHERE status='pending' ORDER BY id DESC LIMIT 50"
)->fetchAll() : array();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Konfirmasi Bayar — PayCore</title>
<style>
  :root { --bg:#fbfcfe; --ink:#0e1b2c; --mut:#5a6b81; --line:#dbe4ef; --acc:#1553b8; --acc2:#2aa198; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Space Grotesk',system-ui,sans-serif; color:var(--ink); background:var(--bg); padding:32px 18px; }
  .wrap { max-width:720px; margin:0 auto; }
  h1 { font-size:22px; margin-bottom:6px; }
  .sub { color:var(--mut); font-size:13.5px; margin-bottom:20px; }
  .kartu { background:#fff; border:1px solid var(--line); border-radius:13px; padding:16px; margin-bottom:12px; }
  .nom { font-size:19px; font-weight:700; color:var(--acc); }
  .meta { color:var(--mut); font-size:12.5px; margin-top:4px; }
  button { font:inherit; font-weight:600; color:#fff; background:var(--acc2); border:0; border-radius:9px; padding:8px 14px; cursor:pointer; margin-top:10px; }
  input { font:inherit; width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:9px; margin-bottom:10px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Konfirmasi Bayar</h1>
  <div class="sub">Invoice pending — cocokkan dengan notif di HP, lalu konfirmasi.</div>
  <?php if ($err): ?>
    <div class="kartu" style="color:#b4232a"><?= htmlspecialchars($err) ?></div>
  <?php elseif (empty($_SESSION['admin'])): ?>
    <div class="kartu">
      <form method="post"><input type="password" name="login_key" placeholder="admin key"><button>Masuk</button></form>
    </div>
  <?php else: ?>
    <?php if (!$rows): ?>
      <div class="kartu" style="color:var(--mut)">Tidak ada invoice pending. Aman. ✓</div>
    <?php endif; ?>
    <?php foreach ($rows as $v): ?>
      <div class="kartu">
        <div class="nom">Rp<?= number_format($v['amount'], 0, ',', '.') ?></div>
        <div class="meta">#<?= (int) $v['id'] ?> · <?= htmlspecialchars($v['ext_id']) ?> · <?= htmlspecialchars($v['created_at']) ?></div>
        <form method="post"><button name="confirm" value="<?= (int) $v['id'] ?>">✓ Sudah Bayar</button></form>
      </div>
    <?php endforeach; ?>
    <div class="sub" style="margin-top:16px"><a href="?logout" style="color:var(--acc)">logout</a></div>
  <?php endif; ?>
</div>
</body>
</html>
