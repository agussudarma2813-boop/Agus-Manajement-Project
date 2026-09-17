<?php
declare(strict_types=1);

/**
 * Partial: tabel daftar pekerjaan (dipakai dashboard, daftar pekerjaan,
 * halaman project, dan halaman tim).
 */
function render_pekerjaan_table(array $rows, bool $showProject = true, string $emptyText = 'Belum ada pekerjaan.'): void
{
    if (!$rows) {
        echo '<div class="empty"><strong>' . e($emptyText) . '</strong><span class="small">Tambahkan pekerjaan baru atau ubah filter di atas.</span></div>';
        return;
    }
    ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Pekerjaan</th>
            <?php if ($showProject): ?><th>Project</th><?php endif; ?>
            <th>Lokasi</th>
            <th>Pelaksana</th>
            <th>Pekerja</th>
            <th>Deadline</th>
            <th>Progress</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
          <tr>
            <td>
              <div class="cell-stack">
                <strong><?= e($p['nama']) ?></strong>
                <small>
                  <?= e($p['kategori'] !== '' ? $p['kategori'] : 'Tanpa kategori') ?>
                  <?php if ((float) $p['volume'] > 0): ?>
                    · <?= e(num($p['volume'])) ?> <?= e($p['satuan']) ?>
                  <?php endif; ?>
                  <?php if ($p['prioritas'] !== 'normal'): ?> · <?= prioritas_pill($p['prioritas']) ?><?php endif; ?>
                </small>
              </div>
            </td>
            <?php if ($showProject): ?>
              <td>
                <div class="cell-stack">
                  <a href="project_detail.php?id=<?= (int) $p['project_id'] ?>"><?= e($p['project_nama']) ?></a>
                  <small class="mono"><?= e($p['project_kode']) ?></small>
                </div>
              </td>
            <?php endif; ?>
            <td class="small"><?= e($p['lokasi_nama'] ?? '—') ?></td>
            <td class="small"><?= $p['pelaksana_nama'] ? e($p['pelaksana_nama']) : '<span class="muted">—</span>' ?></td>
            <td class="small"><?= pekerja_names((int) $p['id']) ?></td>
            <td class="small nowrap"><?= deadline_note($p['deadline'], $p['status']) ?></td>
            <td>
              <div class="progress-cell">
                <?= progress_bar((int) $p['progress'], ' bar-lg') ?>
                <b><?= (int) $p['progress'] ?>%</b>
              </div>
            </td>
            <td><?= status_pill($p) ?></td>
            <td class="right nowrap">
              <a class="btn btn-sm" href="pekerjaan_detail.php?id=<?= (int) $p['id'] ?>">Detail</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/** Pilihan status pekerjaan untuk dropdown filter */
function status_options(string $selected = '', bool $withLate = false, bool $withAll = true): void
{
    if ($withAll) {
        echo '<option value="">Semua status</option>';
    }
    foreach (STATUS_PEKERJAAN as $k => $v) {
        echo '<option value="' . e($k) . '"' . ($selected === $k ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    if ($withLate) {
        echo '<option value="terlambat"' . ($selected === 'terlambat' ? ' selected' : '') . '>Terlambat (lewat deadline)</option>';
    }
}

function project_options(int $selected = 0, bool $withAll = true): void
{
    if ($withAll) {
        echo '<option value="">Semua project</option>';
    }
    foreach (selectable_projects() as $p) {
        echo '<option value="' . (int) $p['id'] . '"' . ($selected === (int) $p['id'] ? ' selected' : '') . '>'
            . e($p['kode'] . ' · ' . $p['nama']) . '</option>';
    }
}

function user_options(string $role, int $selected = 0, string $allLabel = 'Semua'): void
{
    echo '<option value="">' . e($allLabel) . '</option>';
    foreach (all_users($role) as $u) {
        echo '<option value="' . (int) $u['id'] . '"' . ($selected === (int) $u['id'] ? ' selected' : '') . '>'
            . e($u['nama']) . '</option>';
    }
}
