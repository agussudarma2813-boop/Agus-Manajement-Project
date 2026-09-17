<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_role(['admin', 'pelaksana']);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$pekerjaan = $id ? get_pekerjaan($id) : null;

if ($id && !$pekerjaan) {
    flash('Pekerjaan tidak ditemukan.', 'err');
    redirect('pekerjaan.php');
}
if ($id && !can_manage_pekerjaan($pekerjaan)) {
    flash('Kamu hanya bisa mengelola pekerjaan pada project yang kamu pimpin.', 'err');
    redirect('pekerjaan.php');
}

// project yang boleh dipilih
$projectOptions = [];
foreach (selectable_projects() as $p) {
    if (can_manage_project($p)) {
        $projectOptions[] = $p;
    }
}
if (!$projectOptions) {
    flash('Belum ada project yang bisa kamu kelola. Tugaskan dirimu sebagai pelaksana project terlebih dahulu.', 'err');
    redirect('projects.php');
}

$allowedIds = array_map(fn($p) => (int) $p['id'], $projectOptions);

$preselectProject = (int) ($_GET['project_id'] ?? $_POST['project_id'] ?? $pekerjaan['project_id'] ?? 0);
if ($preselectProject && !can_manage_project(get_project($preselectProject))) {
    $preselectProject = 0;
}
if (!$preselectProject) {
    $preselectProject = (int) $projectOptions[0]['id'];
}

