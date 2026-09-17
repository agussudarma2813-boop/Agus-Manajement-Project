<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$isPekerja = $u['role'] === 'pekerja';
$bolehAtur = in_array($u['role'], ['admin', 'pelaksana'], true);

$f = [
    'user_id' => $isPekerja ? (int) $u['id'] : (int) ($_GET['user_id'] ?? 0),
    'status'  => (string) ($_GET['status'] ?? ''),
    'dari'    => valid_tanggal((string) ($_GET['dari'] ?? '')),
    'sampai'  => valid_tanggal((string) ($_GET['sampai'] ?? '')),
];
if (!in_array($f['status'], ['aktif', 'dipotong'], true)) {
    $f['status'] = '';
}

$daftar = fetch_kasbon($f);
$ringkas = ringkasan_kasbon($f);
$periode = periode_gaji();

render_header(
    $isPekerja ? 'Kasbon Saya' : 'Kasbon Pekerja',
    'Kasbon otomatis dipotong dari gaji pada periode ' . e($periode['label']),
    '<a class="btn" href="gaji.php">Gaji</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Jumlah Kasbon', (string) $ringkas['jumlah'], 'pada filter ini', 'info');
  stat_card('Belum Dipotong', e(rupiah($ringkas['aktif'])), 'akan dipotong di penggajian berikutnya', $ringkas['aktif'] > 0 ? 'danger' : 'ok');
  stat_card('Sudah Dipotong', e(rupiah($ringkas['dipotong'])), 'sudah masuk penggajian', 'ok');
  stat_card('Total Kasbon', e(rupiah($ringkas['aktif'] + $ringkas['dipotong'])), 'seluruh kasbon tercatat', '');
  ?>
</div>

