<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/partials.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$isPekerja = $u['role'] === 'pekerja';
$bolehAtur = in_array($u['role'], ['admin', 'pelaksana'], true);

$dari = valid_tanggal((string) ($_GET['dari'] ?? '')) ?: date('Y-m-01');
$sampai = valid_tanggal((string) ($_GET['sampai'] ?? '')) ?: date('Y-m-d');
if ($sampai < $dari) {
    [$dari, $sampai] = [$sampai, $dari];
}

$f = [
    'dari' => $dari,
    'sampai' => $sampai,
    'project_id' => (int) ($_GET['project_id'] ?? 0),
    'user_id' => $isPekerja ? (int) $u['id'] : (int) ($_GET['user_id'] ?? 0),
    'pekerjaan_id' => (int) ($_GET['pekerjaan_id'] ?? 0),
    'skema' => (string) ($_GET['skema'] ?? ''),
    'q' => trim((string) ($_GET['q'] ?? '')),
];
if (!in_array($f['skema'], ['harian', 'borongan'], true)) {
    $f['skema'] = '';
}

$rows = fetch_laporan($f);
$sum = laporan_summary($f);

/* ---------- Export CSV ---------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="hasil-kerja-harian-' . $dari . '_' . $sampai . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tanggal', 'Tenaga', 'Skema', 'Project', 'Pekerjaan', 'Volume', 'Satuan',
        'Tarif / Satuan', 'Upah Terhitung', 'Keterangan', 'Diinput Oleh'], ';');
    foreach ($rows as $r) {
        $borongan = $r['skema'] === 'borongan';
        fputcsv($out, [
            $r['tanggal'], $r['pekerja_nama'], skema_label((string) $r['skema']),
            $r['project_kode'] . ' ' . $r['project_nama'], $r['pekerjaan_nama'],
            str_replace('.', ',', num($r['volume'])), $r['satuan'],
            $borongan ? num($r['upah_satuan']) : '0',
            $borongan ? num($r['nilai']) : '0',
            $r['keterangan'],
            $r['created_by'] === null ? 'Sistem'
                : ((int) $r['created_by'] === (int) $r['user_id'] ? 'Sendiri' : (string) $r['pencatat_nama']),
        ], ';');
    }
    fclose($out);
    exit;
}

// rekap volume per pekerjaan (untuk tagihan perusahaan)
$perItem = [];
foreach ($rows as $r) {
    $k = (int) $r['pekerjaan_id'];
    $perItem[$k] ??= ['nama' => $r['pekerjaan_nama'], 'satuan' => $r['satuan'], 'project' => $r['project_kode'],
                      'volume' => 0.0, 'upah_borongan' => 0.0, 'kontrak' => (float) $r['volume_kontrak']];
    $perItem[$k]['volume'] += (float) $r['volume'];
    if ($r['skema'] === 'borongan') {
        $perItem[$k]['upah_borongan'] += (float) $r['nilai'];
    }
}
uasort($perItem, fn($a, $b) => $b['volume'] <=> $a['volume']);

$actions = '<a class="btn" href="jadwal_harian.php">Jadwal Harian</a>'
    . '<a class="btn btn-dark" href="' . e('laporan_kerja.php?' . http_build_query(array_merge($f, ['export' => '1']))) . '">Export CSV</a>';

render_header(
    $isPekerja ? 'Hasil Kerja Saya' : 'Hasil Kerja Harian',
    $isPekerja
        ? 'Catatan pekerjaan &amp; volume yang kamu laporkan beserta upahnya'
        : 'Laporan hasil kerja tiap tenaga — dasar upah borongan &amp; tagihan ke perusahaan',
    $actions
);
?>

<div class="stats">
  <?php
  stat_card('Volume Dilaporkan', e(num($sum['volume'])), $sum['baris'] . ' laporan · ' . $sum['item'] . ' item pekerjaan', 'info');
  stat_card('Tenaga Melapor', (string) $sum['orang'], 'periode ' . e(tgl($dari)) . ' – ' . e(tgl($sampai)), '');
  if ($isPekerja || can_view_all_wages()) {
      stat_card('Upah Borongan', e(rupiah($sum['upah_borongan'])), 'dari volume laporan pekerja borongan', 'ok');
  } else {
      stat_card('Item Dikerjakan', (string) $sum['item'], 'item pekerjaan pada periode ini', 'ok');
  }
  stat_card('Periode', e(tgl($dari) . ' – ' . tgl($sampai)), 'rentang tanggal laporan', 'warn');
  ?>
</div>

<div class="card">
  <form class="filters" method="get">
    <div class="field">
      <label for="dari">Dari</label>
      <input type="date" id="dari" name="dari" value="<?= e($dari) ?>">
    </div>
    <div class="field">
      <label for="sampai">Sampai</label>
      <input type="date" id="sampai" name="sampai" value="<?= e($sampai) ?>">
    </div>
    <?php if (!$isPekerja): ?>
      <div class="field">
        <label for="project_id">Project</label>
        <select id="project_id" name="project_id"><?php project_options($f['project_id']); ?></select>
      </div>
      <div class="field">
        <label for="user_id">Tenaga</label>
        <select id="user_id" name="user_id">
          <option value="">Semua tenaga</option>
          <?php foreach (all_users() as $p): ?>
            <?php if ($p['role'] === 'admin') { continue; } ?>
            <option value="<?= (int) $p['id'] ?>"<?= $f['user_id'] === (int) $p['id'] ? ' selected' : '' ?>>
              <?= e($p['nama']) ?> (<?= e(skema_label((string) $p['skema'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="skema">Skema</label>
        <select id="skema" name="skema">
          <option value="">Semua skema</option>
          <option value="borongan"<?= $f['skema'] === 'borongan' ? ' selected' : '' ?>>Borongan</option>
          <option value="harian"<?= $f['skema'] === 'harian' ? ' selected' : '' ?>>Harian</option>
        </select>
      </div>
      <div class="field">
        <label for="q">Cari</label>
        <input type="text" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Pekerjaan / tenaga">
      </div>
    <?php endif; ?>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="laporan_kerja.php">Reset</a>
  </form>
</div>

<div class="card flush">
  <div class="card-head">
    <div><h2>Daftar Laporan</h2><p><?= count($rows) ?> laporan pada filter ini</p></div>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">
      <strong>Belum ada laporan hasil kerja</strong>
      <span class="small">
        <?= $isPekerja
              ? 'Buka menu Jadwal untuk mengisi hasil kerjamu hari ini.'
              : 'Tenaga akan mengisi lewat menu Jadwal, atau kamu bisa input atas nama mereka.' ?>
      </span>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Tanggal</th>
            <?php if (!$isPekerja): ?><th>Tenaga</th><?php endif; ?>
            <th>Project</th>
            <th>Pekerjaan</th>
            <th>Volume</th>
            <th>Upah</th>
            <th>Keterangan</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap"><strong><?= e(tgl($r['tanggal'])) ?></strong></td>
            <?php if (!$isPekerja): ?>
              <td>
                <div style="display:flex;align-items:center;gap:9px">
                  <?= badge_avatar($r['pekerja_nama'], 'sm') ?>
                  <div class="cell-stack">
                    <strong><?= e($r['pekerja_nama']) ?></strong>
                    <small><?= e(skema_label((string) $r['skema'])) ?></small>
                  </div>
                </div>
              </td>
            <?php endif; ?>
            <td class="small"><?= e($r['project_kode']) ?></td>
            <td>
              <div class="cell-stack">
                <a href="pekerjaan_detail.php?id=<?= (int) $r['pekerjaan_id'] ?>"><?= e($r['pekerjaan_nama']) ?></a>
                <small>kontrak <?= e(num($r['volume_kontrak'])) ?> <?= e($r['satuan']) ?> · status <?= e(status_label((string) $r['pekerjaan_status'])) ?></small>
              </div>
            </td>
            <td class="nowrap"><span class="tag"><?= e(num($r['volume'])) ?> <?= e($r['satuan']) ?></span></td>
            <td class="nowrap">
              <?php if ($r['skema'] === 'borongan'): ?>
                <strong><?= e(rupiah($r['nilai'])) ?></strong>
                <div class="small muted"><?= e(rupiah($r['upah_satuan'])) ?>/<?= e($r['satuan']) ?></div>
              <?php else: ?>
                <span class="muted small">harian</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= $r['keterangan'] !== '' ? e($r['keterangan']) : '<span class="muted">—</span>' ?></td>
            <td class="right nowrap">
              <div class="row-actions">
                <?php if ($bolehAtur || (int) $r['user_id'] === (int) $u['id']): ?>
                  <a class="btn btn-sm" href="laporan_kerja_form.php?id=<?= (int) $r['id'] ?>">Edit</a>
                  <form method="post" action="laporan_kerja_delete.php" data-confirm="Hapus laporan <?= e($r['pekerja_nama']) ?> tanggal <?= e(tgl($r['tanggal'])) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="strong right" colspan="<?= $isPekerja ? 3 : 4 ?>">Total</td>
            <td class="strong nowrap"><?= e(num($sum['volume'])) ?></td>
            <td class="strong nowrap"><?= ($isPekerja || can_view_all_wages()) ? e(rupiah($sum['upah_borongan'])) : '—' ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if (!$isPekerja && $perItem): ?>
  <div class="card flush">
    <div class="card-head">
      <div>
        <h2>Volume per Item (dasar tagihan ke perusahaan)</h2>
        <p>Semua tenaga — harian &amp; borongan — volumenya dijumlahkan di sini</p>
      </div>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Item pekerjaan</th><th>Project</th><th>Volume laporan</th><th>Volume kontrak</th><th>Upah borongan</th><th>Capaian</th></tr>
        </thead>
        <tbody>
        <?php foreach ($perItem as $it): $pct = $it['kontrak'] > 0 ? min(100, (int) round($it['volume'] / $it['kontrak'] * 100)) : 0; ?>
          <tr>
            <td class="strong"><?= e($it['nama']) ?></td>
            <td class="small"><?= e($it['project']) ?></td>
            <td class="nowrap"><span class="tag"><?= e(num($it['volume'])) ?> <?= e($it['satuan']) ?></span></td>
            <td class="small nowrap"><?= e(num($it['kontrak'])) ?></td>
            <td class="nowrap"><?= can_view_all_wages() ? e(rupiah($it['upah_borongan'])) : '<span class="muted">—</span>' ?></td>
            <td>
              <div class="progress-cell"><?= progress_bar($pct, ' bar-lg') ?><b><?= $pct ?>%</b></div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