$data = [
    'project_id'   => (string) $preselectProject,
    'lokasi_id'    => (string) ($pekerjaan['lokasi_id'] ?? ''),
    'nama'         => $pekerjaan['nama'] ?? '',
    'kategori'     => $pekerjaan['kategori'] ?? '',
    'volume'       => (string) ($pekerjaan['volume'] ?? ''),
    'satuan'       => $pekerjaan['satuan'] ?? '',
    'status'       => $pekerjaan['status'] ?? 'belum',
    'progress'     => (string) ($pekerjaan['progress'] ?? 0),
    'mulai'        => $pekerjaan['mulai'] ?? date('Y-m-d'),
    'deadline'     => $pekerjaan['deadline'] ?? '',
    'prioritas'    => $pekerjaan['prioritas'] ?? 'normal',
    'pelaksana_id' => (string) ($pekerjaan['pelaksana_id'] ?? ''),
    'keterangan'   => $pekerjaan['keterangan'] ?? '',
    'harga_satuan_id' => (string) ($pekerjaan['harga_satuan_id'] ?? ''),
    'harga_jasa'   => (float) ($pekerjaan['harga_jasa'] ?? 0) > 0 ? num($pekerjaan['harga_jasa']) : '',
    'harga_upah'   => (float) ($pekerjaan['harga_upah'] ?? 0) > 0 ? num($pekerjaan['harga_upah']) : '',
    'volume_realisasi' => (float) ($pekerjaan['volume_realisasi'] ?? 0) > 0 ? num($pekerjaan['volume_realisasi']) : '',
    'realisasi_tanggal' => $pekerjaan['realisasi_tanggal'] ?? '',
    'realisasi_catatan' => $pekerjaan['realisasi_catatan'] ?? '',
];
$boronganAwal = [];
if ($pekerjaan) {
    foreach (borongan_detail((int) $pekerjaan['id'])['rows'] as $r) {
        $boronganAwal[(int) $r['user_id']] = $r;
    }
}
$assigned = array_keys($boronganAwal);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($data as $k => $_) {
        $data[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $assigned = array_map('intval', (array) ($_POST['pekerja'] ?? []));

    $projectId = (int) $data['project_id'];
    if (!in_array($projectId, $allowedIds, true)) {
        $errors[] = 'Project tidak valid / bukan tanggung jawabmu.';
    }
    if ($data['nama'] === '') {
        $errors[] = 'Nama pekerjaan wajib diisi.';
    }
    if (!isset(STATUS_PEKERJAAN[$data['status']])) {
        $data['status'] = 'belum';
    }
    if (!isset(PRIORITAS[$data['prioritas']])) {
        $data['prioritas'] = 'normal';
    }
    $data['progress'] = (string) max(0, min(100, (int) $data['progress']));
    if ($data['status'] === 'selesai') {
        $data['progress'] = '100';
    }
    if ($data['lokasi_id'] !== '') {
        $lok = get_lokasi((int) $data['lokasi_id']);
        if (!$lok || (int) $lok['project_id'] !== $projectId) {
            $data['lokasi_id'] = '';
        }
    }
    if ($data['harga_satuan_id'] !== '') {
        $hs = get_harga_satuan((int) $data['harga_satuan_id']);
        if (!$hs) {
            $data['harga_satuan_id'] = '';
        }
    }
    $data['realisasi_tanggal'] = valid_tanggal($data['realisasi_tanggal']);
    $volKontrak = (float) str_replace(',', '.', $data['volume'] === '' ? '0' : $data['volume']);
    $volRealisasi = (float) str_replace(',', '.', $data['volume_realisasi'] === '' ? '0' : $data['volume_realisasi']);
    if ($volRealisasi < 0) {
        $volRealisasi = 0;
    }
    if ($volRealisasi > 0 && $data['realisasi_tanggal'] === '') {
        $data['realisasi_tanggal'] = date('Y-m-d');
    }

    if (empty($errors)) {
        $args = [
            $projectId,
            $data['lokasi_id'] !== '' ? (int) $data['lokasi_id'] : null,
            $data['nama'],
            $data['kategori'],
            $volKontrak,
            $data['satuan'],
            $data['status'],
            (int) $data['progress'],
            $data['mulai'],
            $data['deadline'],
            $data['prioritas'],
            $data['pelaksana_id'] !== '' ? (int) $data['pelaksana_id'] : null,
            $data['keterangan'],
            $data['harga_satuan_id'] !== '' ? (int) $data['harga_satuan_id'] : null,
            parse_money($data['harga_jasa']),
            parse_money($data['harga_upah']),
            $volRealisasi,
            $data['realisasi_tanggal'],
            $data['realisasi_catatan'],
        ];

        if ($id) {
            $args[] = $id;
            db()->prepare(
                'UPDATE pekerjaan SET project_id=?, lokasi_id=?, nama=?, kategori=?, volume=?, satuan=?, status=?,
                        progress=?, mulai=?, deadline=?, prioritas=?, pelaksana_id=?, keterangan=?,
                        harga_satuan_id=?, harga_jasa=?, harga_upah=?, volume_realisasi=?, realisasi_tanggal=?, realisasi_catatan=?
                 WHERE id=?'
            )->execute($args);
            flash('Pekerjaan <strong>' . e($data['nama']) . '</strong> berhasil diperbarui.');
        } else {
            db()->prepare(
                'INSERT INTO pekerjaan (project_id, lokasi_id, nama, kategori, volume, satuan, status, progress,
                        mulai, deadline, prioritas, pelaksana_id, keterangan,
                        harga_satuan_id, harga_jasa, harga_upah, volume_realisasi, realisasi_tanggal, realisasi_catatan)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute($args);
            $id = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO progress_log (pekerjaan_id, user_id, tanggal, progress, catatan) VALUES (?,?,?,?,?)')
                ->execute([$id, (int) $u['id'], $data['mulai'] !== '' ? $data['mulai'] : date('Y-m-d'), (int) $data['progress'], 'Pekerjaan dibuat.']);
            flash('Pekerjaan <strong>' . e($data['nama']) . '</strong> berhasil dibuat.');
        }

        // sinkronkan penugasan pekerja: nominal tetap / tarif per satuan / bagian
        $validPekerja = array_map(fn($r) => (int) $r['id'], selectable_pekerja());
        $assigned = array_values(array_intersect(array_unique($assigned), $validPekerja));
        simpan_penugasan($id, $assigned, $_POST);

        redirect('pekerjaan_detail.php?id=' . $id);
    }
}

$allLokasi = [];
foreach ($projectOptions as $p) {
    foreach (lokasi_of_project((int) $p['id']) as $l) {
        $allLokasi[] = ['id' => (int) $l['id'], 'project_id' => (int) $p['id'], 'nama' => $l['nama']];
    }
}

render_header(
    $pekerjaan ? 'Edit Pekerjaan' : 'Pekerjaan Baru',
    'Tetapkan lokasi, jadwal, pelaksana dan pekerja untuk pekerjaan ini',
    '<a class="btn" href="' . ($pekerjaan ? 'pekerjaan_detail.php?id=' . (int) $id : 'pekerjaan.php') . '">Kembali</a>'
);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="grid" style="gap:20px">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">

  <div class="card">
    <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
      <div><h2>Detail Pekerjaan</h2><p>Nama, kategori, volume dan jadwal</p></div>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="project_id">Project <span class="muted">*</span></label>
        <select id="project_id" name="project_id" data-project-select required>
          <?php foreach ($projectOptions as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= (int) $data['project_id'] === (int) $p['id'] ? ' selected' : '' ?>>
              <?= e($p['kode'] . ' · ' . $p['nama']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="lokasi_id">Lokasi kerja</label>
        <select id="lokasi_id" name="lokasi_id" data-lokasi-select>
          <option value="">— tanpa lokasi —</option>
          <?php foreach ($allLokasi as $l): ?>
            <option value="<?= $l['id'] ?>" data-project="<?= $l['project_id'] ?>"
              <?= (string) $data['lokasi_id'] === (string) $l['id'] ? ' selected' : '' ?>>
              <?= e($l['nama']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Pilihan lokasi mengikuti project yang dipilih.</span>
      </div>

      <div class="field full">
        <label for="harga_satuan_id">Ambil dari master harga satuan</label>
        <select id="harga_satuan_id" name="harga_satuan_id" data-harga-select>
          <option value="">— isi manual —</option>
          <?php foreach (harga_satuan_pilihan() as $hs): ?>
            <option value="<?= (int) $hs['id'] ?>"
                    data-nama="<?= e($hs['nama']) ?>"
                    data-kategori="<?= e($hs['kategori']) ?>"
                    data-satuan="<?= e($hs['satuan']) ?>"
                    data-jasa="<?= e(num($hs['harga_jasa'])) ?>"
                    data-upah="<?= e(num($hs['harga_upah'])) ?>"
                    <?= (string) $data['harga_satuan_id'] === (string) $hs['id'] ? ' selected' : '' ?>>
              <?= e($hs['nama']) ?><?= $hs['satuan'] !== '' ? ' — ' . e($hs['satuan']) : '' ?>
              (jasa <?= e(rupiah($hs['harga_jasa'])) ?> / upah <?= e(rupiah($hs['harga_upah'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">
          Memilih item akan mengisi nama, kategori, satuan, harga jasa &amp; upah dari master.
          Harga di pekerjaan ini masih bisa disesuaikan sendiri.
          <?= is_admin() ? '<a href="harga_satuan.php">Kelola master harga satuan</a>.' : '' ?>
        </span>
      </div>

      <div class="field full">
        <label for="nama">Nama pekerjaan <span class="muted">*</span></label>
        <input type="text" id="nama" name="nama" value="<?= e($data['nama']) ?>" placeholder="mis. Penarikan Kabel Listrik" required data-nama-pekerjaan>
      </div>

      <div class="field">
        <label for="kategori">Kategori</label>
        <input type="text" id="kategori" name="kategori" value="<?= e($data['kategori']) ?>" placeholder="mis. Struktur / MEP / Finishing" list="kategori-list">
        <datalist id="kategori-list">
          <option value="Persiapan"></option><option value="Pembongkaran"></option>
          <option value="Struktur"></option><option value="Arsitektur"></option>
          <option value="MEP"></option><option value="Finishing"></option>
        </datalist>
      </div>
      <div class="field">
        <label for="volume">Volume &amp; satuan</label>
        <div style="display:flex;gap:10px">
          <input type="text" id="volume" name="volume" value="<?= e($data['volume']) ?>" placeholder="mis. 210" style="flex:1">
          <input type="text" name="satuan" value="<?= e($data['satuan']) ?>" placeholder="m2" style="width:110px">
        </div>
      </div>

      <div class="field">
        <label for="harga_jasa">Harga jasa ke perusahaan (Rp / satuan)</label>
        <input type="text" id="harga_jasa" name="harga_jasa" value="<?= e($data['harga_jasa']) ?>" placeholder="mis. 5.000" data-harga-jasa>
        <span class="hint">Nilai yang ditagihkan ke pemilik pekerjaan.</span>
      </div>
      <div class="field">
        <label for="harga_upah">Upah dasar pekerja (Rp / satuan)</label>
        <input type="text" id="harga_upah" name="harga_upah" value="<?= e($data['harga_upah']) ?>" placeholder="mis. 2.500" data-harga-upah>
        <span class="hint">Dipakai menghitung upah borongan pekerja (boleh beda per pekerja).</span>
      </div>

      <div class="field">
        <label for="mulai">Tanggal mulai</label>
        <input type="date" id="mulai" name="mulai" value="<?= e($data['mulai']) ?>">
      </div>
      <div class="field">
        <label for="deadline">Deadline</label>
        <input type="date" id="deadline" name="deadline" value="<?= e($data['deadline']) ?>">
      </div>

      <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
          <?php foreach (STATUS_PEKERJAAN as $k => $v): ?>
            <option value="<?= e($k) ?>"<?= $data['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="prioritas">Prioritas</label>
        <select id="prioritas" name="prioritas">
          <?php foreach (PRIORITAS as $k => $v): ?>
            <option value="<?= e($k) ?>"<?= $data['prioritas'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field full">
        <label for="progress">Progress: <span id="progress-out"><?= (int) $data['progress'] ?>%</span></label>
        <input type="range" id="progress" name="progress" min="0" max="100" step="5"
               value="<?= (int) $data['progress'] ?>" data-progress="progress-out">
      </div>

      <div class="field">
        <label for="volume_realisasi">Volume akhir yang diselesaikan</label>
        <input type="text" id="volume_realisasi" name="volume_realisasi" value="<?= e($data['volume_realisasi']) ?>" placeholder="kosongkan bila belum selesai" data-vol-realisasi>
        <span class="hint">Diisi setelah pekerjaan rampung (mis. ternyata hanya 95 dari 100 m). Dasar hitung tagihan &amp; upah.</span>
      </div>
      <div class="field">
        <label for="realisasi_tanggal">Tanggal volume akhir dicatat</label>
        <input type="date" id="realisasi_tanggal" name="realisasi_tanggal" value="<?= e($data['realisasi_tanggal']) ?>">
      </div>
      <div class="field full">
        <label for="realisasi_catatan">Catatan volume akhir</label>
        <input type="text" id="realisasi_catatan" name="realisasi_catatan" value="<?= e($data['realisasi_catatan']) ?>" placeholder="mis. dikurangi area yang dibatalkan pemilik">
      </div>

      <div class="field full">
        <label for="keterangan">Keterangan</label>
        <textarea id="keterangan" name="keterangan" placeholder="Lingkup pekerjaan, material, catatan lapangan..."><?= e($data['keterangan']) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
      <div><h2>Penanggung Jawab &amp; Pekerja</h2><p>Pelaksana pengawas dan pekerja yang mengerjakan</p></div>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="pelaksana_id">Pelaksana penanggung jawab</label>
        <select id="pelaksana_id" name="pelaksana_id">
          <option value="">— mengikuti pelaksana project —</option>
          <?php foreach (selectable_pelaksana() as $pl): ?>
            <option value="<?= (int) $pl['id'] ?>"<?= (string) $data['pelaksana_id'] === (string) $pl['id'] ? ' selected' : '' ?>>
              <?= e($pl['nama']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field full">
        <label>Pekerja yang ditugaskan &amp; upah borongan</label>
        <span class="hint">
          Centang pekerja yang mengerjakan. Upah borongan dihitung dari
          <strong>upah per satuan × volume akhir</strong> (volume kontrak dipakai bila volume akhir belum diisi dan
          pekerjaan sudah <em>Selesai</em>). Isi <strong>Bagian</strong> bila pembagiannya tidak rata, isi
          <strong>Tarif</strong> bila upah pekerja ini berbeda dari upah dasar item, atau isi
          <strong>Nominal tetap</strong> untuk borongan borongan (mengalahkan hitungan per satuan).
        </span>
        <?php $allPekerja = selectable_pekerja(); ?>
        <?php if (!$allPekerja): ?>
          <p class="muted small">Belum ada akun pekerja. Tambahkan lewat menu <a href="users.php">Pengguna &amp; Akun</a>.</p>
        <?php else: ?>
          <div class="table-wrap borongan-table" data-borongan-table
               data-vol-kontrak="<?= e($data['volume']) ?>"
               data-vol-realisasi="<?= e($data['volume_realisasi']) ?>"
               data-upah-dasar="<?= e($data['harga_upah']) ?>">
            <table class="tbl">
              <thead>
                <tr>
                  <th style="width:44px"></th>
                  <th>Pekerja</th>
                  <th>Tarif / satuan (Rp)</th>
                  <th>Bagian (%)</th>
                  <th>Nominal tetap (Rp)</th>
                  <th>Upah terhitung</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($allPekerja as $pk): $pid = (int) $pk['id']; $row = $boronganAwal[$pid] ?? null; ?>
                <tr data-borongan-row>
                  <td>
                    <input type="checkbox" name="pekerja[]" value="<?= $pid ?>" data-borongan-check
                           <?= $row ? ' checked' : '' ?>>
                  </td>
                  <td>
                    <div style="display:flex;align-items:center;gap:9px">
                      <?= badge_avatar($pk['nama'], 'sm') ?>
                      <div class="cell-stack">
                        <strong><?= e($pk['nama']) ?></strong>
                        <small><?= e($pk['jabatan'] !== '' ? $pk['jabatan'] : 'Pekerja') ?> · harian <?= e(rupiah($pk['upah_harian'])) ?></small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <input type="text" name="tarif[<?= $pid ?>]" data-tarif
                           value="<?= $row && (float) $row['harga_upah_override'] > 0 ? e(num($row['harga_upah_override'])) : '' ?>"
                           placeholder="pakai upah dasar">
                  </td>
                  <td>
                    <input type="text" name="bagian[<?= $pid ?>]" data-bagian
                           value="<?= $row && (float) $row['bagian_pct'] > 0 ? e(num($row['bagian_pct'])) : '' ?>"
                           placeholder="rata">
                  </td>
                  <td>
                    <input type="text" name="borongan[<?= $pid ?>]" data-nominal
                           value="<?= $row && (float) $row['upah_borongan'] > 0 ? e(num($row['upah_borongan'])) : '' ?>"
                           placeholder="—">
                  </td>
                  <td class="nowrap"><span class="tag" data-upah-hitung>—</span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="small muted" data-borongan-total style="margin:10px 0 0">
            Upah borongan bisa cair setelah pekerjaan berstatus <em>Selesai</em>.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-actions" style="margin-top:20px">
      <button class="btn btn-primary" type="submit"><?= $pekerjaan ? 'Simpan Perubahan' : 'Simpan Pekerjaan' ?></button>
      <a class="btn btn-ghost" href="<?= $pekerjaan ? 'pekerjaan_detail.php?id=' . (int) $id : 'pekerjaan.php' ?>">Batal</a>
    </div>
  </div>
</form>

<?php if ($pekerjaan && can_manage_pekerjaan($pekerjaan)): ?>
  <div class="card">
    <h2 class="card-title">Hapus pekerjaan</h2>
    <p class="muted small" style="margin:8px 0 14px">
      Menghapus pekerjaan juga menghapus penugasan pekerja dan riwayat progress-nya.
    </p>
    <form method="post" action="pekerjaan_delete.php" data-confirm="Hapus pekerjaan &quot;<?= e($pekerjaan['nama']) ?>&quot;?">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <button class="btn btn-danger" type="submit">Hapus Pekerjaan</button>
    </form>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
