<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/partials.php';

$u = require_login();
$id = (int) ($_GET['id'] ?? 0);

$project = get_project_summary($id);
if (!$project) {
    flash('Project tidak ditemukan atau bukan bagian dari penugasanmu.', 'err');
    redirect('projects.php');
}

$canManage = can_manage_project($project);

$f = [
    'project_id' => $id,
    'status'     => (string) ($_GET['status'] ?? ''),
    'lokasi_id'  => (int) ($_GET['lokasi_id'] ?? 0),
];
if (isset(STATUS_PEKERJAAN[$f['status']]) === false && $f['status'] !== 'terlambat') {
    $f['status'] = '';
}

$pekerjaanList = fetch_pekerjaan($f);
$allPekerjaan = fetch_pekerjaan(['project_id' => $id]);
$lokasi = lokasi_of_project($id);

// Pekerja terlibat di project ini
$st = db()->prepare(
    'SELECT DISTINCT us.id, us.nama, us.jabatan FROM pekerjaan pj
     JOIN pekerjaan_pekerja pp ON pp.pekerjaan_id = pj.id
     JOIN users us ON us.id = pp.user_id
     WHERE pj.project_id = ? ORDER BY us.nama'
);
$st->execute([$id]);
$timPekerja = $st->fetchAll();

$avg = (int) round((float) $project['avg_progress']);
$terlambatCount = (int) $project['jml_terlambat'];
$berjalanCount = count(array_filter($allPekerjaan, fn($p) => $p['status'] === 'proses'));
$selesaiCount = count(array_filter($allPekerjaan, fn($p) => $p['status'] === 'selesai'));

$actions = '<a class="btn" href="projects.php">Semua Project</a>';
if ($canManage) {
    $actions .= '<a class="btn" href="project_form.php?id=' . $id . '">Edit Project</a>';
}
if ($canManage || $u['role'] === 'pelaksana') {
    $actions .= '<a class="btn" href="pekerjaan_batch.php?project_id=' . $id . '">Input Massal Volume</a>';
    $actions .= '<a class="btn btn-primary" href="pekerjaan_form.php?project_id=' . $id . '">+ Pekerjaan</a>';
}

render_header($project['nama'], 'Detail project · ' . e($project['kode']), $actions);
?>

<div class="stats">
  <?php
  stat_card('Progress Project', $avg . '%', $selesaiCount . ' dari ' . count($allPekerjaan) . ' pekerjaan selesai', 'info');
  stat_card('Pekerjaan Berjalan', (string) $berjalanCount, 'sedang dikerjakan', '');
  stat_card('Pekerjaan Terlambat', (string) $terlambatCount, $terlambatCount ? 'perlu tindakan' : 'sesuai jadwal', $terlambatCount ? 'danger' : 'ok');
  stat_card('Lokasi Kerja', (string) count($lokasi), count($timPekerja) . ' pekerja terlibat', 'warn');
  ?>
</div>

