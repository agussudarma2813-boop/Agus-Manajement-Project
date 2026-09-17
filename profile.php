<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'profil') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $telepon = trim((string) ($_POST['telepon'] ?? ''));
        $jabatan = trim((string) ($_POST['jabatan'] ?? ''));
        if ($nama === '') {
            $errors[] = 'Nama tidak boleh kosong.';
        } else {
            db()->prepare('UPDATE users SET nama = ?, telepon = ?, jabatan = ? WHERE id = ?')
                ->execute([$nama, $telepon, $jabatan, $u['id']]);
            flash('Profil berhasil diperbarui.');
            redirect('profile.php');
        }
    }

    if ($action === 'sandi') {
        $lama = (string) ($_POST['sandi_lama'] ?? '');
        $baru = (string) ($_POST['sandi_baru'] ?? '');
        $ulang = (string) ($_POST['sandi_ulang'] ?? '');
        if (!password_verify($lama, $u['password_hash'])) {
            $errors[] = 'Kata sandi lama tidak sesuai.';
        } elseif (strlen($baru) < 6) {
            $errors[] = 'Kata sandi baru minimal 6 karakter.';
        } elseif ($baru !== $ulang) {
            $errors[] = 'Konfirmasi kata sandi baru tidak sama.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($baru, PASSWORD_DEFAULT), $u['id']]);
            flash('Kata sandi berhasil diganti.');
            redirect('profile.php');
        }
    }
}

$myStats = $u['role'] === 'pelaksana' ? pelaksana_stats((int) $u['id']) : ($u['role'] === 'pekerja' ? pekerja_stats((int) $u['id']) : null);
[$awalBulan, $akhirBulan] = default_periode();
$myUpah = upah_user_periode((int) $u['id'], $awalBulan, $akhirBulan);

render_header('Profil Saya', 'Perbarui data pribadi dan kata sandi akunmu', '<a class="btn" href="dashboard.php">Dashboard</a>');
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="grid grid-side">
  <div class="grid" style="gap:20px">
    <form method="post" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profil">
      <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
        <div><h2>Data Pribadi</h2><p>Nama ini tampil di seluruh laporan progress</p></div>
      </div>
      <div class="form-grid">
        <div class="field">
          <label for="nama">Nama lengkap</label>
          <input type="text" id="nama" name="nama" value="<?= e($u['nama']) ?>" required>
        </div>
        <div class="field">
          <label for="jabatan">Jabatan / keahlian</label>
          <input type="text" id="jabatan" name="jabatan" value="<?= e($u['jabatan']) ?>">
        </div>
        <div class="field">
          <label for="telepon">No. telepon</label>
          <input type="tel" id="telepon" name="telepon" value="<?= e($u['telepon']) ?>">
        </div>
        <div class="field">
          <label>Username</label>
          <input type="text" value="<?= e($u['username']) ?>" disabled>
          <span class="hint">Username hanya bisa diubah admin.</span>
        </div>
      </div>
      <div class="form-actions" style="margin-top:18px">
        <button class="btn btn-primary" type="submit">Simpan Profil</button>
      </div>
    </form>

    <form method="post" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sandi">
      <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
        <div><h2>Ganti Kata Sandi</h2><p>Gunakan kata sandi minimal 6 karakter</p></div>
      </div>
      <div class="form-grid">
        <div class="field full">
          <label for="sandi_lama">Kata sandi saat ini</label>
          <input type="password" id="sandi_lama" name="sandi_lama" autocomplete="current-password">
        </div>
        <div class="field">
          <label for="sandi_baru">Kata sandi baru</label>
          <input type="password" id="sandi_baru" name="sandi_baru" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="sandi_ulang">Ulangi kata sandi baru</label>
          <input type="password" id="sandi_ulang" name="sandi_ulang" autocomplete="new-password">
        </div>
      </div>
      <div class="form-actions" style="margin-top:18px">
        <button class="btn btn-dark" type="submit">Ganti Kata Sandi</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <?= badge_avatar($u['nama']) ?>
      <div>
        <h2 class="card-title"><?= e($u['nama']) ?></h2>
        <span class="pill <?= $u['role'] === 'admin' ? 'pill-tertunda' : ($u['role'] === 'pelaksana' ? 'pill-proses' : 'pill-belum') ?>">
          <?= e(role_label($u['role'])) ?>
        </span>
      </div>
    </div>
    <dl class="kv" style="grid-template-columns:minmax(0,1fr)">
      <dt>Bergabung</dt><dd><?= e(tgl(substr((string) $u['created_at'], 0, 10))) ?></dd>
      <?php if ($myStats !== null && $u['role'] === 'pelaksana'): ?>
        <dt>Project dipimpin</dt><dd><?= (int) $myStats['projects'] ?> project (<?= (int) $myStats['projects_berjalan'] ?> berjalan)</dd>
        <dt>Pekerjaan dikelola</dt><dd><?= (int) $myStats['pekerjaan'] ?> pekerjaan · rata-rata <?= (int) $myStats['avg_progress'] ?>%</dd>
        <dt>Terlambat</dt><dd><?= (int) $myStats['terlambat'] ?> pekerjaan</dd>
      <?php elseif ($myStats !== null): ?>
        <dt>Tugas aktif</dt><dd><?= (int) $myStats['proses'] ?> dari <?= (int) $myStats['tugas'] ?> tugas</dd>
        <dt>Tugas selesai</dt><dd><?= (int) $myStats['selesai'] ?> tugas</dd>
        <dt>Project terlibat</dt><dd><?= (int) $myStats['projects'] ?> project</dd>
      <?php else: ?>
        <dt>Hak akses</dt><dd>Akses penuh seluruh data project</dd>
      <?php endif; ?>
      <?php if ((float) $u['upah_harian'] > 0): ?>
        <dt>Upah harian saya</dt><dd><?= e(rupiah($u['upah_harian'])) ?></dd>
      <?php endif; ?>
      <dt>Hari kerja bulan ini</dt><dd><?= e(hari_format($myUpah['hari'])) ?></dd>
      <dt>Total upah bulan ini</dt><dd><?= e(rupiah($myUpah['total'])) ?></dd>
    </dl>
    <div class="row-actions" style="margin-top:14px;justify-content:flex-start">
      <a class="btn btn-sm" href="absensi.php">Absensi Saya</a>
      <a class="btn btn-sm" href="upah_detail.php?id=<?= (int) $u['id'] ?>">Rincian Upah</a>
    </div>
  </div>
</div>

<?php render_footer(); ?>
