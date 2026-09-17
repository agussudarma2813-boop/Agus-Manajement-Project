<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);

$projectId = (int) ($_GET['id'] ?? 0);
$project = get_project_summary($projectId);
if (!$project) {
    flash('Project tidak ditemukan.', 'err');
    redirect('pengajuan.php');
}

$items = pengajuan_item_rows($projectId);
$harianItem = [];
$st = db()->prepare(
    'SELECT pekerjaan_id, COALESCE(SUM(hari),0) AS hari, COALESCE(SUM(hari*upah),0) AS upah
     FROM absensi WHERE pekerjaan_id IS NOT NULL AND project_id = ? GROUP BY pekerjaan_id'
);
$st->execute([$projectId]);
foreach ($st->fetchAll() as $r) {
    $harianItem[(int) $r['pekerjaan_id']] = $r;
}

/* ---------- Export CSV project ini ---------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pengajuan-' . slugify($project['kode'] . '-' . $project['nama']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Project', $project['kode'] . ' — ' . $project['nama']], ';');
    fputcsv($out, ['Pelaksana', $project['pelaksana_nama'] ?? '', 'Periode', $project['mulai'], $project['target_selesai']], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Pekerjaan', 'Kategori', 'Satuan', 'Volume Kontrak', 'Volume Akhir', 'Harga Jasa / Satuan',
        'Status', 'Nilai Pengajuan', 'Upah Borongan Item', 'Hari Kerja', 'Upah Harian Item', 'Margin Item'], ';');
    $totJasa = 0.0;
    $totUpahBor = 0.0;
    $totHari = 0.0;
    $totHarian = 0.0;
    foreach ($items as $it) {
        $ab = $harianItem[(int) $it['id']] ?? ['hari' => 0, 'upah' => 0];
        $margin = $it['nilai_jasa'] - $it['upah_cair'] - (float) $ab['upah'];
        $totJasa += $it['nilai_jasa'];
        $totUpahBor += $it['upah_cair'] + $it['upah_berjalan'];
        $totHari += (float) $ab['hari'];
        $totHarian += (float) $ab['upah'];
        fputcsv($out, [
            $it['nama'], $it['kategori'], $it['satuan'], num($it['volume']), num($it['volume_realisasi']),
            num($it['harga_jasa']), status_label($it['status']), num($it['nilai_jasa']),
            num($it['upah_cair'] + $it['upah_berjalan']), num_hari($ab['hari']), num($ab['upah']), num($margin),
        ], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['TOTAL NILAI PENGAJUAN', num($totJasa)], ';');
    fputcsv($out, ['Upah Borongan Pekerja (selesai)', num(array_sum(array_map(fn($i) => $i['upah_cair'], $items)))], ';');
    fputcsv($out, ['Upah Harian Terkait Item', num($totHarian)], ';');
    fputcsv($out, ['Total Hari Kerja Terkait Item', num_hari($totHari)], ';');
    fclose($out);
    exit;
}

$totKontrak = array_sum(array_map(fn($i) => $i['nilai_kontrak'], $items));
$totJasa = array_sum(array_map(fn($i) => $i['nilai_jasa'], $items));
$totUpahCair = array_sum(array_map(fn($i) => $i['upah_cair'], $items));
$totUpahJalan = array_sum(array_map(fn($i) => $i['upah_berjalan'], $items));
$totHarian = array_sum(array_map(fn($i) => (float) ($harianItem[(int) $i['id']]['upah'] ?? 0), $items));
$totHari = array_sum(array_map(fn($i) => (float) ($harianItem[(int) $i['id']]['hari'] ?? 0), $items));
$margin = $totJasa - $totUpahCair - $totHarian;

$exportUrl = 'pengajuan_detail.php?id=' . $projectId . '&export=1';

render_header(
    'Pengajuan · ' . $project['nama'],
    'Rincian nilai jasa ke perusahaan pemilik pekerjaan per item pekerjaan',
    '<a class="btn" href="pengajuan.php">Semua Project</a><a class="btn btn-dark" href="' . e($exportUrl) . '">Export CSV</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Nilai Kontrak Jasa', e(rupiah($totKontrak)), 'seluruh item · volume kontrak', 'info');
  stat_card('Siap Diajukan', e(rupiah($totJasa)), count(array_filter($items, fn($i) => $i['status'] === 'selesai')) . ' item selesai', 'ok');
  stat_card('Upah Borongan', e(rupiah($totUpahCair)), 'cair ' . e(rupiah($totUpahCair)) . ' · berjalan ' . e(rupiah($totUpahJalan)), 'warn');
  stat_card('Estimasi Margin', e(rupiah($margin)), 'pengajuan − upah borongan cair − upah harian item', $margin < 0 ? 'danger' : '');
  ?>
</div>

<div class="card flush">
  <div class="card-head">
    <div>
      <h2>Rincian Item Pekerjaan</h2>
      <p><?= count($items) ?> item · volume akhir jadi dasar nilai pengajuan</p>
    </div>
    <span class="spacer"></span>
    <a class="btn btn-sm btn-primary" href="pekerjaan_form.php?project_id=<?= $projectId ?>">+ Item Pekerjaan</a>
  </div>
  <?php if (!$items): ?>
    <div class="empty"><strong>Belum ada item pekerjaan</strong><span class="small">Tambahkan pekerjaan beserta harga jasanya.</span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Item pekerjaan</th><th>Volume</th><th>Volume akhir</th><th>Harga jasa / satuan</th>
            <th>Nilai pengajuan</th><th>Upah borongan item</th><th>Hari kerja</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <?php
          $ab = $harianItem[(int) $it['id']] ?? ['hari' => 0, 'upah' => 0];
          $mItem = $it['nilai_jasa'] - $it['upah_cair'] - (float) $ab['upah'];
          ?>
          <tr>
            <td>
              <div class="cell-stack">
                <a href="pekerjaan_detail.php?id=<?= (int) $it['id'] ?>"><strong><?= e($it['nama']) ?></strong></a>
                <small>
                  <?= e($it['kategori'] !== '' ? $it['kategori'] : 'tanpa kategori') ?>
                  <?= $it['lokasi_nama'] ? ' · ' . e($it['lokasi_nama']) : '' ?>
                  · <?= $it['jml_pekerja'] ?> pekerja
                  · margin <?= e(rupiah($mItem)) ?>
                </small>
              </div>
            </td>
            <td class="small nowrap"><?= (float) $it['volume'] > 0 ? e(num($it['volume']) . ' ' . $it['satuan']) : '<span class="muted">—</span>' ?></td>
            <td class="nowrap">
              <?php if ((float) $it['volume_realisasi'] > 0): ?>
                <strong><?= e(num($it['volume_realisasi']) . ' ' . $it['satuan']) ?></strong>
                <div class="small muted"><?= e(tgl($it['realisasi_tanggal'])) ?></div>
              <?php else: ?>
                <span class="muted small">belum dicatat</span>
              <?php endif; ?>
            </td>
            <td class="small nowrap"><?= e(rupiah($it['harga_jasa'])) ?></td>
            <td class="strong nowrap"><?= e(rupiah($it['nilai_jasa'])) ?></td>
            <td class="nowrap">
              <?= $it['upah_cair'] > 0 ? e(rupiah($it['upah_cair'])) : '<span class="muted">—</span>' ?>
              <?php if ($it['upah_berjalan'] > 0): ?>
                <div class="small muted">+<?= e(rupiah($it['upah_berjalan'])) ?> berjalan</div>
              <?php endif; ?>
            </td>
            <td class="small nowrap"><?= (float) $ab['hari'] > 0 ? e(hari_format($ab['hari'])) : '<span class="muted">—</span>' ?></td>
            <td><?= status_pill($it) ?></td>
            <td class="right nowrap">
              <div class="row-actions">
                <a class="btn btn-sm" href="pekerjaan_detail.php?id=<?= (int) $it['id'] ?>">Kelola</a>
                <a class="btn btn-sm" href="pekerjaan_form.php?id=<?= (int) $it['id'] ?>">Edit</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="strong">Total</td>
            <td colspan="3"></td>
            <td class="strong nowrap"><?= e(rupiah($totJasa)) ?></td>
            <td class="strong nowrap"><?= e(rupiah($totUpahCair)) ?></td>
            <td class="strong nowrap"><?= e(hari_format($totHari)) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2 class="card-title">Rincian Upah per Pekerja (item selesai)</h2>
    <?php
    $pekerjaSelesai = [];
    foreach ($items as $it) {
        if ($it['status'] !== 'selesai') {
            continue;
        }
        foreach ($it['pekerja'] as $row) {
            $uid = (int) $row['user_id'];
            $pekerjaSelesai[$uid] ??= ['nama' => $row['nama'], 'jabatan' => $row['jabatan'], 'nilai' => 0.0, 'item' => 0];
            $pekerjaSelesai[$uid]['nilai'] += (float) $row['nilai'];
            $pekerjaSelesai[$uid]['item']++;
        }
    }
    uasort($pekerjaSelesai, fn($a, $b) => $b['nilai'] <=> $a['nilai']);
    ?>
    <?php if (!$pekerjaSelesai): ?>
      <p class="muted small">Belum ada item yang selesai, jadi upah borongan belum cair.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Pekerja</th><th>Item selesai</th><th>Upah borongan</th></tr></thead>
          <tbody>
          <?php foreach ($pekerjaSelesai as $p): ?>
            <tr>
              <td><div style="display:flex;align-items:center;gap:9px"><?= badge_avatar($p['nama'], 'sm') ?><strong><?= e($p['nama']) ?></strong></div></td>
              <td class="small"><?= (int) $p['item'] ?> item</td>
              <td class="strong nowrap"><?= e(rupiah($p['nilai'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td class="strong" colspan="2">Total upah borongan cair</td><td class="strong nowrap"><?= e(rupiah($totUpahCair)) ?></td></tr></tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="card-title">Ringkasan Keuangan Project</h2>
    <dl class="kv" style="margin-top:12px">
      <dt>Nilai kontrak jasa</dt><dd><?= e(rupiah($totKontrak)) ?></dd>
      <dt>Nilai siap diajukan</dt><dd class="strong"><?= e(rupiah($totJasa)) ?></dd>
      <dt>Upah borongan cair</dt><dd><?= e(rupiah($totUpahCair)) ?></dd>
      <dt>Upah borongan berjalan</dt><dd class="muted"><?= e(rupiah($totUpahJalan)) ?></dd>
      <dt>Upah harian (item)</dt><dd><?= e(rupiah($totHarian)) ?> <span class="muted small">· <?= e(hari_format($totHari)) ?></span></dd>
      <dt>Estimasi margin</dt><dd class="<?= $margin < 0 ? 'deadline-late' : 'strong' ?>"><?= e(rupiah($margin)) ?></dd>
    </dl>
    <p class="small muted" style="margin-top:14px">
      Upah harian pada baris ini hanya yang absensinya dikaitkan ke item pekerjaan project ini.
      Absensi yang belum dikaitkan tidak mengurangi margin di atas.
    </p>
    <div class="row-actions" style="margin-top:14px;justify-content:flex-start">
      <a class="btn btn-sm" href="absensi.php?project_id=<?= $projectId ?>">Absensi Project</a>
      <a class="btn btn-sm" href="pekerjaan.php?project_id=<?= $projectId ?>">Daftar Pekerjaan</a>
    </div>
  </div>
</div>

<?php render_footer(); ?>
