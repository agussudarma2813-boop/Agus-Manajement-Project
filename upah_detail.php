<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/partials.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    $id = (int) $u['id'];
}
if (!can_view_wages_of($id)) {
    flash('Kamu hanya dapat melihat rincian upah milikmu sendiri.', 'err');
    redirect('upah.php');
}

$st = db()->prepare('SELECT * FROM users WHERE id = ? AND perusahaan_id = ?');
$st->execute([$id, tenant_id()]);
$orang = $st->fetch();
if (!$orang) {
    flash('Data pekerja tidak ditemukan.', 'err');
    redirect('upah.php');
}

[$defDari, $defSampai] = default_periode();
$dari = valid_tanggal((string) ($_GET['dari'] ?? '')) ?: $defDari;
$sampai = valid_tanggal((string) ($_GET['sampai'] ?? '')) ?: $defSampai;
$projectId = (int) ($_GET['project_id'] ?? 0);

$absensi = fetch_absensi(['user_id' => $id, 'dari' => $dari, 'sampai' => $sampai, 'project_id' => $projectId]);
$borongan = borongan_of_pekerja($id, $projectId);
$bStats = borongan_stats($id, $projectId);

$hari = 0.0;
$upahHarian = 0.0;
foreach ($absensi as $r) {
    $hari += (float) $r['hari'];
    $upahHarian += (float) $r['hari'] * (float) $r['upah'];
}
$total = $upahHarian + $bStats['selesai'];

render_header(
    'Rincian Upah · ' . $orang['nama'],
    e(role_label($orang['role'])) . ($orang['jabatan'] !== '' ? ' · ' . e($orang['jabatan']) : '') . ' · tarif harian ' . e(rupiah($orang['upah_harian'])),
    '<a class="btn" href="upah.php?id=' . $id . '">Kembali ke Rekap</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Hari Kerja', e(hari_format($hari)), $dari === $sampai ? e(tgl($dari)) : e(tgl($dari) . ' – ' . tgl($sampai)), 'info');
  stat_card('Upah Harian', e(rupiah($upahHarian)), 'hari kerja × tarif saat dicatat', '');
  stat_card('Borongan Selesai', e(rupiah($bStats['selesai'])), $bStats['jml'] . ' penugasan borongan', 'warn');
  stat_card('Total Upah', e(rupiah($total)), $bStats['berjalan'] > 0 ? 'borongan berjalan ' . e(rupiah($bStats['berjalan'])) . ' belum dihitung' : 'siap dibayarkan', 'ok');
  ?>
</div>

<div class="grid grid-side">
  <div class="card flush">
    <div class="card-head">
      <div><h2>Catatan Hari Kerja</h2><p><?= count($absensi) ?> baris pada periode ini</p></div>
    </div>
    <?php if (!$absensi): ?>
      <div class="empty"><strong>Tidak ada absensi</strong><span class="small">Belum ada hari kerja tercatat pada periode ini.</span></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr><th>Tanggal</th><th>Project</th><th>Pekerjaan</th><th>Hari</th><th>Tarif</th><th>Upah</th><th>Keterangan</th></tr>
          </thead>
          <tbody>
          <?php foreach ($absensi as $r): ?>
            <tr>
              <td class="nowrap"><strong><?= e(tgl($r['tanggal'])) ?></strong></td>
              <td class="small"><?= $r['project_nama'] ? e($r['project_nama']) : '<span class="muted">—</span>' ?></td>
              <td class="small"><?= $r['pekerjaan_nama'] ? e($r['pekerjaan_nama']) : '<span class="muted">—</span>' ?></td>
              <td><span class="tag"><?= e(hari_format($r['hari'])) ?></span></td>
              <td class="small nowrap"><?= e(rupiah($r['upah'])) ?></td>
              <td class="strong nowrap"><?= e(rupiah((float) $r['hari'] * (float) $r['upah'])) ?></td>
              <td class="small"><?= $r['keterangan'] !== '' ? e($r['keterangan']) : '<span class="muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" class="strong right">Total</td>
              <td class="strong nowrap"><?= e(hari_format($hari)) ?></td>
              <td></td>
              <td class="strong nowrap"><?= e(rupiah($upahHarian)) ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card flush">
    <div class="card-head">
      <div><h2>Upah Borongan</h2><p>Perhitungan per item pekerjaan (tarif × volume akhir × bagian)</p></div>
    </div>
    <?php if (!$borongan): ?>
      <div class="empty"><strong>Belum ada borongan</strong><span class="small">Nominal borongan diisi pelaksana saat menugaskan pekerja ke sebuah pekerjaan.</span></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Pekerjaan</th><th>Status</th><th>Nominal</th></tr></thead>
          <tbody>
          <?php foreach ($borongan as $b): ?>
            <tr>
              <td>
                <div class="cell-stack">
                  <a href="pekerjaan_detail.php?id=<?= (int) $b['pekerjaan_id'] ?>"><?= e($b['pekerjaan_nama']) ?></a>
                  <small>
                    <?= e($b['project_nama']) ?> · progress <?= (int) $b['progress'] ?>% ·
                    <?php if (($b['mode'] ?? '') === 'nominal'): ?>
                      nominal tetap
                    <?php else: ?>
                      <?= e(rupiah($b['harga_terpakai'])) ?>/<?= e($b['satuan'] !== '' ? $b['satuan'] : 'satuan') ?>
                      × <?= e(num($b['volume_realisasi'] > 0 ? $b['volume_realisasi'] : ($b['status'] === 'selesai' ? $b['volume'] : 0))) ?>
                      × <?= e(num($b['pct'])) ?>%
                    <?php endif; ?>
                    <?= $b['volume_realisasi'] <= 0 && $b['status'] !== 'selesai' ? ' · menunggu volume akhir' : '' ?>
                  </small>
                </div>
              </td>
              <td><?= status_pill($b) ?></td>
              <td class="strong nowrap"><?= e(rupiah($b['nilai'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2" class="strong right">Borongan selesai (cair)</td>
              <td class="strong nowrap"><?= e(rupiah($bStats['selesai'])) ?></td>
            </tr>
            <?php if ($bStats['berjalan'] > 0): ?>
              <tr>
                <td colspan="2" class="right muted">Borongan berjalan (belum cair)</td>
                <td class="muted nowrap"><?= e(rupiah($bStats['berjalan'])) ?></td>
              </tr>
            <?php endif; ?>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
