<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/partials.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);

$f = [
    'project_id' => (int) ($_GET['project_id'] ?? 0),
    'status'     => (string) ($_GET['status'] ?? ''),
    'dari'       => valid_tanggal((string) ($_GET['dari'] ?? '')),
    'sampai'     => valid_tanggal((string) ($_GET['sampai'] ?? '')),
];
if (!in_array($f['status'], ['diajukan', 'dibayar'], true)) {
    $f['status'] = '';
}

$daftar = fetch_pengajuan($f);

// ringkasan: per project (hanya yang bisa saya kelola)
$projects = [];
foreach (selectable_projects() as $p) {
    $projects[(int) $p['id']] = $p;
}
$ringkas = [];
foreach ($daftar as $d) {
    $pid = (int) $d['project_id'];
    if (!isset($ringkas[$pid])) {
        $ringkas[$pid] = ['nama' => $d['project_nama'], 'kode' => $d['project_kode'], 'jumlah' => 0,
                          'diajukan' => 0.0, 'dibayar' => 0.0, 'item' => 0];
    }
    $ringkas[$pid]['jumlah']++;
    $ringkas[$pid]['item'] += (int) $d['jml_item'];
    if ($d['status'] === 'dibayar') {
        $ringkas[$pid]['dibayar'] += (float) $d['nilai'];
    } else {
        $ringkas[$pid]['diajukan'] += (float) $d['nilai'];
    }
}

$totMenunggu = 0.0;
$totDibayar = 0.0;
foreach ($daftar as $d) {
    if ($d['status'] === 'dibayar') {
        $totDibayar += (float) $d['nilai'];
    } else {
        $totMenunggu += (float) $d['nilai'];
    }
}

// sisa volume yang belum diajukan (hanya untuk project yang dipilih/dikelola)
$proyekKelola = array_values(array_filter(selectable_projects(), fn($p) => can_manage_project($p)));
$sisaRekap = [];
foreach ($proyekKelola as $p) {
    $r = ringkasan_tagihan_project((int) $p['id']);
    if ($r['nilai_sisa'] > 0 || $r['nilai_diajukan'] > 0) {
        $sisaRekap[(int) $p['id']] = $r + ['nama' => $p['nama'], 'kode' => $p['kode']];
    }
}

$actions = '<a class="btn" href="pengajuan_export.php">Export Excel</a>';
if ($proyekKelola) {
    $actions .= '<a class="btn btn-primary" href="pengajuan_form.php">+ Buat Pengajuan</a>';
}

render_header(
    'Pengajuan ke Perusahaan',
    'Pilih project, centang sub pekerjaan, isi volume yang diajukan — bisa bertahap sampai sisa habis',
    $actions
);
?>

<div class="stats">
  <?php
  stat_card('Pengajuan Dibuat', (string) count($daftar), count($ringkas) . ' project terlibat', 'info');
  stat_card('Menunggu Dibayar', e(rupiah($totMenunggu)), 'sudah diajukan, belum dibayar', $totMenunggu > 0 ? 'warn' : '');
  stat_card('Sudah Dibayar', e(rupiah($totDibayar)), 'dibayar perusahaan pemilik pekerjaan', 'ok');
  stat_card('Masih Ada Sisa', (string) count($sisaRekap), 'project yang volumenya belum habis diajukan', 'info');
  ?>
</div>

