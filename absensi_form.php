<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);

/* ---------- Project yang boleh dipakai ---------- */
$projectOptions = array_values(array_filter(selectable_projects(), fn($p) => can_manage_project($p)));
if (!$projectOptions) {
    flash('Belum ada project yang bisa kamu kelola untuk mencatat absensi.', 'err');
    redirect('absensi.php');
}
$allowedIds = array_map(fn($p) => (int) $p['id'], $projectOptions);

/* ---------- Mode ---------- */
$editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editRow = $editId ? get_absensi($editId) : null;
if ($editId && (!$editRow || !can_record_absensi(get_project((int) $editRow['project_id'])))) {
    flash('Catatan absensi tidak ditemukan atau bukan bagian dari project yang kamu kelola.', 'err');
    redirect('absensi.php');
}

$tanggal = valid_tanggal((string) ($_GET['tanggal'] ?? $_POST['tanggal'] ?? '')) ?: ($editRow['tanggal'] ?? date('Y-m-d'));
$projectId = (int) ($_GET['project_id'] ?? $_POST['project_id'] ?? $editRow['project_id'] ?? 0);
if ($projectId && !in_array($projectId, $allowedIds, true)) {
    $projectId = 0;
}
if (!$projectId) {
    $projectId = (int) $projectOptions[0]['id'];
}

/* ---------- Data pendukung ---------- */
$tim = array_values(array_filter(all_users(), fn($x) => $x['role'] !== 'admin' && (int) $x['aktif'] === 1));

// siapa saja yang sudah punya catatan pada tanggal ini
$st = db()->prepare('SELECT user_id, hari, upah FROM absensi WHERE tanggal = ? AND perusahaan_id = ?');
$st->execute([$tanggal, tenant_id()]);
$sudah = [];
foreach ($st->fetchAll() as $r) {
    $sudah[(int) $r['user_id']] = $r;
}

// pekerjaan pada project terpilih (untuk menandai absensi dikaitkan ke pekerjaan mana)
$pekerjaanPilihan = [];
foreach (fetch_pekerjaan(['project_id' => $projectId]) as $pj) {
    $pekerjaanPilihan[] = $pj;
}

$errors = $_SESSION['absensi_errors'] ?? [];
unset($_SESSION['absensi_errors']);

$editPekerja = null;
if ($editRow) {
    $stp = db()->prepare('SELECT nama, jabatan, role FROM users WHERE id = ? AND perusahaan_id = ?');
    $stp->execute([(int) $editRow['user_id'], tenant_id()]);
    $editPekerja = $stp->fetch() ?: null;
}

