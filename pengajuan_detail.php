<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);

$id = (int) ($_GET['id'] ?? 0);
$peng = get_pengajuan($id);
if (!$peng) {
    flash('Pengajuan tidak ditemukan.', 'err');
    redirect('pengajuan.php');
}
$project = get_project((int) $peng['project_id']);
$bisaKelola = can_manage_project($project);

$items = item_pengajuan($id);
$total = array_sum(array_map(fn($i) => (float) $i['volume'] * (float) $i['harga_jasa'], $items));
$rincian = ringkasan_tagihan_project((int) $peng['project_id']);

render_header(
    'Pengajuan ' . $peng['nomor'],
    $peng['project_kode'] . ' · ' . e($peng['project_nama']) . ' · ' . e(tgl($peng['tanggal'])),
    '<a class="btn" href="pengajuan.php">Semua Pengajuan</a>'
    . '<a class="btn btn-dark" href="pengajuan_export.php?project_id=' . (int) $peng['project_id'] . '">Export Excel</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Nilai Pengajuan', e(rupiah($total)), count($items) . ' sub pekerjaan', 'ok');
  stat_card('Status', $peng['status'] === 'dibayar' ? 'Sudah Dibayar' : 'Sudah Diajukan',
      $peng['status'] === 'dibayar' && $peng['tanggal_bayar'] !== '' ? 'dibayar ' . e(tgl($peng['tanggal_bayar'])) : 'menunggu pembayaran perusahaan',
      $peng['status'] === 'dibayar' ? 'ok' : 'warn');
  stat_card('Masih Bisa Diajukan', e(rupiah($rincian['nilai_sisa'])), 'sisa volume yang belum diajukan', $rincian['nilai_sisa'] > 0 ? 'info' : '');
  stat_card('Sudah Diajukan (total)', e(rupiah($rincian['nilai_diajukan'])), 'semua pengajuan project ini', '');
  ?>
</div>

<div class="card flush">
  <div class="card-head">
    <div>
      <h2>Rincian Sub Pekerjaan</h2>
      <p>Yang ditagihkan ke perusahaan pemilik pekerjaan</p>
    </div>
    <?php if ($bisaKelola): ?>
      <span class="spacer"></span>
      <span class="muted small">Nomor: <span class="mono"><?= e($peng['nomor']) ?></span></span>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr><th>Sub pekerjaan</th><th>Volume diajukan</th><th>Harga ke perusahaan</th><th>Nilai</th></tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td class="strong"><?= e($it['nama_item'] !== '' ? $it['nama_item'] : '—') ?></td>
          <td class="nowrap"><span class="tag"><?= e(num($it['volume']) . ' ' . $it['satuan']) ?></span></td>
          <td class="small nowrap"><?= e(rupiah($it['harga_jasa'])) ?> / <?= e($it['satuan'] !== '' ? $it['satuan'] : 'satuan') ?></td>
          <td class="strong nowrap"><?= e(rupiah((float) $it['volume'] * (float) $it['harga_jasa'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="strong">Total</td>
          <td colspan="2"></td>
          <td class="strong nowrap"><?= e(rupiah($total)) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php if (trim((string) $peng['catatan']) !== ''): ?>
    <div class="card-head" style="border-top:1px solid var(--line-soft);border-bottom:0">
      <span class="small muted">Catatan: <?= e($peng['catatan']) ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($bisaKelola): ?>
  <div class="card">
    <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
      <div><h2>Status Pengajuan</h2><p>Tandai kalau sudah dibayar perusahaan</p></div>
    </div>
    <div class="row-actions" style="justify-content:flex-start;flex-wrap:wrap;gap:10px">
      <?php if ($peng['status'] !== 'dibayar'): ?>
        <form method="post" action="pengajuan_status.php" class="filters" style="align-items:flex-end">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="status" value="dibayar">
          <div class="field">
            <label for="tanggal_bayar">Tanggal dibayar</label>
            <input type="date" id="tanggal_bayar" name="tanggal_bayar" value="<?= e(date('Y-m-d')) ?>">
          </div>
          <button class="btn btn-primary" type="submit">Tandai Sudah Dibayar</button>
        </form>
      <?php else: ?>
        <form method="post" action="pengajuan_status.php">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="status" value="diajukan">
          <button class="btn" type="submit">Kembalikan ke Belum Dibayar</button>
        </form>
      <?php endif; ?>
      <form method="post" action="pengajuan_hapus.php" data-confirm="Hapus pengajuan ini? Volume yang sudah diajukan akan kembali menjadi sisa yang belum diajukan.">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-danger" type="submit">Hapus Pengajuan</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
    <div><h2>Posisi Tagihan Project Ini</h2><p>Nilai kontrak vs yang sudah diajukan</p></div>
  </div>
  <dl class="kv">
    <dt>Nilai kontrak (volume × harga jasa)</dt><dd><?= e(rupiah($rincian['nilai_kontrak'])) ?></dd>
    <dt>Sudah diajukan</dt><dd class="strong"><?= e(rupiah($rincian['nilai_diajukan'])) ?></dd>
    <dt>Belum diajukan</dt><dd class="<?= $rincian['nilai_sisa'] > 0 ? 'strong' : 'muted' ?>"><?= e(rupiah($rincian['nilai_sisa'])) ?></dd>
    <dt>Menunggu dibayar</dt><dd><?= e(rupiah($rincian['nilai_menunggu'])) ?></dd>
    <dt>Sudah dibayar</dt><dd class="strong"><?= e(rupiah($rincian['nilai_dibayar'])) ?></dd>
  </dl>
  <?php if ($rincian['nilai_sisa'] > 0): ?>
    <div class="form-actions" style="margin-top:14px">
      <a class="btn btn-primary" href="pengajuan_form.php?project_id=<?= (int) $peng['project_id'] ?>">Ajukan Sisanya</a>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
