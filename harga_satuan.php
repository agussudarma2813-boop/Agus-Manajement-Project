<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);
$isAdmin = is_admin();

$f = [
    'q'     => trim((string) ($_GET['q'] ?? '')),
    'aktif' => (string) ($_GET['aktif'] ?? ''),
];
$items = fetch_harga_satuan($f);

// ringkasan margin
$totalJasa = 0.0;
$totalUpah = 0.0;
foreach ($items as $it) {
    $totalJasa += (float) $it['harga_jasa'];
    $totalUpah += (float) $it['harga_upah'];
}

$actions = '';
if ($isAdmin) {
    $actions = '<a class="btn btn-primary" href="#form-harga-satuan">+ Item Harga Baru</a>';
}

render_header(
    'Master Harga Satuan',
    'Harga jasa ke perusahaan pemilik pekerjaan dan upah dasar ke pekerja — dipakai di seluruh project',
    $actions
);
?>

<div class="stats">
  <?php
  stat_card('Jumlah Item', (string) count($items), 'item harga satuan', 'info');
  stat_card('Total Harga Jasa', e(rupiah($totalJasa)), 'penjumlahan harga jasa semua item', '');
  stat_card('Total Upah Pekerja', e(rupiah($totalUpah)), 'penjumlahan upah dasar semua item', 'warn');
  stat_card('Selisih Dasar', e(rupiah($totalJasa - $totalUpah)), 'selisih jasa − upah per item', 'ok');
  ?>
</div>

<div class="card">
  <form class="filters" method="get">
    <div class="field" style="flex:1;min-width:200px">
      <label for="q">Cari item</label>
      <input type="text" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="mis. penarikan kabel / CCTV">
    </div>
    <div class="field">
      <label for="aktif">Status</label>
      <select id="aktif" name="aktif">
        <option value="">Semua</option>
        <option value="1"<?= $f['aktif'] === '1' ? ' selected' : '' ?>>Aktif saja</option>
        <option value="0"<?= $f['aktif'] === '0' ? ' selected' : '' ?>>Nonaktif saja</option>
      </select>
    </div>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="harga_satuan.php">Reset</a>
  </form>
</div>