<div class="grid grid-side">
  <?php if ($bolehAtur): ?>
    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div><h2>Catat Kasbon Baru</h2><p>Kasbon otomatis dipotong pada penggajian periode berikutnya</p></div>
      </div>
      <form method="post" action="kasbon_save.php" class="form-grid">
        <?= csrf_field() ?>
        <div class="field">
          <label for="user_id">Tenaga</label>
          <select id="user_id" name="user_id" required>
            <option value="">— pilih tenaga —</option>
            <?php foreach (all_users() as $p): ?>
              <?php if ($p['role'] === 'admin' || (int) $p['aktif'] !== 1) { continue; } ?>
              <option value="<?= (int) $p['id'] ?>">
                <?= e($p['nama']) ?> — <?= e(skema_label((string) $p['skema'])) ?>
                <?php $aktif = kasbon_total_aktif((int) $p['id']); ?>
                <?= $aktif > 0 ? '(kasbon aktif ' . e(rupiah($aktif)) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="tanggal">Tanggal kasbon</label>
          <input type="date" id="tanggal" name="tanggal" value="<?= e(date('Y-m-d')) ?>" required>
        </div>
        <div class="field">
          <label for="nominal">Nominal (Rp)</label>
          <input type="text" id="nominal" name="nominal" placeholder="mis. 500.000" required>
        </div>
        <div class="field">
          <label for="keterangan">Keterangan</label>
          <input type="text" id="keterangan" name="keterangan" placeholder="mis. kebutuhan keluarga / biaya sekolah">
        </div>
        <div class="field full">
          <button class="btn btn-primary" type="submit">Simpan Kasbon</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
      <div><h2>Ringkasan per Tenaga</h2><p>Kasbon yang belum dipotong</p></div>
    </div>
    <?php if (!$ringkas['orang']): ?>
      <p class="muted small">Belum ada kasbon.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>Tenaga</th><th>Total Kasbon</th></tr></thead>
          <tbody>
          <?php foreach ($ringkas['orang'] as $uid => $nominal): ?>
            <?php
            $st = db()->prepare('SELECT nama, jabatan, skema FROM users WHERE id = ? AND perusahaan_id = ?');
            $st->execute([(int) $uid, tenant_id()]);
            $orang = $st->fetch() ?: ['nama' => '-', 'jabatan' => '', 'skema' => 'harian'];
            $aktif = kasbon_total_aktif((int) $uid);
            ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:9px">
                  <?= badge_avatar($orang['nama'], 'sm') ?>
                  <div class="cell-stack">
                    <strong><?= e($orang['nama']) ?></strong>
                    <small><?= e(skema_label((string) $orang['skema'])) ?></small>
                  </div>
                </div>
              </td>
              <td class="nowrap">
                <?= e(rupiah($nominal)) ?>
                <?php if ($aktif > 0): ?>
                  <div class="small deadline-late">aktif: <?= e(rupiah($aktif)) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <form class="filters" method="get">
    <?php if (!$isPekerja): ?>
      <div class="field">
        <label for="f_user">Tenaga</label>
        <select id="f_user" name="user_id">
          <option value="">Semua tenaga</option>
          <?php foreach (all_users() as $p): ?>
            <?php if ($p['role'] === 'admin') { continue; } ?>
            <option value="<?= (int) $p['id'] ?>"<?= $f['user_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Semua status</option>
        <option value="aktif"<?= $f['status'] === 'aktif' ? ' selected' : '' ?>>Belum dipotong</option>
        <option value="dipotong"<?= $f['status'] === 'dipotong' ? ' selected' : '' ?>>Sudah dipotong</option>
      </select>
    </div>
    <div class="field">
      <label for="dari">Dari</label>
      <input type="date" id="dari" name="dari" value="<?= e($f['dari']) ?>">
    </div>
    <div class="field">
      <label for="sampai">Sampai</label>
      <input type="date" id="sampai" name="sampai" value="<?= e($f['sampai']) ?>">
    </div>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="kasbon.php">Reset</a>
  </form>
</div>

<div class="card flush">
  <div class="card-head">
    <div><h2>Riwayat Kasbon</h2><p><?= count($daftar) ?> data</p></div>
  </div>
  <?php if (!$daftar): ?>
    <div class="empty"><strong>Belum ada kasbon</strong><span class="small">Kasbon yang dicatat akan tampil di sini.</span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Tanggal</th>
            <?php if (!$isPekerja): ?><th>Tenaga</th><?php endif; ?>
            <th>Nominal</th><th>Keterangan</th><th>Status Potong</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($daftar as $k): ?>
          <tr>
            <td class="nowrap"><strong><?= e(tgl($k['tanggal'])) ?></strong></td>
            <?php if (!$isPekerja): ?>
              <td>
                <div style="display:flex;align-items:center;gap:9px">
                  <?= badge_avatar($k['pekerja_nama'], 'sm') ?>
                  <div class="cell-stack">
                    <strong><?= e($k['pekerja_nama']) ?></strong>
                    <small><?= e($k['jabatan'] !== '' ? $k['jabatan'] : '—') ?></small>
                  </div>
                </div>
              </td>
            <?php endif; ?>
            <td class="strong nowrap"><?= e(rupiah($k['nominal'])) ?></td>
            <td class="small"><?= $k['keterangan'] !== '' ? e($k['keterangan']) : '<span class="muted">—</span>' ?></td>
            <td>
              <?php if ($k['penggajian_id'] === null): ?>
                <span class="pill pill-tertunda">Belum dipotong</span>
              <?php else: ?>
                <span class="pill pill-selesai">Sudah dipotong</span>
                <?php if ($k['gaji_nomor']): ?><div class="small muted mono"><?= e($k['gaji_nomor']) ?></div><?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="right nowrap">
              <?php if ($bolehAtur && $k['penggajian_id'] === null): ?>
                <form method="post" action="kasbon_hapus.php" data-confirm="Hapus kasbon <?= e($k['pekerja_nama']) ?> sebesar <?= e(rupiah($k['nominal'])) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                </form>
              <?php elseif ($bolehAtur && $k['penggajian_id'] !== null): ?>
                <span class="muted small">terkunci</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="strong" colspan="<?= $isPekerja ? 2 : 3 ?>">Total pada filter ini</td>
            <td class="strong nowrap" colspan="2"><?= e(rupiah($ringkas['aktif'] + $ringkas['dipotong'])) ?></td>
            <td colspan="<?= $isPekerja ? 2 : 3 ?>"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
