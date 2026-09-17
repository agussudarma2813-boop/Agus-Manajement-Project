<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/items_form.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$project = $id ? get_project($id) : null;
if ($id && !$project) {
    flash('Project tidak ditemukan.', 'err');
    redirect('projects.php');
}

$data = [
    'kode'           => $project['kode'] ?? '',
    'nama'           => $project['nama'] ?? '',
    'pelaksana_id'   => (string) ($project['pelaksana_id'] ?? ''),
    'mulai'          => $project['mulai'] ?? date('Y-m-d'),
    'target_selesai' => $project['target_selesai'] ?? '',
    'status'         => $project['status'] ?? 'perencanaan',
    'anggaran'       => (string) ($project['anggaran'] ?? ''),
    'keterangan'     => $project['keterangan'] ?? '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($data as $k => $_) {
        $data[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    if ($data['nama'] === '') {
        $errors[] = 'Nama project wajib diisi.';
    }
    if ($data['kode'] === '') {
        $errors[] = 'Kode project wajib diisi.';
    }
    if (!array_key_exists($data['status'], STATUS_PROJECT)) {
        $data['status'] = 'perencanaan';
    }
    if ($data['mulai'] !== '' && $data['target_selesai'] !== '' && $data['target_selesai'] < $data['mulai']) {
        $errors[] = 'Target selesai tidak boleh lebih awal dari tanggal mulai.';
    }
    if (empty($errors)) {
        $st = db()->prepare('SELECT id FROM projects WHERE kode = ? AND id <> ?');
        $st->execute([$data['kode'], $id]);
        if ($st->fetchColumn()) {
            $errors[] = 'Kode project sudah dipakai project lain.';
        }
    }

    if (empty($errors)) {
        $args = [
            $data['kode'],
            $data['nama'],
            $data['pelaksana_id'] !== '' ? (int) $data['pelaksana_id'] : null,
            $data['mulai'],
            $data['target_selesai'],
            $data['status'],
            parse_money($data['anggaran']),
            $data['keterangan'],
        ];
        if ($id) {
            $args[] = $id;
            db()->prepare(
                'UPDATE projects SET kode=?, nama=?, pelaksana_id=?, mulai=?, target_selesai=?, status=?, anggaran=?, keterangan=?
                 WHERE id=?'
            )->execute($args);
            flash('Project <strong>' . e($data['nama']) . '</strong> berhasil diperbarui.');
        } else {
            db()->prepare(
                'INSERT INTO projects (kode, nama, pelaksana_id, mulai, target_selesai, status, anggaran, keterangan, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([...$args, (int) $u['id']]);
            $id = (int) db()->lastInsertId();

            $hasil = simpan_item_batch($id, $_POST, (int) $u['id']);
            $pesan = 'Project <strong>' . e($data['nama']) . '</strong> berhasil dibuat.';
            if ($hasil['dibuat'] > 0) {
                $pesan .= ' <strong>' . $hasil['dibuat'] . ' item pekerjaan</strong> sekaligus tersimpan'
                    . ' (' . e(implode(', ', array_slice($hasil['nama'], 0, 3)))
                    . (count($hasil['nama']) > 3 ? ', dll' : '') . ').';
            } else {
                $pesan .= ' Belum ada item pekerjaan — bisa ditambahkan lewat tombol "Input Massal Volume" di halaman project.';
            }
            flash($pesan);
        }
        redirect('project_detail.php?id=' . $id);
    }
}

render_header(
    $project ? 'Edit Project' : 'Project Baru',
    'Lengkapi data project, tetapkan pelaksana penanggung jawab',
    '<a class="btn" href="projects.php">Kembali</a>'
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
      <label for="kode">Kode project <span class="muted">*</span></label>
      <input type="text" id="kode" name="kode" value="<?= e($data['kode']) ?>" placeholder="PRJ-2026-003" required>
    </div>
    <div class="field">
      <label for="nama">Nama project <span class="muted">*</span></label>
      <input type="text" id="nama" name="nama" value="<?= e($data['nama']) ?>" placeholder="mis. Renovasi Gedung Kantor B" required>
    </div>

    <div class="field">
      <label for="pelaksana_id">Pelaksana penanggung jawab</label>
      <select id="pelaksana_id" name="pelaksana_id">
        <option value="">— belum ditetapkan —</option>
        <?php foreach (selectable_pelaksana() as $pl): ?>
          <option value="<?= (int) $pl['id'] ?>"<?= $data['pelaksana_id'] === (string) $pl['id'] ? ' selected' : '' ?>>
            <?= e($pl['nama']) ?><?= $pl['jabatan'] ? ' — ' . e($pl['jabatan']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="hint">Pelaksana ini yang berhak mengelola pekerjaan &amp; lokasi di dalam project.</span>
    </div>
    <div class="field">
      <label for="status">Status project</label>
      <select id="status" name="status">
        <?php foreach (STATUS_PROJECT as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $data['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="mulai">Tanggal mulai</label>
      <input type="date" id="mulai" name="mulai" value="<?= e($data['mulai']) ?>">
    </div>
    <div class="field">
      <label for="target_selesai">Target selesai</label>
      <input type="date" id="target_selesai" name="target_selesai" value="<?= e($data['target_selesai']) ?>">
    </div>

    <div class="field">
      <label for="anggaran">Nilai anggaran (Rp)</label>
      <input type="text" id="anggaran" name="anggaran" value="<?= e($data['anggaran']) ?>" placeholder="mis. 850000000">
      <span class="hint">Isi angka saja, tanpa titik/koma.</span>
    </div>

    <div class="field full">
      <label for="keterangan">Keterangan</label>
      <textarea id="keterangan" name="keterangan" placeholder="Lingkup pekerjaan, catatan penting, dsb."><?= e($data['keterangan']) ?></textarea>
    </div>
  </div>
  <?php if (!$project): ?>
    <div class="editor-section">
      <div class="card-head" style="padding:22px 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div>
          <h2>Daftar Jenis Pekerjaan &amp; Volume</h2>
          <p>Pilih beberapa jenis pekerjaan sekaligus lalu isi volumenya — itemnya otomatis dibuat untuk project ini</p>
        </div>
      </div>
      <p class="small muted" style="margin:0 0 14px">
        Contoh: Penarikan Kabel 250 m, Pemasangan Unit 12 titik, Terminasi 24 titik, Pasang Panel 3 unit.
        Pilihan diambil dari <a href="harga_satuan.php">Master Harga Satuan</a> (harga jasa &amp; upah terisi otomatis),
        tapi nama manual juga boleh.
      </p>
      <?php render_items_editor([
          'lokasi' => [],
          'pelaksana' => selectable_pelaksana(),
          'baris_kosong' => 5,
          'nilai' => $_POST,
      ]); ?>
    </div>
  <?php else: ?>
    <div class="alert alert-info" style="margin-top:18px">
      Ingin menambah/mengubah banyak item beserta volumenya? Buka
      <a href="pekerjaan_batch.php?project_id=<?= (int) $id ?>">Input Massal Item &amp; Volume</a>.
    </div>
  <?php endif; ?>

  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit"><?= $project ? 'Simpan Perubahan' : 'Buat Project' ?></button>
    <a class="btn btn-ghost" href="<?= $project ? 'project_detail.php?id=' . (int) $id : 'projects.php' ?>">Batal</a>
  </div>
</form>

<?php if ($project): ?>
  <div class="card">
    <h2 class="card-title">Hapus project</h2>
    <p class="muted small" style="margin:8px 0 14px">
      Menghapus project akan menghapus seluruh lokasi, pekerjaan, penugasan pekerja dan riwayat progress di dalamnya.
      Tindakan ini tidak bisa dibatalkan.
    </p>
    <form method="post" action="project_delete.php" data-confirm="Hapus project &quot;<?= e($project['nama']) ?>&quot; beserta seluruh lokasi dan pekerjaannya?">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <button class="btn btn-danger" type="submit">Hapus Project Ini</button>
    </form>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
