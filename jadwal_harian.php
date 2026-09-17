<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/partials.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$isPekerja = $u['role'] === 'pekerja';
$bolehAtur = in_array($u['role'], ['admin', 'pelaksana'], true);

$tanggal = valid_tanggal((string) ($_GET['tanggal'] ?? '')) ?: date('Y-m-d');
$projectId = (int) ($_GET['project_id'] ?? 0);
$userFilter = $isPekerja ? (int) $u['id'] : (int) ($_GET['user_id'] ?? 0);

$f = ['tanggal' => $tanggal, 'project_id' => $projectId, 'user_id' => $userFilter];
$rows = fetch_jadwal($f);
$stats = jadwal_stats($f);

// laporan yang sudah masuk pada tanggal itu
$laporHariIni = fetch_laporan([
    'tanggal' => $tanggal,
    'project_id' => $projectId,
    'user_id' => $userFilter,
]);
$laporPerOrang = [];
foreach ($laporHariIni as $l) {
    $laporPerOrang[(int) $l['user_id']][] = $l;
}

$rekapPesan = jadwal_rekap_pesan($tanggal, $rows);

$actions = '';
if ($bolehAtur) {
    $actions .= '<a class="btn" href="jadwal_harian.php">Lihat Jadwal</a>';
    $actions .= '<a class="btn btn-primary" href="jadwal_form.php?tanggal=' . e($tanggal) . ($projectId ? '&project_id=' . $projectId : '') . '">+ Susun Jadwal</a>';
} elseif (!$isPekerja) {
    $actions .= '<a class="btn" href="jadwal_harian.php">Lihat Jadwal</a>';
}

render_header(
    $isPekerja ? 'Jadwal Saya' : 'Jadwal Harian',
    $isPekerja
        ? 'Jadwal kerja yang diberikan admin/pelaksana, lengkap dengan input hasil kerja'
        : 'Susun jadwal tenaga per hari, kirim ke pekerja via WhatsApp, dan pantau laporan masuk',
    $actions
);
?>

<div class="stats">
  <?php
  stat_card('Dijadwalkan', (string) $stats['baris'], ($isPekerja ? 'penugasan' : $stats['orang'] . ' orang') . ' pada ' . e(tgl($tanggal)), 'info');
  stat_card('Sudah Lapor', (string) $stats['sudah_lapor'], $stats['baris'] > 0 ? round($stats['sudah_lapor'] / $stats['baris'] * 100) . '% dari jadwal' : '—', 'ok');
  stat_card('Belum Lapor', (string) max(0, $stats['baris'] - $stats['sudah_lapor']), 'menunggu input hasil kerja', $stats['baris'] > $stats['sudah_lapor'] ? 'warn' : '');
  stat_card('Volume Dilaporkan', e(num(array_sum(array_map(fn($l) => (float) $l['volume'], $laporHariIni)))),
      count($laporHariIni) . ' laporan · ' . $stats['project'] . ' project', '');
  ?>
</div>

<?php if ($isPekerja): ?>
  <div class="alert alert-info">
    Skema upah kamu: <strong><?= e(skema_label((string) $u['skema'])) ?></strong>.
    <?php if ((string) $u['skema'] === 'borongan'): ?>
      Setiap volume yang kamu laporkan langsung dihitung ke upahmu (volume × tarif per satuan).
    <?php else: ?>
      Upahmu dihitung harian, jadi hasil kerja yang kamu input dipakai untuk tagihan ke perusahaan
      (gaji harianmu otomatis tercatat begitu kamu lapor kerja).
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <form class="filters" method="get">
    <div class="field">
      <label for="tanggal">Tanggal</label>
      <input type="date" id="tanggal" name="tanggal" value="<?= e($tanggal) ?>">
    </div>
    <?php if (!$isPekerja): ?>
      <div class="field">
        <label for="project_id">Project</label>
        <select id="project_id" name="project_id"><?php project_options($projectId); ?></select>
      </div>
      <div class="field">
        <label for="user_id">Tenaga</label>
        <select id="user_id" name="user_id"><?php user_options('pekerja', $userFilter, 'Semua tenaga'); ?></select>
      </div>
    <?php endif; ?>
    <button class="btn btn-dark" type="submit">Tampilkan</button>
    <a class="btn btn-ghost" href="jadwal_harian.php">Hari ini</a>
  </form>
</div>

