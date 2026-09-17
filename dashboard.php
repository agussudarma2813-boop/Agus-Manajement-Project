<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/partials.php';

$u = require_login();
$myTasks = $u['role'] === 'pekerja';

// ---- Angka ringkasan ----
if ($myTasks) {
    $st = pekerja_stats((int) $u['id']);
    $totalProject = (int) $st['projects'];
    $projectBerjalan = (int) $st['proses'];
    $aktif = (int) $st['tugas'] - (int) $st['selesai'];
    $terlambat = (int) $st['terlambat'];
    $avg = avg_progress([]);
} else {
    $projects = fetch_projects([]);
    $totalProject = count($projects);
    $projectBerjalan = count(array_filter($projects, fn($p) => $p['status'] === 'berjalan'));
    $aktif = count_pekerjaan(['status' => 'proses']);
    $terlambat = count_pekerjaan(['status' => 'terlambat']);
    $avg = avg_progress([]);
}

$perhatian = fetch_pekerjaan(['status' => 'terlambat']);
$berjalan = fetch_pekerjaan(['status' => 'proses']);

// Deadline terdekat (belum selesai, punya deadline, urut terdekat)
$dekat = array_values(array_filter(fetch_pekerjaan([]), fn($p) => $p['status'] !== 'selesai' && $p['deadline'] !== ''));
usort($dekat, fn($a, $b) => strcmp($a['deadline'], $b['deadline']));
$dekat = array_slice($dekat, 0, 5);

// ---- Ringkasan hari kerja & upah bulan ini ----
[$awalBulan, $akhirBulan] = default_periode();
$lihatNominal = can_view_all_wages();
$upahBulan = upah_total_periode($awalBulan, $akhirBulan, 0, $myTasks ? (int) $u['id'] : 0);
$boronganSaya = borongan_stats((int) $u['id']);

// Jadwal hari ini & yang belum lapor
$jadwalHariIni = fetch_jadwal(['tanggal' => date('Y-m-d')]);
$belumLapor = array_values(array_filter($jadwalHariIni, fn($j) => (int) $j['jml_laporan'] === 0));
$laporHariIni = fetch_laporan(['tanggal' => date('Y-m-d')]);

$actions = '';
if (can_manage_users()) {
    $actions .= '<a class="btn" href="projects.php">Kelola Project</a>';
    $actions .= '<a class="btn btn-primary" href="project_form.php">+ Project Baru</a>';
} elseif ($u['role'] === 'pelaksana') {
    $actions .= '<a class="btn" href="pengajuan.php">Pengajuan</a>';
    $actions .= '<a class="btn btn-primary" href="pekerjaan_form.php">+ Pekerjaan Baru</a>';
}

render_header('Dashboard', 'Ringkasan kondisi project, pekerjaan dan tim lapangan · ' . date('d/m/Y'), $actions);
?>

<?php if ($myTasks): ?>
  <div class="alert alert-info">
    Kamu masuk sebagai <strong>Pekerja</strong> — daftar di bawah hanya menampilkan pekerjaan yang ditugaskan kepadamu.
    Perbarui persentase progress langsung dari halaman detail pekerjaan.
  </div>
<?php endif; ?>

<div class="stats">
  <?php
  stat_card($myTasks ? 'Project Melibatkan Saya' : 'Total Project', (string) $totalProject,
      $myTasks ? 'project yang memakai tenaga kamu' : 'seluruh project terdaftar', 'info');
  stat_card($myTasks ? 'Tugas Berjalan' : 'Project Berjalan', (string) $projectBerjalan,
      $myTasks ? 'tugas dengan status berjalan' : 'project dengan status berjalan', '');
  stat_card($myTasks ? 'Tugas Aktif' : 'Pekerjaan Aktif', (string) $aktif,
      'belum selesai · rata-rata progress ' . $avg . '%', 'warn');
  stat_card('Pekerjaan Terlambat', (string) $terlambat,
      $terlambat > 0 ? '<span class="deadline-late">butuh tindakan segera</span>' : 'semua sesuai jadwal',
      $terlambat > 0 ? 'danger' : 'ok');
  ?>
</div>

