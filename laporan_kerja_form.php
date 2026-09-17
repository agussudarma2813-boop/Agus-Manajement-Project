<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$bolehAtur = in_array($u['role'], ['admin', 'pelaksana'], true);

/** Satuan sebuah pekerjaan (untuk tampilan) */
function satuan_pekerjaan(int $id): string
{
    if (!$id) {
        return '';
    }
    $st = db()->prepare('SELECT satuan FROM pekerjaan WHERE id = ? AND perusahaan_id = ?');
    $st->execute([$id, tenant_id()]);
    return (string) ($st->fetchColumn() ?: '');
}

/* ---------- Mode edit ---------- */
$editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editRow = null;
if ($editId) {
    $editRow = get_laporan($editId);
    if (!$editRow) {
        flash('Laporan hasil kerja tidak ditemukan.', 'err');
        redirect('laporan_kerja.php');
    }
    if (!$bolehAtur && (int) $editRow['user_id'] !== (int) $u['id']) {
        flash('Kamu hanya bisa mengubah laporan hasil kerja milikmu.', 'err');
        redirect('laporan_kerja.php');
    }
    if ($bolehAtur && !can_manage_pekerjaan(get_pekerjaan((int) $editRow['pekerjaan_id']))) {
        flash('Kamu hanya bisa mengubah laporan pada project yang kamu kelola.', 'err');
        redirect('laporan_kerja.php');
    }
}

/* ---------- Konteks jadwal / tenaga ---------- */
$jadwalId = (int) ($_GET['jadwal_id'] ?? $_POST['jadwal_id'] ?? ($editRow['jadwal_id'] ?? 0));
$jadwal = $jadwalId ? get_jadwal($jadwalId) : null;

if ($bolehAtur && $jadwal) {
    $userId = (int) $jadwal['user_id'];
} elseif ($bolehAtur) {
    $userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? ($editRow['user_id'] ?? 0));
} else {
    $userId = (int) $u['id'];
}
if (!$userId) {
    $userId = (int) $u['id'];
}

$st = db()->prepare('SELECT * FROM users WHERE id = ? AND perusahaan_id = ?');
$st->execute([$userId, tenant_id()]);
$pekerja = $st->fetch();
if (!$pekerja) {
    flash('Data tenaga tidak ditemukan.', 'err');
    redirect('jadwal_harian.php');
}
if (!$bolehAtur && (int) $pekerja['id'] !== (int) $u['id']) {
    flash('Kamu hanya bisa mengisi hasil kerja atas namamu sendiri.', 'err');
    redirect('jadwal_harian.php');
}

$tanggal = valid_tanggal((string) ($_GET['tanggal'] ?? $_POST['tanggal'] ?? ''))
    ?: ($jadwal['tanggal'] ?? ($editRow['tanggal'] ?? date('Y-m-d')));
$projectId = (int) ($jadwal['project_id'] ?? ($editRow['project_id'] ?? ($_GET['project_id'] ?? 0)));

/* ---------- Project yang boleh dipilih ---------- */
if ($bolehAtur) {
    $projectOptions = array_values(array_filter(selectable_projects(), fn($p) => can_manage_project($p)));
} else {
    // pekerja: project dari jadwalnya + project tempat dia ditugaskan
    $map = [];
    foreach (selectable_projects() as $p) {
        $map[(int) $p['id']] = $p;
    }
    $st = db()->prepare(
        'SELECT DISTINCT p.* FROM jadwal j JOIN projects p ON p.id = j.project_id
         WHERE j.user_id = ? AND p.perusahaan_id = ? ORDER BY p.nama'
    );
    $st->execute([(int) $u['id'], tenant_id()]);
    foreach ($st->fetchAll() as $p) {
        $map[(int) $p['id']] = $p;
    }
    $projectOptions = array_values($map);
}
if (!$projectOptions) {
    flash('Belum ada project yang bisa kamu pakai. Hubungi admin/pelaksana.', 'err');
    redirect('jadwal_harian.php');
}

$cocok = fn(int $id) => (bool) array_filter($projectOptions, fn($p) => (int) $p['id'] === $id);
if ($projectId && !$cocok($projectId)) {
    $projectId = 0;
}
if (!$projectId) {
    $projectId = (int) $projectOptions[0]['id'];
}

// semua_item = true -> seluruh item project bisa dipilih (bukan hanya yang ditugaskan),
// karena sehari bisa 4 macam pekerjaan: narik kabel + pasang unit + terminasi + panel.
$opsiItem = opsi_pekerjaan_laporan($projectId, (int) $pekerja['id']);
$pekerjaanPilihan = $opsiItem['items'];
$masterPilihan = $opsiItem['master'];
$pekerjaanId = (int) ($jadwal['pekerjaan_id'] ?? ($editRow['pekerjaan_id'] ?? ($_GET['pekerjaan_id'] ?? 0)));
if ($pekerjaanId && !array_filter($pekerjaanPilihan, fn($p) => (int) $p['id'] === $pekerjaanId)) {
    $pekerjaanId = 0;
}
// Jangan paksa pilih item pertama: biarkan pekerja memilih sendiri (default: yang ada di jadwal).

