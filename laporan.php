<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/partials.php';

$u = require_role(['admin', 'pelaksana']);

$f = [
    'q'            => trim((string) ($_GET['q'] ?? '')),
    'project_id'   => (int) ($_GET['project_id'] ?? 0),
    'status'       => (string) ($_GET['status'] ?? ''),
    'pelaksana_id' => (int) ($_GET['pelaksana_id'] ?? 0),
    'pekerja_id'   => (int) ($_GET['pekerja_id'] ?? 0),
];
if (!isset(STATUS_PEKERJAAN[$f['status']]) && $f['status'] !== 'terlambat') {
    $f['status'] = '';
}

$rows = fetch_pekerjaan($f);

/* ---------- Export CSV ---------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan-pekerjaan-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM agar rapi di Excel
    fputcsv($out, [
        'Kode Project', 'Project', 'Lokasi', 'Pekerjaan', 'Kategori', 'Volume', 'Satuan',
        'Status', 'Progress (%)', 'Mulai', 'Deadline', 'Pelaksana', 'Pekerja', 'Terlambat',
    ], ';');
    foreach ($rows as $r) {
        $pekerjaList = array_map(fn($p) => $p['nama'], pekerja_of((int) $r['id']));
        fputcsv($out, [
            $r['project_kode'],
            $r['project_nama'],
            $r['lokasi_nama'] ?? '',
            $r['nama'],
            $r['kategori'],
            num($r['volume']),
            $r['satuan'],
            status_label($r['status']),
            (int) $r['progress'],
            $r['mulai'],
            $r['deadline'],
            $r['pelaksana_nama'] ?? '',
            implode(', ', $pekerjaList),
            is_late($r) ? 'Ya' : 'Tidak',
        ], ';');
    }
    fclose($out);
    exit;
}

$countAll = count($rows);
$countSelesai = count(array_filter($rows, fn($p) => $p['status'] === 'selesai'));
$countBerjalan = count(array_filter($rows, fn($p) => $p['status'] === 'proses'));
$countTerlambat = count(array_filter($rows, fn($p) => is_late($p)));
$avg = $countAll ? (int) round(array_sum(array_map(fn($p) => (int) $p['progress'], $rows)) / $countAll) : 0;

// Rekap per project (dari hasil filter)
$perProject = [];
foreach ($rows as $r) {
    $k = (int) $r['project_id'];
    $perProject[$k] ??= ['nama' => $r['project_nama'], 'kode' => $r['project_kode'], 'total' => 0, 'selesai' => 0, 'berjalan' => 0, 'terlambat' => 0, 'sum' => 0];
    $perProject[$k]['total']++;
    $perProject[$k]['sum'] += (int) $r['progress'];
    if ($r['status'] === 'selesai') {
        $perProject[$k]['selesai']++;
    } elseif ($r['status'] === 'proses') {
        $perProject[$k]['berjalan']++;
    }
    if (is_late($r)) {
        $perProject[$k]['terlambat']++;
    }
}
uasort($perProject, fn($a, $b) => $b['total'] <=> $a['total']);

$query = $_GET;
unset($query['export']);
$exportUrl = 'laporan.php?' . http_build_query(array_merge($query, ['export' => '1']));

render_header(
    'Laporan & Rekap',
    'Rekapitulasi progress pekerjaan, status dan beban kerja tim',
    '<a class="btn" href="' . e($exportUrl) . '">Export CSV</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Pekerjaan Terfilter', (string) $countAll, 'sesuai kriteria laporan', 'info');
  stat_card('Selesai', (string) $countSelesai, $countAll ? round($countSelesai / $countAll * 100) . '% dari total' : '—', 'ok');
  stat_card('Berjalan', (string) $countBerjalan, 'rata-rata progress ' . $avg . '%', '');
  stat_card('Terlambat', (string) $countTerlambat, 'lewat deadline &amp; belum selesai', $countTerlambat ? 'danger' : 'warn');
  ?>
</div>

<div class="card">
  <form class="filters" method="get">
    <div class="field" style="flex:1;min-width:200px">
      <label for="q">Cari</label>
      <input type="text" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Nama pekerjaan / project">
    </div>
    <div class="field">
      <label for="project_id">Project</label>
      <select id="project_id" name="project_id"><?php project_options($f['project_id']); ?></select>
    </div>
    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status"><?php status_options($f['status'], true); ?></select>
    </div>
    <div class="field">
      <label for="pelaksana_id">Pelaksana</label>
      <select id="pelaksana_id" name="pelaksana_id"><?php user_options('pelaksana', $f['pelaksana_id'], 'Semua pelaksana'); ?></select>
    </div>
    <div class="field">
      <label for="pekerja_id">Pekerja</label>
      <select id="pekerja_id" name="pekerja_id"><?php user_options('pekerja', $f['pekerja_id'], 'Semua pekerja'); ?></select>
    </div>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="laporan.php">Reset</a>
  </form>
</div>

<div class="grid grid-2">
  <div class="card flush">
    <div class="card-head">
      <div><h2>Rekap per Project</h2><p>Ringkasan status pekerjaan tiap project</p></div>
    </div>
    <?php if (!$perProject): ?>
      <div class="empty"><strong>Belum ada data</strong><span class="small">Tidak ada pekerjaan pada filter ini.</span></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr><th>Project</th><th>Pekerjaan</th><th>Selesai</th><th>Berjalan</th><th>Terlambat</th><th>Progress</th></tr>
          </thead>
          <tbody>
            <?php foreach ($perProject as $pid => $rp): $pavg = (int) round($rp['sum'] / $rp['total']); ?>
              <tr>
                <td>
                  <div class="cell-stack">
                    <a href="project_detail.php?id=<?= (int) $pid ?>"><?= e($rp['nama']) ?></a>
                    <small class="mono"><?= e($rp['kode']) ?></small>
                  </div>
                </td>
                <td class="strong"><?= $rp['total'] ?></td>
                <td><?= $rp['selesai'] ?></td>
                <td><?= $rp['berjalan'] ?></td>
                <td><?= $rp['terlambat'] > 0 ? '<span class="deadline-late">' . $rp['terlambat'] . '</span>' : '0' ?></td>
                <td>
                  <div class="progress-cell"><?= progress_bar($pavg, ' bar-lg') ?><b><?= $pavg ?>%</b></div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card flush">
    <div class="card-head">
      <div><h2>Beban Kerja Pelaksana</h2><p>Jumlah pekerjaan &amp; keterlambatan tiap pelaksana</p></div>
    </div>
    <?php $pelaksanaAll = selectable_pelaksana(); ?>
    <?php if (!$pelaksanaAll): ?>
      <div class="empty"><strong>Belum ada pelaksana</strong><span class="small">Tambahkan akun pelaksana terlebih dahulu.</span></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr><th>Pelaksana</th><th>Project</th><th>Pekerjaan</th><th>Berjalan</th><th>Selesai</th><th>Terlambat</th><th>Progress</th></tr>
          </thead>
          <tbody>
            <?php foreach ($pelaksanaAll as $pl): $st = pelaksana_stats((int) $pl['id']); ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:9px">
                    <?= badge_avatar($pl['nama'], 'sm') ?>
                    <div class="cell-stack">
                      <strong><?= e($pl['nama']) ?></strong>
                      <small><?= e($pl['jabatan'] !== '' ? $pl['jabatan'] : 'Pelaksana') ?></small>
                    </div>
                  </div>
                </td>
                <td><?= (int) $st['projects'] ?></td>
                <td class="strong"><?= (int) $st['pekerjaan'] ?></td>
                <td><?= (int) $st['proses'] ?></td>
                <td><?= (int) $st['selesai'] ?></td>
                <td><?= (int) $st['terlambat'] > 0 ? '<span class="deadline-late">' . (int) $st['terlambat'] . '</span>' : '0' ?></td>
                <td>
                  <div class="progress-cell"><?= progress_bar((int) $st['avg_progress'], ' bar-lg') ?><b><?= (int) $st['avg_progress'] ?>%</b></div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card flush">
  <div class="card-head">
    <div><h2>Beban Kerja Pekerja</h2><p>Distribusi tugas tiap pekerja lapangan</p></div>
  </div>
  <?php $pekerjaAll = selectable_pekerja(); ?>
  <?php if (!$pekerjaAll): ?>
    <div class="empty"><strong>Belum ada pekerja</strong><span class="small">Tambahkan akun pekerja terlebih dahulu.</span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Pekerja</th><th>Keahlian</th><th>Project</th><th>Tugas Aktif</th><th>Tugas Selesai</th><th>Total Tugas</th><th>Terlambat</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($pekerjaAll as $pk): $st = pekerja_stats((int) $pk['id']); ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:9px">
                  <?= badge_avatar($pk['nama'], 'sm') ?>
                  <strong><?= e($pk['nama']) ?></strong>
                </div>
              </td>
              <td class="small"><?= e($pk['jabatan'] !== '' ? $pk['jabatan'] : '—') ?></td>
              <td><?= (int) $st['projects'] ?></td>
              <td class="strong"><?= (int) $st['proses'] ?></td>
              <td><?= (int) $st['selesai'] ?></td>
              <td><?= (int) $st['tugas'] ?></td>
              <td><?= (int) $st['terlambat'] > 0 ? '<span class="deadline-late">' . (int) $st['terlambat'] . '</span>' : '0' ?></td>
              <td class="right"><a class="btn btn-sm" href="pekerjaan.php?pekerja_id=<?= (int) $pk['id'] ?>">Lihat Tugas</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card flush">
  <div class="card-head">
    <div><h2>Rincian Pekerjaan</h2><p><?= count($rows) ?> baris sesuai filter — bisa diexport ke CSV</p></div>
    <span class="spacer"></span>
    <a class="btn btn-sm btn-dark" href="<?= e($exportUrl) ?>">Export CSV</a>
  </div>
  <?php render_pekerjaan_table($rows, true, 'Tidak ada pekerjaan pada filter ini.'); ?>
</div>

<?php render_footer(); ?>
