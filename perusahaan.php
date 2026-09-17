<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/partials.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_owner();

$q = trim((string) ($_GET['q'] ?? ''));
$daftar = fetch_perusahaan($q);

// ringkasan tiap perusahaan
$ringkas = [];
$totalUser = 0;
$aktifCount = 0;
$kadaluarsa = 0;
foreach ($daftar as $p) {
    $r = tenant_ringkas((int) $p['id']);
    $ringkas[(int) $p['id']] = $r;
    $totalUser += $r['users'];
    if ((int) $p['aktif'] !== 1) {
        // nonaktif
    } elseif ($r['sisa_hari'] !== 0 && ((string) $p['masa_aktif_sampai'] !== '' && $p['masa_aktif_sampai'] < date('Y-m-d'))) {
        $kadaluarsa++;
    } else {
        $aktifCount++;
    }
}

$tutupLoginBantu = isset($_GET['bantu']) ? (int) $_GET['bantu'] : 0;

render_header(
    'Perusahaan &amp; Lisensi',
    'Kelola pelanggan aplikasi: masa aktif, kuota pengguna, dan nama/logo mereka',
    '<a class="btn btn-primary" href="perusahaan_form.php">+ Perusahaan Baru</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Jumlah Perusahaan', (string) count($daftar), 'seluruh pelanggan terdaftar', 'info');
  stat_card('Aktif', (string) $aktifCount, 'masa aktif masih berjalan', 'ok');
  stat_card('Perlu Perhatian', (string) $kadaluarsa, 'masa aktif sudah berakhir', $kadaluarsa > 0 ? 'danger' : '');
  stat_card('Total Pengguna', (string) $totalUser, 'seluruh perusahaan', '');
  ?>
</div>

<div class="card">
  <form class="filters" method="get">
    <div class="field" style="flex:1;min-width:220px">
      <label for="q">Cari perusahaan</label>
      <input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="Nama, link, atau nama kontak">
    </div>
    <button class="btn btn-dark" type="submit">Cari</button>
    <a class="btn btn-ghost" href="perusahaan.php">Reset</a>
  </form>
  <p class="small muted" style="margin:14px 0 0">
    Setiap perusahaan punya data terpisah total (project, pekerja, absensi, jadwal, upah, harga satuan).
    Pemilik aplikasi bisa <strong>masuk sebagai</strong> sebuah perusahaan untuk membantu tanpa memakai kata sandi mereka.
  </p>
</div>

<?php if (!$daftar): ?>
  <div class="card">
    <div class="empty">
      <strong>Belum ada perusahaan</strong>
      <span class="small">Buat perusahaan baru, lalu buatkan akun admin pertamanya.</span>
    </div>
  </div>
