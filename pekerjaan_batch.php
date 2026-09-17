<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/items_form.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);
$projectId = (int) ($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$project = get_project($projectId);
if (!$project) {
    flash('Project tidak ditemukan.', 'err');
    redirect('projects.php');
}
if (!can_manage_project($project)) {
    flash('Kamu hanya bisa mengelola item pada project yang kamu pimpin.', 'err');
    redirect('projects.php');
}

$errors = [];

/** Ambil nilai array dengan kunci int atau string (PHP mengubah kunci angka jadi int) */
function parse_key(array $arr, int|string $key): ?string
{
    if (array_key_exists($key, $arr)) {
        return (string) $arr[$key];
    }
    if (array_key_exists((string) $key, $arr)) {
        return (string) $arr[(string) $key];
    }
    return null;
}

/** Dipakai di tabel & footer ringkasan volume */
$ringkasVolume = static function (array $peta): string {
    if (!$peta) {
        return '—';
    }
    $bagian = [];
    foreach ($peta as $sat => $vol) {
        $bagian[] = num($vol) . ' ' . $sat;
    }
    return implode(' · ', $bagian);
};

/* ============================ Simpan (tambah / perbarui) ============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = (string) ($_POST['aksi'] ?? '');
    $kembali = 'pekerjaan_batch.php?project_id=' . $projectId;

    if ($aksi === 'tambah') {
        $hasil = simpan_item_batch($projectId, $_POST, (int) $u['id']);
        if ($hasil['dibuat'] > 0) {
            flash('<strong>' . $hasil['dibuat'] . ' item pekerjaan</strong> berhasil ditambahkan ke project ini.');
        } else {
            flash('Tidak ada baris yang tersimpan — nama pekerjaan dan volume wajib diisi.', 'err');
        }
        redirect($kembali);
    }

    if ($aksi === 'perbarui') {
        $volArr = (array) ($_POST['upd_volume'] ?? []);
        $jasaArr = (array) ($_POST['upd_jasa'] ?? []);
        $upahArr = (array) ($_POST['upd_upah'] ?? []);
        $satArr = (array) ($_POST['upd_satuan'] ?? []);

        $milikProject = [];
        foreach (fetch_pekerjaan(['project_id' => $projectId]) as $pj) {
            $milikProject[(int) $pj['id']] = $pj;
        }

        $upd = db()->prepare('UPDATE pekerjaan SET volume = ?, satuan = ?, harga_jasa = ?, harga_upah = ? WHERE id = ?');
        $jml = 0;
        foreach ($milikProject as $wid => $pj) {
            // Baris yang fieldnya TIDAK dikirim sama sekali dianggap tidak diubah
            // (mis. baris yang dihapus dari form) — jangan sampai menimpa jadi 0/kosong.
            if (!array_key_exists($wid, $volArr) && !array_key_exists((string) $wid, $volArr)) {
                continue;
            }
            $vol = (float) str_replace(',', '.', trim((string) ($volArr[$wid] ?? '')));
            $sat = trim((string) ($satArr[$wid] ?? ''));
            if ($vol < 0) {
                $vol = 0.0;
            }
            $jasa = parse_key($jasaArr, $wid) !== null ? parse_money((string) parse_key($jasaArr, $wid)) : (float) $pj['harga_jasa'];
            $upah = parse_key($upahArr, $wid) !== null ? parse_money((string) parse_key($upahArr, $wid)) : (float) $pj['harga_upah'];
            if (!array_key_exists($wid, $satArr) && !array_key_exists((string) $wid, $satArr)) {
                $sat = (string) $pj['satuan'];
            }
            $ubah = ((float) $pj['volume'] !== $vol)
                || ((string) $pj['satuan'] !== $sat)
                || ((float) $pj['harga_jasa'] !== $jasa)
                || ((float) $pj['harga_upah'] !== $upah);
            if (!$ubah) {
                continue;
            }
            $upd->execute([$vol, $sat, $jasa, $upah, $wid]);
            $jml++;
        }
        flash($jml > 0 ? '<strong>' . $jml . ' item</strong> berhasil diperbarui (volume &amp; harga).' : 'Tidak ada perubahan pada item yang sudah ada.', $jml > 0 ? 'ok' : 'info');
        redirect($kembali);
    }

    redirect($kembali);
}

/* ============================ Data tampilan ============================ */
$items = fetch_pekerjaan(['project_id' => $projectId]);
$lokasi = lokasi_of_project($projectId);
$summary = [
    'jml' => count($items),
    'nilai_kontrak' => array_sum(array_map(fn($pj) => nilai_kontrak_pekerjaan($pj), $items)),
    'nol_volume' => count(array_filter($items, fn($pj) => (float) $pj['volume'] <= 0)),
];

$totalVolumePerSatuan = [];
foreach ($items as $pj) {
    $sat = $pj['satuan'] !== '' ? $pj['satuan'] : 'satuan';
    $totalVolumePerSatuan[$sat] = ($totalVolumePerSatuan[$sat] ?? 0) + (float) $pj['volume'];
}

render_header(
    'Input Massal Item &amp; Volume',
    $project['kode'] . ' · ' . e($project['nama']) . ' · ' . count($items) . ' item terdaftar',
    '<a class="btn" href="project_detail.php?id=' . $projectId . '">Kembali ke Project</a>'
);
?>

<div class="stats">
  <?php
  stat_card('Item Terdaftar', (string) $summary['jml'], 'jenis pekerjaan di project ini', 'info');
  stat_card('Nilai Kontrak Jasa', e(rupiah($summary['nilai_kontrak'])), 'harga jasa × volume kontrak', 'ok');
  stat_card('Belum Ada Volume', (string) $summary['nol_volume'], 'item yang volumenya masih 0', $summary['nol_volume'] > 0 ? 'warn' : '');
  stat_card('Ringkasan Volume', e($ringkasVolume($totalVolumePerSatuan)), count($totalVolumePerSatuan) . ' jenis satuan', '');
  ?>
</div>

<?php if ($items): ?>
  <form method="post" class="card flush">
    <?= csrf_field() ?>
    <input type="hidden" name="project_id" value="<?= $projectId ?>">
    <input type="hidden" name="aksi" value="perbarui">

    <div class="card-head">
      <div>
        <h2>Item yang Sudah Ada</h2>
        <p>Sesuaikan volume &amp; harga di sini, lalu simpan sekaligus</p>
      </div>
      <span class="spacer"></span>
      <span class="muted small">Nilai jasa &amp; nilai kontrak mengikuti volume</span>
    </div>

    <div class="table-wrap">
      <table class="tbl items-batch-tbl">
        <thead>
          <tr>
            <th>Jenis pekerjaan</th>
            <th>Volume</th>
            <th>Satuan</th>
            <th>Harga jasa / satuan</th>
            <th>Upah pekerja / satuan</th>
            <th>Nilai kontrak</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $pj): $wid = (int) $pj['id']; ?>
          <tr>
            <td>
              <div class="cell-stack">
                <strong><?= e($pj['nama']) ?></strong>
                <small>
                  <?= e($pj['kategori'] !== '' ? $pj['kategori'] : 'tanpa kategori') ?>
                  <?= $pj['lokasi_nama'] ? ' · ' . e($pj['lokasi_nama']) : '' ?>
                  · <?= e(status_label($pj['status'])) ?>
                </small>
              </div>
            </td>
            <td><input type="text" name="upd_volume[<?= $wid ?>]" value="<?= e((float) $pj['volume'] > 0 ? num($pj['volume']) : '') ?>" placeholder="0" data-batch-volume></td>
            <td><input type="text" name="upd_satuan[<?= $wid ?>]" value="<?= e($pj['satuan']) ?>" placeholder="m / unit" style="width:88px"></td>
            <td><input type="text" name="upd_jasa[<?= $wid ?>]" value="<?= (float) $pj['harga_jasa'] > 0 ? e(num($pj['harga_jasa'])) : '' ?>" placeholder="0" data-batch-jasa></td>
            <td><input type="text" name="upd_upah[<?= $wid ?>]" value="<?= (float) $pj['harga_upah'] > 0 ? e(num($pj['harga_upah'])) : '' ?>" placeholder="0" data-batch-upah></td>
            <td class="nowrap"><span class="tag" data-batch-nilai><?= e(rupiah(nilai_kontrak_pekerjaan($pj))) ?></span></td>
            <td class="right nowrap">
              <a class="btn btn-sm" href="pekerjaan_form.php?id=<?= $wid ?>">Detail</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="strong">Total</td>
            <td colspan="4" class="muted small"><?= e($ringkasVolume($totalVolumePerSatuan)) ?></td>
            <td class="strong nowrap" data-batch-total><?= e(rupiah($summary['nilai_kontrak'])) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="card-head" style="border-top:1px solid var(--line-soft);border-bottom:0">
      <span class="muted small">Perubahan hanya disimpan untuk baris yang benar-benar diubah.</span>
      <span class="spacer"></span>
      <button class="btn btn-primary" type="submit">Simpan Perubahan Volume &amp; Harga</button>
    </div>
  </form>
<?php endif; ?>

<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="project_id" value="<?= $projectId ?>">
  <input type="hidden" name="aksi" value="tambah">

  <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
    <div>
      <h2>Tambah Banyak Item Sekaligus</h2>
      <p>Pilih beberapa jenis pekerjaan lalu isi volumenya masing-masing</p>
    </div>
  </div>
  <p class="small muted" style="margin:0 0 14px">
    Misalnya: Penarikan Kabel 250 m · Pemasangan Unit 12 titik · Terminasi 24 titik · Pasang Panel 3 unit.
    Baris dengan nama <em>atau</em> volume kosong akan diabaikan.
  </p>

  <?php render_items_editor([
      'lokasi' => $lokasi,
      'pelaksana' => selectable_pelaksana(),
      'baris_kosong' => 5,
      'nilai' => $_POST,
  ]); ?>

  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit">Simpan Item Baru</button>
    <a class="btn btn-ghost" href="project_detail.php?id=<?= $projectId ?>">Batal</a>
  </div>
</form>

<?php render_footer(); ?>
