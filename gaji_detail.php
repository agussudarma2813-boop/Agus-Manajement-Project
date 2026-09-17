<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$id = (int) ($_GET['id'] ?? 0);
$g = get_penggajian($id);
if (!$g) {
    flash('Penggajian tidak ditemukan.', 'err');
    redirect('gaji.php');
}
$milikSendiri = (int) $g['user_id'] === (int) $u['id'];
if ($u['role'] === 'pekerja' && !$milikSendiri) {
    flash('Kamu hanya bisa melihat gaji milikmu sendiri.', 'err');
    redirect('gaji.php');
}
$bolehAtur = in_array($u['role'], ['admin', 'pelaksana'], true);
$items = item_penggajian($id);

$harian = array_filter($items, fn($i) => $i['jenis'] === 'harian');
$borongan = array_filter($items, fn($i) => $i['jenis'] === 'borongan');
$kasbon = array_filter($items, fn($i) => $i['jenis'] === 'kasbon');

render_header(
    'Rincian Gaji ' . $g['nomor'],
    e((string) $g['pekerja_nama']) . ' · periode ' . e(tgl($g['periode_dari'])) . ' – ' . tgl($g['periode_sampai']),
    '<a class="btn" href="gaji.php">Daftar Gaji</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Gaji Seharusnya', e(rupiah($g['total_gaji'])),
      e(skema_label((string) $g['skema'])) . ((float) $g['hari_kerja'] > 0 ? ' · ' . e(hari_format($g['hari_kerja'])) : ''), '');
  stat_card('Kasbon Dipotong', (float) $g['total_kasbon'] > 0 ? '− ' . e(rupiah($g['total_kasbon'])) : '—',
      count($kasbon) . ' kasbon dipotong periode ini', (float) $g['total_kasbon'] > 0 ? 'warn' : '');
  stat_card('Gaji Diterima', e(rupiah($g['total_dibayar'])), 'gaji seharusnya − kasbon', 'ok');
  stat_card('Status', $g['status'] === 'dibayar' ? 'Sudah Dibayar' : 'Belum Dibayar',
      $g['status'] === 'dibayar' && $g['tanggal_bayar'] !== '' ? 'dibayar ' . e(tgl($g['tanggal_bayar'])) : 'belum dibayarkan',
      $g['status'] === 'dibayar' ? 'ok' : 'danger');
  ?>
</div>

<div class="grid grid-side">
  <div class="grid" style="gap:20px">
    <div class="card flush">
      <div class="card-head">
        <div><h2>Rincian Perhitungan</h2><p>Semua angka tersimpan saat penggajian dibuat (riwayat tidak berubah)</p></div>
      </div>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Komponen</th><th>Volume / Hari</th><th>Nilai</th></tr></thead>
          <tbody>
          <?php foreach ($harian as $i): ?>
            <tr>
              <td><?= e($i['keterangan']) ?></td>
              <td class="nowrap"><span class="tag"><?= e(hari_format($i['volume'])) ?></span></td>
              <td class="nowrap"><?= e(rupiah($i['nilai'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($harian): ?>
            <tr>
              <td class="strong" colspan="2">Subtotal upah harian</td>
              <td class="strong nowrap"><?= e(rupiah($g['upah_harian'])) ?></td>
            </tr>
          <?php endif; ?>

          <?php foreach ($borongan as $i): ?>
            <tr>
              <td><?= e($i['keterangan']) ?></td>
              <td class="nowrap"><span class="tag"><?= e(num($i['volume']) . ' ' . $i['satuan']) ?></span></td>
              <td class="nowrap"><?= e(rupiah($i['nilai'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($borongan): ?>
            <tr>
              <td class="strong" colspan="2">Subtotal upah borongan</td>
              <td class="strong nowrap"><?= e(rupiah($g['upah_borongan'])) ?></td>
            </tr>
          <?php endif; ?>

          <?php if (!$harian && !$borongan): ?>
            <tr><td colspan="3" class="muted">Tidak ada komponen upah pada periode ini (hanya kasbon).</td></tr>
          <?php endif; ?>

          <?php foreach ($kasbon as $i): ?>
            <tr>
              <td class="deadline-late"><?= e($i['keterangan']) ?></td>
              <td class="nowrap muted">potongan</td>
              <td class="nowrap deadline-late"><?= e(rupiah($i['nilai'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td class="strong" colspan="2">Gaji seharusnya</td>
              <td class="strong nowrap"><?= e(rupiah($g['total_gaji'])) ?></td>
            </tr>
            <tr>
              <td class="strong" colspan="2">Kasbon dipotong</td>
              <td class="strong nowrap deadline-late">− <?= e(rupiah($g['total_kasbon'])) ?></td>
            </tr>
            <tr>
              <td class="strong" colspan="2">GAJI DITERIMA</td>
              <td class="strong nowrap"><?= e(rupiah($g['total_dibayar'])) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="grid" style="gap:20px">
    <?php if ($bolehAtur): ?>
      <div class="card">
        <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
          <div><h2>Status Pembayaran</h2><p>Tandai kalau gaji sudah diberikan ke pekerja</p></div>
        </div>
        <?php if ($g['status'] !== 'dibayar'): ?>
          <form method="post" action="gaji_status.php" class="grid" style="gap:13px">
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
          <p class="small muted" style="margin:0 0 14px">
            Dibayarkan <?= e(tgl($g['tanggal_bayar'])) ?>. Bila salah tandai, bisa dikembalikan.
          </p>
          <form method="post" action="gaji_status.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="status" value="belum">
            <button class="btn" type="submit">Kembalikan ke Belum Dibayar</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2 class="card-title">Hapus Penggajian</h2>
        <p class="muted small" style="margin:8px 0 14px">
          Kasbon yang sempat dipotong akan kembali menjadi <strong>kasbon aktif</strong> (bisa dipotong di penggajian berikutnya).
          Data absensi &amp; laporan kerja tetap utuh.
        </p>
        <form method="post" action="gaji_hapus.php" data-confirm="Hapus penggajian ini?">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-danger" type="submit">Hapus Penggajian</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 class="card-title">Catatan</h2>
      <dl class="kv" style="margin-top:10px;grid-template-columns:minmax(0,1fr)">
        <dt>Nomor</dt><dd class="mono"><?= e($g['nomor']) ?></dd>
        <dt>Skema upah</dt><dd><?= e(skema_label((string) $g['skema'])) ?></dd>
        <dt>Dibuat</dt><dd><?= e(tgl(substr((string) $g['created_at'], 0, 10))) ?><?= $g['pembuat_nama'] ? ' oleh ' . e($g['pembuat_nama']) : '' ?></dd>
        <?php if (trim((string) $g['catatan']) !== ''): ?>
          <dt>Keterangan</dt><dd><?= e($g['catatan']) ?></dd>
        <?php endif; ?>
      </dl>
      <?php if ($milikSendiri): ?>
        <p class="small muted" style="margin-top:14px">
          Gaji diterima = hari kerja × tarif harian + upah borongan − kasbon yang belum dibayar.
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php render_footer(); ?>