<div class="grid grid-side">
  <div class="grid" style="gap:20px">
    <?php if (!$myTasks): ?>
      <div class="card flush">
        <div class="card-head">
          <div>
            <h2>Progress per Project</h2>
            <p>Rata-rata progress seluruh pekerjaan di tiap project</p>
          </div>
          <span class="spacer"></span>
          <a class="btn btn-sm" href="projects.php">Lihat semua</a>
        </div>
        <div style="padding:20px">
          <?php if (!$projects): ?>
            <div class="empty"><strong>Belum ada project</strong><span class="small">Mulai dengan membuat project pertama.</span></div>
          <?php else: ?>
            <div class="bars-list">
              <?php foreach (array_slice($projects, 0, 6) as $p): $avgP = (int) round((float) $p['avg_progress']); ?>
                <div>
                  <div class="bl-head">
                    <span>
                      <a href="project_detail.php?id=<?= (int) $p['id'] ?>"><strong><?= e($p['nama']) ?></strong></a>
                      <span class="mono muted small">· <?= e($p['kode']) ?></span>
                    </span>
                    <span class="small muted nowrap">
                      <?= (int) $p['jml_selesai'] ?>/<?= (int) $p['jml_pekerjaan'] ?> pekerjaan selesai · <?= $avgP ?>%
                    </span>
                  </div>
                  <?= progress_bar($avgP, ' bar-lg') ?>
                  <div class="small muted" style="margin-top:6px">
                    <?= project_status_pill($p['status']) ?>
                    <?php if ((int) $p['jml_terlambat'] > 0): ?>
                      <span class="pill pill-late"><?= (int) $p['jml_terlambat'] ?> terlambat</span>
                    <?php endif; ?>
                    <span><?= (int) $p['jml_lokasi'] ?> lokasi</span>
                    <span>· Pelaksana: <?= $p['pelaksana_nama'] ? e($p['pelaksana_nama']) : 'belum ditetapkan' ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card flush">
      <div class="card-head">
        <div>
          <h2><?= $myTasks ? 'Tugas Saya yang Berjalan' : 'Pekerjaan Berjalan' ?></h2>
          <p><?= $myTasks ? 'Pekerjaan yang sedang kamu kerjakan' : 'Pekerjaan yang sedang dikerjakan di lapangan' ?></p>
        </div>
        <span class="spacer"></span>
        <a class="btn btn-sm" href="pekerjaan.php">Lihat semua</a>
      </div>
      <?php render_pekerjaan_table(array_slice($berjalan, 0, 8), !$myTasks, 'Tidak ada pekerjaan berjalan.'); ?>
    </div>

    <?php if ($perhatian): ?>
      <div class="card flush">
        <div class="card-head">
          <div>
            <h2>Perlu Perhatian — Lewat Deadline</h2>
            <p>Pekerjaan yang sudah melewati tanggal deadline</p>
          </div>
          <span class="spacer"></span>
          <a class="btn btn-sm btn-danger" href="pekerjaan.php?status=terlambat">Lihat semua</a>
        </div>
        <?php render_pekerjaan_table(array_slice($perhatian, 0, 6), !$myTasks, 'Tidak ada pekerjaan terlambat.'); ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="grid" style="gap:20px">
    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div><h2>Deadline Terdekat</h2><p>5 pekerjaan paling dekat tenggatnya</p></div>
      </div>
      <?php if (!$dekat): ?>
        <p class="muted small">Belum ada deadline yang tercatat.</p>
      <?php else: ?>
        <div class="list">
          <?php foreach ($dekat as $p): ?>
            <a class="list-item" href="pekerjaan_detail.php?id=<?= (int) $p['id'] ?>" style="color:inherit">
              <div class="grow">
                <h4><?= e($p['nama']) ?></h4>
                <p>
                  <?= $myTasks ? '' : e($p['project_nama']) . ' · ' ?>
                  <?= e($p['lokasi_nama'] ?? 'tanpa lokasi') ?>
                </p>
                <p style="margin-top:6px"><?= deadline_note($p['deadline'], $p['status']) ?> · <?= (int) $p['progress'] ?>%</p>
              </div>
              <?= status_pill($p) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div>
          <h2><?= $myTasks ? 'Jadwal Saya Hari Ini' : 'Jadwal Hari Ini' ?></h2>
          <p><?= e(tgl(date('Y-m-d'))) ?> · <?= count($jadwalHariIni) ?> penugasan · <?= count($laporHariIni) ?> laporan masuk</p>
        </div>
      </div>
      <?php if (!$jadwalHariIni): ?>
        <p class="muted small">
          <?= $myTasks ? 'Belum ada jadwal untukmu hari ini.' : 'Belum ada jadwal tersusun untuk hari ini.' ?>
        </p>
      <?php else: ?>
        <div class="list">
          <?php foreach (array_slice($belumLapor, 0, 4) as $j): ?>
            <a class="list-item" href="laporan_kerja_form.php?jadwal_id=<?= (int) $j['id'] ?>" style="color:inherit">
              <div class="grow">
                <h4><?= e($myTasks ? ($j['pekerjaan_nama'] ?: $j['project_nama']) : $j['pekerja_nama']) ?></h4>
                <p>
                  <?= $myTasks ? e($j['project_kode']) : e($j['project_kode'] . ' · ' . ($j['pekerjaan_nama'] ?: 'pilih pekerjaan')) ?>
                  · <?= e(skema_label((string) $j['pekerja_skema'])) ?>
                </p>
              </div>
              <span class="pill pill-tertunda">Belum lapor</span>
            </a>
          <?php endforeach; ?>
          <?php if (!$belumLapor): ?>
            <div class="list-item" style="border-color:#bbf7d0">
              <div class="grow">
                <h4>Semua sudah melapor ✓</h4>
                <p><?= count($jadwalHariIni) ?> penugasan hari ini sudah ada hasil kerjanya</p>
              </div>
              <span class="pill pill-selesai">Lengkap</span>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="row-actions" style="margin-top:14px;justify-content:flex-start">
        <a class="btn btn-sm btn-primary" href="jadwal_harian.php"><?= $myTasks ? 'Buka Jadwal Saya' : 'Buka Jadwal Harian' ?></a>
        <a class="btn btn-sm" href="laporan_kerja.php">Hasil Kerja</a>
      </div>
    </div>

    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div>
          <h2><?= $myTasks ? 'Hari Kerja &amp; Upah Saya' : 'Hari Kerja &amp; Upah' ?></h2>
          <p>Periode <?= e(tgl($awalBulan)) ?> – <?= e(tgl($akhirBulan)) ?></p>
        </div>
      </div>
      <div class="upah-summary">
        <div>
          <span class="stat-label">Hari kerja</span>
          <strong><?= e(hari_format($upahBulan['hari'])) ?></strong>
        </div>
        <?php if ($lihatNominal): ?>
          <div>
            <span class="stat-label">Upah harian (absensi)</span>
            <strong><?= e(rupiah($upahBulan['upah_harian'])) ?></strong>
          </div>
          <div>
            <span class="stat-label">Upah borongan</span>
            <strong><?= e(rupiah($upahBulan['borongan_selesai'])) ?></strong>
          </div>
          <div class="hl">
            <span class="stat-label">Total upah</span>
            <strong><?= e(rupiah($upahBulan['total'])) ?></strong>
          </div>
        <?php else: ?>
          <div class="hl">
            <span class="stat-label">Total upah saya</span>
            <strong><?= e(rupiah($upahBulan['total'])) ?></strong>
          </div>
        <?php endif; ?>
      </div>
      <?php if ((string) $u['skema'] === 'borongan' && $myTasks): ?>
        <p class="small muted" style="margin:12px 0 0">
          Skema upahmu <strong>borongan</strong>: upah dihitung dari volume hasil kerja yang kamu laporkan.
          <?php if ($boronganSaya['berjalan'] > 0): ?>
            Belum cair: <?= e(rupiah($boronganSaya['berjalan'])) ?>.
          <?php endif; ?>
        </p>
      <?php endif; ?>
      <div class="row-actions" style="margin-top:14px;justify-content:flex-start">
        <a class="btn btn-sm" href="absensi.php">Absensi</a>
        <a class="btn btn-sm" href="upah.php">Rekap Upah</a>
        <?php if (can_record_absensi()): ?>
          <a class="btn btn-sm btn-primary" href="absensi_form.php">+ Catat Kehadiran</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div><h2>Tim Lapangan</h2><p>Jumlah personel yang terdaftar</p></div>
      </div>
      <?php
      $pelaksanaAll = selectable_pelaksana();
      $pekerjaAll = selectable_pekerja();
      ?>
      <div class="list">
        <a class="list-item" href="tim.php?role=pelaksana" style="color:inherit">
          <div class="grow"><h4><?= count($pelaksanaAll) ?> Pelaksana</h4><p>Penanggung jawab pekerjaan di lapangan</p></div>
          <span class="pill pill-proses">Lihat</span>
        </a>
        <a class="list-item" href="tim.php?role=pekerja" style="color:inherit">
          <div class="grow"><h4><?= count($pekerjaAll) ?> Pekerja</h4><p>Tenaga kerja yang ditugaskan ke pekerjaan</p></div>
          <span class="pill pill-proses">Lihat</span>
        </a>
        <?php if (!$myTasks): ?>
          <?php $jasa = nilai_jasa_periode(); ?>
          <a class="list-item" href="pengajuan.php" style="color:inherit">
            <div class="grow">
              <h4>Pengajuan ke Perusahaan</h4>
              <p>
                Siap diajukan <strong><?= e(rupiah($jasa['nilai_diajukan'])) ?></strong>
                dari <?= (int) $jasa['jml_selesai'] ?>/<?= (int) $jasa['jml_item'] ?> item selesai
              </p>
            </div>
            <span class="pill pill-selesai">Buka</span>
          </a>
          <a class="list-item" href="laporan.php" style="color:inherit">
            <div class="grow"><h4>Laporan &amp; Rekap</h4><p>Rekap progress, status dan beban kerja tim</p></div>
            <span class="pill pill-selesai">Buka</span>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
