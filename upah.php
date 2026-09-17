<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/partials.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$myOwn = $u['role'] === 'pekerja';

[$defDari, $defSampai] = default_periode();
$f = [
    'dari'       => valid_tanggal((string) ($_GET['dari'] ?? '')) ?: $defDari,
    'sampai'     => valid_tanggal((string) ($_GET['sampai'] ?? '')) ?: $defSampai,
    'project_id' => (int) ($_GET['project_id'] ?? 0),
    'user_id'    => $myOwn ? (int) $u['id'] : (int) ($_GET['user_id'] ?? 0),
    'q'          => '',
];

$rekap = rekap_upah($f);

// Pekerja hanya melihat barisnya sendiri
if ($myOwn) {
    $rekap = array_values(array_filter($rekap, fn($r) => (int) $r['user_id'] === (int) $u['id']));
}

/* ---------- Export CSV ---------- */
if (isset($_GET['export'])) {
    $nama = $myOwn ? 'upah-' . slugify($u['nama']) : 'rekap-upah';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nama . '-' . $f['dari'] . '_' . $f['sampai'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Pekerja', 'Peran', 'Jabatan', 'Hari Kerja', 'Tarif Harian', 'Upah Harian',
        'Borongan Selesai', 'Borongan Berjalan', 'Total Upah Dibayar', 'Periode Dari', 'Periode Sampai'], ';');
    foreach ($rekap as $r) {
        fputcsv($out, [
            $r['nama'], role_label($r['role']), $r['jabatan'],
            num_hari($r['hari']),
            num($r['tarif']), num($r['upah_harian']),
            num($r['borongan_selesai']), num($r['borongan_berjalan']), num($r['total']),
            $f['dari'], $f['sampai'],
        ], ';');
    }
    fclose($out);
    exit;
}

$totalHari = array_sum(array_map(fn($r) => $r['hari'], $rekap));
$totalHarian = array_sum(array_map(fn($r) => $r['upah_harian'], $rekap));
$totalBorongan = array_sum(array_map(fn($r) => $r['borongan_selesai'], $rekap));
$totalBerjalan = array_sum(array_map(fn($r) => $r['borongan_berjalan'], $rekap));
$totalUpah = array_sum(array_map(fn($r) => $r['total'], $rekap));

$exportQuery = array_merge($_GET, ['export' => '1']);
$actions = '<a class="btn" href="absensi.php?' . e(http_build_query(['dari' => $f['dari'], 'sampai' => $f['sampai'], 'project_id' => $f['project_id'] ?: ''])) . '">Absensi</a>'
    . '<a class="btn btn-dark" href="' . e('upah.php?' . http_build_query($exportQuery)) . '">Export CSV</a>';

render_header(
    $myOwn ? 'Upah Saya' : 'Upah & Gaji',
    $myOwn
        ? 'Rincian hari kerja dan upah yang kamu peroleh'
        : 'Rekap hari kerja, upah harian dan upah borongan setiap pekerja',
    $actions
);
?>

<div class="stats">
  <?php
  stat_card($myOwn ? 'Hari Kerja Saya' : 'Total Hari Kerja', e(hari_format($totalHari)), 'periode ' . e(tgl($f['dari'])) . ' – ' . e(tgl($f['sampai'])), 'info');
  stat_card('Upah Harian', e(rupiah($totalHarian)), 'hari kerja × tarif harian masing-masing', '');
  stat_card('Borongan Selesai', e(rupiah($totalBorongan)), 'pekerjaan berstatus selesai', 'warn');
  stat_card('Total Upah', e(rupiah($totalUpah)), $totalBerjalan > 0 ? 'borongan berjalan ' . e(rupiah($totalBerjalan)) . ' belum dihitung' : 'siap dibayarkan', 'ok');
  ?>
</div>

