<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);

$f = [
    'pelaksana_id' => (int) ($_GET['pelaksana_id'] ?? 0),
    'q'            => trim((string) ($_GET['q'] ?? '')),
];

$per = rekap_pengajuan($f);

/* ---------- Export CSV (rincian per item, semua project pada filter) ---------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pengajuan-pekerjaan-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Kode Project', 'Project', 'Pekerjaan', 'Kategori', 'Satuan', 'Volume Kontrak', 'Volume Akhir',
        'Harga Jasa / Satuan', 'Nilai Kontrak Jasa', 'Status', 'Nilai Pengajuan',
        'Upah Borongan Item', 'Hari Kerja', 'Upah Harian Item', 'Margin Item',
    ], ';');
    foreach ($per as $r) {
        foreach ($r['item'] as $it) {
            $wid = (int) $it['id'];
            $st = db()->prepare(
                'SELECT COALESCE(SUM(hari),0) AS hari, COALESCE(SUM(hari*upah),0) AS upah
                 FROM absensi WHERE pekerjaan_id = ?'
            );
            $st->execute([$wid]);
            $ab = $st->fetch() ?: ['hari' => 0, 'upah' => 0];
            fputcsv($out, [
                $r['kode'], $r['nama'], $it['nama'], $it['kategori'], $it['satuan'],
                num($it['volume']), num($it['volume_realisasi']),
                num($it['harga_jasa']), num($it['nilai_kontrak']),
                status_label($it['status']), num($it['nilai_jasa']),
                num($it['upah_cair'] + $it['upah_berjalan']),
                num_hari($ab['hari']), num($ab['upah']),
                num($it['nilai_jasa'] - $it['upah_cair'] - (float) $ab['upah']),
            ], ';');
        }
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['REKAP PER PROJECT'], ';');
    fputcsv($out, ['Project', 'Jumlah Item', 'Item Selesai', 'Nilai Kontrak', 'Nilai Siap Diajukan',
        'Upah Borongan Item Selesai', 'Upah Harian Project', 'Estimasi Margin'], ';');
    foreach ($per as $r) {
        fputcsv($out, [
            $r['kode'] . ' ' . $r['nama'], $r['jml_item'], $r['jml_selesai'],
            num($r['nilai_kontrak']), num($r['nilai_diajukan']),
            num($r['upah_selesai']), num($r['upah_harian']), num($r['margin']),
        ], ';');
    }
    fclose($out);
    exit;
}

$totalKontrak = array_sum(array_map(fn($r) => $r['nilai_kontrak'], $per));
$totalDiajukan = array_sum(array_map(fn($r) => $r['nilai_diajukan'], $per));
$totalUpah = array_sum(array_map(fn($r) => $r['upah_selesai'], $per));
$totalMargin = array_sum(array_map(fn($r) => $r['margin'], $per));

$exportUrl = 'pengajuan.php?' . http_build_query(array_merge($f, ['export' => '1']));

render_header(
    'Pengajuan ke Perusahaan',
    'Nilai jasa yang bisa ditagihkan berdasarkan pekerjaan yang sudah diselesaikan per project',
    '<a class="btn" href="upah.php">Upah Pekerja</a><a class="btn btn-dark" href="' . e($exportUrl) . '">Export CSV</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Nilai Kontrak (Jasa)', e(rupiah($totalKontrak)), 'seluruh item pada filter ini', 'info');
  stat_card('Siap Diajukan', e(rupiah($totalDiajukan)), 'item berstatus Selesai · volume akhir', 'ok');
  stat_card('Upah Borongan Item', e(rupiah($totalUpah)), 'upah pekerja item yang sudah selesai', 'warn');
  stat_card('Estimasi Margin', e(rupiah($totalMargin)), 'nilai diajukan − upah borongan − upah harian terkait', $totalMargin < 0 ? 'danger' : '');
  ?>
</div>

<div class="card">
  <form class="filters" method="get">
    <div class="field" style="flex:1;min-width:200px">
      <label for="q">Cari project</label>
      <input type="text" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Nama atau kode project">
    </div>
    <div class="field">
      <label for="pelaksana_id">Pelaksana</label>
      <select id="pelaksana_id" name="pelaksana_id">
        <option value="">Semua pelaksana</option>
        <?php foreach (selectable_pelaksana() as $pl): ?>
          <option value="<?= (int) $pl['id'] ?>"<?= $f['pelaksana_id'] === (int) $pl['id'] ? ' selected' : '' ?>><?= e($pl['nama']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="pengajuan.php">Reset</a>
  </form>
  <p class="small muted" style="margin:14px 0 0">
    Nilai pengajuan = <strong>harga jasa × volume akhir</strong> untuk pekerjaan yang sudah berstatus
    <em>Selesai</em>. Bila volume akhir belum diisi, volume kontrak dipakai sebagai gantinya.
  </p>
</div>

<div class="card flush">
  <div class="card-head">
    <div><h2>Rekap Pengajuan per Project</h2><p><?= count($per) ?> project pada filter ini</p></div>
  </div>
  <?php if (!$per): ?>
    <div class="empty">
      <strong>Belum ada project dengan nilai jasa</strong>
      <span class="small">Isi harga jasa pada pekerjaan (atau lewat master harga satuan) agar nilai pengajuan terhitung.</span>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Project</th><th>Item</th><th>Nilai Kontrak</th><th>Siap Diajukan</th>
            <th>Upah Borongan</th><th>Upah Harian</th><th>Estimasi Margin</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($per as $r): ?>
          <tr>
            <td>
              <div class="cell-stack">
                <a href="pengajuan_detail.php?id=<?= (int) $r['project_id'] ?>"><strong><?= e($r['nama']) ?></strong></a>
                <small><?= e($r['kode']) ?><?= $r['pelaksana'] !== '' ? ' · Pelaksana ' . e($r['pelaksana']) : '' ?></small>
              </div>
            </td>
            <td class="small nowrap"><?= (int) $r['jml_selesai'] ?>/<?= (int) $r['jml_item'] ?> selesai</td>
            <td class="nowrap"><?= e(rupiah($r['nilai_kontrak'])) ?></td>
            <td class="strong nowrap"><?= e(rupiah($r['nilai_diajukan'])) ?></td>
            <td class="nowrap"><?= e(rupiah($r['upah_selesai'])) ?></td>
            <td class="small nowrap muted"><?= e(rupiah($r['upah_harian'])) ?></td>
            <td class="nowrap <?= $r['margin'] < 0 ? 'deadline-late' : 'strong' ?>"><?= e(rupiah($r['margin'])) ?></td>
            <td class="right nowrap">
              <a class="btn btn-sm" href="pengajuan_detail.php?id=<?= (int) $r['project_id'] ?>">Rincian Item</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="strong">Total</td>
            <td></td>
            <td class="strong nowrap"><?= e(rupiah($totalKontrak)) ?></td>
            <td class="strong nowrap"><?= e(rupiah($totalDiajukan)) ?></td>
            <td class="nowrap"><?= e(rupiah($totalUpah)) ?></td>
            <td></td>
            <td class="strong nowrap"><?= e(rupiah($totalMargin)) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
