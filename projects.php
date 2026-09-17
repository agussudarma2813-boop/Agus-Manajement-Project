<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/partials.php';

$u = require_login();

$f = [
    'q'            => trim((string) ($_GET['q'] ?? '')),
    'status'       => (string) ($_GET['status'] ?? ''),
    'pelaksana_id' => (int) ($_GET['pelaksana_id'] ?? 0),
];
if (!array_key_exists($f['status'], STATUS_PROJECT)) {
    $f['status'] = '';
}

$projects = fetch_projects($f);

$actions = '';
if (can_manage_users()) {
    $actions = '<a class="btn btn-primary" href="project_form.php">+ Project Baru</a>';
}

render_header(
    'Daftar Project',
    $u['role'] === 'pekerja'
        ? 'Project tempat kamu ditugaskan bekerja'
        : 'Seluruh project beserta pelaksana dan progress pekerjaannya',
    $actions
);
?>

<div class="card">
  <form class="filters" method="get">
    <div class="field" style="flex:1;min-width:220px">
      <label for="q">Cari project</label>
      <input type="text" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Nama atau kode project">
    </div>
    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Semua status</option>
        <?php foreach (STATUS_PROJECT as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($u['role'] !== 'pekerja'): ?>
      <div class="field">
        <label for="pelaksana_id">Pelaksana</label>
        <select id="pelaksana_id" name="pelaksana_id">
          <option value="">Semua pelaksana</option>
          <?php foreach (selectable_pelaksana() as $pl): ?>
            <option value="<?= (int) $pl['id'] ?>"<?= $f['pelaksana_id'] === (int) $pl['id'] ? ' selected' : '' ?>><?= e($pl['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="projects.php">Reset</a>
  </form>
</div>

<?php if (!$projects): ?>
  <div class="card">
    <div class="empty">
      <strong>Tidak ada project ditemukan</strong>
      <span class="small">Ubah filter pencarian<?= can_manage_users() ? ' atau buat project baru' : '' ?>.</span>
    </div>
  </div>
<?php else: ?>
  <div class="grid grid-2">
    <?php foreach ($projects as $p): $avg = (int) round((float) $p['avg_progress']); ?>
      <div class="card project-card">
        <div class="pc-head">
          <div class="grow">
            <span class="mono muted small"><?= e($p['kode']) ?></span>
            <h3><a href="project_detail.php?id=<?= (int) $p['id'] ?>"><?= e($p['nama']) ?></a></h3>
          </div>
          <?= project_status_pill($p['status']) ?>
        </div>

        <div>
          <div class="bl-head" style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px">
            <span class="muted">Progress keseluruhan</span>
            <strong><?= $avg ?>%</strong>
          </div>
          <?= progress_bar($avg, ' bar-lg') ?>
        </div>

        <div class="pc-meta">
          <span>Pelaksana: <strong><?= $p['pelaksana_nama'] ? e($p['pelaksana_nama']) : 'belum ditetapkan' ?></strong></span>
          <span>Lokasi: <strong><?= (int) $p['jml_lokasi'] ?></strong></span>
          <span>Pekerjaan: <strong><?= (int) $p['jml_selesai'] ?>/<?= (int) $p['jml_pekerjaan'] ?> selesai</strong></span>
          <span>Anggaran: <strong><?= e(rupiah($p['anggaran'])) ?></strong></span>
        </div>

        <div class="pc-meta">
          <span>Mulai: <strong><?= e(tgl($p['mulai'])) ?></strong></span>
          <span>Target: <strong><?= e(tgl($p['target_selesai'])) ?></strong></span>
          <?php if ((int) $p['jml_terlambat'] > 0): ?>
            <span class="pill pill-late"><?= (int) $p['jml_terlambat'] ?> pekerjaan terlambat</span>
          <?php endif; ?>
        </div>

        <div class="row-actions">
          <a class="btn btn-sm" href="project_detail.php?id=<?= (int) $p['id'] ?>">Buka Project</a>
          <?php if (can_manage_project($p)): ?>
            <a class="btn btn-sm" href="project_form.php?id=<?= (int) $p['id'] ?>">Edit</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
