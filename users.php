<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin']);
$people = all_users();

$countByRole = ['admin' => 0, 'pelaksana' => 0, 'pekerja' => 0];
foreach ($people as $p) {
    $countByRole[$p['role']] = ($countByRole[$p['role']] ?? 0) + 1;
}

render_header(
    'Pengguna & Akun',
    'Kelola akun admin, pelaksana dan pekerja beserta hak aksesnya',
    '<a class="btn btn-primary" href="user_form.php">+ Pengguna Baru</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Total Akun', (string) count($people), 'seluruh peran', 'info');
  stat_card('Admin', (string) $countByRole['admin'], 'akses penuh sistem', '');
  stat_card('Pelaksana', (string) $countByRole['pelaksana'], 'pengelola pekerjaan project', 'warn');
  stat_card('Pekerja', (string) $countByRole['pekerja'], 'pelaksana teknis lapangan', 'ok');
  ?>
</div>

<div class="card flush">
  <div class="card-head">
    <div><h2>Daftar Akun</h2><p>Hak akses menentukan menu &amp; data yang bisa diubah tiap peran</p></div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Nama</th><th>Peran</th><th>Jabatan</th><th>Kontak</th><th>Skema &amp; Tarif</th><th>Beban Tugas</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($people as $p): ?>
          <?php
          $beban = $p['role'] === 'pelaksana' ? pelaksana_stats((int) $p['id']) : ($p['role'] === 'pekerja' ? pekerja_stats((int) $p['id']) : null);
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <?= badge_avatar($p['nama']) ?>
                <div class="cell-stack">
                  <strong><?= (int) $p['id'] === (int) $u['id'] ? e($p['nama']) . ' <span class="muted small">(kamu)</span>' : e($p['nama']) ?></strong>
                  <small class="mono"><?= e($p['username']) ?></small>
                </div>
              </div>
            </td>
            <td>
              <span class="pill <?= $p['role'] === 'admin' ? 'pill-tertunda' : ($p['role'] === 'pelaksana' ? 'pill-proses' : 'pill-belum') ?>">
                <?= e(role_label($p['role'])) ?>
              </span>
            </td>
            <td class="small"><?= e($p['jabatan'] !== '' ? $p['jabatan'] : '—') ?></td>
            <td class="small"><?= e($p['telepon'] !== '' ? $p['telepon'] : '—') ?></td>
            <td class="small nowrap">
              <span class="pill <?= $p['skema'] === 'borongan' ? 'pill-proses' : 'pill-belum' ?>"><?= e(skema_label((string) $p['skema'])) ?></span>
              <?php if ((float) $p['upah_harian'] > 0): ?>
                <div class="muted small">harian <?= e(rupiah($p['upah_harian'])) ?></div>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if ($beban === null): ?>
                <span class="muted">—</span>
              <?php elseif ($p['role'] === 'pelaksana'): ?>
                <?= (int) $beban['projects'] ?> project · <?= (int) $beban['pekerjaan'] ?> pekerjaan · <?= (int) $beban['avg_progress'] ?>%
              <?php else: ?>
                <?= (int) $beban['proses'] ?> aktif · <?= (int) $beban['selesai'] ?> selesai · <?= (int) $beban['tugas'] ?> total
              <?php endif; ?>
            </td>
            <td><?= (int) $p['aktif'] === 1 ? '<span class="pill pill-selesai">Aktif</span>' : '<span class="pill pill-late">Nonaktif</span>' ?></td>
            <td class="right nowrap">
              <div class="row-actions">
                <a class="btn btn-sm" href="user_form.php?id=<?= (int) $p['id'] ?>">Edit</a>
                <?php if ((int) $p['id'] !== (int) $u['id']): ?>
                  <form method="post" action="user_toggle.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button class="btn btn-sm" type="submit"><?= (int) $p['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2 class="card-title">Tentang hak akses peran</h2>
  <div class="grid grid-3" style="margin-top:16px">
    <div>
      <h4>Admin</h4>
      <p class="muted small">Mengelola seluruh project, pekerjaan, lokasi, akun pengguna, serta melihat semua laporan.</p>
    </div>
    <div>
      <h4>Pelaksana</h4>
      <p class="muted small">Membuat &amp; memperbarui pekerjaan pada project yang dipimpinnya, menugaskan pekerja, mengubah status dan deadline.</p>
    </div>
    <div>
      <h4>Pekerja</h4>
      <p class="muted small">Melihat pekerjaan yang ditugaskan kepadanya dan memperbarui persentase progress serta catatan lapangan.</p>
    </div>
  </div>
</div>

<?php render_footer(); ?>
