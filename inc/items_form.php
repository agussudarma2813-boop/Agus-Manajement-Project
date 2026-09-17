<?php
declare(strict_types=1);

/**
 * Editor massal item pekerjaan: pilih banyak jenis pekerjaan sekaligus dan
 * isi volumenya (mis. penarikan kabel 250 m, pemasangan unit 12 titik,
 * terminasi 24 titik, pasang panel 3 unit).
 *
 * Dipakai di:
 *   - project_form.php    (saat membuat project baru)
 *   - pekerjaan_batch.php (menambah item ke project yang sudah ada)
 *
 * Setiap baris dikirim sebagai array paralel:
 *   item_nama[], item_master[], item_kategori[], item_satuan[],
 *   item_jasa[], item_upah[], item_volume[], item_lokasi[],
 *   item_pelaksana[], item_deadline[]
 */

/**
 * Menyimpan baris-baris item dari POST ke sebuah project.
 *
 * @return array ['dibuat' => int, 'nama' => string[]]
 */
function simpan_item_batch(int $projectId, array $post, int $olehUser): array
{
    $project = get_project($projectId);
    if (!$project) {
        return ['dibuat' => 0, 'nama' => []];
    }

    $arr = static function (array $post, string $key): array {
        $v = $post[$key] ?? [];
        return is_array($v) ? array_values($v) : [];
    };

    $nama = $arr($post, 'item_nama');
    $master = $arr($post, 'item_master');
    $kategori = $arr($post, 'item_kategori');
    $satuan = $arr($post, 'item_satuan');
    $jasa = $arr($post, 'item_jasa');
    $upah = $arr($post, 'item_upah');
    $volume = $arr($post, 'item_volume');
    // opsi tambahan berlaku untuk semua baris (dari blok "Opsi tambahan")
    $lokasiBatch = (int) ($post['batch_lokasi'] ?? 0);
    $pelaksanaBatch = (int) ($post['batch_pelaksana'] ?? 0);
    $deadlineBatch = valid_tanggal(trim((string) ($post['batch_deadline'] ?? '')));
    $lokasi = $arr($post, 'item_lokasi') ?: [];
    $pelaksana = $arr($post, 'item_pelaksana') ?: [];
    $deadline = $arr($post, 'item_deadline') ?: [];

    // lokasi yang benar-benar milik project ini
    $lokasiValid = array_map(fn($l) => (int) $l['id'], lokasi_of_project($projectId));
    $pelaksanaValid = array_map(fn($p) => (int) $p['id'], selectable_pelaksana());

    $ins = db()->prepare(
        'INSERT INTO pekerjaan (project_id, lokasi_id, nama, kategori, volume, satuan, status, progress,
                mulai, deadline, prioritas, pelaksana_id, keterangan,
                harga_satuan_id, harga_jasa, harga_upah)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $log = db()->prepare('INSERT INTO progress_log (pekerjaan_id, user_id, tanggal, progress, catatan) VALUES (?,?,?,?,?)');

    $dibuat = [];
    $jumlah = max(count($nama), count($volume));
    for ($i = 0; $i < $jumlah; $i++) {
        $n = trim((string) ($nama[$i] ?? ''));
        $vol = (float) str_replace(',', '.', trim((string) ($volume[$i] ?? '0')));

        // Baris yang hanya memilih item master (nama dibiarkan kosong) tetap valid:
        // nama, kategori, satuan & harga diambil dari master.
        $mid = (int) ($master[$i] ?? 0);
        $hs = $mid ? get_harga_satuan($mid) : null;
        if (!$hs) {
            $mid = 0;
        }
        if ($n === '' && $hs) {
            $n = (string) $hs['nama'];
        }

        if ($n === '' && $vol <= 0) {
            continue; // baris benar-benar kosong
        }
        if ($n === '' || $vol <= 0) {
            continue; // butuh nama + volume
        }

        $kat = trim((string) ($kategori[$i] ?? ''));
        $sat = trim((string) ($satuan[$i] ?? ''));
        $hj = parse_money((string) ($jasa[$i] ?? ''));
        $hu = parse_money((string) ($upah[$i] ?? ''));
        if ($hs) {
            if ($kat === '') {
                $kat = (string) $hs['kategori'];
            }
            if ($sat === '') {
                $sat = (string) $hs['satuan'];
            }
            if ($hj <= 0) {
                $hj = (float) $hs['harga_jasa'];
            }
            if ($hu <= 0) {
                $hu = (float) $hs['harga_upah'];
            }
        }

        $lok = (int) ($lokasi[$i] ?? 0) ?: $lokasiBatch;
        if ($lok && !in_array($lok, $lokasiValid, true)) {
            $lok = 0;
        }
        $pel = (int) ($pelaksana[$i] ?? 0) ?: $pelaksanaBatch;
        if ($pel && !in_array($pel, $pelaksanaValid, true)) {
            $pel = 0;
        }
        if (!$pel) {
            $pel = (int) ($project['pelaksana_id'] ?? 0);
        }
        $dl = valid_tanggal(trim((string) ($deadline[$i] ?? ''))) ?: $deadlineBatch;

        $ins->execute([
            $projectId,
            $lok ?: null,
            $n,
            $kat,
            $vol,
            $sat,
            'belum',
            0,
            date('Y-m-d'),
            $dl,
            'normal',
            $pel ?: null,
            '',
            $mid ?: null,
            $hj,
            $hu,
        ]);
        $wid = (int) db()->lastInsertId();
        $log->execute([$wid, $olehUser, date('Y-m-d'), 0, 'Item dibuat sekaligus dari daftar pekerjaan.']);
        $dibuat[] = $n;
    }

    return ['dibuat' => count($dibuat), 'nama' => $dibuat];
}