<?php if ($bolehAtur && $rows): ?>
  <div class="card">
    <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
      <div>
        <h2>Kirim Jadwal via WhatsApp</h2>
        <p>Tombol per pekerja, atau salin rekap untuk grup WA tim</p>
      </div>
    </div>
    <div class="grid grid-2">
      <div>
        <div class="chips">
          <?php foreach ($rows as $r): ?>
            <?php $wa = wa_number((string) $r['pekerja_telepon']); ?>
            <?php if ($wa !== ''): ?>
              <a class="btn btn-sm" href="<?= e(wa_link((string) $r['pekerja_telepon'], jadwal_pesan($r))) ?>" target="_blank" rel="noopener">
                <?= nav_icon('wa') ?> <?= e($r['pekerja_nama']) ?>
              </a>
            <?php else: ?>
              <span class="chip"><?= badge_avatar($r['pekerja_nama'], 'sm') ?><?= e($r['pekerja_nama']) ?>
                <span class="muted small">nomor belum diisi</span>
              </span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <p class="small muted" style="margin:14px 0 0">
          Nomor diambil dari data pekerja. Isi/benahi di <a href="users.php">Pengguna &amp; Akun</a> bila ada yang kosong.
        </p>
      </div>
      <div>
        <label class="small muted" for="rekap-wa">Rekap untuk grup WA</label>
        <textarea id="rekap-wa" readonly rows="9" style="font-size:12.5px"><?= e($rekapPesan) ?></textarea>
        <div class="row-actions" style="justify-content:flex-start;margin-top:10px">
          <button class="btn btn-sm" type="button" data-copy-target="#rekap-wa">Salin teks</button>
          <a class="btn btn-sm btn-dark" href="<?= e(wa_link('', $rekapPesan)) ?>" target="_blank" rel="noopener">Buka WhatsApp</a>
        </div>
        <p class="small muted" style="margin:10px 0 0">
          "Buka WhatsApp" tanpa nomor → pilih grup tim, lalu paste teks yang tersalin.
        </p>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card flush">
  <div class="card-head">
    <div>
      <h2><?= $isPekerja ? 'Tugas Terjadwal' : 'Daftar Jadwal' ?></h2>
      <p><?= count($rows) ?> baris · <?= e(tgl($tanggal)) ?></p>
    </div>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">
      <strong>Belum ada jadwal pada tanggal ini</strong>
      <span class="small">
        <?= $bolehAtur
              ? 'Klik "Susun Jadwal" untuk menentukan siapa bekerja di project mana.'
              : 'Jadwal akan muncul setelah admin/pelaksana menyusunnya.' ?>
      </span>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <?php if (!$isPekerja): ?><th>Tenaga</th><?php endif; ?>
            <th>Project &amp; Lokasi</th>
            <th>Pekerjaan</th>
            <th>Skema</th>
            <th>Hasil Kerja</th>
            <th>Catatan</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $lapr = $laporPerOrang[(int) $r['user_id']] ?? []; ?>
          <tr>
            <?php if (!$isPekerja): ?>
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
            <td>
              <div class="cell-stack">
                <a href="project_detail.php?id=<?= (int) $r['project_id'] ?>"><?= e($r['project_nama']) ?></a>
                <small><?= e($r['project_kode']) ?><?= $r['lokasi_nama'] ? ' · ' . e($r['lokasi_nama']) : '' ?></small>
              </div>
            </td>
            <td class="small"><?= $r['pekerjaan_nama'] ? e($r['pekerjaan_nama']) : '<span class="muted">pilih saat lapor</span>' ?></td>
            <td>
              <span class="pill <?= $r['pekerja_skema'] === 'borongan' ? 'pill-proses' : 'pill-belum' ?>">
                <?= e(skema_label((string) $r['pekerja_skema'])) ?>
              </span>
            </td>
            <td class="small">
              <?php if ($lapr): ?>
                <div class="cell-stack">
                  <span class="pill pill-selesai"><?= count($lapr) ?> laporan</span>
                  <small>volume <?= e(num(array_sum(array_map(fn($x) => (float) $x['volume'], $lapr)))) ?></small>
                </div>
              <?php else: ?>
                <span class="pill pill-tertunda">Belum lapor</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= $r['catatan'] !== '' ? e($r['catatan']) : '<span class="muted">—</span>' ?></td>
            <td class="right nowrap">
              <div class="row-actions">
                <?php if ($isPekerja || $bolehAtur): ?>
                  <a class="btn btn-sm btn-primary" href="laporan_kerja_form.php?jadwal_id=<?= (int) $r['id'] ?>">
                    <?= $isPekerja ? 'Input Hasil Kerja' : 'Input atas nama' ?>
                  </a>
                <?php endif; ?>
                <?php if ($bolehAtur): ?>
                  <a class="btn btn-sm" href="jadwal_form.php?id=<?= (int) $r['id'] ?>">Edit</a>
                  <form method="post" action="jadwal_delete.php" data-confirm="Hapus jadwal <?= e($r['pekerja_nama']) ?> pada <?= e(tgl($r['tanggal'])) ?>?">
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
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($laporHariIni): ?>
  <div class="card flush">
    <div class="card-head">
      <div><h2>Hasil Kerja Masuk</h2><p><?= count($laporHariIni) ?> laporan pada <?= e(tgl($tanggal)) ?></p></div>
      <span class="spacer"></span>
      <a class="btn btn-sm" href="laporan_kerja.php?tanggal=<?= e($tanggal) ?>">Kelola Semua</a>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Tenaga</th><th>Pekerjaan</th><th>Volume</th><th>Upah Terhitung</th><th>Catatan</th></tr>
        </thead>
        <tbody>
        <?php foreach ($laporHariIni as $l): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:9px">
                <?= badge_avatar($l['pekerja_nama'], 'sm') ?>
                <div class="cell-stack">
                  <strong><?= e($l['pekerja_nama']) ?></strong>
                  <small><?= e(skema_label((string) $l['skema'])) ?></small>
                </div>
              </div>
            </td>
            <td>
              <div class="cell-stack">
                <strong><?= e($l['pekerjaan_nama']) ?></strong>
                <small><?= e($l['project_kode']) ?></small>
              </div>
            </td>
            <td class="nowrap"><span class="tag"><?= e(num($l['volume'])) ?> <?= e($l['satuan']) ?></span></td>
            <td class="nowrap">
              <?php if ($l['skema'] === 'borongan'): ?>
                <strong><?= e(rupiah($l['nilai'])) ?></strong>
              <?php else: ?>
                <span class="muted small">harian — untuk tagihan</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= $l['keterangan'] !== '' ? e($l['keterangan']) : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
