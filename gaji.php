<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/queries.php';
require_once __DIR__ . '/inc/partials.php';
require_once __DIR__ . '/inc/layout.php';

$u = require_login();
$isPekerja = $u['role'] === 'pekerja';
$bolehAtur = in_array($u['role'], ['admin', 'pelaksana'], true);

$periode = periode_gaji((string) ($_GET['acuan'] ?? ''), (int) ($_GET['geser'] ?? 0));
$f = [
    'dari'   => valid_tanggal((string) ($_GET['dari'] ?? '')) ?: $periode['dari'],
    'sampai' => valid_tanggal((string) ($_GET['sampai'] ?? '')) ?: $periode['sampai'],
    'status' => (string) ($_GET['status'] ?? ''),
    'user_id' => $isPekerja ? (int) $u['id'] : (int) ($_GET['user_id'] ?? 0),
];
if (!in_array($f['status'], ['belum', 'dibayar'], true)) {
    $f['status'] = '';
}

$daftar = fetch_penggajian($f);
$ringkas = ringkasan_gaji($f);

// tenaga yang belum dibuatkan gaji pada periode ini (untuk admin/pelaksana)
$belumDigaji = [];
if ($bolehAtur) {
    $sudahIds = array_map(fn($g) => (int) $g['user_id'], $daftar);
    foreach (all_users() as $p) {
        if ($p['role'] === 'admin' || (int) $p['aktif'] !== 1) {
            continue;
        }
        if (in_array((int) $p['id'], $sudahIds, true)) {
            continue;
        }
        $g = hitung_gaji((int) $p['id'], $f['dari'], $f['sampai']);
        // Hanya tenaga yang punya upah pada periode ini (kalau upah 0, tidak ada yang dibayarkan
        // dan kasbonnya tetap aktif untuk periode berikutnya).
        if ($g['total_gaji'] <= 0) {
            continue;
        }
        $belumDigaji[] = ['orang' => $p] + $g;
    }
}

$totalKasbonAktif = 0.0;
foreach (all_users() as $p) {
    if ($isPekerja && (int) $p['id'] !== (int) $u['id']) {
        continue;
    }
    $totalKasbonAktif += kasbon_total_aktif((int) $p['id']);
}

$aksi = '<a class="btn" href="kasbon.php">Kasbon</a>';
if (!$isPekerja) {
    $aksi .= '<a class="btn" href="pengaturan.php">Tutup Buku: tgl ' . (tutup_buku_tgl() ?: '—') . '</a>';
}

render_header(
    $isPekerja ? 'Gaji Saya' : 'Gaji Pekerja',
    'Periode ' . e($periode['label']) . ' · tandai sudah dibayar / belum dibayar',
    $aksi
);
?>

<div class="stats">
  <?php
  stat_card('Penggajian', (string) $ringkas['jumlah'], $ringkas['belum_orang'] . ' belum dibayar · ' . $ringkas['dibayar_orang'] . ' sudah', 'info');
  stat_card('Belum Dibayar', e(rupiah($ringkas['belum'])), 'gaji bersih yang belum dibayarkan', $ringkas['belum'] > 0 ? 'warn' : 'ok');
  stat_card('Sudah Dibayar', e(rupiah($ringkas['dibayar'])), 'periode pada filter ini', 'ok');
  stat_card('Kasbon Aktif', e(rupiah($totalKasbonAktif)), 'belum dipotong dari gaji', $totalKasbonAktif > 0 ? 'danger' : '');
  ?>
</div>