/**
 * Merender editor baris item pekerjaan.
 *
 * @param array $opt [
 *   'lokasi' => array daftar lokasi project,
 *   'pelaksana' => array daftar pelaksana,
 *   'baris_kosong' => int jumlah baris kosong awal,
 *   'nilai' => array data lama untuk mengisi ulang form saat validasi gagal
 * ]
 */
function render_items_editor(array $opt = []): void
{
    $lokasi = $opt['lokasi'] ?? [];
    $pelaksana = $opt['pelaksana'] ?? [];
    $jumlahBaris = (int) ($opt['baris_kosong'] ?? 5);
    $nilai = $opt['nilai'] ?? [];
    $ambil = static function (string $key, int $i, string $default = '') use ($nilai): string {
        $v = $nilai[$key] ?? [];
        return isset($v[$i]) ? (string) $v[$i] : $default;
    };
    $masterItems = harga_satuan_pilihan();
    ?>
    <div class="items-editor" data-items-editor>
      <div class="items-head">
        <div class="items-head-cell">Jenis pekerjaan</div>
        <div class="items-head-cell">Volume</div>
        <div class="items-head-cell">Satuan</div>
        <div class="items-head-cell">Harga jasa / satuan</div>
        <div class="items-head-cell">Upah pekerja / satuan</div>
        <div class="items-head-cell">Nilai jasa</div>
        <div class="items-head-cell"></div>
      </div>

      <div data-items-rows>
        <?php for ($i = 0; $i < $jumlahBaris; $i++): ?>
          <?php $namaAwal = $ambil('item_nama', $i); ?>
          <div class="items-row" data-item-row>
            <div class="items-cell items-cell-nama">
              <select name="item_master[]" data-item-master aria-label="Ambil dari master harga">
                <option value="">— pilih dari master —</option>
                <?php foreach ($masterItems as $hs): ?>
                  <option value="<?= (int) $hs['id'] ?>"
                          data-nama="<?= e($hs['nama']) ?>"
                          data-kategori="<?= e($hs['kategori']) ?>"
                          data-satuan="<?= e($hs['satuan']) ?>"
                          data-jasa="<?= e(num($hs['harga_jasa'])) ?>"
                          data-upah="<?= e(num($hs['harga_upah'])) ?>"
                          <?= $ambil('item_master', $i) === (string) $hs['id'] ? ' selected' : '' ?>>
                    <?= e($hs['nama']) ?><?= $hs['satuan'] !== '' ? ' (' . e($hs['satuan']) . ')' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="item_nama[]" value="<?= e($namaAwal) ?>" placeholder="atau tulis nama pekerjaan" data-item-nama>
              <input type="hidden" name="item_kategori[]" value="<?= e($ambil('item_kategori', $i)) ?>" data-item-kategori>
            </div>
            <div class="items-cell" data-label="Volume">
              <input type="text" name="item_volume[]" value="<?= e($ambil('item_volume', $i)) ?>" placeholder="0" data-item-volume>
            </div>
            <div class="items-cell" data-label="Satuan">
              <input type="text" name="item_satuan[]" value="<?= e($ambil('item_satuan', $i)) ?>" placeholder="m / unit" data-item-satuan>
            </div>
            <div class="items-cell" data-label="Harga jasa">
              <input type="text" name="item_jasa[]" value="<?= e($ambil('item_jasa', $i)) ?>" placeholder="0" data-item-jasa>
            </div>
            <div class="items-cell" data-label="Upah pekerja">
              <input type="text" name="item_upah[]" value="<?= e($ambil('item_upah', $i)) ?>" placeholder="0" data-item-upah>
            </div>
            <div class="items-cell items-cell-nilai" data-label="Nilai jasa">
              <span class="tag" data-item-nilai>—</span>
            </div>
            <div class="items-cell items-cell-aksi">
              <button class="btn btn-sm btn-danger" type="button" data-item-hapus aria-label="Hapus baris">×</button>
            </div>
          </div>
        <?php endfor; ?>
      </div>

      <div class="items-foot">
        <button class="btn btn-sm" type="button" data-item-tambah>+ Tambah Baris</button>
        <span class="muted small" data-items-info>Isi minimal satu baris (nama pekerjaan + volume).</span>
        <span class="spacer" style="margin-left:auto"></span>
        <span class="small">Total nilai jasa: <strong data-items-total>Rp 0</strong></span>
      </div>

      <?php if ($lokasi || $pelaksana): ?>
        <details class="items-opsi">
          <summary>Opsi tambahan (lokasi, pelaksana, deadline per item)</summary>
          <p class="small muted" style="margin:10px 0 12px">
            Berlaku untuk beberapa baris sekaligus. Kosongkan bila tidak perlu.
          </p>
          <div class="form-grid">
            <div class="field">
              <label for="batch_lokasi">Lokasi kerja (semua baris)</label>
              <select id="batch_lokasi" name="batch_lokasi">
                <option value="">— tidak disebut —</option>
                <?php foreach ($lokasi as $l): ?>
                  <option value="<?= (int) $l['id'] ?>"><?= e($l['nama']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="batch_pelaksana">Pelaksana penanggung jawab</label>
              <select id="batch_pelaksana" name="batch_pelaksana">
                <option value="">— mengikuti pelaksana project —</option>
                <?php foreach ($pelaksana as $p): ?>
                  <option value="<?= (int) $p['id'] ?>"><?= e($p['nama']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="batch_deadline">Deadline (semua baris)</label>
              <input type="date" id="batch_deadline" name="batch_deadline">
            </div>
          </div>
        </details>
      <?php endif; ?>
    </div>
    <?php
}