<div class="grid grid-side">
  <div class="grid" style="gap:20px">
    <div class="card">
      <div class="card-head">
        <div>
          <h2>Informasi Project</h2>
          <p>Data pelaksana, jadwal dan anggaran</p>
        </div>
        <span class="spacer"></span>
        <?= project_status_pill($project['status']) ?>
      </div>
      <dl class="kv">
        <dt>Kode project</dt><dd class="mono"><?= e($project['kode']) ?></dd>
        <dt>Pelaksana</dt>
        <dd><?= $project['pelaksana_nama']
              ? e($project['pelaksana_nama']) . ($project['pelaksana_jabatan'] ? ' <span class="muted small">· ' . e($project['pelaksana_jabatan']) . '</span>' : '')
              : '<span class="muted">belum ditetapkan</span>' ?></dd>
        <dt>Periode</dt><dd><?= e(tgl($project['mulai'])) ?> — <?= e(tgl($project['target_selesai'])) ?></dd>
        <dt>Anggaran</dt><dd><?= e(rupiah($project['anggaran'])) ?></dd>
        <dt>Jumlah pekerjaan</dt><dd><?= count($allPekerjaan) ?> pekerjaan · <?= count($lokasi) ?> lokasi</dd>
        <dt>Keterangan</dt><dd><?= $project['keterangan'] !== '' ? nl2br(e($project['keterangan'])) : '<span class="muted">—</span>' ?></dd>
      </dl>
    </div>

    <div class="card flush">
      <div class="card-head">
        <div>
          <h2>Daftar Pekerjaan</h2>
          <p>Status, progress, deadline dan pekerja di setiap pekerjaan</p>
        </div>
        <span class="spacer"></span>
        <?php if ($canManage): ?>
          <a class="btn btn-sm" href="pekerjaan_batch.php?project_id=<?= $id ?>">Input Massal Volume</a>
        <?php endif; ?>
        <form class="filters" method="get">
          <input type="hidden" name="id" value="<?= $id ?>">
          <select name="status" onchange="this.form.submit()">
            <?php status_options($f['status'], true); ?>
          </select>
          <select name="lokasi_id" onchange="this.form.submit()">
            <option value="">Semua lokasi</option>
            <?php foreach ($lokasi as $l): ?>
              <option value="<?= (int) $l['id'] ?>"<?= $f['lokasi_id'] === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['nama']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php render_pekerjaan_table($pekerjaanList, false, 'Belum ada pekerjaan pada filter ini.'); ?>
    </div>
  </div>

  <div class="grid" style="gap:20px">
    <?php if ($canManage): ?>
      <div class="card">
        <h2 class="card-title">Tambah Lokasi Kerja</h2>
        <p class="muted small" style="margin:8px 0 14px">Lokasi dipakai untuk menandai tempat sebuah pekerjaan berlangsung.</p>
        <form method="post" action="lokasi_save.php" class="grid" style="gap:13px">
          <?= csrf_field() ?>
          <input type="hidden" name="project_id" value="<?= $id ?>">
          <div class="field">
            <label for="lok-nama">Nama lokasi</label>
            <input type="text" id="lok-nama" name="nama" placeholder="mis. Gedung A — Lantai 3" required>
          </div>
          <div class="field">
            <label for="lok-alamat">Alamat</label>
            <input type="text" id="lok-alamat" name="alamat" placeholder="Jl. ...">
          </div>
          <div class="field">
            <label for="lok-ket">Keterangan</label>
            <input type="text" id="lok-ket" name="keterangan" placeholder="opsional">
          </div>
          <button class="btn btn-primary" type="submit">Simpan Lokasi</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div><h2>Lokasi Kerja</h2><p><?= count($lokasi) ?> lokasi terdaftar</p></div>
      </div>
      <?php if (!$lokasi): ?>
        <p class="muted small">Belum ada lokasi. Tambahkan lokasi agar pekerjaan bisa ditandai tempatnya.</p>
      <?php else: ?>
        <div class="list">
          <?php foreach ($lokasi as $l): ?>
            <?php
            $jmlLok = count(array_filter($allPekerjaan, fn($p) => (int) $p['lokasi_id'] === (int) $l['id']));
            $avgLok = 0;
            $tmp = array_filter($allPekerjaan, fn($p) => (int) $p['lokasi_id'] === (int) $l['id']);
            if ($tmp) {
                $avgLok = (int) round(array_sum(array_map(fn($p) => (int) $p['progress'], $tmp)) / count($tmp));
            }
            ?>
            <div class="list-item" style="flex-direction:column;align-items:stretch;gap:9px">
              <div style="display:flex;align-items:center;gap:12px">
                <div class="grow">
                  <h4><?= e($l['nama']) ?></h4>
                  <p><?= $l['alamat'] !== '' ? e($l['alamat']) : 'Tanpa alamat' ?><?= $l['keterangan'] !== '' ? ' · ' . e($l['keterangan']) : '' ?></p>
                </div>
                <span class="tag"><?= $jmlLok ?> pekerjaan</span>
              </div>
              <?= progress_bar($avgLok) ?>
              <?php if ($canManage): ?>
                <div class="row-actions">
                  <form method="post" action="lokasi_delete.php" data-confirm="Hapus lokasi &quot;<?= e($l['nama']) ?>&quot;? Pekerjaan di lokasi ini tidak ikut terhapus.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                    <input type="hidden" name="project_id" value="<?= $id ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div><h2>Pekerja Terlibat</h2><p><?= count($timPekerja) ?> orang di project ini</p></div>
      </div>
      <?php if (!$timPekerja): ?>
        <p class="muted small">Belum ada pekerja yang ditugaskan. Buka detail pekerjaan untuk menugaskan pekerja.</p>
      <?php else: ?>
        <div class="chips">
          <?php foreach ($timPekerja as $p): ?>
            <span class="chip"><?= badge_avatar($p['nama'], 'sm') ?><?= e($p['nama']) ?>
              <span class="muted small"><?= e($p['jabatan']) ?></span></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php render_footer(); ?>
