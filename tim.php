<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$role = (string) ($_GET['role'] ?? 'pelaksana');
if (!in_array($role, ['pelaksana', 'pekerja'], true)) {
    $role = 'pelaksana';
}

$people = all_users($role);
$lihatNominal = can_view_all_wages();
[$awalBulan, $akhirBulan] = default_periode();

$actions = '<a class="btn ' . ($role === 'pelaksana' ? 'btn-primary' : '') . '" href="tim.php?role=pelaksana">Pelaksana</a>'
    . '<a class="btn ' . ($role === 'pekerja' ? 'btn-primary' : '') . '" href="tim.php?role=pekerja">Pekerja</a>';
if (can_manage_users()) {
    $actions .= '<a class="btn btn-dark" href="user_form.php?role=' . e($role) . '">+ Tambah ' . ($role === 'pelaksana' ? 'Pelaksana' : 'Pekerja') . '</a>';
}

render_header(
    $role === 'pelaksana' ? 'Daftar Pelaksana' : 'Daftar Pekerja',
    $role === 'pelaksana'
        ? 'Penanggung jawab project &amp; pekerjaan di lapangan'
        : 'Tenaga kerja dan beban tugas yang sedang dikerjakan',
    $actions
);
?>

<?php if (!$people): ?>
  <div class="card">
    <div class="empty">
      <strong>Belum ada data <?= e($role) ?></strong>
      <span class="small"><?= can_manage_users() ? 'Tambahkan akun baru lewat tombol di kanan atas.' : 'Hubungi admin untuk menambahkan akun.' ?></span>
    </div>
  </div>
<?php else: ?>
  <div class="grid grid-3">
    <?php foreach ($people as $p): ?>
      <?php
      $st = $role === 'pelaksana' ? pelaksana_stats((int) $p['id']) : pekerja_stats((int) $p['id']);
      $avg = (int) ($st['avg_progress'] ?? 0);
      ?>
      <div class="card project-card">
        <div class="pc-head">
          <?= badge_avatar($p['nama']) ?>
          <div class="grow">
            <h3><?= e($p['nama']) ?></h3>
            <span class="small muted"><?= e($p['jabatan'] !== '' ? $p['jabatan'] : role_label($p['role'])) ?></span>
          </div>
          <?= (int) $p['aktif'] === 1 ? '<span class="pill pill-selesai">Aktif</span>' : '<span class="pill pill-belum">Nonaktif</span>' ?>
        </div>

        <div class="pc-meta">
          <span>Username: <strong class="mono"><?= e($p['username']) ?></strong></span>
          <span>Telepon: <strong><?= e($p['telepon'] !== '' ? $p['telepon'] : '—') ?></strong></span>
        </div>

        <?php if ($lihatNominal || (int) $p['id'] === (int) $u['id']): ?>
          <?php $up = upah_user_periode((int) $p['id'], $awalBulan, $akhirBulan); ?>
          <div class="pc-meta wage-block">
            <?php if ($p['skema'] === 'borongan'): ?>
              <span>Skema: <strong>Borongan</strong></span>
              <span>Volume lapor bulan ini: <strong><?= e(num($up['volume_lapor'])) ?></strong></span>
              <span>Upah borongan: <strong><?= e(rupiah($up['borongan_selesai'])) ?></strong></span>
              <?php if ($up['borongan_berjalan'] > 0): ?>
                <span class="muted">Belum cair: <?= e(rupiah($up['borongan_berjalan'])) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span>Skema: <strong>Harian</strong></span>
              <span>Upah harian: <strong><?= (float) $p['upah_harian'] > 0 ? e(rupiah($p['upah_harian'])) : '<span class="muted">belum diisi</span>' ?></strong></span>
              <span>Hari kerja bulan ini: <strong><?= e(hari_format($up['hari'])) ?></strong></span>
              <span>Total upah: <strong><?= e(rupiah($up['total'])) ?></strong></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($role === 'pelaksana'): ?>
          <div class="pc-meta">
            <span>Project: <strong><?= (int) $st['projects'] ?></strong> (<?= (int) $st['projects_berjalan'] ?> berjalan)</span>
            <span>Pekerjaan: <strong><?= (int) $st['pekerjaan'] ?></strong></span>
            <span>Selesai: <strong><?= (int) $st['selesai'] ?></strong></span>
            <span>Berjalan: <strong><?= (int) $st['proses'] ?></strong></span>
          </div>
          <div>
            <div class="bl-head" style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px">
              <span class="muted">Rata-rata progress pekerjaan</span><strong><?= $avg ?>%</strong>
            </div>
            <?= progress_bar($avg, ' bar-lg') ?>
          </div>
          <?php if ((int) $st['terlambat'] > 0): ?>
            <span class="pill pill-late"><?= (int) $st['terlambat'] ?> pekerjaan terlambat</span>
          <?php endif; ?>
        <?php else: ?>
          <div class="pc-meta">
            <span>Tugas aktif: <strong><?= (int) $st['proses'] ?></strong></span>
            <span>Tugas selesai: <strong><?= (int) $st['selesai'] ?></strong></span>
            <span>Total tugas: <strong><?= (int) $st['tugas'] ?></strong></span>
            <span>Project: <strong><?= (int) $st['projects'] ?></strong></span>
          </div>
          <?php if ((int) $st['terlambat'] > 0): ?>
            <span class="pill pill-late"><?= (int) $st['terlambat'] ?> tugas terlambat</span>
          <?php endif; ?>
        <?php endif; ?>

        <div class="row-actions">
          <a class="btn btn-sm" href="pekerjaan.php?<?= $role === 'pelaksana' ? 'pelaksana_id=' : 'pekerja_id=' ?><?= (int) $p['id'] ?>">Lihat Pekerjaan</a>
          <?php if ($lihatNominal || (int) $p['id'] === (int) $u['id']): ?>
            <a class="btn btn-sm" href="upah_detail.php?id=<?= (int) $p['id'] ?>">Rincian Upah</a>
          <?php endif; ?>
          <?php if (can_manage_users()): ?>
            <a class="btn btn-sm" href="user_form.php?id=<?= (int) $p['id'] ?>">Edit</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