<div class="card">
  <form class="filters" method="get">
    <div class="field">
      <label for="acuan">Periode gaji</label>
      <select id="acuan" name="acuan" onchange="this.form.submit()">
        <?php
        $opsi = [-1 => 'Periode sebelumnya', 0 => 'Periode berjalan', 1 => 'Periode berikutnya'];
        foreach ($opsi as $g => $label):
            $p = periode_gaji(date('Y-m-d'), $g);
            ?>
          <option value="<?= e($p['sampai']) ?>"<?= $p['sampai'] === $f['sampai'] ? ' selected' : '' ?>>
            <?= e($label . ': ' . $p['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="dari">Dari tanggal</label>
      <input type="date" id="dari" name="dari" value="<?= e($f['dari']) ?>">
    </div>
    <div class="field">
      <label for="sampai">Sampai tanggal</label>
      <input type="date" id="sampai" name="sampai" value="<?= e($f['sampai']) ?>">
    </div>
    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Semua status</option>
        <option value="belum"<?= $f['status'] === 'belum' ? ' selected' : '' ?>>Belum dibayar</option>
        <option value="dibayar"<?= $f['status'] === 'dibayar' ? ' selected' : '' ?>>Sudah dibayar</option>
      </select>
    </div>
    <?php if (!$isPekerja): ?>
      <div class="field">
        <label for="user_id">Tenaga</label>
        <select id="user_id" name="user_id">
          <option value="">Semua tenaga</option>
          <?php foreach (all_users() as $p): ?>
            <?php if ($p['role'] === 'admin') { continue; } ?>
            <option value="<?= (int) $p['id'] ?>"<?= $f['user_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <button class="btn btn-dark" type="submit">Terapkan</button>
    <a class="btn btn-ghost" href="gaji.php">Reset</a>
  </form>
  <?php if (!$periode['tutup']): ?>
    <p class="small muted" style="margin:14px 0 0">
      Tanggal tutup buku belum diatur, jadi periode memakai bulan kalender (tanggal 1 – akhir bulan).
      Atur di <a href="pengaturan.php">Pengaturan</a> supaya penanggalan gaji &amp; kasbon jelas.
    </p>
  <?php endif; ?>
</div>

<?php if ($bolehAtur && $belumDigaji): ?>
  <form method="post" action="gaji_form.php" class="card flush">
    <?= csrf_field() ?>
    <input type="hidden" name="dari" value="<?= e($f['dari']) ?>">
    <input type="hidden" name="sampai" value="<?= e($f['sampai']) ?>">
    <div class="card-head">
      <div>
        <h2>Belum Digaji pada Periode Ini</h2>
        <p><?= count($belumDigaji) ?> tenaga · centang lalu buat penggajiannya sekaligus</p>
      </div>
      <span class="spacer"></span>
      <button class="btn btn-primary" type="submit">Buat Gaji untuk yang Dicentang</button>
    </div>
    <div class="table-wrap">
      <table class="tbl absensi-tbl">
        <thead>
          <tr>
            <th style="width:44px"><input type="checkbox" data-check-all aria-label="Pilih semua"></th>
            <th>Tenaga</th><th>Skema</th><th>Hari Kerja</th><th>Upah Harian</th><th>Upah Borongan</th>
            <th>Gaji Seharusnya</th><th>Kasbon Dipotong</th><th>Gaji Diterima</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($belumDigaji as $b): $id = (int) $b['orang']['id']; ?>
          <tr>
            <td><input type="checkbox" name="pekerja[]" value="<?= $id ?>" data-row-check checked></td>
            <td>
              <div style="display:flex;align-items:center;gap:9px">
                <?= badge_avatar($b['orang']['nama'], 'sm') ?>
                <div class="cell-stack">
                  <strong><?= e($b['orang']['nama']) ?></strong>
                  <small><?= e($b['orang']['jabatan'] !== '' ? $b['orang']['jabatan'] : '—') ?></small>
                </div>
              </div>
            </td>
            <td><span class="pill <?= $b['skema'] === 'borongan' ? 'pill-proses' : 'pill-belum' ?>"><?= e(skema_label($b['skema'])) ?></span></td>
            <td class="small nowrap"><?= (float) $b['hari'] > 0 ? e(hari_format($b['hari'])) : '<span class="muted">—</span>' ?></td>
            <td class="small nowrap"><?= e(rupiah($b['upah_harian'])) ?></td>
            <td class="small nowrap"><?= e(rupiah($b['upah_borongan'])) ?></td>
            <td class="strong nowrap"><?= e(rupiah($b['total_gaji'])) ?></td>
            <td class="nowrap <?= $b['total_kasbon'] > 0 ? 'deadline-late' : '' ?>">
              <?= $b['total_kasbon'] > 0 ? '− ' . e(rupiah($b['total_kasbon'])) . ' <span class="small muted">(' . count($b['kasbon']) . ' kasbon)</span>' : '<span class="muted">—</span>' ?>
              <?php if ($b['kasbon_tertahan'] > 0): ?>
                <div class="small muted">sisa kasbon <?= e(rupiah($b['kasbon_tertahan'])) ?> ditahan ke periode berikutnya</div>
              <?php endif; ?>
            </td>
            <td class="strong nowrap"><?= e(rupiah($b['diterima'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
<?php endif; ?>

<div class="card flush">
  <div class="card-head">
    <div><h2>Daftar Penggajian</h2><p><?= count($daftar) ?> data pada filter ini</p></div>
  </div>
  <?php if (!$daftar): ?>
    <div class="empty">
      <strong>Belum ada penggajian</strong>
      <span class="small">
        <?= $bolehAtur
              ? 'Pilih periode, lalu centang tenaga di daftar “Belum Digaji” untuk membuat penggajian.'
              : 'Gaji akan muncul setelah admin membuat penggajian periode ini.' ?>
      </span>
    </div>
  <?php else: ?>
    <?php if ($bolehAtur): ?>
      <form method="post" action="gaji_status_massal.php" id="daftar-gaji">
        <?= csrf_field() ?>
        <input type="hidden" name="kembali" value="<?= e('gaji.php?' . http_build_query(array_filter(['dari' => $f['dari'], 'sampai' => $f['sampai'], 'status' => $f['status'], 'user_id' => $f['user_id'] ?: '']))) ?>">
        <div class="aksi-massal">
          <span class="small muted">Centang gaji lalu tandai sekaligus:</span>
          <label class="check" style="padding:7px 11px">
            <input type="checkbox" data-check-all aria-label="Pilih semua">
            <span>Pilih semua</span>
          </label>
          <input type="date" name="tanggal_bayar" value="<?= e(date('Y-m-d')) ?>" title="Tanggal dibayar">
          <button class="btn btn-sm btn-primary" type="submit" name="status" value="dibayar">Tandai Sudah Dibayar</button>
          <button class="btn btn-sm" type="submit" name="status" value="belum">Tandai Belum Dibayar</button>
          <span class="spacer" style="margin-left:auto"></span>
          <span class="muted small" data-gaji-info>Belum ada yang dicentang.</span>
        </div>
    <?php endif; ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <?php if ($bolehAtur): ?><th style="width:44px"></th><?php endif; ?>
            <th>Periode</th>
            <?php if (!$isPekerja): ?><th>Tenaga</th><?php endif; ?>
            <th>Gaji Seharusnya</th><th>Kasbon</th><th>Diterima</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($daftar as $g): ?>
          <tr>
            <?php if ($bolehAtur): ?>
              <td>
                <input type="checkbox" name="gaji[]" value="<?= (int) $g['id'] ?>" data-gaji-item
                       data-status="<?= e((string) $g['status']) ?>" data-nilai="<?= (float) $g['total_dibayar'] ?>">
              </td>
            <?php endif; ?>
            <td class="nowrap">
              <div class="cell-stack">
                <strong><?= e(tgl($g['periode_dari'])) ?> – <?= e(tgl($g['periode_sampai'])) ?></strong>
                <small class="mono"><?= e($g['nomor']) ?></small>
              </div>
            </td>
            <?php if (!$isPekerja): ?>
              <td>
                <div style="display:flex;align-items:center;gap:9px">
                  <?= badge_avatar($g['pekerja_nama'], 'sm') ?>
                  <div class="cell-stack">
                    <strong><?= e($g['pekerja_nama']) ?></strong>
                    <small><?= e(skema_label((string) $g['skema'])) ?></small>
                  </div>
                </div>
              </td>
            <?php endif; ?>
            <td class="nowrap"><?= e(rupiah($g['total_gaji'])) ?></td>
            <td class="nowrap <?= (float) $g['total_kasbon'] > 0 ? 'deadline-late' : '' ?>">
              <?= (float) $g['total_kasbon'] > 0 ? '− ' . e(rupiah($g['total_kasbon'])) : '<span class="muted">—</span>' ?>
            </td>
            <td class="strong nowrap"><?= e(rupiah($g['total_dibayar'])) ?></td>
            <td>
              <?php if ($g['status'] === 'dibayar'): ?>
                <span class="pill pill-selesai">Sudah dibayar</span>
                <?php if ($g['tanggal_bayar'] !== ''): ?><div class="small muted"><?= e(tgl($g['tanggal_bayar'])) ?></div><?php endif; ?>
              <?php else: ?>
                <span class="pill pill-tertunda">Belum dibayar</span>
              <?php endif; ?>
            </td>
            <td class="right nowrap"><a class="btn btn-sm" href="gaji_detail.php?id=<?= (int) $g['id'] ?>">Rincian</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="strong" colspan="<?= ($bolehAtur ? 1 : 0) + ($isPekerja ? 2 : 3) ?>">Total pada filter ini</td>
            <td class="strong nowrap"><?= e(rupiah(array_sum(array_map(fn($g) => (float) $g['total_gaji'], $daftar)))) ?></td>
            <td class="strong nowrap">− <?= e(rupiah($ringkas['kasbon'])) ?></td>
            <td class="strong nowrap"><?= e(rupiah($ringkas['belum'] + $ringkas['dibayar'])) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php if ($bolehAtur): ?>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