<?php if ($sisaRekap): ?>
  <div class="card flush">
    <div class="card-head">
      <div><h2>Volume yang Belum Diajukan</h2><p>Semua sub pekerjaan dihitung: volume akhir bila sudah ada, kalau belum pakai volume kontrak</p></div>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Project</th><th>Sub pekerjaan</th><th>Nilai kontrak</th><th>Sudah diajukan</th><th>Belum diajukan</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($sisaRekap as $pid => $r): ?>
          <tr>
            <td>
              <div class="cell-stack">
                <a href="project_detail.php?id=<?= (int) $pid ?>"><strong><?= e($r['nama']) ?></strong></a>
                <small><?= e($r['kode']) ?></small>
              </div>
            </td>
            <td class="small"><?= (int) $r['item'] ?> item</td>
            <td class="nowrap"><?= e(rupiah($r['nilai_kontrak'])) ?></td>
            <td class="nowrap"><?= e(rupiah($r['nilai_diajukan'])) ?></td>
            <td class="strong nowrap <?= $r['nilai_sisa'] > 0 ? 'deadline-soon' : '' ?>"><?= e(rupiah($r['nilai_sisa'])) ?></td>
            <td class="right nowrap">
              <?php if ($r['nilai_sisa'] > 0 && can_manage_project(get_project((int) $pid))): ?>
                <a class="btn btn-sm btn-primary" href="pengajuan_form.php?project_id=<?= (int) $pid ?>">Ajukan Sisa</a>
              <?php else: ?>
                <span class="pill pill-selesai">Sudah diajukan penuh</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <form class="filters" method="get">
    <div class="field">
      <label for="project_id">Project</label>
      <select id="project_id" name="project_id"><?php project_options($f['project_id']); ?></select>
    </div>
    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Semua status</option>
        <option value="diajukan"<?= $f['status'] === 'diajukan' ? ' selected' : '' ?>>Sudah diajukan (belum bayar)</option>
        <option value="dibayar"<?= $f['status'] === 'dibayar' ? ' selected' : '' ?>>Sudah dibayar</option>
      </select>
    </div>
    <div class="field">
      <label for="dari">Dari tanggal</label>
      <input type="date" id="dari" name="dari" value="<?= e($f['dari']) ?>">
    </div>
    <div class="field">
      <label for="sampai">Sampai</label>
      <input type="date" id="sampai" name="sampai" value="<?= e($f['sampai']) ?>">
    </div>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="pengajuan.php">Reset</a>
  </form>
</div>

<div class="card flush">
  <div class="card-head">
    <div><h2>Daftar Pengajuan</h2><p><?= count($daftar) ?> pengajuan pada filter ini</p></div>
  </div>
  <?php if (!$daftar): ?>
    <div class="empty">
      <strong>Belum ada pengajuan</strong>
      <span class="small">
        Klik <strong>+ Buat Pengajuan</strong>: pilih project → centang sub pekerjaan → isi volume yang
        mau diajukan. Volume yang belum sesuai kontrak bisa diajukan lagi nanti.
      </span>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Tanggal</th><th>Project</th><th>Sub pekerjaan</th><th>Volume diajukan</th>
            <th>Nilai ke perusahaan</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($daftar as $d): ?>
          <tr>
            <td class="nowrap"><strong><?= e(tgl($d['tanggal'])) ?></strong></td>
            <td>
              <div class="cell-stack">
                <a href="project_detail.php?id=<?= (int) $d['project_id'] ?>"><?= e($d['project_nama']) ?></a>
                <small><?= e($d['project_kode']) ?></small>
              </div>
            </td>
            <td class="small"><?= (int) $d['jml_item'] ?> item</td>
            <td class="nowrap"><?= e(num($d['volume'])) ?></td>
            <td class="strong nowrap"><?= e(rupiah($d['nilai'])) ?></td>
            <td>
              <?php if ($d['status'] === 'dibayar'): ?>
                <span class="pill pill-selesai">Sudah dibayar</span>
                <?php if ($d['tanggal_bayar'] !== ''): ?><div class="small muted"><?= e(tgl($d['tanggal_bayar'])) ?></div><?php endif; ?>
              <?php else: ?>
                <span class="pill pill-proses">Sudah diajukan</span>
              <?php endif; ?>
            </td>
            <td class="right nowrap">
              <div class="row-actions">
                <a class="btn btn-sm" href="pengajuan_detail.php?id=<?= (int) $d['id'] ?>">Rincian</a>
                <a class="btn btn-sm btn-dark" href="pengajuan_export.php?project_id=<?= (int) $d['project_id'] ?>">Excel</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
