<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$id = (int) ($_GET['id'] ?? 0);
$pj = get_pekerjaan($id);

if (!$pj || !can_view_pekerjaan($pj)) {
    flash('Pekerjaan tidak ditemukan atau bukan bagian dari penugasanmu.', 'err');
    redirect('pekerjaan.php');
}

$canManage = can_manage_pekerjaan($pj);
$canUpdate = can_update_progress($pj);
$log = log_of_pekerjaan($id);
$pekerja = pekerja_of($id);
$assignedIds = array_map(fn($r) => (int) $r['id'], $pekerja);
$absensiPj = absensi_of_pekerjaan($id);
$totalHariPj = array_sum(array_map(fn($a) => (float) $a['hari'], $absensiPj));
$totalUpahHariPj = array_sum(array_map(fn($a) => (float) $a['upah'], $absensiPj));
$lihatNominal = can_view_all_wages();

// hitungan uang: harga jasa (tagihan ke perusahaan) & upah pekerja
$bor = borongan_detail($id);
$borongan = [];
foreach ($bor['rows'] as $brow) {
    $borongan[(int) $brow['user_id']] = $brow;
}
$totalBorongan = $bor['total'];
$volEfektif = volume_efektif($pj);
$stLapor = db()->prepare('SELECT COUNT(*) FROM laporan_kerja WHERE pekerjaan_id = ? AND perusahaan_id = ?');
$stLapor->execute([$id]);
$jmlLaporanPj = (int) $stLapor->fetchColumn();
$nilaiJasa = nilai_jasa_pekerjaan($pj);
$nilaiKontrak = nilai_kontrak_pekerjaan($pj);
$marginItem = $nilaiJasa - $bor['total_cair'];
$hsMaster = $pj['harga_satuan_id'] ? get_harga_satuan((int) $pj['harga_satuan_id']) : null;
$bisaRealisasi = can_update_progress($pj);

$actions = '<a class="btn" href="project_detail.php?id=' . (int) $pj['project_id'] . '">Project</a>';
if ($canManage) {
    $actions .= '<a class="btn btn-primary" href="pekerjaan_form.php?id=' . $id . '">Edit Pekerjaan</a>';
}

render_header(
    $pj['nama'],
    e($pj['project_nama']) . ' · ' . e($pj['project_kode']) . (($pj['lokasi_nama'] ?? '') ? ' · ' . e($pj['lokasi_nama']) : ''),
    $actions
);
?>

<div class="stats">
  <?php
  stat_card('Progress', (int) $pj['progress'] . '%', 'status: ' . e(status_label($pj['status'])), (int) $pj['progress'] >= 100 ? 'ok' : 'info');
  stat_card('Deadline', tgl($pj['deadline'], '—'), deadline_note($pj['deadline'], $pj['status']), is_late($pj) ? 'danger' : 'warn');
  stat_card('Volume Kontrak', (float) $pj['volume'] > 0 ? e(num($pj['volume']) . ' ' . $pj['satuan']) : '—',
      'volume akhir: ' . (float) $pj['volume_realisasi'] > 0 ? e(num($pj['volume_realisasi']) . ' ' . $pj['satuan']) : '<span class="muted">belum dicatat</span>', '');
  if ($lihatNominal) {
      stat_card('Nilai Pengajuan', (float) $nilaiJasa > 0 ? e(rupiah($nilaiJasa)) : '—',
          'harga jasa ' . e(rupiah($pj['harga_jasa'])) . ' × volume akhir', 'ok');
  } else {
      stat_card('Pekerja Ditugaskan', (string) count($pekerja), 'dari total ' . count($pekerja) . ' pekerja', 'info');
  }
  ?>
</div>