$satuanTampil = satuan_pekerjaan($pekerjaanId);
$tarifDefault = 0.0;
if ($pekerjaanId) {
    $pjSel = get_pekerjaan($pekerjaanId);
    if ($pjSel) {
        $tarifDefault = tarif_upah($pjSel, (int) $pekerja['id']);
    }
}

$errors = $_SESSION['laporan_errors'] ?? [];
unset($_SESSION['laporan_errors']);

$isBorongan = (string) $pekerja['skema'] === 'borongan';

render_header(
    $editRow ? 'Edit Hasil Kerja' : ((int) $pekerja['id'] !== (int) $u['id'] ? 'Input Hasil Kerja (atas nama)' : 'Input Hasil Kerja'),
    $pekerja['nama'] . ' · ' . e(skema_label((string) $pekerja['skema'])) . ' · ' . e(tgl($tanggal)),
    '<a class="btn" href="jadwal_harian.php?tanggal=' . e($tanggal) . '">Jadwal Harian</a>'
);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="alert alert-info">
  <?php if ($isBorongan): ?>
    <strong>Upah borongan</strong> — volume yang dilaporkan langsung dikalikan tarif, mis.
    <span class="mono">30 × <?= e(rupiah($tarifDefault > 0 ? $tarifDefault : 2500)) ?> = <?= e(rupiah(30 * ($tarifDefault > 0 ? $tarifDefault : 2500))) ?></span>,
    dan langsung masuk ke rekap gaji. Volume ini juga jadi dasar tagihan ke perusahaan.
  <?php else: ?>
    <strong>Upah harian</strong> — hasil kerja ini dipakai sebagai dasar tagihan ke perusahaan.
    Gaji dihitung harian, dan absensi hari ini otomatis tercatat saat disimpan.
  <?php endif; ?>
</div>

