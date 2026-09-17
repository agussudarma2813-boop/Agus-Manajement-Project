<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$me = require_role(['admin']);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$person = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$id, tenant_id()]);
    $person = $st->fetch() ?: null;
    if (!$person) {
        flash('Pengguna tidak ditemukan.', 'err');
        redirect('users.php');
    }
}

$data = [
    'nama'     => $person['nama'] ?? '',
    'username' => $person['username'] ?? '',
    'role'     => $person['role'] ?? (in_array($_GET['role'] ?? '', ['admin', 'pelaksana', 'pekerja'], true) ? $_GET['role'] : 'pekerja'),
    'jabatan'  => $person['jabatan'] ?? '',
    'telepon'  => $person['telepon'] ?? '',
    'upah'     => (float) ($person['upah_harian'] ?? 0) > 0 ? num($person['upah_harian']) : '',
    'skema'    => skema_valid((string) ($person['skema'] ?? 'harian')),
    'aktif'    => (string) ($person['aktif'] ?? 1),
];
$password = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['nama', 'username', 'role', 'jabatan', 'telepon', 'upah', 'skema'] as $k) {
        $data[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $data['aktif'] = isset($_POST['aktif']) ? '1' : '0';
    $password = (string) ($_POST['password'] ?? '');

    if ($data['nama'] === '') {
        $errors[] = 'Nama lengkap wajib diisi.';
    }
    if ($data['username'] === '') {
        $errors[] = 'Username wajib diisi.';
    }
    if (!isset(ROLES[$data['role']])) {
        $errors[] = 'Peran tidak valid.';
    }
    if (!$person && strlen($password) < 6) {
        $errors[] = 'Kata sandi untuk akun baru minimal 6 karakter.';
    }
    // Batas jumlah user per perusahaan (kuota pelanggan)
    if (!$person) {
        $perusahaanSaya = tenant();
        if ($perusahaanSaya && tenant_batas_user($perusahaanSaya)) {
            $errors[] = 'Kuota jumlah pengguna perusahaan ini sudah penuh (maksimal '
                . (int) $perusahaanSaya['maks_user'] . ' pengguna). Hubungi pengelola aplikasi untuk menambah kuota.';
        }
    }
    if ($password !== '' && strlen($password) < 6) {
        $errors[] = 'Kata sandi minimal 6 karakter.';
    }
    if ($person && (int) $person['id'] === (int) $me['id'] && $data['role'] !== 'admin') {
        $errors[] = 'Kamu tidak dapat menurunkan peran akunmu sendiri.';
    }

    if (empty($errors)) {
        // username unik secara global (dipakai untuk login)
        $st = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
        $st->execute([$data['username'], $id]);
        if ($st->fetchColumn()) {
            $errors[] = 'Username sudah dipakai akun lain.';
        }
    }

    if (empty($errors)) {
        $upahHarian = max(0, parse_money($data['upah']));
        $data['skema'] = skema_valid($data['skema']);
        if ($person) {
            $args = [$data['nama'], $data['username'], $data['role'], $data['jabatan'], $data['telepon'], $upahHarian, $data['skema'], (int) $data['aktif']];
            $sql = 'UPDATE users SET nama=?, username=?, role=?, jabatan=?, telepon=?, upah_harian=?, skema=?, aktif=?';
            if ($password !== '') {
                $sql .= ', password_hash=?';
                $args[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id=?';
            $args[] = $id;
            db()->prepare($sql)->execute($args);
            flash('Akun <strong>' . e($data['nama']) . '</strong> berhasil diperbarui.');
        } else {
            db()->prepare(
                'INSERT INTO users (nama, username, password_hash, role, jabatan, telepon, upah_harian, skema, aktif)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $data['nama'], $data['username'], password_hash($password, PASSWORD_DEFAULT),
                $data['role'], $data['jabatan'], $data['telepon'], $upahHarian, $data['skema'], (int) $data['aktif'],
            ]);
            $id = (int) db()->lastInsertId();
            flash('Akun <strong>' . e($data['nama']) . '</strong> berhasil dibuat.');
        }
        redirect($data['role'] === 'admin' ? 'users.php' : 'tim.php?role=' . $data['role']);
    }
}

render_header(
    $person ? 'Edit Pengguna' : 'Pengguna Baru',
    'Tentukan peran akun: admin, pelaksana atau pekerja',
    '<a class="btn" href="users.php">Kembali</a>'
);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">

  <div class="form-grid">
    <div class="field">
      <label for="nama">Nama lengkap <span class="muted">*</span></label>
      <input type="text" id="nama" name="nama" value="<?= e($data['nama']) ?>" required>
    </div>
    <div class="field">
      <label for="username">Username <span class="muted">*</span></label>
      <input type="text" id="username" name="username" value="<?= e($data['username']) ?>" required>
    </div>

    <div class="field">
      <label for="role">Peran</label>
      <select id="role" name="role">
        <?php foreach (ROLES as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $data['role'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint">Pelaksana mengelola pekerjaan project yang dipimpinnya; pekerja hanya melihat &amp; memperbarui tugasnya.</span>
    </div>
    <div class="field">
      <label for="jabatan">Jabatan / keahlian</label>
      <input type="text" id="jabatan" name="jabatan" value="<?= e($data['jabatan']) ?>" placeholder="mis. Tukang Kayu / Pelaksana Lapangan">
    </div>

    <div class="field">
      <label for="telepon">No. telepon</label>
      <input type="tel" id="telepon" name="telepon" value="<?= e($data['telepon']) ?>" placeholder="0812-xxxx-xxxx">
    </div>
    <div class="field">
      <label for="upah">Upah harian (Rp)</label>
      <input type="text" id="upah" name="upah" value="<?= e($data['upah']) ?>" placeholder="mis. 175.000">
      <span class="hint">Tarif per hari. Dipakai otomatis saat mencatat absensi; kosongkan bila digaji borongan saja.</span>
    </div>
    <div class="field">
      <label for="skema">Skema upah</label>
      <select id="skema" name="skema">
        <?php foreach (['harian' => 'Harian (gaji per hari kerja)', 'borongan' => 'Borongan (upah dari volume hasil kerja)'] as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $data['skema'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint">
        <strong>Harian</strong>: gaji = hari kerja × upah harian, hasil kerjanya jadi dasar tagihan ke perusahaan.
        <strong>Borongan</strong>: gaji = volume hasil kerja × tarif per satuan.
      </span>
    </div>
    <div class="field">
      <label for="password">Kata sandi <?= $person ? '(biarkan kosong bila tidak diganti)' : '<span class="muted">*</span>' ?></label>
      <input type="password" id="password" name="password" autocomplete="new-password" placeholder="minimal 6 karakter">
    </div>

    <div class="field full">
      <label class="check" style="width:fit-content">
        <input type="checkbox" name="aktif" value="1"<?= $data['aktif'] === '1' ? ' checked' : '' ?>>
        <span>Akun aktif (bisa login)</span>
      </label>
    </div>
  </div>

  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit"><?= $person ? 'Simpan Perubahan' : 'Buat Akun' ?></button>
    <a class="btn btn-ghost" href="users.php">Batal</a>
  </div>
</form>

<?php if ($person && (int) $person['id'] !== (int) $me['id']): ?>
  <div class="card">
    <h2 class="card-title">Hapus akun</h2>
    <p class="muted small" style="margin:8px 0 14px">
      Menghapus akun akan menghapus penugasan pekerja tersebut. Pekerjaan yang pernah dia kerjakan tetap ada.
    </p>
    <form method="post" action="user_delete.php" data-confirm="Hapus akun &quot;<?= e($person['nama']) ?>&quot;?">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <button class="btn btn-danger" type="submit">Hapus Akun</button>
    </form>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
