<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$item = $id ? get_harga_satuan($id) : null;
if ($id && !$item) {
    flash('Item harga satuan tidak ditemukan.', 'err');
    redirect('harga_satuan.php');
}

$data = [
    'nama'        => $item['nama'] ?? '',
    'kategori'    => $item['kategori'] ?? '',
    'satuan'      => $item['satuan'] ?? '',
    'harga_jasa'  => (float) ($item['harga_jasa'] ?? 0) > 0 ? num($item['harga_jasa']) : '',
    'harga_upah'  => (float) ($item['harga_upah'] ?? 0) > 0 ? num($item['harga_upah']) : '',
    'keterangan'  => $item['keterangan'] ?? '',
    'aktif'       => (string) ($item['aktif'] ?? 1),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['nama', 'kategori', 'satuan', 'harga_jasa', 'harga_upah', 'keterangan'] as $k) {
        $data[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $data['aktif'] = isset($_POST['aktif']) ? '1' : '0';

    if ($data['nama'] === '') {
        $errors[] = 'Nama item pekerjaan wajib diisi.';
    }
    if (parse_money($data['harga_jasa']) < 0 || parse_money($data['harga_upah']) < 0) {
        $errors[] = 'Harga tidak boleh negatif.';
    }
    if (empty($errors)) {
        $st = db()->prepare('SELECT id FROM harga_satuan WHERE nama = ? AND id <> ? AND perusahaan_id = ?');
        $st->execute([$data['nama'], $id, tenant_id()]);
        if ($st->fetchColumn()) {
            $errors[] = 'Nama item sudah ada di master harga.';
        }
    }

    if (empty($errors)) {
        $args = [
            $data['nama'],
            $data['kategori'],
            $data['satuan'],
            parse_money($data['harga_jasa']),
            parse_money($data['harga_upah']),
            $data['keterangan'],
            (int) $data['aktif'],
        ];
        if ($item) {
            $args[] = $id;
            db()->prepare(
                'UPDATE harga_satuan SET nama=?, kategori=?, satuan=?, harga_jasa=?, harga_upah=?, keterangan=?, aktif=?
                 WHERE id=?'
            )->execute($args);
            flash('Item harga <strong>' . e($data['nama']) . '</strong> berhasil diperbarui.');
        } else {
            db()->prepare(
                'INSERT INTO harga_satuan (nama, kategori, satuan, harga_jasa, harga_upah, keterangan, aktif)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute($args);
            flash('Item harga <strong>' . e($data['nama']) . '</strong> berhasil ditambahkan.');
        }
        redirect('harga_satuan.php');
    }
}

render_header(
    $item ? 'Edit Item Harga' : 'Item Harga Baru',
    'Harga jasa ke perusahaan & upah dasar pekerja untuk satu item pekerjaan',
    '<a class="btn" href="harga_satuan.php">Kembali</a>'
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
      <label for="nama">Nama item pekerjaan <span class="muted">*</span></label>
      <input type="text" id="nama" name="nama" value="<?= e($data['nama']) ?>" placeholder="mis. Penarikan Kabel Listrik &amp; Data" required>
    </div>
    <div class="field">
      <label for="kategori">Kategori</label>
      <input type="text" id="kategori" name="kategori" value="<?= e($data['kategori']) ?>" list="kategori-list">
      <datalist id="kategori-list">
        <option value="Persiapan"></option><option value="Pembongkaran"></option>
        <option value="Struktur"></option><option value="Arsitektur"></option>
        <option value="MEP"></option><option value="Finishing"></option>
      </datalist>
    </div>
    <div class="field">
      <label for="satuan">Satuan</label>
      <input type="text" id="satuan" name="satuan" value="<?= e($data['satuan']) ?>" placeholder="m / m2 / titik / unit / ls" list="satuan-list">
      <datalist id="satuan-list">
        <option value="m"></option><option value="m2"></option><option value="m3"></option>
        <option value="titik"></option><option value="unit"></option><option value="ls"></option>
      </datalist>
    </div>
    <div class="field">
      <label>Selisih / margin per satuan</label>
      <div class="money-note" data-harga-selisih>—</div>
    </div>
    <div class="field">
      <label for="harga_jasa">Harga jasa ke perusahaan (Rp / satuan)</label>
      <input type="text" id="harga_jasa" name="harga_jasa" value="<?= e($data['harga_jasa']) ?>" placeholder="mis. 5.000" data-harga-jasa>
    </div>
    <div class="field">
      <label for="harga_upah">Upah dasar ke pekerja (Rp / satuan)</label>
      <input type="text" id="harga_upah" name="harga_upah" value="<?= e($data['harga_upah']) ?>" placeholder="mis. 2.500" data-harga-upah>
      <span class="hint">Boleh di-override per pekerja saat menugaskan ke sebuah pekerjaan.</span>
    </div>
    <div class="field full">
      <label for="keterangan">Keterangan</label>
      <textarea id="keterangan" name="keterangan" placeholder="Catatan harga, mis. sudah termasuk material / hanya jasa"><?= e($data['keterangan']) ?></textarea>
    </div>
    <div class="field full">
      <label class="check" style="width:fit-content">
        <input type="checkbox" name="aktif" value="1"<?= $data['aktif'] === '1' ? ' checked' : '' ?>>
        <span>Item aktif (bisa dipilih saat membuat pekerjaan)</span>
      </label>
    </div>
  </div>

  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit"><?= $item ? 'Simpan Perubahan' : 'Simpan Item Harga' ?></button>
    <a class="btn btn-ghost" href="harga_satuan.php">Batal</a>
  </div>
</form>

<?php render_footer(); ?>