<form method="post" action="laporan_kerja_save.php" class="card" data-laporan-form
      data-skema="<?= e((string) $pekerja['skema']) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $editId ?>">
  <input type="hidden" name="jadwal_id" value="<?= (int) $jadwalId ?>">
  <input type="hidden" name="user_id" value="<?= (int) $pekerja['id'] ?>">
  <input type="hidden" name="project_id" value="<?= (int) $projectId ?>">

  <div class="form-grid">
    <div class="field">
      <label>Tenaga</label>
      <input type="text" value="<?= e($pekerja['nama']) ?> · <?= e(skema_label((string) $pekerja['skema'])) ?>" disabled>
    </div>

    <div class="field">
      <label for="tanggal">Tanggal kerja</label>
      <input type="date" id="tanggal" name="tanggal" value="<?= e($tanggal) ?>" required>
    </div>

    <div class="field">
      <label for="project_pilih">Project</label>
      <select id="project_pilih" data-project-pilih<?= $bolehAtur ? '' : ' disabled' ?>>
        <?php foreach ($projectOptions as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= $projectId === (int) $p['id'] ? ' selected' : '' ?>>
            <?= e($p['kode'] . ' · ' . $p['nama']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!$bolehAtur): ?>
        <span class="hint">Project terkunci sesuai jadwal yang diberikan.</span>
      <?php endif; ?>
    </div>

    <div class="field">
      <label>Jumlah item dilaporkan</label>
      <div class="money-note" data-laporan-total>Belum ada volume diisi.</div>
    </div>
  </div>

  <div class="card-head" style="padding:22px 0 14px;border-bottom:1px solid var(--line-soft);margin:18px 0 16px">
    <div>
      <h2><?= $editRow ? 'Pekerjaan yang Dikerjakan' : 'Pekerjaan yang Dikerjakan Hari Ini' ?></h2>
      <p>
        <?= $editRow
              ? 'Ubah pekerjaan atau volumenya di sini'
              : 'Bisa diisi lebih dari satu baris — mis. penarikan kabel, pasang unit, terminasi, pasang panel sekaligus' ?>
      </p>
    </div>
  </div>

  <div class="laporan-rows" data-laporan-rows>
    <?php
    $barisAwal = $editRow
        ? [[
            'pekerjaan_id' => (int) $editRow['pekerjaan_id'],
            'volume' => num($editRow['volume']),
            'keterangan' => (string) $editRow['keterangan'],
        ]]
        : [[ 'pekerjaan_id' => $pekerjaanId, 'volume' => '', 'keterangan' => '' ]];
    foreach ($barisAwal as $b):
    ?>
      <div class="laporan-row" data-laporan-row>
        <div class="laporan-cell laporan-cell-item">
          <span class="laporan-label">Pekerjaan / item</span>
          <select name="<?= $editRow ? 'pekerjaan_id' : 'pekerjaan_id[]' ?>" required data-pekerjaan-select>
            <option value="">— pilih pekerjaan —</option>
            <?php if ($pekerjaanPilihan): ?>
              <optgroup label="Sudah ada di project ini">
                <?php foreach ($pekerjaanPilihan as $pj): ?>
                  <?php
                  $sisa = max(0, (float) $pj['volume'] - (float) $pj['volume_realisasi']);
                  $sudahLapor = volume_lapor((int) $pj['id'], (int) $pekerja['id']);
                  ?>
                  <option value="<?= (int) $pj['id'] ?>"
                          data-tarif="<?= e(num(tarif_upah($pj, (int) $pekerja['id']))) ?>"
                          data-satuan="<?= e($pj['satuan']) ?>"
                          data-terlapor="<?= e(num($sudahLapor)) ?>"
                          data-kontrak="<?= e(num($pj['volume'])) ?>"
                          data-sisa="<?= e(num($sisa)) ?>"
                          <?= (int) $b['pekerjaan_id'] === (int) $pj['id'] ? ' selected' : '' ?>>
                    <?= e($pj['nama']) ?> — <?= e(rupiah(tarif_upah($pj, (int) $pekerja['id']))) ?>/<?= e($pj['satuan'] !== '' ? $pj['satuan'] : 'satuan') ?>
                    (sisa <?= e(num($sisa)) ?> <?= e($pj['satuan']) ?>)
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>

            <?php if ($masterPilihan): ?>
              <optgroup label="Jenis pekerjaan lain (dari Master Harga — otomatis ditambahkan ke project)">
                <?php foreach ($masterPilihan as $hs): ?>
                  <option value="m<?= (int) $hs['id'] ?>"
                          data-tarif="<?= e(num($hs['harga_upah'])) ?>"
                          data-satuan="<?= e($hs['satuan']) ?>"
                          data-terlapor="0"
                          data-kontrak="0"
                          data-sisa="0"
                          data-master="<?= (int) $hs['id'] ?>">
                    <?= e($hs['nama']) ?> — <?= e(rupiah($hs['harga_upah'])) ?>/<?= e($hs['satuan'] !== '' ? $hs['satuan'] : 'satuan') ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
          <span class="hint" data-info-item>Pilih pekerjaan untuk melihat tarif &amp; sisa volume.</span>
        </div>

        <div class="laporan-cell">
          <span class="laporan-label">Volume</span>
          <div class="laporan-volume">
            <input type="text" name="volume[]" value="<?= e((string) $b['volume']) ?>" placeholder="mis. 30" required data-volume>
            <input type="text" value="" placeholder="satuan" disabled data-satuan-tampil>
          </div>
        </div>

        <div class="laporan-cell">
          <span class="laporan-label">Upah terhitung</span>
          <div class="money-note" data-upah-hitung>
            <?= $isBorongan ? 'Isi volume untuk melihat upah.' : 'Upah harian — untuk tagihan.' ?>
          </div>
        </div>

        <div class="laporan-cell">
          <span class="laporan-label">Keterangan</span>
          <input type="text" name="keterangan[]" value="<?= e((string) $b['keterangan']) ?>" placeholder="opsional" data-keterangan>
        </div>

        <?php if (!$editRow): ?>
          <div class="laporan-cell laporan-cell-aksi">
            <button class="btn btn-sm btn-danger" type="button" data-laporan-hapus aria-label="Hapus baris">×</button>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!$editRow): ?>
    <div class="laporan-foot">
      <button class="btn btn-sm" type="button" data-laporan-tambah>+ Tambah Pekerjaan Lain</button>
      <span class="muted small" data-laporan-info>
        Isi satu baris saja kalau hari itu hanya mengerjakan satu pekerjaan.
      </span>
    </div>
  <?php endif; ?>

  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit">Simpan Hasil Kerja</button>
    <a class="btn btn-ghost" href="jadwal_harian.php?tanggal=<?= e($tanggal) ?>">Batal</a>
  </div>
</form>

<div class="card">
  <h2 class="card-title">Apa yang terjadi setelah disimpan?</h2>
  <ul class="steps">
    <li>Volume laporan <strong>otomatis menambah volume akhir</strong> pekerjaan → langsung terhitung di nilai pengajuan ke perusahaan.</li>
    <?php if ($isBorongan): ?>
      <li>Karena skema kamu <strong>borongan</strong>: volume × tarif tercatat sebagai upah dan langsung masuk rekap gaji.</li>
    <?php else: ?>
      <li>Karena skema kamu <strong>harian</strong>: absensi tanggal ini otomatis tercatat (<?= e(rupiah($pekerja['upah_harian'])) ?>/hari) dan gaji dihitung dari absensi.</li>
    <?php endif; ?>
    <li>Kalau kamu belum tercatat sebagai pekerja pada item itu, sistem otomatis menambahkan penugasanmu agar upahnya terhitung.</li>
  </ul>
</div>

<?php render_footer(); ?>