<div class="grid grid-side">
  <div class="grid" style="gap:20px">
    <div class="card">
      <div class="card-head">
        <div><h2>Informasi Pekerjaan</h2><p>Detail pelaksanaan di lapangan</p></div>
        <span class="spacer"></span>
        <?= status_pill($pj) ?>
      </div>
      <div style="margin-bottom:18px">
        <?= progress_bar((int) $pj['progress'], ' bar-lg') ?>
      </div>
      <dl class="kv">
        <dt>Project</dt>
        <dd><a href="project_detail.php?id=<?= (int) $pj['project_id'] ?>"><?= e($pj['project_nama']) ?></a>
            <span class="mono muted small"><?= e($pj['project_kode']) ?></span></dd>
        <dt>Lokasi kerja</dt><dd><?= $pj['lokasi_nama'] ? e($pj['lokasi_nama']) : '<span class="muted">belum ditentukan</span>' ?></dd>
        <dt>Pelaksana</dt>
        <dd><?= $pj['pelaksana_nama'] ? e($pj['pelaksana_nama']) : '<span class="muted">mengikuti pelaksana project</span>' ?></dd>
        <dt>Kategori</dt><dd><?= e($pj['kategori'] !== '' ? $pj['kategori'] : '—') ?></dd>
        <dt>Volume</dt><dd><?= (float) $pj['volume'] > 0 ? e(num($pj['volume'])) . ' ' . e($pj['satuan']) : '<span class="muted">—</span>' ?></dd>
        <dt>Mulai</dt><dd><?= e(tgl($pj['mulai'])) ?></dd>
        <dt>Deadline</dt><dd><?= deadline_note($pj['deadline'], $pj['status']) ?></dd>
        <dt>Prioritas</dt><dd><?= prioritas_pill($pj['prioritas']) ?></dd>
        <dt>Keterangan</dt><dd><?= $pj['keterangan'] !== '' ? nl2br(e($pj['keterangan'])) : '<span class="muted">—</span>' ?></dd>
        <?php if ($lihatNominal): ?>
          <dt>Item master</dt>
          <dd><?= $hsMaster ? '<a href="harga_satuan_form.php?id=' . (int) $hsMaster['id'] . '">' . e($hsMaster['nama']) . '</a>' : '<span class="muted">isi manual</span>' ?></dd>
          <dt>Harga jasa / satuan</dt><dd><?= e(rupiah($pj['harga_jasa'])) ?> <span class="muted small">ditagihkan ke pemilik pekerjaan</span></dd>
          <dt>Upah dasar / satuan</dt><dd><?= e(rupiah($pj['harga_upah'])) ?> <span class="muted small">dibayarkan ke pekerja</span></dd>
          <dt>Volume akhir</dt>
          <dd>
            <?= (float) $pj['volume_realisasi'] > 0
                  ? e(num($pj['volume_realisasi']) . ' ' . $pj['satuan']) . ' <span class="muted small">· dicatat ' . e(tgl($pj['realisasi_tanggal'])) . '</span>'
                  : '<span class="muted">belum dicatat</span>' ?>
            <?php if ((int) ($pj['volume_auto'] ?? 0) === 1): ?>
              <span class="pill pill-proses">otomatis dari laporan harian</span>
            <?php endif; ?>
            <?php if ($pj['realisasi_catatan'] !== ''): ?><br><span class="muted small"><?= e($pj['realisasi_catatan']) ?></span><?php endif; ?>
          </dd>
          <dt>Nilai kontrak (jasa)</dt><dd><?= e(rupiah($nilaiKontrak)) ?> <span class="muted small">volume kontrak penuh</span></dd>
          <dt>Nilai pengajuan</dt><dd class="strong"><?= e(rupiah($nilaiJasa)) ?></dd>
          <dt>Hari kerja tercatat</dt><dd><?= e(hari_format($totalHariPj)) ?> · upah harian <?= e(rupiah($totalUpahHariPj)) ?></dd>
          <dt>Upah borongan</dt>
          <dd><?= e(rupiah($totalBorongan)) ?>
              <span class="muted small">(<?= e(rupiah($bor['total_cair'])) ?> cair · <?= e(rupiah($bor['total_berjalan'])) ?> berjalan)</span></dd>
          <dt>Estimasi margin item</dt>
          <dd class="<?= $marginItem < 0 ? 'deadline-late' : 'strong' ?>"><?= e(rupiah($marginItem)) ?>
              <span class="muted small">nilai pengajuan − upah borongan cair</span></dd>
        <?php endif; ?>
      </dl>
    </div>

    <div class="card">
      <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
        <div><h2>Riwayat Progress</h2><p><?= count($log) ?> catatan laporan lapangan</p></div>
      </div>
      <?php if (!$log): ?>
        <p class="muted small">Belum ada laporan progress. Update pertama akan tampil di sini.</p>
      <?php else: ?>
        <div class="timeline">
          <?php foreach ($log as $i => $l): ?>
            <div class="tl-item">
              <div class="tl-dot"><?= (int) $l['progress'] ?>%</div>
              <div class="tl-body">
                <strong><?= $l['user_nama'] ? e($l['user_nama']) : 'Sistem' ?></strong>
                <span class="muted small"> · <?= e(tgl($l['tanggal'])) ?><?= $i === 0 ? ' · terbaru' : '' ?></span>
                <?php if ($l['catatan'] !== ''): ?>
                  <p><?= nl2br(e($l['catatan'])) ?></p>
                <?php else: ?>
                  <p class="muted">Tanpa catatan.</p>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid" style="gap:20px">
    <?php if ($canUpdate): ?>
      <div class="card">
        <div class="card-head" style="padding:0 0 16px;border-bottom:1px solid var(--line-soft);margin-bottom:18px">
          <div><h2>Update Progress</h2><p>Catat perkembangan pekerjaan hari ini</p></div>
        </div>
        <form method="post" action="progress_save.php" class="grid" style="gap:15px">
          <?= csrf_field() ?>
          <input type="hidden" name="pekerjaan_id" value="<?= $id ?>">

          <div class="field">
            <label for="progress">Persentase progress: <span id="progress-out"><?= (int) $pj['progress'] ?>%</span></label>
            <input type="range" id="progress" name="progress" min="0" max="100" step="5"
                   value="<?= (int) $pj['progress'] ?>" data-progress="progress-out">
          </div>

          <?php if ($canManage): ?>
            <div class="field">
              <label for="status">Status pekerjaan</label>
              <select id="status" name="status">
                <?php foreach (STATUS_PEKERJAAN as $k => $v): ?>
                  <option value="<?= e($k) ?>"<?= $pj['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div class="field">
            <label for="tanggal">Tanggal laporan</label>
            <input type="date" id="tanggal" name="tanggal" value="<?= e(date('Y-m-d')) ?>">
          </div>

          <div class="field">
            <label for="catatan">Catatan lapangan</label>
            <textarea id="catatan" name="catatan" placeholder="mis. Pemasangan rangka selesai 60%, material besok datang"></textarea>
          </div>

          <button class="btn btn-primary btn-block" type="submit">Simpan Progress</button>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Update Progress</h2>
        <p class="muted small" style="margin-top:8px">
          Hanya pelaksana project atau pekerja yang ditugaskan pada pekerjaan ini yang dapat memperbarui progress.
        </p>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div><h2>Volume Akhir</h2><p>Volume yang benar-benar diselesaikan</p></div>
      </div>
      <?php if ($bisaRealisasi): ?>
        <form method="post" action="pekerjaan_realisasi_save.php" class="grid" style="gap:13px">
          <?= csrf_field() ?>
          <input type="hidden" name="pekerjaan_id" value="<?= $id ?>">
          <div class="field">
            <label for="volume_realisasi">Volume akhir (<?= e($pj['satuan'] !== '' ? $pj['satuan'] : 'satuan') ?>)</label>
            <input type="text" id="volume_realisasi" name="volume_realisasi"
                   value="<?= (float) $pj['volume_realisasi'] > 0 ? e(num($pj['volume_realisasi'])) : '' ?>"
                   placeholder="mis. 95 dari kontrak <?= e(num($pj['volume'])) ?>">
            <span class="hint">Kosongkan bila belum ada volume yang selesai. Isinya bisa dikoreksi kapan saja.</span>
          </div>
          <div class="field">
            <label for="realisasi_tanggal">Tanggal dicatat</label>
            <input type="date" id="realisasi_tanggal" name="realisasi_tanggal" value="<?= e($pj['realisasi_tanggal'] ?: date('Y-m-d')) ?>">
          </div>
          <div class="field">
            <label for="realisasi_catatan">Catatan</label>
            <input type="text" id="realisasi_catatan" name="realisasi_catatan" value="<?= e($pj['realisasi_catatan']) ?>" placeholder="mis. ada area yang dibatalkan">
          </div>
          <button class="btn btn-primary" type="submit">Simpan Volume Akhir</button>
        </form>
        <?php if ((float) $pj['volume_realisasi'] > 0): ?>
          <div class="money-note" style="margin-top:14px">
            <?php if ((int) ($pj['volume_auto'] ?? 0) === 1): ?>
              Volume akhir <strong><?= e(num($pj['volume_realisasi']) . ' ' . $pj['satuan']) ?></strong> dihitung
              <strong>otomatis dari <?= $jmlLaporanPj ?> laporan hasil kerja harian</strong>
              — mengubah manual di sini akan menimpanya sampai laporan berikutnya masuk.
            <?php else: ?>
              Volume akhir <strong><?= e(num($pj['volume_realisasi']) . ' ' . $pj['satuan']) ?></strong> diisi manual dan dipakai sebagai
              dasar nilai pengajuan<?= $lihatNominal ? ' (' . e(rupiah($nilaiJasa)) . ')' : '' ?> serta upah borongan pekerja.
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted small">
          Hanya pelaksana project atau pekerja yang ditugaskan yang dapat mencatat volume akhir.
        </p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--line-soft);margin-bottom:16px">
        <div><h2>Pekerja &amp; Upah</h2><p><?= count($pekerja) ?> orang ditugaskan · <?= e(hari_format($totalHariPj)) ?> tercatat</p></div>
      </div>
      <?php if (!$pekerja): ?>
        <p class="muted small">Belum ada pekerja yang ditugaskan pada pekerjaan ini.</p>
      <?php else: ?>
        <div class="table-wrap" style="margin-bottom:16px">
          <table class="tbl">
            <thead>
              <tr>
                <th>Pekerja</th>
                <th>Hari</th>
                <?php if ($lihatNominal): ?><th>Cara upah</th><th>Upah Harian</th><th>Upah Borongan</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($pekerja as $p): $pid = (int) $p['id']; ?>
              <?php
              $ab = $absensiPj[$pid] ?? null;
              $bb = $borongan[$pid] ?? null;
              $nilaiBor = $bb ? (float) $bb['nilai'] : 0.0;
              $cara = $bb ? ($bb['mode'] === 'nominal'
                    ? 'nominal tetap'
                    : 'satuan ' . num($bb['harga']) . ' × ' . num($volEfektif) . ' × ' . num($bb['pct']) . '%') : 'harian';
              ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:9px">
                    <?= badge_avatar($p['nama'], 'sm') ?>
                    <div class="cell-stack">
                      <strong><?= e($p['nama']) ?></strong>
                      <small><?= e($p['jabatan'] !== '' ? $p['jabatan'] : 'Pekerja') ?></small>
                    </div>
                  </div>
                </td>
                <td class="nowrap"><?= $ab ? '<span class="tag">' . e(hari_format($ab['hari'])) . '</span>' : '<span class="muted small">—</span>' ?></td>
                <?php if ($lihatNominal): ?>
                  <td class="small"><?= e($cara) ?></td>
                  <td class="small nowrap"><?= $ab ? e(rupiah($ab['upah'])) : '<span class="muted">—</span>' ?></td>
                  <td class="nowrap"><?= $nilaiBor > 0 ? '<strong>' . e(rupiah($nilaiBor)) . '</strong>' : '<span class="muted">—</span>' ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($lihatNominal): ?>
              <tfoot>
                <tr>
                  <td class="strong right">Total</td>
                  <td class="strong nowrap"><?= e(hari_format($totalHariPj)) ?></td>
                  <td></td>
                  <td class="strong nowrap"><?= e(rupiah($totalUpahHariPj)) ?></td>
                  <td class="strong nowrap"><?= e(rupiah($totalBorongan)) ?></td>
                </tr>
              </tfoot>
            <?php endif; ?>
          </table>
        </div>
        <p class="small muted" style="margin:0 0 14px">
          Upah borongan dihitung sebagai gaji begitu pekerjaan ini berstatus <em>Selesai</em>.
          Hari kerja dicatat pada menu
          <?php if ($bisaRealisasi): ?>
            <a href="absensi.php?project_id=<?= (int) $pj['project_id'] ?>">Absensi Hari Kerja</a>.
          <?php else: ?>
            Absensi Hari Kerja.
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <form method="post" action="pekerjaan_pekerja_save.php" class="grid" style="gap:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="pekerjaan_id" value="<?= $id ?>">
          <?php $allPekerja = selectable_pekerja(); ?>
          <?php if (!$allPekerja): ?>
            <p class="muted small">Belum ada akun pekerja. <a href="users.php">Tambah pengguna</a>.</p>
          <?php else: ?>
            <span class="hint">
              Centang pekerja yang mengerjakan. Kosongkan Tarif bila memakai upah dasar item
              (<?= e(rupiah($pj['harga_upah'])) ?> / <?= e($pj['satuan'] !== '' ? $pj['satuan'] : 'satuan') ?>),
              dan kosongkan Bagian bila dibagi rata.
            </span>
            <div class="table-wrap borongan-table" data-borongan-table
                 data-vol-kontrak="<?= e(num($pj['volume'])) ?>"
                 data-vol-realisasi="<?= e((float) $pj['volume_realisasi'] > 0 ? num($pj['volume_realisasi']) : '') ?>"
                 data-upah-dasar="<?= e(num($pj['harga_upah'])) ?>">
              <table class="tbl">
                <thead>
                  <tr>
                    <th style="width:44px"></th><th>Pekerja</th>
                    <th>Tarif / satuan (Rp)</th><th>Bagian (%)</th><th>Nominal tetap (Rp)</th><th>Upah</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($allPekerja as $pk): $pid = (int) $pk['id']; $row = $borongan[$pid] ?? null; ?>
                  <tr data-borongan-row>
                    <td><input type="checkbox" name="pekerja[]" value="<?= $pid ?>" data-borongan-check
                          <?= $row ? ' checked' : '' ?>></td>
                    <td>
                      <div style="display:flex;align-items:center;gap:9px">
                        <?= badge_avatar($pk['nama'], 'sm') ?>
                        <div class="cell-stack">
                          <strong><?= e($pk['nama']) ?></strong>
                          <small>harian <?= e(rupiah($pk['upah_harian'])) ?></small>
                        </div>
                      </div>
                    </td>
                    <td><input type="text" name="tarif[<?= $pid ?>]" data-tarif
                          value="<?= $row && (float) $row['harga_upah_override'] > 0 ? e(num($row['harga_upah_override'])) : '' ?>"
                          placeholder="upah dasar"></td>
                    <td><input type="text" name="bagian[<?= $pid ?>]" data-bagian
                          value="<?= $row && (float) $row['bagian_pct'] > 0 ? e(num($row['bagian_pct'])) : '' ?>"
                          placeholder="rata"></td>
                    <td><input type="text" name="borongan[<?= $pid ?>]" data-nominal
                          value="<?= $row && (float) $row['upah_borongan'] > 0 ? e(num($row['upah_borongan'])) : '' ?>"
                          placeholder="—"></td>
                    <td class="nowrap"><span class="tag" data-upah-hitung>—</span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <button class="btn" type="submit">Simpan Penugasan &amp; Upah</button>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
      <div class="card">
        <h2 class="card-title">Hapus Pekerjaan</h2>
        <p class="muted small" style="margin:8px 0 14px">Riwayat progress dan penugasan ikut terhapus.</p>
        <form method="post" action="pekerjaan_delete.php" data-confirm="Hapus pekerjaan &quot;<?= e($pj['nama']) ?>&quot;?">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-danger" type="submit">Hapus Pekerjaan</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>