render_header(
    $editRow ? 'Edit Absensi' : 'Catat Kehadiran',
    $editRow
        ? 'Ubah jumlah hari kerja atau tarif harian pada catatan ini'
        : 'Tandai pekerja yang masuk kerja pada satu tanggal sekaligus',
    '<a class="btn" href="absensi.php">Kembali</a>'
);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($editRow): ?>
  <!-- ============ Mode edit satu catatan ============ -->
  <form method="post" action="absensi_save.php" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">

    <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
      <?= badge_avatar($editPekerja['nama'] ?? '—') ?>
      <div>
        <h2><?= e($editPekerja['nama'] ?? 'Pekerja tidak ditemukan') ?></h2>
        <p><?= e($editPekerja['jabatan'] ?? '') ?> · catatan kehadiran tanggal <?= e(tgl($editRow['tanggal'])) ?></p>
      </div>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="tanggal">Tanggal</label>
        <input type="date" id="tanggal" name="tanggal" value="<?= e($editRow['tanggal']) ?>" required>
      </div>
      <div class="field">
        <label for="project_id">Project</label>
        <select id="project_id" name="project_id">
          <?php foreach ($projectOptions as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= (int) $editRow['project_id'] === (int) $p['id'] ? ' selected' : '' ?>>
              <?= e($p['kode'] . ' · ' . $p['nama']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="hari">Jumlah hari</label>
        <select id="hari" name="hari">
          <?php foreach ([0.5, 1, 1.5, 2, 3] as $h): ?>
            <option value="<?= e((string) $h) ?>"<?= (float) $editRow['hari'] === (float) $h ? ' selected' : '' ?>><?= e(hari_format($h)) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Pilih 0,5 hari bila pekerja masuk setengah hari.</span>
      </div>
      <div class="field">
        <label for="upah">Tarif upah per hari (Rp)</label>
        <input type="text" id="upah" name="upah" value="<?= e(num($editRow['upah'])) ?>">
        <span class="hint">Tarif yang berlaku pada tanggal ini. Tarif default diambil dari data pekerja.</span>
      </div>
      <div class="field full">
        <label for="pekerjaan_id">Kaitkan ke pekerjaan (opsional)</label>
        <select id="pekerjaan_id" name="pekerjaan_id">
          <option value="">— tidak dikaitkan —</option>
          <?php foreach ($pekerjaanPilihan as $pj): ?>
            <option value="<?= (int) $pj['id'] ?>"<?= (int) $editRow['pekerjaan_id'] === (int) $pj['id'] ? ' selected' : '' ?>><?= e($pj['nama']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Bila diisi, hari kerja ini ikut tampil di halaman pekerjaan tersebut.</span>
      </div>
      <div class="field full">
        <label for="keterangan">Keterangan</label>
        <input type="text" id="keterangan" name="keterangan" value="<?= e($editRow['keterangan']) ?>" placeholder="mis. lembur pasang rangka">
      </div>
    </div>

    <div class="form-actions" style="margin-top:18px">
      <button class="btn btn-primary" type="submit">Simpan Perubahan</button>
      <a class="btn btn-ghost" href="absensi.php">Batal</a>
    </div>
  </form>

<?php else: ?>
  <!-- ============ Mode input massal ============ -->
  <form method="post" action="absensi_save.php" class="card" data-batch-absensi>
    <?= csrf_field() ?>
    <input type="hidden" name="batch" value="1">

    <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
      <div><h2>1. Pilih Tanggal &amp; Project</h2><p>Semua pekerja yang dicentang akan dicatat pada tanggal ini</p></div>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="tanggal">Tanggal kerja <span class="muted">*</span></label>
        <input type="date" id="tanggal" name="tanggal" value="<?= e($tanggal) ?>" required data-absensi-tanggal>
      </div>
      <div class="field">
        <label for="project_id">Project <span class="muted">*</span></label>
        <select id="project_id" name="project_id" required>
          <?php foreach ($projectOptions as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= $projectId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['kode'] . ' · ' . $p['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pekerjaan_id">Kaitkan ke pekerjaan (opsional)</label>
        <select id="pekerjaan_id" name="pekerjaan_id">
          <option value="">— tidak dikaitkan —</option>
          <?php foreach ($pekerjaanPilihan as $pj): ?>
            <option value="<?= (int) $pj['id'] ?>"><?= e($pj['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="hari_default">Jumlah hari default</label>
        <select id="hari_default" data-hari-default>
          <?php foreach ([1, 0.5, 1.5, 2] as $h): ?>
            <option value="<?= e((string) $h) ?>"<?= $h === 1 ? ' selected' : '' ?>><?= e(hari_format($h)) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Dipakai untuk semua pekerja; bisa diubah per orang di bawah.</span>
      </div>
    </div>

    <div class="card-head" style="padding:20px 0 16px;border-bottom:1px solid var(--line-soft);margin:20px 0 18px">
      <div><h2>2. Tandai Pekerja yang Masuk</h2><p>Centang beberapa pekerja sekaligus, lalu isi hari &amp; tarif hariannya</p></div>
      <span class="spacer"></span>
      <?php if ($sudah): ?>
        <span class="pill pill-tertunda"><?= count($sudah) ?> pekerja sudah tercatat pada <?= e(tgl($tanggal)) ?></span>
      <?php endif; ?>
    </div>

    <?php if (!$tim): ?>
      <p class="muted small">Belum ada akun pekerja/pelaksana aktif. Tambahkan lewat menu Pengguna &amp; Akun.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl absensi-tbl">
          <thead>
            <tr>
              <th style="width:44px"><input type="checkbox" data-check-all aria-label="Pilih semua"></th>
              <th>Pekerja</th>
              <th>Peran</th>
              <th>Hari</th>
              <th>Tarif / hari (Rp)</th>
              <th>Status tanggal ini</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($tim as $p): ?>
            <?php
            $pid = (int) $p['id'];
            $adaSudah = $sudah[$pid] ?? null;
            $tarif = (float) $p['upah_harian'];
            ?>
            <tr>
              <td>
                <input type="checkbox" name="pekerja[]" value="<?= $pid ?>" data-row-check
                       <?= $adaSudah ? 'checked disabled' : '' ?>>
              </td>
              <td>
                <div style="display:flex;align-items:center;gap:9px">
                  <?= badge_avatar($p['nama'], 'sm') ?>
                  <div class="cell-stack">
                    <strong><?= e($p['nama']) ?></strong>
                    <small><?= e($p['jabatan'] !== '' ? $p['jabatan'] : '—') ?></small>
                  </div>
                </div>
              </td>
              <td><span class="pill <?= $p['role'] === 'pelaksana' ? 'pill-proses' : 'pill-belum' ?>"><?= e(role_label($p['role'])) ?></span></td>
              <td>
                <select name="hari[<?= $pid ?>]"<?= $adaSudah ? ' disabled' : '' ?>>
                  <?php foreach ([0.5, 1, 1.5, 2, 3] as $h): ?>
                    <option value="<?= e((string) $h) ?>"<?= $h === 1 ? ' selected' : '' ?>><?= e(hari_format($h)) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <input type="text" name="upah[<?= $pid ?>]" value="<?= e(num($tarif)) ?>" data-upah-input<?= $adaSudah ? ' disabled' : '' ?>>
                <?php if ($tarif <= 0): ?>
                  <span class="hint deadline-soon">Tarif belum diisi — set di data pekerja.</span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ($adaSudah): ?>
                  <span class="pill pill-selesai">Sudah: <?= e(hari_format($adaSudah['hari'])) ?></span>
                <?php else: ?>
                  <span class="muted">Belum tercatat</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="form-actions" style="margin-top:20px">
        <button class="btn btn-primary" type="submit">Simpan Absensi</button>
        <a class="btn btn-ghost" href="absensi.php">Batal</a>
        <span class="spacer" style="margin-left:auto"></span>
        <span class="muted small" data-absensi-info>Pilih minimal satu pekerja.</span>
      </div>
    <?php endif; ?>
  </form>
<?php endif; ?>

<?php render_footer(); ?>
