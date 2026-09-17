<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/partials.php';

$u = require_login();

$f = [
    'q'            => trim((string) ($_GET['q'] ?? '')),
    'project_id'   => (int) ($_GET['project_id'] ?? 0),
    'lokasi_id'    => (int) ($_GET['lokasi_id'] ?? 0),
    'status'       => (string) ($_GET['status'] ?? ''),
    'pelaksana_id' => (int) ($_GET['pelaksana_id'] ?? 0),
    'pekerja_id'   => (int) ($_GET['pekerja_id'] ?? 0),
];
if (!isset(STATUS_PEKERJAAN[$f['status']]) && $f['status'] !== 'terlambat') {
    $f['status'] = '';
}

$list = fetch_pekerjaan($f);
$lokasiFilter = $f['project_id'] ? lokasi_of_project($f['project_id']) : [];

$countBerjalan = count_pekerjaan(array_merge($f, ['status' => 'proses']));
$countSelesai = count_pekerjaan(array_merge($f, ['status' => 'selesai']));
$countTerlambat = count_pekerjaan(array_merge($f, ['status' => 'terlambat']));
$avg = avg_progress($f);
$base = array_diff_key($f, ['status' => 1]);

$actions = '';
if ($u['role'] !== 'pekerja') {
    $actions .= '<a class="btn" href="laporan.php">Laporan</a>';
    $actions .= '<a class="btn" href="project_detail.php?id=' . ($f['project_id'] ?: (int) (selectable_projects()[0]['id'] ?? 0)) . '">Input Massal Volume</a>';
    $actions .= '<a class="btn btn-primary" href="pekerjaan_form.php">+ Pekerjaan</a>';
}

render_header(
    $u['role'] === 'pekerja' ? 'Tugas Saya' : 'Daftar Pekerjaan',
    $u['role'] === 'pekerja'
        ? 'Pekerjaan lapangan yang ditugaskan kepadamu'
        : 'Semua pekerjaan dari seluruh project beserta status & progressnya',
    $actions
);
?>

<div class="stats">
  <?php
  stat_card('Total Pekerjaan', (string) count($list), 'sesuai filter aktif', 'info');
  stat_card('Berjalan', (string) $countBerjalan, 'sedang dikerjakan', '');
  stat_card('Selesai', (string) $countSelesai, 'sudah tuntas', 'ok');
  stat_card('Terlambat', (string) $countTerlambat, 'rata-rata progress ' . $avg . '%', $countTerlambat ? 'danger' : 'warn');
  ?>
</div>

<div class="card">
  <form class="filters" method="get">
    <div class="field" style="flex:1;min-width:200px">
      <label for="q">Cari pekerjaan</label>
      <input type="text" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Nama pekerjaan, kategori atau project">
    </div>
    <div class="field">
      <label for="project_id">Project</label>
      <select id="project_id" name="project_id" onchange="this.form.submit()"><?php project_options($f['project_id']); ?></select>
    </div>
    <?php if ($lokasiFilter): ?>
      <div class="field">
        <label for="lokasi_id">Lokasi</label>
        <select id="lokasi_id" name="lokasi_id" onchange="this.form.submit()">
          <option value="">Semua lokasi</option>
          <?php foreach ($lokasiFilter as $l): ?>
            <option value="<?= (int) $l['id'] ?>"<?= $f['lokasi_id'] === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status" onchange="this.form.submit()"><?php status_options($f['status'], true); ?></select>
    </div>
    <?php if ($u['role'] !== 'pekerja'): ?>
      <div class="field">
        <label for="pelaksana_id">Pelaksana</label>
        <select id="pelaksana_id" name="pelaksana_id" onchange="this.form.submit()"><?php user_options('pelaksana', $f['pelaksana_id'], 'Semua pelaksana'); ?></select>
      </div>
    <?php endif; ?>
    <?php if ($u['role'] !== 'pekerja'): ?>
      <div class="field">
        <label for="pekerja_id">Pekerja</label>
        <select id="pekerja_id" name="pekerja_id" onchange="this.form.submit()"><?php user_options('pekerja', $f['pekerja_id'], 'Semua pekerja'); ?></select>
      </div>
    <?php endif; ?>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="pekerjaan.php">Reset</a>
  </form>
</div>

<div class="card flush">
  <div class="card-head">
    <div>
      <h2>Hasil Pencarian</h2>
      <p><?= count($list) ?> pekerjaan ditemukan<?= $f['status'] === 'terlambat' ? ' · hanya yang lewat deadline' : '' ?></p>
    </div>
    <span class="spacer"></span>
    <a class="btn btn-sm" href="laporan.php?<?= e(http_build_query($base)) ?>">Rekap &amp; Export CSV</a>
  </div>
  <?php render_pekerjaan_table($list, true, 'Tidak ada pekerjaan pada filter ini.'); ?>
</div>

<?php render_footer(); ?>
