<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_owner();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = $id ? get_perusahaan($id) : null;
if ($id && !$row) {
    flash('Perusahaan tidak ditemukan.', 'err');
    redirect('perusahaan.php');
}

$data = [
    'nama'              => $row['nama'] ?? '',
    'slug'              => $row['slug'] ?? '',
    'logo'              => $row['logo'] ?? '',
    'kontak_nama'       => $row['kontak_nama'] ?? '',
    'kontak_telepon'    => $row['kontak_telepon'] ?? '',
    'masa_aktif_sampai' => $row['masa_aktif_sampai'] ?? '',
    'maks_user'         => $row['maks_user'] ?? 5,
    'aktif'             => (string) ($row['aktif'] ?? 1),
    'catatan'           => $row['catatan'] ?? '',
];
$adminBaru = ['nama' => '', 'username' => '', 'password' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['nama', 'slug', 'logo', 'kontak_nama', 'kontak_telepon', 'masa_aktif_sampai', 'catatan'] as $k) {
        $data[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $data['maks_user'] = max(0, (int) ($_POST['maks_user'] ?? 0));
    $data['aktif'] = isset($_POST['aktif']) ? '1' : '0';
    $data['masa_aktif_sampai'] = valid_tanggal($data['masa_aktif_sampai']);
    if (!$row) {
        $adminBaru = [
            'nama'     => trim((string) ($_POST['admin_nama'] ?? '')),
            'username' => trim((string) ($_POST['admin_username'] ?? '')),
            'password' => (string) ($_POST['admin_password'] ?? ''),
        ];
    }

    if ($data['nama'] === '') {
        $errors[] = 'Nama perusahaan wajib diisi.';
    }
    if ($data['slug'] !== '' && !preg_match('/^[a-z0-9-]+$/', $data['slug'])) {
        $errors[] = 'Link hanya boleh huruf kecil, angka, dan tanda minus.';
    }
    if (!$row) {
        if ($adminBaru['nama'] === '' || $adminBaru['username'] === '') {
            $errors[] = 'Akun admin pertama wajib diisi (nama & username).';
        }
        if (strlen($adminBaru['password']) < 6) {
            $errors[] = 'Kata sandi admin pertama minimal 6 karakter.';
        }
    }
    if (empty($errors)) {
        $data['slug'] = $data['slug'] !== '' ? $data['slug'] : slug_unik($data['nama'], $id);
        $st = db()->prepare('SELECT id FROM perusahaan WHERE slug = ? AND id <> ?');
        $st->execute([$data['slug'], $id]);
        if ($st->fetchColumn()) {
            $errors[] = 'Link tersebut sudah dipakai perusahaan lain.';
        }
    }
    if (!$row && empty($errors)) {
        $st = db()->prepare('SELECT id FROM users WHERE username = ?');
        $st->execute([$adminBaru['username']]);
        if ($st->fetchColumn()) {
            $errors[] = 'Username admin tersebut sudah dipakai. Pilih username lain.';
        }
    }

    if (empty($errors)) {
        $args = [
            $data['nama'], $data['slug'], $data['logo'], $data['kontak_nama'], $data['kontak_telepon'],
            $data['masa_aktif_sampai'], $data['maks_user'], (int) $data['aktif'], $data['catatan'],
        ];
        if ($row) {
            $args[] = $id;
            db()->prepare(
                'UPDATE perusahaan SET nama=?, slug=?, logo=?, kontak_nama=?, kontak_telepon=?,
                        masa_aktif_sampai=?, maks_user=?, aktif=?, catatan=? WHERE id=?'
            )->execute($args);
            flash('Perusahaan <strong>' . e($data['nama']) . '</strong> berhasil diperbarui.');
        } else {
            db()->prepare(
                'INSERT INTO perusahaan (nama, slug, logo, kontak_nama, kontak_telepon,
                        masa_aktif_sampai, maks_user, aktif, catatan)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute($args);
            $id = (int) db()->lastInsertId();

            // Admin pertama perusahaan tersebut
            db()->prepare(
                'INSERT INTO users (nama, username, password_hash, role, jabatan, telepon, upah_harian, skema, aktif, perusahaan_id, is_owner)
                 VALUES (?,?,?,?,?,?,0,?,1,?,0)'
            )->execute([
                $adminBaru['nama'], $adminBaru['username'], password_hash($adminBaru['password'], PASSWORD_DEFAULT),
                'admin', 'Admin ' . $data['nama'], $data['kontak_telepon'], 'harian', $id,
            ]);
            flash('Perusahaan <strong>' . e($data['nama']) . '</strong> dibuat beserta akun admin <strong>' . e($adminBaru['username']) . '</strong>.');
        }
        redirect('perusahaan.php');
    }
}

render_header(
    $row ? 'Edit Perusahaan' : 'Perusahaan Baru',
    'Masa aktif, kuota pengguna, dan nama/logo pelanggan',
    '<a class="btn" href="perusahaan.php">Kembali</a>'
);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="grid" style="gap:20px">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">

  <div class="card">
    <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
      <div><h2>Data Perusahaan</h2><p>Nama &amp; logo ini yang tampil di aplikasi mereka</p></div>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="nama">Nama perusahaan <span class="muted">*</span></label>
        <input type="text" id="nama" name="nama" value="<?= e($data['nama']) ?>" placeholder="mis. CV Karya Mandiri" required>
      </div>
      <div class="field">
        <label for="slug">Link login (opsional)</label>
        <input type="text" id="slug" name="slug" value="<?= e($data['slug']) ?>" placeholder="mis. karya-mandiri">
        <span class="hint">Dipakai untuk link khusus: <span class="mono">login.php?p=karya-mandiri</span>. Kosongkan untuk dibuat otomatis.</span>
      </div>

      <div class="field full">
        <label for="logo">Logo perusahaan (URL)</label>
        <input type="text" id="logo" name="logo" value="<?= e($data['logo']) ?>" placeholder="https://... atau unggah di bawah">
        <span class="hint">Bisa ditempel dari internet, atau diunggah langsung di bawah ini.</span>
      </div>
      <div class="field full">
        <label for="logo_file">Unggah logo (JPG/PNG/WebP/SVG, maks 15 MB)</label>
        <input type="file" id="logo_file" accept="image/*" data-logo-upload>
        <span class="hint" data-logo-status>Pilih berkas, logo akan langsung diunggah dan URL-nya diisi otomatis.</span>
      </div>

      <div class="field">
        <label for="kontak_nama">Nama kontak</label>
        <input type="text" id="kontak_nama" name="kontak_nama" value="<?= e($data['kontak_nama']) ?>" placeholder="mis. Pak Budi (pemilik)">
      </div>
      <div class="field">
        <label for="kontak_telepon">No. WA/telepon kontak</label>
        <input type="tel" id="kontak_telepon" name="kontak_telepon" value="<?= e($data['kontak_telepon']) ?>" placeholder="0812-xxxx-xxxx">
      </div>

      <div class="field">
        <label for="masa_aktif_sampai">Masa aktif sampai</label>
        <input type="date" id="masa_aktif_sampai" name="masa_aktif_sampai" value="<?= e($data['masa_aktif_sampai']) ?>">
        <span class="hint">Kosongkan bila tanpa batas. Setelah tanggal ini semua akun perusahaan tidak bisa login.</span>
      </div>
      <div class="field">
        <label for="maks_user">Kuota jumlah pengguna</label>
        <input type="number" id="maks_user" name="maks_user" value="<?= (int) $data['maks_user'] ?>" min="0" step="1">
        <span class="hint">0 = tanpa batas. Isi mis. 5 untuk paket maksimal 5 pengguna.</span>
      </div>

      <div class="field full">
        <label for="catatan">Catatan internal</label>
        <textarea id="catatan" name="catatan" placeholder="mis. langganan 1 tahun, sudah bayar, perpanjang Januari"><?= e($data['catatan']) ?></textarea>
      </div>

      <div class="field full">
        <label class="check" style="width:fit-content">
          <input type="checkbox" name="aktif" value="1"<?= $data['aktif'] === '1' ? ' checked' : '' ?>>
          <span>Perusahaan aktif (boleh login)</span>
        </label>
      </div>
    </div>
  </div>

  <?php if (!$row): ?>
    <div class="card">
      <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
        <div><h2>Akun Admin Pertama</h2><p>Untuk login pemilik perusahaan pelanggan</p></div>
      </div>
      <div class="form-grid">
        <div class="field">
          <label for="admin_nama">Nama lengkap <span class="muted">*</span></label>
          <input type="text" id="admin_nama" name="admin_nama" value="<?= e($adminBaru['nama']) ?>" placeholder="mis. Budi Santoso">
        </div>
        <div class="field">
          <label for="admin_username">Username <span class="muted">*</span></label>
          <input type="text" id="admin_username" name="admin_username" value="<?= e($adminBaru['username']) ?>" placeholder="mis. budi">
        </div>
        <div class="field">
          <label for="admin_password">Kata sandi <span class="muted">*</span></label>
          <input type="text" id="admin_password" name="admin_password" value="<?= e($adminBaru['password']) ?>" placeholder="minimal 6 karakter">
          <span class="hint">Kirimkan username &amp; kata sandi ini ke pelanggan Anda.</span>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= $row ? 'Simpan Perubahan' : 'Buat Perusahaan' ?></button>
    <a class="btn btn-ghost" href="perusahaan.php">Batal</a>
  </div>
</form>

<?php render_footer(); ?>
