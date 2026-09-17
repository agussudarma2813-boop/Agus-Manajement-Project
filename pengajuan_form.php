<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);

$projectOptions = array_values(array_filter(selectable_projects(), fn($p) => can_manage_project($p)));
if (!$projectOptions) {
    flash('Belum ada project yang bisa kamu ajukan ke perusahaan.', 'err');
    redirect('pengajuan.php');
}

$projectId = (int) ($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
if ($projectId && !array_filter($projectOptions, fn($p) => (int) $p['id'] === $projectId)) {
    $projectId = 0;
}
if (!$projectId) {
    $projectId = (int) $projectOptions[0]['id'];
}

$project = get_project($projectId);
$items = item_untuk_pengajuan($projectId);
$tanggal = valid_tanggal((string) ($_GET['tanggal'] ?? '')) ?: date('Y-m-d');

$errors = $_SESSION['pengajuan_errors'] ?? [];
unset($_SESSION['pengajuan_errors']);
$nilaiKembali = $_SESSION['pengajuan_nilai'] ?? [];
unset($_SESSION['pengajuan_nilai']);

$adаSisa = array_filter($items, fn($i) => $i['sisa'] > 0);
$totalSisa = array_sum(array_map(fn($i) => $i['nilai_sisa'], $items));

render_header(
    'Buat Pengajuan',
    $project['kode'] . ' · ' . e($project['nama']) . ' · ' . e(tgl($tanggal)),
    '<a class="btn" href="pengajuan.php">Kembali</a>'
);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (!$items): ?>
  <div class="card">
    <div class="empty">
      <strong>Project ini belum punya sub pekerjaan</strong>
      <span class="small">Tambahkan item pekerjaan dulu di halaman project.</span>
    </div>
  </div>
<?php elseif (!$adаSisa): ?>
  <div class="card">
    <div class="empty">
      <strong>Semua volume sudah diajukan</strong>
      <span class="small">Tidak ada sisa volume untuk diajukan pada project ini.</span>
    </div>
    <div class="form-actions" style="justify-content:center">
      <a class="btn" href="pengajuan.php">Kembali ke daftar pengajuan</a>
    </div>
  </div>
<?php else: ?>
  <form method="post" action="pengajuan_save.php" class="card" data-pengajuan-form>
    <?= csrf_field() ?>
    <input type="hidden" name="project_id" value="<?= $projectId ?>">

    <div class="form-grid">
      <div class="field">
        <label for="project_pilih">Project</label>
        <select id="project_pilih" data-project-pilih>
          <?php foreach ($projectOptions as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= $projectId === (int) $p['id'] ? ' selected' : '' ?>>
              <?= e($p['kode'] . ' · ' . $p['nama']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Ganti project untuk memuat daftar sub pekerjaannya.</span>
      </div>
      <div class="field">
        <label for="tanggal">Tanggal pengajuan</label>
        <input type="date" id="tanggal" name="tanggal" value="<?= e($tanggal) ?>" required>
      </div>
      <div class="field">
        <label for="catatan">Catatan (opsional)</label>
        <input type="text" id="catatan" name="catatan" placeholder="mis. termin 1 / sesuai progress minggu ini">
      </div>
      <div class="field">
        <label>Total yang diajukan</label>
        <div class="money-note" data-pengajuan-total>Belum ada item dipilih.</div>
      </div>
    </div>

    <div class="card-head" style="padding:22px 0 14px;border-bottom:1px solid var(--line-soft);margin:18px 0 16px">
      <div>
        <h2>Sub Pekerjaan</h2>
        <p>Centang item yang mau diajukan, lalu isi volumenya (sudah terisi sisa yang belum diajukan)</p>
      </div>
      <span class="spacer"></span>
      <span class="muted small">Total sisa yang bisa diajukan: <strong><?= e(rupiah($totalSisa)) ?></strong></span>
    </div>

    <div class="table-wrap">
      <table class="tbl pengajuan-tbl">
        <thead>
          <tr>
            <th style="width:44px"><input type="checkbox" data-pengajuan-semua aria-label="Pilih semua"></th>
            <th>Sub pekerjaan</th>
            <th>Kontrak</th>
            <th>Sudah diajukan</th>
            <th>Sisa</th>
            <th>Volume diajukan</th>
            <th>Harga ke perusahaan</th>
            <th>Nilai</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): $wid = (int) $it['id']; $habis = $it['sisa'] <= 0; ?>
          <tr class="<?= $habis ? 'is-habis' : '' ?>">
            <td>
              <?php if ($habis): ?>
                <span class="muted small">✓</span>
              <?php else: ?>
                <input type="checkbox" name="pekerjaan[]" value="<?= $wid ?>" data-pengajuan-item checked>
              <?php endif; ?>
            </td>
            <td>
              <div class="cell-stack">
                <strong><?= e($it['nama']) ?></strong>
                <small>
                  <?= e($it['kategori'] !== '' ? $it['kategori'] : 'tanpa kategori') ?>
                  · dasar tagihan: <?= e($it['dasar_dari']) ?>
                  <?php if ($habis): ?><span class="pill pill-selesai">sudah diajukan penuh</span><?php endif; ?>
                </small>
              </div>
            </td>
            <td class="small nowrap"><?= e(num($it['volume']) . ' ' . $it['satuan']) ?></td>
            <td class="small nowrap"><?= (float) $it['tagih_volume'] > 0 ? e(num($it['tagih_volume']) . ' ' . $it['satuan']) : '<span class="muted">—</span>' ?></td>
            <td class="nowrap"><span class="tag"><?= e(num($it['sisa']) . ' ' . $it['satuan']) ?></span></td>
            <td>
              <?php if ($habis): ?>
                <span class="muted small">—</span>
              <?php else: ?>
                <input type="text" name="volume[<?= $wid ?>]" data-pengajuan-volume
                       value="<?= e($nilaiKembali[$wid] ?? num($it['sisa'])) ?>"
                       data-sisa="<?= e(num($it['sisa'])) ?>" data-harga="<?= e(num($it['harga_jasa'])) ?>">
              <?php endif; ?>
            </td>
            <td class="small nowrap"><?= e(rupiah($it['harga_jasa'])) ?> / <?= e($it['satuan'] !== '' ? $it['satuan'] : 'satuan') ?></td>
            <td class="nowrap"><span class="tag" data-pengajuan-nilai>—</span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="form-actions" style="margin-top:18px">
      <button class="btn btn-primary" type="submit">Simpan Pengajuan</button>
      <a class="btn btn-ghost" href="pengajuan.php">Batal</a>
      <span class="spacer" style="margin-left:auto"></span>
      <span class="muted small" data-pengajuan-info>Volume boleh sebagian — sisanya bisa diajukan lagi nanti.</span>
    </div>
  </form>
<?php endif; ?>

<?php render_footer(); ?>
