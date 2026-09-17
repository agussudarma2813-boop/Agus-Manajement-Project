<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/partials.php';

$u = require_login();
$myOwn = $u['role'] === 'pekerja';

[$defDari, $defSampai] = default_periode();
$f = [
    'dari'       => valid_tanggal((string) ($_GET['dari'] ?? '')) ?: $defDari,
    'sampai'     => valid_tanggal((string) ($_GET['sampai'] ?? '')) ?: $defSampai,
    'project_id' => (int) ($_GET['project_id'] ?? 0),
    'user_id'    => $myOwn ? (int) $u['id'] : (int) ($_GET['user_id'] ?? 0),
    'q'          => trim((string) ($_GET['q'] ?? '')),
];

$rows = fetch_absensi($f);
$sum = absensi_summary($f);

// Rekap hari per pekerja dalam periode filter
$perOrang = [];
foreach ($rows as $r) {
    $id = (int) $r['user_id'];
    if (!isset($perOrang[$id])) {
        $perOrang[$id] = ['nama' => $r['pekerja_nama'], 'jabatan' => $r['pekerja_jabatan'], 'hari' => 0.0, 'upah' => 0.0, 'hari_terakhir' => ''];
    }
    $perOrang[$id]['hari'] += (float) $r['hari'];
    $perOrang[$id]['upah'] += (float) $r['hari'] * (float) $r['upah'];
    if ($r['tanggal'] > $perOrang[$id]['hari_terakhir']) {
        $perOrang[$id]['hari_terakhir'] = $r['tanggal'];
    }
}
uasort($perOrang, fn($a, $b) => $b['hari'] <=> $a['hari']);

$lihatNominal = can_view_all_wages();

$actions = '<a class="btn" href="upah.php?' . e(http_build_query(['dari' => $f['dari'], 'sampai' => $f['sampai'], 'project_id' => $f['project_id'] ?: ''])) . '">Rekap Upah</a>';
if (!$myOwn && can_record_absensi()) {
    $link = 'absensi_form.php?tanggal=' . e($f['sampai']) . ($f['project_id'] ? '&project_id=' . $f['project_id'] : '');
    $actions .= '<a class="btn btn-primary" href="' . $link . '">+ Catat Kehadiran</a>';
}

render_header(
    $myOwn ? 'Absensi Saya' : 'Absensi Hari Kerja',
    $myOwn
        ? 'Catatan hari kerja kamu beserta upah hariannya'
        : 'Catatan kehadiran pekerja per tanggal, per project, beserta upah hariannya',
    $actions
);
?>

<div class="stats">
  <?php
  stat_card('Total Hari Kerja', e(hari_format($sum['hari'])), $sum['baris'] . ' baris absensi · ' . $sum['tanggal'] . ' tanggal', 'info');
  if ($myOwn) {
      stat_card('Upah Harian Saya', e(rupiah($sum['upah'])), 'hari kerja × tarif harian', 'ok');
      stat_card('Periode', e(tgl($f['dari']) . ' – ' . tgl($f['sampai'])), 'rentang tanggal catatan', 'warn');
      stat_card('Tanggal Tercatat', (string) $sum['tanggal'], 'hari dengan catatan kehadiran', '');
  } else {
      stat_card('Orang Bekerja', (string) $sum['orang'], 'pekerja tercatat pada periode ini', '');
      stat_card('Upah Harian', e(rupiah($sum['upah'])), 'hari kerja × tarif harian', 'ok');
      stat_card('Periode', e(tgl($f['dari']) . ' – ' . tgl($f['sampai'])), 'rentang tanggal catatan', 'warn');
  }
  ?>
</div>

<div class="card">
  <form class="filters" method="get">
    <div class="field">
      <label for="dari">Dari tanggal</label>
      <input type="date" id="dari" name="dari" value="<?= e($f['dari']) ?>">
    </div>
    <div class="field">
      <label for="sampai">Sampai tanggal</label>
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
      <div class="field">
        <label for="q">Cari</label>
        <input type="text" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Nama / keterangan">
      </div>
    <?php endif; ?>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="absensi.php">Reset</a>
  </form>
</div>

