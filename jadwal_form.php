<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);

/* ---------- Project yang boleh dipakai ---------- */
$projectOptions = array_values(array_filter(selectable_projects(), fn($p) => can_manage_project($p)));
if (!$projectOptions) {
    flash('Belum ada project yang bisa kamu kelola untuk menyusun jadwal.', 'err');
    redirect('jadwal_harian.php');
}
$allowedIds = array_map(fn($p) => (int) $p['id'], $projectOptions);

/* ---------- Mode edit satu baris ---------- */
$editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editRow = null;
if ($editId) {
    $editRow = get_jadwal($editId);
    if (!$editRow || !can_manage_project(get_project((int) $editRow['project_id']))) {
        flash('Jadwal tidak ditemukan atau bukan bagian dari project yang kamu kelola.', 'err');
        redirect('jadwal_harian.php');
    }
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

// jadwal yang sudah ada pada tanggal itu (per pekerja)
$st = db()->prepare('SELECT id, user_id, project_id, pekerjaan_id FROM jadwal WHERE tanggal = ? AND perusahaan_id = ?');
$st->execute([$tanggal, tenant_id()]);
$sudah = [];
foreach ($st->fetchAll() as $r) {
    $sudah[(int) $r['user_id']] = $r;
}

$lokasiPilihan = lokasi_of_project($projectId);
$pekerjaanPilihan = fetch_pekerjaan(['project_id' => $projectId]);

$errors = $_SESSION['jadwal_errors'] ?? [];
unset($_SESSION['jadwal_errors']);

render_header(
    $editRow ? 'Edit Jadwal' : 'Susun Jadwal Harian',
    $editRow
        ? 'Ubah jadwal ' . e($editRow['pekerja_nama']) . ' pada ' . e(tgl($editRow['tanggal']))
        : 'Pilih tanggal & project, lalu centang tenaga yang bekerja hari itu',
    '<a class="btn" href="jadwal_harian.php?tanggal=' . e($tanggal) . '">Kembali</a>'
);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($editRow): ?>
  <!-- ============ Edit satu jadwal ============ -->
  <form method="post" action="jadwal_save.php" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">

    <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
      <?= badge_avatar($editRow['pekerja_nama']) ?>
      <div>
        <h2><?= e($editRow['pekerja_nama']) ?></h2>
        <p><?= e(skema_label((string) $editRow['pekerja_skema'])) ?> · <?= e($editRow['pekerja_telepon'] !== '' ? $editRow['pekerja_telepon'] : 'nomor WA belum diisi') ?></p>
      </div>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="tanggal">Tanggal <span class="muted">*</span></label>
        <input type="date" id="tanggal" name="tanggal" value="<?= e($editRow['tanggal']) ?>" required>
      </div>
      <div class="field">
        <label for="project_id">Project <span class="muted">*</span></label>
        <select id="project_id" name="project_id" data-project-select required>
          <?php foreach ($projectOptions as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= (int) $editRow['project_id'] === (int) $p['id'] ? ' selected' : '' ?>>
              <?= e($p['kode'] . ' · ' . $p['nama']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pekerjaan_id">Pekerjaan (opsional)</label>
        <select id="pekerjaan_id" name="pekerjaan_id">
          <option value="">— pekerja pilih saat lapor —</option>
          <?php foreach ($pekerjaanPilihan as $pj): ?>
            <option value="<?= (int) $pj['id'] ?>"<?= (int) $editRow['pekerjaan_id'] === (int) $pj['id'] ? ' selected' : '' ?>>
              <?= e($pj['nama']) ?><?= (float) $pj['harga_upah'] > 0 ? ' (' . e(rupiah($pj['harga_upah'])) . '/' . e($pj['satuan'] !== '' ? $pj['satuan'] : 'satuan') . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="lokasi_id">Lokasi (opsional)</label>
        <select id="lokasi_id" name="lokasi_id">
          <option value="">— tidak disebut —</option>
          <?php foreach ($lokasiPilihan as $l): ?>
            <option value="<?= (int) $l['id'] ?>"<?= (int) $editRow['lokasi_id'] === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field full">
        <label for="catatan">Catatan untuk pekerja</label>
        <textarea id="catatan" name="catatan" placeholder="mis. bawa alat sendiri, mulai jam 8 pagi"><?= e($editRow['catatan']) ?></textarea>
      </div>
    </div>

    <div class="form-actions" style="margin-top:18px">
      <button class="btn btn-primary" type="submit">Simpan Jadwal</button>
      <a class="btn btn-ghost" href="jadwal_harian.php?tanggal=<?= e($editRow['tanggal']) ?>">Batal</a>
    </div>
  </form>

<?php else: ?>
  <!-- ============ Susun jadwal massal ============ -->
  <form method="post" action="jadwal_save.php" class="card" data-jadwal-form>
    <?= csrf_field() ?>
    <input type="hidden" name="batch" value="1">

    <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
      <div><h2>1. Tanggal &amp; Project</h2><p>Semua tenaga yang dicentang dijadwalkan pada tanggal ini</p></div>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="tanggal">Tanggal kerja <span class="muted">*</span></label>
        <input type="date" id="tanggal" name="tanggal" value="<?= e($tanggal) ?>" required>
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
        <label for="lokasi_id">Lokasi (opsional)</label>
        <select id="lokasi_id" name="lokasi_id">
          <option value="">— tidak disebut —</option>
          <?php foreach ($lokasiPilihan as $l): ?>
            <option value="<?= (int) $l['id'] ?>"><?= e($l['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pekerjaan_id">Pekerjaan (opsional)</label>
        <select id="pekerjaan_id" name="pekerjaan_id">
          <option value="">— pekerja pilih saat lapor —</option>
          <?php foreach ($pekerjaanPilihan as $pj): ?>
            <option value="<?= (int) $pj['id'] ?>">
              <?= e($pj['nama']) ?><?= (float) $pj['harga_upah'] > 0 ? ' (' . e(rupiah($pj['harga_upah'])) . '/' . e($pj['satuan'] !== '' ? $pj['satuan'] : 'satuan') . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="card-head" style="padding:20px 0 16px;border-bottom:1px solid var(--line-soft);margin:20px 0 18px">
      <div><h2>2. Pilih Tenaga</h2><p>Skema upah tampil di samping nama sebagai pengingat</p></div>
      <span class="spacer"></span>
      <?php if ($sudah): ?>
        <span class="pill pill-tertunda"><?= count($sudah) ?> tenaga sudah punya jadwal pada <?= e(tgl($tanggal)) ?></span>
      <?php endif; ?>
    </div>

    <?php if (!$tim): ?>
      <p class="muted small">Belum ada akun tenaga aktif. Tambahkan lewat menu Pengguna &amp; Akun.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl absensi-tbl">
          <thead>
            <tr>
              <th style="width:44px"><input type="checkbox" data-check-all aria-label="Pilih semua"></th>
              <th>Tenaga</th>
              <th>Peran</th>
              <th>Skema upah</th>
              <th>Status tanggal ini</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($tim as $p): $pid = (int) $p['id']; $ada = $sudah[$pid] ?? null; ?>
            <tr>
              <td><input type="checkbox" name="pekerja[]" value="<?= $pid ?>" data-row-check<?= $ada ? ' disabled' : '' ?>></td>
              <td>
                <div style="display:flex;align-items:center;gap:9px">
                  <?= badge_avatar($p['nama'], 'sm') ?>
                  <div class="cell-stack">
                    <strong><?= e($p['nama']) ?></strong>
                    <small><?= e($p['jabatan'] !== '' ? $p['jabatan'] : '—') ?><?= $p['telepon'] !== '' ? ' · ' . e($p['telepon']) : ' · nomor WA belum diisi' ?></small>
                  </div>
                </div>
              </td>
              <td><span class="pill <?= $p['role'] === 'pelaksana' ? 'pill-proses' : 'pill-belum' ?>"><?= e(role_label($p['role'])) ?></span></td>
              <td>
                <span class="pill <?= $p['skema'] === 'borongan' ? 'pill-proses' : 'pill-belum' ?>"><?= e(skema_label((string) $p['skema'])) ?></span>
                <?php if ($p['skema'] === 'borongan'): ?>
                  <div class="small muted">upah dari volume yang dilaporkan</div>
                <?php else: ?>
                  <div class="small muted">upah harian <?= e(rupiah($p['upah_harian'])) ?></div>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ($ada): ?>
                  <span class="pill pill-selesai">Sudah dijadwalkan</span>
                <?php else: ?>
                  <span class="muted">Belum dijadwalkan</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="form-grid" style="margin-top:20px">
        <div class="field full">
          <label for="catatan">Catatan untuk pekerja</label>
          <textarea id="catatan" name="catatan" placeholder="mis. kerja mulai 08.00, bawa alat sendiri"><?= e((string) ($_POST['catatan'] ?? '')) ?></textarea>
        </div>
      </div>

      <div class="form-actions" style="margin-top:18px">
        <button class="btn btn-primary" type="submit">Simpan Jadwal</button>
        <a class="btn btn-ghost" href="jadwal_harian.php">Batal</a>
        <span class="spacer" style="margin-left:auto"></span>
        <span class="muted small" data-jadwal-info>Pilih minimal satu tenaga.</span>
      </div>
    <?php endif; ?>
  </form>
<?php endif; ?>

<?php render_footer(); ?>
