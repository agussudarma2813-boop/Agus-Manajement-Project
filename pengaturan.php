<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);
$p = tenant();
if (!$p) {
    flash('Perusahaan tidak ditemukan.', 'err');
    redirect('dashboard.php');
}
$owner = is_owner();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tutup = (int) ($_POST['tutup_buku_tgl'] ?? 0);
    if ($tutup !== 0 && ($tutup < 1 || $tutup > 28)) {
        $errors[] = 'Tanggal tutup buku harus antara 1 dan 28 (atau 0 untuk tanpa tutup buku).';
    }
    if (empty($errors)) {
        // admin perusahaan hanya boleh mengubah pengaturan periode (bukan data lisensi/branding)
        db()->prepare('UPDATE perusahaan SET tutup_buku_tgl = ? WHERE id = ?')->execute([$tutup, (int) $p['id']]);
        flash('Pengaturan periode berhasil disimpan.');
        redirect('pengaturan.php');
    }
}

$berjalan = periode_gaji(date('Y-m-d'), 0);
$sebelumnya = periode_gaji(date('Y-m-d'), -1);

render_header(
    'Pengaturan Periode Gaji',
    'Tentukan kapan tutup buku supaya penanggalan gaji &amp; kasbon jelas',
    '<a class="btn" href="gaji.php">Gaji</a><a class="btn" href="kasbon.php">Kasbon</a>'
);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="grid grid-side">
  <div class="card">
    <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
      <div><h2>Tutup Buku</h2><p>Periode gaji &amp; kasbon mengikuti tanggal ini</p></div>
    </div>
    <form method="post" class="grid" style="gap:15px">
      <?= csrf_field() ?>
      <div class="field">
        <label for="tutup_buku_tgl">Tanggal tutup buku setiap bulan</label>
        <select id="tutup_buku_tgl" name="tutup_buku_tgl">
          <option value="0"<?= (int) $p['tutup_buku_tgl'] === 0 ? ' selected' : '' ?>>0 — tanpa tutup buku (periode = tanggal 1 s/d akhir bulan)</option>
          <?php for ($i = 1; $i <= 28; $i++): ?>
            <option value="<?= $i ?>"<?= (int) $p['tutup_buku_tgl'] === $i ? ' selected' : '' ?>>
              Tanggal <?= $i ?> (periode <?= $i + 1 ?> bulan lalu s/d tanggal <?= $i ?> bulan ini)
            </option>
          <?php endfor; ?>
        </select>
        <span class="hint">
          Contoh: tutup buku <strong>tanggal 25</strong> → periode gaji berjalan
          <strong><?= e(tgl((periode_gaji(date('Y-m-d'), 0)['dari']))) ?> – <?= e(tgl(periode_gaji(date('Y-m-d'), 0)['sampai'])) ?></strong>,
          dan gaji dibayarkan setelah tanggal itu.
        </span>
      </div>
      <div class="field">
        <label>Periode yang sedang dipakai</label>
        <div class="money-note">
          <strong>Berjalan:</strong> <?= e($berjalan['label']) ?><br>
          <strong>Sebelumnya:</strong> <?= e($sebelumnya['label']) ?>
        </div>
      </div>
      <div class="field">
        <button class="btn btn-primary" type="submit">Simpan Pengaturan</button>
      </div>
    </form>

    <p class="small muted" style="margin-top:16px">
      Cara hitung gaji:
      <strong>hari kerja × tarif harian</strong> (untuk skema harian) atau
      <strong>volume hasil kerja × tarif borongan</strong> (untuk skema borongan),
      lalu <strong>dikurangi kasbon</strong> yang belum dibayar.
    </p>
  </div>

  <div class="grid" style="gap:20px">
    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div><h2>Data Perusahaan</h2><p>Dikelola oleh pengelola aplikasi</p></div>
      </div>
      <dl class="kv" style="grid-template-columns:minmax(0,1fr)">
        <dt>Nama</dt><dd><?= e((string) $p['nama']) ?></dd>
        <dt>Link login</dt><dd class="mono"><?= $p['slug'] !== '' ? e('login.php?p=' . $p['slug']) : '—' ?></dd>
        <dt>Masa aktif</dt><dd><?= trim((string) $p['masa_aktif_sampai']) !== '' ? e(tgl($p['masa_aktif_sampai'])) : 'tanpa batas' ?></dd>
        <dt>Kuota pengguna</dt><dd><?= (int) $p['maks_user'] > 0 ? (int) $p['maks_user'] . ' pengguna' : 'tanpa batas' ?></dd>
      </dl>
      <p class="small muted" style="margin-top:14px">
        Untuk mengubah nama, logo, masa aktif, atau kuota — hubungi pengelola aplikasi.
        <?php if ($owner): ?>
          <br>(Anda pengelola: ubah lewat <a href="perusahaan.php">Perusahaan &amp; Lisensi</a>.)
        <?php endif; ?>
      </p>
    </div>

    <div class="card">
      <h2 class="card-title">Cara kerja gaji &amp; kasbon</h2>
      <ul class="steps">
        <li>Pekerja menerima kasbon kapan saja — dicatat di menu <strong>Kasbon</strong>.</li>
        <li>Setelah tanggal tutup buku, buka menu <strong>Gaji</strong> → daftar “Belum Digaji” sudah berisi gaji seharusnya tiap tenaga.</li>
        <li>Kasbon otomatis <strong>dipotong</strong> pada penggajian periode itu; sisanya dibayarkan.</li>
        <li>Tandai <strong>Sudah Dibayar</strong> saat gaji benar-benar diberikan — status “Belum dibayar/Dibayar” inilah yang jadi patokan.</li>
        <li>Kasbon yang belum sempat dipotong tetap aktif dan akan dipotong pada periode berikutnya.</li>
      </ul>
    </div>
  </div>
</div>

<?php render_footer(); ?>