<div class="grid grid-side">
  <div class="card flush">
    <div class="card-head">
      <div><h2>Catatan Kehadiran</h2><p><?= count($rows) ?> baris pada periode ini</p></div>
    </div>
    <?php if (!$rows): ?>
      <div class="empty">
        <strong>Belum ada catatan kehadiran</strong>
        <span class="small">
          <?= can_record_absensi() ? 'Klik "Catat Kehadiran" untuk mencatat hari kerja beberapa pekerja sekaligus.' : 'Catatan akan muncul setelah pelaksana atau admin mengisinya.' ?>
        </span>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Tanggal</th>
              <?= $myOwn ? '' : '<th>Pekerja</th>' ?>
              <th>Project</th>
              <th>Pekerjaan</th>
              <th>Hari</th>
              <?php if ($lihatNominal): ?><th>Tarif / hari</th><th>Upah</th><?php endif; ?>
              <th>Keterangan</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
            $bolehNominal = can_view_wages_of((int) $r['user_id']);
            $upahBaris = (float) $r['hari'] * (float) $r['upah'];
            ?>
            <tr>
              <td class="nowrap">
                <div class="cell-stack">
                  <strong><?= e(tgl($r['tanggal'])) ?></strong>
                  <small><?= e(date('D', (int) strtotime($r['tanggal']))) ?></small>
                </div>
              </td>
              <?php if (!$myOwn): ?>
                <td>
                  <div style="display:flex;align-items:center;gap:9px">
                    <?= badge_avatar($r['pekerja_nama'], 'sm') ?>
                    <div class="cell-stack">
                      <strong><?= e($r['pekerja_nama']) ?></strong>
                      <small><?= e($r['pekerja_jabatan'] !== '' ? $r['pekerja_jabatan'] : role_label($r['pekerja_role'])) ?></small>
                    </div>
                  </div>
                </td>
              <?php endif; ?>
              <td class="small"><?= $r['project_nama'] ? e($r['project_nama']) : '<span class="muted">—</span>' ?></td>
              <td class="small"><?= $r['pekerjaan_nama'] ? e($r['pekerjaan_nama']) : '<span class="muted">—</span>' ?></td>
              <td><span class="tag"><?= e(hari_format($r['hari'])) ?></span></td>
              <?php if ($lihatNominal): ?>
                <td class="small nowrap"><?= upah_or_hidden($bolehNominal, $r['upah']) ?></td>
                <td class="strong nowrap"><?= upah_or_hidden($bolehNominal, $upahBaris) ?></td>
              <?php endif; ?>
              <td class="small"><?= $r['keterangan'] !== '' ? e($r['keterangan']) : '<span class="muted">—</span>' ?></td>
              <td class="right nowrap">
                <?php if (can_record_absensi(get_project((int) ($r['project_id'] ?? 0)))): ?>
                  <div class="row-actions">
                    <a class="btn btn-sm" href="absensi_form.php?id=<?= (int) $r['id'] ?>">Edit</a>
                    <form method="post" action="absensi_delete.php" data-confirm="Hapus catatan kehadiran <?= e($r['pekerja_nama']) ?> tanggal <?= e(tgl($r['tanggal'])) ?>?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="<?= $myOwn ? 3 : 4 ?>" class="strong right">Total periode ini</td>
              <td class="strong"><?= e(hari_format($sum['hari'])) ?></td>
              <?php if ($lihatNominal): ?>
                <td></td>
                <td class="strong nowrap"><?= e(rupiah($sum['upah'])) ?></td>
              <?php endif; ?>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="grid" style="gap:20px">
    <?php if (!$myOwn): ?>
      <div class="card">
        <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
          <div><h2>Hari Kerja per Pekerja</h2><p>Periode <?= e(tgl($f['dari'])) ?> – <?= e(tgl($f['sampai'])) ?></p></div>
        </div>
        <?php if (!$perOrang): ?>
          <p class="muted small">Belum ada data pada periode ini.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>Pekerja</th><th>Hari</th><?php if ($lihatNominal): ?><th>Upah</th><?php endif; ?><th></th></tr></thead>
              <tbody>
              <?php foreach ($perOrang as $uid => $p): ?>
                <tr>
                  <td>
                    <div class="cell-stack">
                      <strong><?= e($p['nama']) ?></strong>
                      <small>Terakhir: <?= e(tgl($p['hari_terakhir'])) ?></small>
                    </div>
                  </td>
                  <td class="strong nowrap"><?= e(hari_format($p['hari'])) ?></td>
                  <?php if ($lihatNominal): ?>
                    <td class="nowrap"><?= e(rupiah($p['upah'])) ?></td>
                  <?php endif; ?>
                  <td class="right"><a class="btn btn-sm" href="upah_detail.php?id=<?= (int) $uid ?>">Detail</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 class="card-title">Cara kerja absensi</h2>
      <ul class="steps">
        <li><strong>Harian.</strong> Tiap catatan kehadiran menambah jumlah hari kerja pekerja. Upah hariannya = jumlah hari × tarif harian pekerja (tarif tersimpan saat dicatat, jadi ubah tarif tidak mengubah riwayat).</li>
        <li><strong>Borongan.</strong> Nominal borongan ditempel saat menugaskan pekerja ke sebuah pekerjaan, bisa berbeda tiap orang. Nominalnya dihitung sebagai upah begitu pekerjaan berstatus <em>Selesai</em>.</li>
        <li><strong>Setengah hari.</strong> Isi jumlah hari <span class="mono">0,5</span> bila pekerja hanya masuk sebagian hari.</li>
        <?php if ($lihatNominal && !$myOwn): ?>
          <li><strong>Pekerja hanya melihat miliknya.</strong> Pekerja tidak dapat melihat nominal upah pekerja lain.</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php render_footer(); ?>
