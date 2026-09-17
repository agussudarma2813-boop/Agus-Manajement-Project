<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
$username = '';

/**
 * Branding per perusahaan: pelanggan bisa dibukakan link khusus
 * login.php?p=<slug> sehingga halaman login memakai nama & logo mereka.
 */
$perusahaanLogin = null;
$slugLogin = trim((string) ($_GET['p'] ?? ''));
if ($slugLogin !== '') {
    $stp = db()->prepare('SELECT * FROM perusahaan WHERE slug = ?');
    $stp->execute([$slugLogin]);
    $perusahaanLogin = $stp->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Nama pengguna dan kata sandi wajib diisi.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            $error = 'Nama pengguna atau kata sandi salah.';
        } elseif ((int) $u['aktif'] !== 1) {
            $error = 'Akun ini sedang dinonaktifkan. Hubungi admin perusahaan Anda.';
        } else {
            // Cek status perusahaan (nonaktif / masa aktif habis)
            $alasan = '';
            if ((int) $u['perusahaan_id'] > 0) {
                $stp = db()->prepare('SELECT * FROM perusahaan WHERE id = ?');
                $stp->execute([(int) $u['perusahaan_id']]);
                $alasan = tenant_alasan_tutup($stp->fetch() ?: null);
            }
            if ($alasan !== '') {
                $error = $alasan;
            } else {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int) $u['id'];
                unset($_SESSION['as_perusahaan']);
                $stp = db()->prepare('SELECT nama FROM perusahaan WHERE id = ?');
                $stp->execute([(int) $u['perusahaan_id']]);
                $namaPerusahaan = (string) ($stp->fetchColumn() ?: '');
                flash('Selamat datang, <strong>' . e($u['nama']) . '</strong>'
                    . ($namaPerusahaan !== '' ? ' — ' . e($namaPerusahaan) : '') . '.');
                redirect('dashboard.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php $brandLogin = $perusahaanLogin ? ['nama' => (string) $perusahaanLogin['nama'], 'logo' => (string) $perusahaanLogin['logo']] : ['nama' => 'ProyekKita', 'logo' => '']; ?>
<title>Masuk · <?= e($brandLogin['nama']) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
<div class="login-wrap">
  <div class="login-side">
    <div class="login-brand">
      <?php if ($brandLogin['logo'] !== ''): ?>
        <img class="brand-logo" src="<?= e($brandLogin['logo']) ?>" alt="Logo <?= e($brandLogin['nama']) ?>">
      <?php else: ?>
        <span class="brand-mark">
          <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h3.6l1.7 2H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
        </span>
      <?php endif; ?>
      <span class="brand-text">
        <strong><?= e($brandLogin['nama']) ?></strong>
        <small>Manajemen Project</small>
      </span>
    </div>
    <h2>Kelola project, pelaksana, pekerja &amp; lokasi dalam satu tempat.</h2>
    <ul class="login-points">
      <li>Progress tiap pekerjaan dengan status, persentase &amp; deadline</li>
      <li>Pembagian tugas per pelaksana dan pekerja lapangan</li>
      <li>Riwayat laporan progress harian di tiap lokasi kerja</li>
      <li>Rekap otomatis: pekerjaan berjalan, terlambat &amp; selesai</li>
    </ul>
  </div>

  <div class="login-card">
    <h1>Masuk ke akun<?= $perusahaanLogin ? ' ' . e($perusahaanLogin['nama']) : '' ?></h1>
    <p class="muted">Gunakan akun yang diberikan admin project.</p>
    <?php if ($perusahaanLogin && tenant_alasan_tutup($perusahaanLogin) !== ''): ?>
      <div class="alert alert-err"><?= e(tenant_alasan_tutup($perusahaanLogin)) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="alert alert-err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="login-form">
      <?= csrf_field() ?>
      <div class="field">
        <label for="username">Nama pengguna</label>
        <input type="text" id="username" name="username" value="<?= e($username) ?>" autofocus autocomplete="username" placeholder="mis. admin">
      </div>
      <div class="field">
        <label for="password">Kata sandi</label>
        <input type="password" id="password" name="password" autocomplete="current-password" placeholder="••••••••">
      </div>
      <button class="btn btn-primary btn-block" type="submit">Masuk</button>
    </form>

    <details class="demo-login">
      <summary>Akun contoh untuk mencoba</summary>
      <table class="tbl">
        <thead><tr><th>Peran</th><th>Pengguna</th><th>Kata sandi</th></tr></thead>
        <tbody>
          <tr><td>Admin</td><td class="mono">admin</td><td class="mono">admin123</td></tr>
          <tr><td>Pelaksana</td><td class="mono">budi</td><td class="mono">pelaksana123</td></tr>
          <tr><td>Pekerja</td><td class="mono">andi</td><td class="mono">pekerja123</td></tr>
        </tbody>
      </table>
      <p class="small muted">Segera ganti kata sandi lewat menu <strong>Profil</strong> setelah masuk.</p>
    </details>
  </div>
</div>
</body>
</html>