<div class="card">
  <form class="filters" method="get">
    <div class="field">
      <label for="dari">Periode dari</label>
      <input type="date" id="dari" name="dari" value="<?= e($f['dari']) ?>">
    </div>
    <div class="field">
      <label for="sampai">Sampai</label>
      <input type="date" id="sampai" name="sampai" value="<?= e($f['sampai']) ?>">
    </div>
    <?php if (!$myOwn): ?>
      <div class="field">
        <label for="project_id">Project</label>
        <select id="project_id" name="project_id"><?php project_options($f['project_id']); ?></select>
      </div>
      <div class="field">
        <label for="user_id">Pekerja</label>
        <select id="user_id" name="user_id">
          <option value="">Semua pekerja</option>
          <?php foreach (all_users() as $p): ?>
            <?php if ($p['role'] === 'admin') { continue; } ?>
            <option value="<?= (int) $p['id'] ?>"<?= $f['user_id'] === (int) $p['id'] ? ' selected' : '' ?>>
              <?= e($p['nama']) ?> (<?= e(role_label($p['role'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="upah.php">Reset</a>
  </form>
  <p class="small muted" style="margin:14px 0 0">
    Upah harian mengikuti periode tanggal di atas. Nominal borongan dihitung dari pekerjaan yang sudah berstatus
    <em>Selesai</em> (borongan pada pekerjaan yang masih berjalan ditampilkan terpisah dan belum masuk total).
  </p>
</div>

<div class="card flush">
  <div class="card-head">
    <div>
      <h2><?= $myOwn ? 'Rincian Upah Saya' : 'Rekap Upah per Pekerja' ?></h2>
      <p><?= count($rekap) ?> pekerja memiliki catatan pada filter ini</p>
    </div>
  </div>
  <?php if (!$rekap): ?>
    <div class="empty">
      <strong>Belum ada data upah</strong>
      <span class="small">
        <?= can_record_absensi() ? 'Catat kehadiran pekerja dulu, atau isi tarif harian pada data pekerja.' : 'Hubungi pelaksana/admin untuk mencatat kehadiranmu.' ?>
      </span>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Pekerja</th>
            <th>Hari Kerja (absensi)</th>
            <th>Tarif / hari</th>
            <th>Upah Harian (absensi)</th>
            <th>Borongan Selesai</th>
            <th>Borongan Berjalan</th>
            <th>Total Upah</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rekap as $r): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:9px">
                <?= badge_avatar($r['nama'], 'sm') ?>
                <div class="cell-stack">
                  <strong><?= e($r['nama']) ?></strong>
                  <small>
                    <?= e($r['jabatan'] !== '' ? $r['jabatan'] : role_label($r['role'])) ?>
                    · <span class="pill <?= ($r['skema'] ?? 'harian') === 'borongan' ? 'pill-proses' : 'pill-belum' ?>"><?= e(skema_label((string) ($r['skema'] ?? 'harian'))) ?></span>
                  </small>
                </div>
              </div>
            </td>
            <td><span class="tag"><?= e(hari_format($r['hari'])) ?></span></td>
            <td class="small nowrap"><?= e(rupiah($r['tarif'])) ?></td>
            <td class="nowrap"><?= e(rupiah($r['upah_harian'])) ?></td>
            <td class="nowrap">
              <?php if ($r['borongan_selesai'] > 0): ?>
                <strong><?= e(rupiah($r['borongan_selesai'])) ?></strong>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td class="nowrap">
              <?php if ($r['borongan_berjalan'] > 0): ?>
                <span class="muted"><?= e(rupiah($r['borongan_berjalan'])) ?></span>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td class="strong nowrap"><?= e(rupiah($r['total'])) ?></td>
            <td class="right"><a class="btn btn-sm" href="upah_detail.php?id=<?= (int) $r['user_id'] ?>&dari=<?= e($f['dari']) ?>&sampai=<?= e($f['sampai']) ?>">Rincian</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="strong">Total</td>
            <td class="strong nowrap"><?= e(hari_format($totalHari)) ?></td>
            <td class="muted small">—</td>
            <td class="strong nowrap"><?= e(rupiah($totalHarian)) ?></td>
            <td class="strong nowrap"><?= e(rupiah($totalBorongan)) ?></td>
            <td class="nowrap muted"><?= e(rupiah($totalBerjalan)) ?></td>
            <td class="strong nowrap"><?= e(rupiah($totalUpah)) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