<div class="grid grid-side">
  <div class="card flush">
    <div class="card-head">
      <div><h2>Daftar Item Harga</h2><p><?= count($items) ?> item · harga jasa (tagihan) vs upah dasar pekerja</p></div>
    </div>
    <?php if (!$items): ?>
      <div class="empty">
        <strong>Belum ada item harga satuan</strong>
        <span class="small"><?= $isAdmin ? 'Tambahkan item seperti "Penarikan Kabel" atau "Pemasangan CCTV".' : 'Hubungi admin untuk mengisi master harga.' ?></span>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Item pekerjaan</th><th>Kategori</th><th>Satuan</th>
              <th>Harga jasa (perusahaan)</th><th>Upah pekerja</th><th>Selisih</th><th>Status</th>
              <?php if ($isAdmin): ?><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $it): $selisih = (float) $it['harga_jasa'] - (float) $it['harga_upah']; ?>
            <tr>
              <td>
                <div class="cell-stack">
                  <strong><?= e($it['nama']) ?></strong>
                  <?php if ($it['keterangan'] !== ''): ?><small><?= e($it['keterangan']) ?></small><?php endif; ?>
                </div>
              </td>
              <td class="small"><?= e($it['kategori'] !== '' ? $it['kategori'] : '—') ?></td>
              <td><span class="tag"><?= e($it['satuan'] !== '' ? $it['satuan'] : '—') ?></span></td>
              <td class="strong nowrap"><?= e(rupiah($it['harga_jasa'])) ?></td>
              <td class="nowrap"><?= e(rupiah($it['harga_upah'])) ?></td>
              <td class="nowrap <?= $selisih < 0 ? 'deadline-late' : '' ?>"><?= e(rupiah($selisih)) ?></td>
              <td><?= (int) $it['aktif'] === 1 ? '<span class="pill pill-selesai">Aktif</span>' : '<span class="pill pill-belum">Nonaktif</span>' ?></td>
              <?php if ($isAdmin): ?>
                <td class="right nowrap">
                  <div class="row-actions">
                    <a class="btn btn-sm" href="harga_satuan_form.php?id=<?= (int) $it['id'] ?>">Edit</a>
                    <form method="post" action="harga_satuan_delete.php" data-confirm="Hapus item harga &quot;<?= e($it['nama']) ?>&quot;? Pekerjaan yang memakainya tetap tersimpan harganya.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                      <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                    </form>
                  </div>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="grid" style="gap:20px">
    <?php if ($isAdmin): ?>
      <div class="card" id="form-harga-satuan">
        <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
          <div><h2>Item Harga Baru</h2><p>Sekali input, langsung bisa dipakai di semua project</p></div>
        </div>
        <form method="post" action="harga_satuan_form.php" class="grid" style="gap:13px">
          <?= csrf_field() ?>
          <div class="field">
            <label for="hs-nama">Nama item pekerjaan <span class="muted">*</span></label>
            <input type="text" id="hs-nama" name="nama" placeholder="mis. Penarikan Kabel Listrik" required>
          </div>
          <div class="field">
            <label for="hs-kategori">Kategori</label>
            <input type="text" id="hs-kategori" name="kategori" placeholder="mis. MEP" list="hs-kategori-list">
            <datalist id="hs-kategori-list">
              <option value="Persiapan"></option><option value="Pembongkaran"></option>
              <option value="Struktur"></option><option value="Arsitektur"></option>
              <option value="MEP"></option><option value="Finishing"></option>
            </datalist>
          </div>
          <div class="field">
            <label for="hs-satuan">Satuan</label>
            <input type="text" id="hs-satuan" name="satuan" placeholder="m / m2 / titik / unit / ls" list="hs-satuan-list">
            <datalist id="hs-satuan-list">
              <option value="m"></option><option value="m2"></option><option value="m3"></option>
              <option value="titik"></option><option value="unit"></option><option value="ls"></option>
            </datalist>
          </div>
          <div class="field">
            <label for="hs-jasa">Harga jasa ke perusahaan (Rp / satuan)</label>
            <input type="text" id="hs-jasa" name="harga_jasa" placeholder="mis. 5.000" data-harga-jasa>
            <span class="hint">Harga yang ditagihkan ke perusahaan pemilik pekerjaan.</span>
          </div>
          <div class="field">
            <label for="hs-upah">Upah dasar ke pekerja (Rp / satuan)</label>
            <input type="text" id="hs-upah" name="harga_upah" placeholder="mis. 2.500" data-harga-upah>
            <span class="hint">Upah per satuan; masih bisa di-override per pekerja saat penugasan.</span>
          </div>
          <div class="field">
            <label>Selisih / margin per satuan</label>
            <div class="money-note" data-harga-selisih>Isi harga jasa &amp; upah untuk melihat selisih.</div>
          </div>
          <div class="field">
            <label for="hs-ket">Keterangan</label>
            <input type="text" id="hs-ket" name="keterangan" placeholder="opsional">
          </div>
          <label class="check" style="width:fit-content">
            <input type="checkbox" name="aktif" value="1" checked>
            <span>Item aktif (muncul di pilihan pekerjaan)</span>
          </label>
          <button class="btn btn-primary" type="submit">Simpan Item Harga</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 class="card-title">Cara harga ini dipakai</h2>
      <ul class="steps">
        <li><strong>Harga jasa</strong> dipakai untuk menghitung nilai pengajuan ke perusahaan pemilik pekerjaan: <span class="mono">harga jasa × volume akhir</span>.</li>
        <li><strong>Upah pekerja</strong> dipakai untuk menghitung upah borongan pekerja: <span class="mono">upah × volume akhir × bagian</span>. Bagian bisa diatur per pekerja, dan tarifnya boleh di-override per orang.</li>
        <li>Angka yang sudah dipakai di sebuah pekerjaan <strong>tersimpan di pekerjaan itu</strong>, jadi mengubah master tidak mengubah nilai yang sudah berjalan.</li>
      </ul>
    </div>
  </div>
</div>

<?php render_footer(); ?>