<?php else: ?>
  <div class="grid grid-2">
    <?php foreach ($daftar as $p): ?>
      <?php
      $pid = (int) $p['id'];
      $r = $ringkas[$pid];
      $sampai = trim((string) $p['masa_aktif_sampai']);
      $lewat = $sampai !== '' && $sampai < date('Y-m-d');
      $maks = (int) $p['maks_user'];
      $penuh = $maks > 0 && $r['users'] >= $maks;
      ?>
      <div class="card tenant-card">
        <div class="tenant-head">
          <?php if (trim((string) $p['logo']) !== ''): ?>
            <img class="tenant-logo" src="<?= e($p['logo']) ?>" alt="Logo <?= e($p['nama']) ?>">
          <?php else: ?>
            <span class="tenant-logo-kosong"><?= e(initials((string) $p['nama'])) ?></span>
          <?php endif; ?>
          <div class="grow">
            <h3><?= e($p['nama']) ?></h3>
            <span class="small muted">
              <?= $p['kontak_nama'] !== '' ? e($p['kontak_nama']) : 'tanpa kontak' ?>
              <?= $p['kontak_telepon'] !== '' ? ' · ' . e($p['kontak_telepon']) : '' ?>
            </span>
          </div>
          <?php if ((int) $p['aktif'] !== 1): ?>
            <span class="pill pill-late">Dinonaktifkan</span>
          <?php elseif ($lewat): ?>
            <span class="pill pill-tertunda">Masa aktif habis</span>
          <?php else: ?>
            <span class="pill pill-selesai">Aktif</span>
          <?php endif; ?>
        </div>

        <div class="tenant-meta">
          <span>Pengguna: <strong><?= (int) $r['users'] ?></strong><?= $maks > 0 ? ' / ' . $maks . ($penuh ? ' (penuh)' : '') : ' (tanpa batas)' ?></span>
          <span>Masa aktif: <strong><?= $sampai !== '' ? e(tgl($sampai)) : 'tanpa batas' ?></strong></span>
          <?php if ($sampai !== '' && !$lewat): ?>
            <span><?= (int) $r['sisa_hari'] ?> hari lagi</span>
          <?php endif; ?>
          <span>Project: <strong><?= (int) $r['projects'] ?></strong></span>
          <span>Item pekerjaan: <strong><?= (int) $r['pekerjaan'] ?></strong></span>
          <span>Harga satuan: <strong><?= (int) $r['harga'] ?></strong></span>
          <span>Laporan kerja: <strong><?= (int) $r['laporan'] ?></strong></span>
        </div>

        <?php if (trim((string) $p['slug']) !== ''): ?>
          <div class="small">
            Link login perusahaan:
            <span class="mono">login.php?p=<?= e($p['slug']) ?></span>
            <button class="btn btn-sm" type="button" data-copy-text="login.php?p=<?= e($p['slug']) ?>">Salin</button>
          </div>
        <?php endif; ?>

        <?php if (trim((string) $p['catatan']) !== ''): ?>
          <p class="small muted" style="margin:0"><?= nl2br(e($p['catatan'])) ?></p>
        <?php endif; ?>

        <div class="row-actions" style="justify-content:flex-start;flex-wrap:wrap;gap:8px">
          <a class="btn btn-sm" href="perusahaan_form.php?id=<?= $pid ?>">Edit</a>
          <form method="post" action="perusahaan_bantu.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $pid ?>">
            <button class="btn btn-sm btn-primary" type="submit">Masuk sebagai</button>
          </form>
          <form method="post" action="perusahaan_toggle.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $pid ?>">
            <button class="btn btn-sm" type="submit"><?= (int) $p['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
          </form>
          <details class="hapus-perusahaan">
            <summary class="btn btn-sm btn-danger">Hapus</summary>
            <div class="hapus-box">
              <p class="small muted" style="margin:0 0 10px">
                Menghapus perusahaan ini akan menghapus SELURUH datanya (project, pekerja, absensi, jadwal, upah, harga satuan).
                Tidak bisa dibatalkan. Ketik <strong><?= e($p['nama']) ?></strong> untuk mengonfirmasi.
              </p>
              <form method="post" action="perusahaan_hapus.php" class="grid" style="gap:10px">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $pid ?>">
                <input type="text" name="konfirmasi" placeholder="ketik nama perusahaan" required>
                <button class="btn btn-sm btn-danger" type="submit">Hapus permanen</button>
              </form>
            </div>
          </details>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2 class="card-title">Cara menjual ke teman (panduan singkat)</h2>
  <ul class="steps">
    <li><strong>Buat perusahaan</strong> lewat tombol “+ Perusahaan Baru”: isi nama usaha mereka, logo, masa aktif, dan kuota pengguna (mis. 5).</li>
    <li><strong>Buatkan akun admin pertama</strong> di halaman yang sama (username &amp; kata sandi), lalu kirimkan <em>link login</em> perusahaan mereka beserta akun itu.</li>
    <li><strong>Mereka login</strong> → datanya terpisah total dari perusahaan lain; mereka bisa menambah pekerja sendiri sampai batas kuota.</li>
    <li><strong>Perpanjangan</strong>: ubah “masa aktif sampai” di halaman Edit. Kalau tanggal terlewat, semua akun perusahaan itu otomatis tidak bisa login sampai diperpanjang.</li>
    <li><strong>Berhenti berlangganan</strong>: klik Nonaktifkan (data tetap aman, hanya tidak bisa diakses).</li>
  </ul>
</div>

<?php render_footer(); ?>
