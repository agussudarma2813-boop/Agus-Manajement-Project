<?php
/**
 * Koneksi database + migrasi/seed.
 * Database disimpan di dalam folder aplikasi ini (data/app.sqlite).
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('DB_PATH', defined('APP_DB_PATH') ? APP_DB_PATH : APP_ROOT . '/data/app.sqlite');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    app_migrate($pdo);

    return $pdo;
}

/**
 * Membuat tabel yang belum ada + seed awal.
 * Seluruh proses dibungkus SATU transaksi (BEGIN IMMEDIATE) supaya tidak
 * menulis/fsync ke disk berkali-kali pada setiap request.
 */
function app_migrate(PDO $pdo): void
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            nama          TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'pekerja',
            jabatan       TEXT NOT NULL DEFAULT '',
            telepon       TEXT NOT NULL DEFAULT '',
            aktif         INTEGER NOT NULL DEFAULT 1,
            created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS projects (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            kode          TEXT NOT NULL UNIQUE,
            nama          TEXT NOT NULL,
            pelaksana_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            mulai         TEXT NOT NULL DEFAULT '',
            target_selesai TEXT NOT NULL DEFAULT '',
            status        TEXT NOT NULL DEFAULT 'berjalan',
            anggaran      REAL NOT NULL DEFAULT 0,
            keterangan    TEXT NOT NULL DEFAULT '',
            created_by    INTEGER,
            created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS lokasi (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            nama        TEXT NOT NULL,
            alamat      TEXT NOT NULL DEFAULT '',
            keterangan  TEXT NOT NULL DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS pekerjaan (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            lokasi_id    INTEGER REFERENCES lokasi(id) ON DELETE SET NULL,
            nama         TEXT NOT NULL,
            kategori     TEXT NOT NULL DEFAULT '',
            volume       REAL NOT NULL DEFAULT 0,
            satuan       TEXT NOT NULL DEFAULT '',
            status       TEXT NOT NULL DEFAULT 'belum',
            progress     INTEGER NOT NULL DEFAULT 0,
            mulai        TEXT NOT NULL DEFAULT '',
            deadline     TEXT NOT NULL DEFAULT '',
            prioritas    TEXT NOT NULL DEFAULT 'normal',
            pelaksana_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            keterangan   TEXT NOT NULL DEFAULT '',
            created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS pekerjaan_pekerja (
            pekerjaan_id INTEGER NOT NULL REFERENCES pekerjaan(id) ON DELETE CASCADE,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            PRIMARY KEY (pekerjaan_id, user_id)
        );

        CREATE TABLE IF NOT EXISTS progress_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            pekerjaan_id INTEGER NOT NULL REFERENCES pekerjaan(id) ON DELETE CASCADE,
            user_id      INTEGER,
            tanggal      TEXT NOT NULL,
            progress     INTEGER NOT NULL,
            catatan      TEXT NOT NULL DEFAULT '',
            created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        /* Absensi hari kerja: 1 baris = 1 pekerja pada 1 tanggal (di 1 project).
           `upah` menyimpan tarif harian yang berlaku SAAT absensi dicatat
           (snapshot) supaya perubahan tarif tidak mengubah riwayat gaji. */
        CREATE TABLE IF NOT EXISTS absensi (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            project_id   INTEGER REFERENCES projects(id) ON DELETE SET NULL,
            pekerjaan_id INTEGER REFERENCES pekerjaan(id) ON DELETE SET NULL,
            tanggal      TEXT NOT NULL,
            hari         REAL NOT NULL DEFAULT 1,
            upah         REAL NOT NULL DEFAULT 0,
            keterangan   TEXT NOT NULL DEFAULT '',
            created_by   INTEGER,
            created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE (user_id, tanggal)
        );

        CREATE INDEX IF NOT EXISTS idx_pekerjaan_project ON pekerjaan(project_id);
        CREATE INDEX IF NOT EXISTS idx_pekerjaan_status  ON pekerjaan(status);
        CREATE INDEX IF NOT EXISTS idx_lokasi_project    ON lokasi(project_id);
        CREATE INDEX IF NOT EXISTS idx_log_pekerjaan     ON progress_log(pekerjaan_id);
        CREATE INDEX IF NOT EXISTS idx_absensi_user      ON absensi(user_id);
        CREATE INDEX IF NOT EXISTS idx_absensi_tanggal   ON absensi(tanggal);

        /* Master harga satuan: sekali input, dipakai di semua project.
           harga_jasa = harga yang ditagihkan ke perusahaan pemilik pekerjaan
           harga_upah = upah dasar yang dibayarkan ke pekerja (per satuan) */
        CREATE TABLE IF NOT EXISTS harga_satuan (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            nama        TEXT NOT NULL UNIQUE,
            kategori    TEXT NOT NULL DEFAULT '',
            satuan      TEXT NOT NULL DEFAULT '',
            harga_jasa  REAL NOT NULL DEFAULT 0,
            harga_upah  REAL NOT NULL DEFAULT 0,
            keterangan  TEXT NOT NULL DEFAULT '',
            aktif       INTEGER NOT NULL DEFAULT 1,
            created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE INDEX IF NOT EXISTS idx_harga_satuan_nama ON harga_satuan(nama);

        /* Tarif borongan khusus per pekerja untuk sebuah item master.
           Opsi: dipakai HANYA bila upah pekerja itu memang berbeda-beda.
           Kosong = semua pekerja pakai upah dasar item. */
        CREATE TABLE IF NOT EXISTS harga_satuan_pekerja (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            harga_satuan_id INTEGER NOT NULL REFERENCES harga_satuan(id) ON DELETE CASCADE,
            user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            harga_upah      REAL NOT NULL DEFAULT 0,
            perusahaan_id   INTEGER NOT NULL DEFAULT 0,
            UNIQUE (harga_satuan_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_hsp_harga ON harga_satuan_pekerja(harga_satuan_id);

        /* Pengajuan tagihan ke perusahaan pemilik pekerjaan.
           Sederhana: satu pengajuan = satu project, berisi beberapa sub pekerjaan
           dengan volume yang diajukan (boleh bertahap / sebagian). */
        CREATE TABLE IF NOT EXISTS pengajuan (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            nomor            TEXT NOT NULL DEFAULT '',
            project_id       INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            tanggal          TEXT NOT NULL,
            status           TEXT NOT NULL DEFAULT 'diajukan',
            tanggal_bayar    TEXT NOT NULL DEFAULT '',
            catatan          TEXT NOT NULL DEFAULT '',
            created_by       INTEGER,
            created_at       TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            perusahaan_id    INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_pengajuan_project ON pengajuan(project_id);
        CREATE INDEX IF NOT EXISTS idx_pengajuan_status  ON pengajuan(status);

        /* Kasbon (pinjaman/kas bon pekerja): dipotong dari gaji berikutnya.
           penggajian_id terisi bila kasbon sudah dipotong pada sebuah penggajian. */
        CREATE TABLE IF NOT EXISTS kasbon (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            tanggal       TEXT NOT NULL,
            nominal       REAL NOT NULL DEFAULT 0,
            keterangan    TEXT NOT NULL DEFAULT '',
            penggajian_id INTEGER REFERENCES penggajian(id) ON DELETE SET NULL,
            created_by    INTEGER,
            created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            perusahaan_id INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_kasbon_user ON kasbon(user_id);
        CREATE INDEX IF NOT EXISTS idx_kasbon_tgl  ON kasbon(tanggal);

        /* Penggajian per pekerja per periode (periode mengikuti tanggal tutup buku).
           Nilai gaji & kasbon disimpan sebagai snapshot supaya riwayat tidak berubah. */
        CREATE TABLE IF NOT EXISTS penggajian (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            nomor           TEXT NOT NULL DEFAULT '',
            user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            periode_dari    TEXT NOT NULL DEFAULT '',
            periode_sampai  TEXT NOT NULL DEFAULT '',
            hari_kerja      REAL NOT NULL DEFAULT 0,
            upah_harian     REAL NOT NULL DEFAULT 0,
            upah_borongan   REAL NOT NULL DEFAULT 0,
            total_gaji      REAL NOT NULL DEFAULT 0,
            total_kasbon    REAL NOT NULL DEFAULT 0,
            total_dibayar   REAL NOT NULL DEFAULT 0,
            status          TEXT NOT NULL DEFAULT 'belum',
            tanggal_bayar   TEXT NOT NULL DEFAULT '',
            catatan         TEXT NOT NULL DEFAULT '',
            created_by      INTEGER,
            created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            perusahaan_id   INTEGER NOT NULL DEFAULT 0,
            UNIQUE (user_id, periode_dari, periode_sampai)
        );
        CREATE INDEX IF NOT EXISTS idx_gaji_user   ON penggajian(user_id);
        CREATE INDEX IF NOT EXISTS idx_gaji_status ON penggajian(status);
        CREATE INDEX IF NOT EXISTS idx_gaji_periode ON penggajian(periode_sampai);

        CREATE TABLE IF NOT EXISTS penggajian_item (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            penggajian_id INTEGER NOT NULL REFERENCES penggajian(id) ON DELETE CASCADE,
            jenis         TEXT NOT NULL DEFAULT 'borongan',
            keterangan    TEXT NOT NULL DEFAULT '',
            volume        REAL NOT NULL DEFAULT 0,
            satuan        TEXT NOT NULL DEFAULT '',
            nilai         REAL NOT NULL DEFAULT 0,
            perusahaan_id INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_gitem_penggajian ON penggajian_item(penggajian_id);

        CREATE TABLE IF NOT EXISTS pengajuan_item (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            pengajuan_id    INTEGER NOT NULL REFERENCES pengajuan(id) ON DELETE CASCADE,
            pekerjaan_id    INTEGER NOT NULL REFERENCES pekerjaan(id) ON DELETE CASCADE,
            volume          REAL NOT NULL DEFAULT 0,
            harga_jasa      REAL NOT NULL DEFAULT 0,
            satuan          TEXT NOT NULL DEFAULT '',
            nama_item       TEXT NOT NULL DEFAULT '',
            perusahaan_id   INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_pengitem_pengajuan ON pengajuan_item(pengajuan_id);
        CREATE INDEX IF NOT EXISTS idx_pengitem_pekerjaan ON pengajuan_item(pekerjaan_id);
        CREATE INDEX IF NOT EXISTS idx_hsp_user  ON harga_satuan_pekerja(user_id);

        /* Perusahaan (pelanggan) — pemisah data antar pelanggan aplikasi.
           Masa aktif, batas jumlah user, status aktif/nonaktif, dan branding. */
        CREATE TABLE IF NOT EXISTS perusahaan (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            nama              TEXT NOT NULL,
            slug              TEXT NOT NULL DEFAULT '',
            logo              TEXT NOT NULL DEFAULT '',
            kontak_nama       TEXT NOT NULL DEFAULT '',
            kontak_telepon    TEXT NOT NULL DEFAULT '',
            masa_aktif_sampai TEXT NOT NULL DEFAULT '',
            maks_user         INTEGER NOT NULL DEFAULT 0,
            aktif             INTEGER NOT NULL DEFAULT 1,
            catatan           TEXT NOT NULL DEFAULT '',
            created_at        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_perusahaan_slug ON perusahaan(slug) WHERE slug <> '';

        /* Pengaturan tingkat aplikasi (bukan per perusahaan), mis. domain dasar
           untuk sub-domain pelanggan: pt-maju.<domain-dasar>. */
        CREATE TABLE IF NOT EXISTS pengaturan_app (
            kunci TEXT PRIMARY KEY,
            nilai TEXT NOT NULL DEFAULT ''
        );

        /* Konteks request: perusahaan mana yang sedang aktif. Dipakai TRIGGER
           untuk menandai perusahaan_id baris baru secara otomatis, sehingga
           tidak mungkin ada data yang "lupa" ditandai perusahaan. */
        CREATE TABLE IF NOT EXISTS _konteks (
            id            INTEGER PRIMARY KEY CHECK (id = 1),
            perusahaan_id INTEGER NOT NULL DEFAULT 0
        );
        INSERT OR IGNORE INTO _konteks (id, perusahaan_id) VALUES (1, 0);

        /* Jadwal harian: admin/pelaksana menentukan pekerja mana yang bekerja
           di project (dan item) apa pada tanggal tertentu. */
        CREATE TABLE IF NOT EXISTS jadwal (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            tanggal      TEXT NOT NULL,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            pekerjaan_id INTEGER REFERENCES pekerjaan(id) ON DELETE SET NULL,
            lokasi_id    INTEGER REFERENCES lokasi(id) ON DELETE SET NULL,
            catatan      TEXT NOT NULL DEFAULT '',
            created_by   INTEGER,
            created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE (tanggal, user_id, project_id)
        );
        CREATE INDEX IF NOT EXISTS idx_jadwal_tanggal ON jadwal(tanggal);
        CREATE INDEX IF NOT EXISTS idx_jadwal_user    ON jadwal(user_id);

        /* Hasil kerja harian yang dilaporkan pekerja (atau diinput atas namanya):
           - pekerja BORONGAN: nilai = volume × upah_satuan  -> langsung jadi upah
           - pekerja HARIAN  : nilai = 0, tapi volumenya tetap tercatat
                               sebagai dasar tagihan ke perusahaan
           Volume laporan otomatis menjadi `pekerjaan.volume_realisasi`. */
        CREATE TABLE IF NOT EXISTS laporan_kerja (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            tanggal      TEXT NOT NULL,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            pekerjaan_id INTEGER NOT NULL REFERENCES pekerjaan(id) ON DELETE CASCADE,
            jadwal_id    INTEGER REFERENCES jadwal(id) ON DELETE SET NULL,
            volume       REAL NOT NULL DEFAULT 0,
            upah_satuan  REAL NOT NULL DEFAULT 0,
            keterangan   TEXT NOT NULL DEFAULT '',
            created_by   INTEGER,
            created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE INDEX IF NOT EXISTS idx_laporkerja_tanggal   ON laporan_kerja(tanggal);
        CREATE INDEX IF NOT EXISTS idx_laporkerja_user      ON laporan_kerja(user_id);
        CREATE INDEX IF NOT EXISTS idx_laporkerja_pekerjaan ON laporan_kerja(pekerjaan_id);
        SQL);

        // Kolom baru untuk versi lama (database yang sudah jalan)
        ensure_column($pdo, 'users', 'upah_harian', 'REAL NOT NULL DEFAULT 0');
        ensure_column($pdo, 'pekerjaan_pekerja', 'upah_borongan', 'REAL NOT NULL DEFAULT 0');

        // Harga jasa/upah per pekerjaan + volume akhir yang diselesaikan
        ensure_column($pdo, 'pekerjaan', 'harga_satuan_id', 'INTEGER REFERENCES harga_satuan(id) ON DELETE SET NULL');
        ensure_column($pdo, 'pekerjaan', 'harga_jasa', 'REAL NOT NULL DEFAULT 0');
        ensure_column($pdo, 'pekerjaan', 'harga_upah', 'REAL NOT NULL DEFAULT 0');
        ensure_column($pdo, 'pekerjaan', 'volume_realisasi', 'REAL NOT NULL DEFAULT 0');
        ensure_column($pdo, 'pekerjaan', 'realisasi_tanggal', "TEXT NOT NULL DEFAULT ''");
        ensure_column($pdo, 'pekerjaan', 'realisasi_catatan', "TEXT NOT NULL DEFAULT ''");
        // 1 = volume_realisasi dihitung otomatis dari laporan harian (bukan isian manual)
        ensure_column($pdo, 'pekerjaan', 'volume_auto', 'INTEGER NOT NULL DEFAULT 0');

        // Status penagihan ke perusahaan pemilik pekerjaan: belum → diajukan → dibayar
        ensure_column($pdo, 'pekerjaan', 'tagih_status', "TEXT NOT NULL DEFAULT 'belum'");
        ensure_column($pdo, 'pekerjaan', 'tagih_diajukan_tanggal', "TEXT NOT NULL DEFAULT ''");
        ensure_column($pdo, 'pekerjaan', 'tagih_dibayar_tanggal', "TEXT NOT NULL DEFAULT ''");
        ensure_column($pdo, 'pekerjaan', 'tagih_catatan', "TEXT NOT NULL DEFAULT ''");
        // Total volume yang sudah diajukan ke perusahaan (boleh bertahap)
        ensure_column($pdo, 'pekerjaan', 'tagih_volume', 'REAL NOT NULL DEFAULT 0');

        // Pembagian upah borongan per pekerja
        ensure_column($pdo, 'pekerjaan_pekerja', 'bagian_pct', 'REAL NOT NULL DEFAULT 0');
        ensure_column($pdo, 'pekerjaan_pekerja', 'harga_upah_override', 'REAL NOT NULL DEFAULT 0');

        // Skema upah per orang: 'harian' (gaji harian) atau 'borongan' (per volume)
        ensure_column($pdo, 'users', 'skema', "TEXT NOT NULL DEFAULT 'harian'");

        // Multi-perusahaan: setiap baris data milik satu perusahaan (pelanggan)
        foreach (['users', 'projects', 'lokasi', 'pekerjaan', 'pekerjaan_pekerja',
                  'progress_log', 'absensi', 'harga_satuan', 'jadwal', 'laporan_kerja',
                  'harga_satuan_pekerja', 'pengajuan', 'pengajuan_item',
                  'kasbon', 'penggajian', 'penggajian_item'] as $tabel) {
            ensure_column($pdo, $tabel, 'perusahaan_id', 'INTEGER NOT NULL DEFAULT 0');
        }
        ensure_column($pdo, 'users', 'is_owner', 'INTEGER NOT NULL DEFAULT 0');
        // Tanggal tutup buku (1-28). 0 = tanpa tutup buku (periode = tanggal 1..akhir bulan).
        ensure_column($pdo, 'perusahaan', 'tutup_buku_tgl', 'INTEGER NOT NULL DEFAULT 25');
        ensure_index($pdo, 'idx_users_perusahaan', 'users', 'perusahaan_id');
        ensure_index($pdo, 'idx_projects_perusahaan', 'projects', 'perusahaan_id');
        ensure_index($pdo, 'idx_pekerjaan_perusahaan', 'pekerjaan', 'perusahaan_id');
        ensure_index($pdo, 'idx_absensi_perusahaan', 'absensi', 'perusahaan_id');
        ensure_index($pdo, 'idx_jadwal_perusahaan', 'jadwal', 'perusahaan_id');
        ensure_index($pdo, 'idx_laporan_perusahaan', 'laporan_kerja', 'perusahaan_id');
        ensure_index($pdo, 'idx_harga_perusahaan', 'harga_satuan', 'perusahaan_id');

        seed_users($pdo);
        seed_demo($pdo);
        seed_upah($pdo);
        seed_harga_satuan($pdo);
        seed_skema_demo($pdo);
        seed_jadwal_kerja($pdo);
        migrasi_tagihan_lama($pdo);
        pastikan_trigger_tenant($pdo);
        migrasi_perusahaan($pdo);

        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Menambah kolom bila belum ada (untuk database yang sudah berjalan).
 */
function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $col) {
        if ($col['name'] === $column) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

/**
 * Membuat index bila belum ada (untuk database yang sudah berjalan).
 */
function ensure_index(PDO $pdo, string $nama, string $tabel, string $kolom): void
{
    $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $nama . ' ON ' . $tabel . '(' . $kolom . ')');
}

/**
 * Trigger: setiap baris baru otomatis ditandai perusahaan yang sedang aktif
 * (dari tabel `_konteks`). Data lama/sistem yang perusahaan_id-nya sudah
 * diisi tidak diubah.
 */
function pastikan_trigger_tenant(PDO $pdo): void
{
    $pakaiId = ['users', 'projects', 'lokasi', 'pekerjaan', 'progress_log',
                'absensi', 'harga_satuan', 'jadwal', 'laporan_kerja', 'harga_satuan_pekerja',
                'pengajuan', 'pengajuan_item', 'kasbon', 'penggajian', 'penggajian_item'];
    foreach ($pakaiId as $tabel) {
        $pdo->exec(
            'CREATE TRIGGER IF NOT EXISTS trg_' . $tabel . '_tenant AFTER INSERT ON ' . $tabel . '
             WHEN NEW.perusahaan_id = 0
             BEGIN
                UPDATE ' . $tabel . ' SET perusahaan_id = (SELECT perusahaan_id FROM _konteks WHERE id = 1)
                WHERE id = NEW.id;
             END'
        );
    }
    // pekerjaan_pekerja memakai primary key gabungan -> pakai rowid
    $pdo->exec(
        'CREATE TRIGGER IF NOT EXISTS trg_pekerjaan_pekerja_tenant AFTER INSERT ON pekerjaan_pekerja
         WHEN NEW.perusahaan_id = 0
         BEGIN
            UPDATE pekerjaan_pekerja SET perusahaan_id = (SELECT perusahaan_id FROM _konteks WHERE id = 1)
            WHERE rowid = NEW.rowid;
         END'
    );
}

/**
 * Memindahkan status penagihan model LAMA (per item pekerjaan) menjadi
 * data pengajuan yang sederhana, supaya riwayat tidak hilang.
 * Hanya dijalankan sekali (kalau tabel pengajuan masih kosong).
 */
function migrasi_tagihan_lama(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM pengajuan')->fetchColumn() > 0) {
        return;
    }
    $rows = $pdo->query(
        "SELECT id, project_id, nama, satuan, harga_jasa, volume, volume_realisasi, perusahaan_id,
                tagih_status, tagih_diajukan_tanggal, tagih_dibayar_tanggal
         FROM pekerjaan WHERE tagih_status IN ('diajukan','dibayar')"
    )->fetchAll();
    if (!$rows) {
        return;
    }

    $perProject = [];
    foreach ($rows as $r) {
        $perProject[(int) $r['project_id']][] = $r;
    }
    $insP = $pdo->prepare('INSERT INTO pengajuan (nomor, project_id, tanggal, status, tanggal_bayar, catatan, perusahaan_id)
                           VALUES (?,?,?,?,?,?,?)');
    $insI = $pdo->prepare('INSERT INTO pengajuan_item (pengajuan_id, pekerjaan_id, volume, harga_jasa, satuan, nama_item, perusahaan_id)
                           VALUES (?,?,?,?,?,?,?)');

    foreach ($perProject as $projectId => $items) {
        $status = 'diajukan';
        $tanggal = date('Y-m-d');
        $bayar = '';
        foreach ($items as $it) {
            if ($it['tagih_status'] === 'dibayar') {
                $status = 'dibayar';
            }
            if (($it['tagih_diajukan_tanggal'] ?? '') !== '') {
                $tanggal = (string) $it['tagih_diajukan_tanggal'];
            }
            if (($it['tagih_dibayar_tanggal'] ?? '') !== '') {
                $bayar = (string) $it['tagih_dibayar_tanggal'];
            }
        }
        $insP->execute(['', $projectId, $tanggal, $status, $bayar,
            'Dipindahkan otomatis dari status penagihan versi lama.', (int) ($items[0]['perusahaan_id'] ?? 0)]);
        $pengajuanId = (int) $pdo->lastInsertId();

        foreach ($items as $it) {
            $vol = (float) $it['volume_realisasi'] > 0 ? (float) $it['volume_realisasi'] : (float) $it['volume'];
            if ($vol <= 0) {
                continue;
            }
            $insI->execute([$pengajuanId, (int) $it['id'], $vol, (float) $it['harga_jasa'],
                (string) $it['satuan'], (string) $it['nama'], (int) ($it['perusahaan_id'] ?? 0)]);
            $pdo->prepare('UPDATE pekerjaan SET tagih_volume = ? WHERE id = ?')->execute([$vol, (int) $it['id']]);
        }
    }
}

/**
 * Menyiapkan pemisahan data antar perusahaan.
 *
 * - Database lama (belum ada tabel perusahaan): dibuatkan satu perusahaan
 *   "Usaha Saya" dan SELURUH data yang sudah ada dipindahkan ke sana, supaya
 *   pemilik aplikasi tetap melihat datanya seperti sebelumnya.
 * - Admin pertama ditandai is_owner = 1 (pengelola daftar pelanggan).
 * - Aman dijalankan berkali-kali (idempotent).
 */
function migrasi_perusahaan(PDO $pdo): void
{
    $jumlah = (int) $pdo->query('SELECT COUNT(*) FROM perusahaan')->fetchColumn();
    if ($jumlah === 0) {
        $pdo->prepare('INSERT INTO perusahaan (nama, slug, catatan) VALUES (?,?,?)')
            ->execute(['Usaha Saya', 'usaha-saya', 'Perusahaan bawaan untuk data yang sudah ada. Nama & logo bisa diubah.']);
    }

    $perusahaanId = (int) $pdo->query('SELECT MIN(id) FROM perusahaan')->fetchColumn();
    if ($perusahaanId <= 0) {
        return;
    }

    // Data lama (perusahaan_id = 0) dipindahkan ke perusahaan pertama.
    foreach (['users', 'projects', 'lokasi', 'pekerjaan', 'pekerjaan_pekerja',
              'progress_log', 'absensi', 'harga_satuan', 'jadwal', 'laporan_kerja',
              'harga_satuan_pekerja', 'pengajuan', 'pengajuan_item',
              'kasbon', 'penggajian', 'penggajian_item'] as $tabel) {
        $pdo->prepare('UPDATE ' . $tabel . ' SET perusahaan_id = ? WHERE perusahaan_id = 0 OR perusahaan_id IS NULL')
            ->execute([$perusahaanId]);
    }

    // Minimal satu pengelola pelanggan: admin pertama.
    $adaOwner = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_owner = 1')->fetchColumn();
    if ($adaOwner === 0) {
        $pdo->exec("UPDATE users SET is_owner = 1 WHERE id = (SELECT MIN(id) FROM users WHERE role = 'admin')");
    }
}

function seed_users(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    // skema: 'harian' = digaji per hari, 'borongan' = digaji dari volume yang dilaporkan
    $users = [
        ['admin',   'admin123',     'Administrator',  'admin',     'Manajer Proyek',      '0812-1000-0001', 0,      'harian'],
        ['budi',    'pelaksana123', 'Budi Santoso',   'pelaksana', 'Pelaksana Lapangan',  '0812-1000-0002', 250000, 'harian'],
        ['siti',    'pelaksana123', 'Siti Aminah',    'pelaksana', 'Pelaksana Lapangan',  '0812-1000-0003', 250000, 'harian'],
        ['andi',    'pekerja123',   'Andi Pratama',   'pekerja',   'Tukang Bangunan',     '0812-1000-0004', 175000, 'borongan'],
        ['joko',    'pekerja123',   'Joko Susilo',    'pekerja',   'Tukang Kayu',         '0812-1000-0005', 170000, 'borongan'],
        ['rudi',    'pekerja123',   'Rudi Hartono',   'pekerja',   'Tukang Besi',         '0812-1000-0006', 180000, 'borongan'],
        ['dewi',    'pekerja123',   'Dewi Lestari',   'pekerja',   'Pekerja Umum',        '0812-1000-0007', 140000, 'harian'],
        ['slamet',  'pekerja123',   'Slamet Riyadi',  'pekerja',   'Mandor',              '0812-1000-0008', 200000, 'harian'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, nama, role, jabatan, telepon, upah_harian, skema)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($users as $u) {
        $stmt->execute([$u[0], password_hash($u[1], PASSWORD_DEFAULT), $u[2], $u[3], $u[4], $u[5], $u[6], $u[7]]);
    }
}

function seed_demo(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    if ($count > 0) {
        return;
    }

    // --- Project 1 ---
    $pdo->prepare(
        'INSERT INTO projects (kode, nama, pelaksana_id, mulai, target_selesai, status, anggaran, keterangan, created_by)
         VALUES (?,?,?,?,?,?,?,?,1)'
    )->execute([
        'PRJ-2026-001', 'Renovasi Gedung Kantor A', 2,
        date('Y-m-d', strtotime('-45 days')), date('Y-m-d', strtotime('+30 days')),
        'berjalan', 850000000,
        'Renovasi interior lantai 1 dan 2 termasuk instalasi listrik dan pengecatan.',
    ]);
    $p1 = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO lokasi (project_id, nama, alamat, keterangan) VALUES (?,?,?,?)')
        ->execute([$p1, 'Gedung A — Lantai 1', 'Jl. Merdeka No. 12, Jakarta Pusat', 'Area resepsionis & ruang meeting']);
    $l1 = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO lokasi (project_id, nama, alamat, keterangan) VALUES (?,?,?,?)')
        ->execute([$p1, 'Gedung A — Lantai 2', 'Jl. Merdeka No. 12, Jakarta Pusat', 'Ruang kerja staf']);
    $l2 = (int) $pdo->lastInsertId();

    // --- Project 2 ---
    $pdo->prepare(
        'INSERT INTO projects (kode, nama, pelaksana_id, mulai, target_selesai, status, anggaran, keterangan, created_by)
         VALUES (?,?,?,?,?,?,?,?,1)'
    )->execute([
        'PRJ-2026-002', 'Pembangunan Gudang B', 3,
        date('Y-m-d', strtotime('+5 days')), date('Y-m-d', strtotime('+120 days')),
        'perencanaan', 1200000000,
        'Pembangunan gudang penyimpanan 20x30 m beserta area bongkar muat.',
    ]);
    $p2 = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO lokasi (project_id, nama, alamat, keterangan) VALUES (?,?,?,?)')
        ->execute([$p2, 'Lahan Gudang B', 'Kawasan Industri Blok C7, Bekasi', 'Lahan kosong siap bangun']);
    $l3 = (int) $pdo->lastInsertId();

    $works = [
        // [project, lokasi, nama, kategori, volume, satuan, status, progress, mulai, deadline, prioritas, pelaksana, pekerja[], keterangan]
        [$p1, $l1, 'Pekerjaan Persiapan & Pembersihan', 'Persiapan', 1, 'ls', 'selesai', 100,
            date('Y-m-d', strtotime('-45 days')), date('Y-m-d', strtotime('-28 days')), 'tinggi', 2, [4, 7],
            'Pembersihan area, pemasangan papan nama proyek, dan direksi keet.'],
        [$p1, $l1, 'Bongkar Partisi Ruang Meeting', 'Pembongkaran', 120, 'm2', 'proses', 65,
            date('Y-m-d', strtotime('-25 days')), date('Y-m-d', strtotime('+4 days')), 'tinggi', 2, [4, 5],
            'Bongkar partisi gypsum lama, buang puing ke TPS.'],
        [$p1, $l2, 'Instalasi Titik Listrik & Data', 'MEP', 48, 'titik', 'proses', 40,
            date('Y-m-d', strtotime('-18 days')), date('Y-m-d', strtotime('-2 days')), 'tinggi', 2, [6, 4],
            'Penarikan kabel, pemasangan stop kontak dan jalur data.'],
        [$p1, $l2, 'Pemasangan Plafon Gypsum', 'Arsitektur', 210, 'm2', 'proses', 20,
            date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('+12 days')), 'normal', 2, [5, 7],
            'Rangka hollow + gypsum 9mm, termasuk list profon.'],
        [$p1, $l1, 'Pengecatan Dinding & Plafon', 'Finishing', 480, 'm2', 'belum', 0,
            date('Y-m-d', strtotime('+10 days')), date('Y-m-d', strtotime('+26 days')), 'normal', 2, [7, 8],
            'Skim coat, plamir, cat dasar dan cat akhir 2 lapis.'],
        [$p1, $l1, 'Pekerjaan Sanitair Toilet', 'MEP', 6, 'unit', 'tertunda', 15,
            date('Y-m-d', strtotime('-14 days')), date('Y-m-d', strtotime('-1 days')), 'normal', 2, [6],
            'Menunggu kedatangan material closet dari supplier.'],
        [$p2, $l3, 'Pembersihan Lahan & Pengukuran', 'Persiapan', 600, 'm2', 'belum', 0,
            date('Y-m-d', strtotime('+5 days')), date('Y-m-d', strtotime('+15 days')), 'tinggi', 3, [8, 4], ''],
        [$p2, $l3, 'Pondasi Telapak & Sloof', 'Struktur', 32, 'titik', 'belum', 0,
            date('Y-m-d', strtotime('+16 days')), date('Y-m-d', strtotime('+55 days')), 'tinggi', 3, [4, 6], ''],
        [$p2, $l3, 'Struktur Baja Rangka Gudang', 'Struktur', 1, 'ls', 'belum', 0,
            date('Y-m-d', strtotime('+56 days')), date('Y-m-d', strtotime('+100 days')), 'normal', 3, [6, 8], ''],
    ];

    $insW = $pdo->prepare(
        'INSERT INTO pekerjaan (project_id, lokasi_id, nama, kategori, volume, satuan, status, progress,
                                mulai, deadline, prioritas, pelaksana_id, keterangan)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $insP = $pdo->prepare('INSERT INTO pekerjaan_pekerja (pekerjaan_id, user_id) VALUES (?,?)');
    $insL = $pdo->prepare(
        'INSERT INTO progress_log (pekerjaan_id, user_id, tanggal, progress, catatan) VALUES (?,?,?,?,?)'
    );

    foreach ($works as $w) {
        $insW->execute([$w[0], $w[1], $w[2], $w[3], $w[4], $w[5], $w[6], $w[7], $w[8], $w[9], $w[10], $w[11], $w[13]]);
        $wid = (int) $pdo->lastInsertId();
        foreach ($w[12] as $pid) {
            $insP->execute([$wid, $pid]);
        }
        if ($w[7] > 0) {
            $insL->execute([$wid, $w[11], $w[8], 0, 'Pekerjaan dimulai.']);
            $insL->execute([$wid, $w[11], date('Y-m-d', strtotime($w[8] . ' +7 days')), (int) round($w[7] / 2), 'Progress lapangan sesuai rencana.']);
            $insL->execute([$wid, $w[11], date('Y-m-d', strtotime('-2 days')), (int) $w[7], 'Update progress terakhir.']);
        }
    }
}

/**
 * Tarif harian default untuk akun demo + contoh absensi & upah borongan.
 *
 * Hanya menyentuh data demo yang belum diubah user:
 *  - tarif diisi bila akun demo masih bernilai 0
 *  - absensi contoh dibuat hanya bila tabel absensi masih kosong DAN
 *    isi database masih persis data demo (8 akun & 2 project)
 */
function seed_upah(PDO $pdo): void
{
    $tarif = [
        'budi' => 250000, 'siti' => 250000,
        'andi' => 175000, 'joko' => 170000, 'rudi' => 180000,
        'dewi' => 140000, 'slamet' => 200000,
    ];

    $upd = $pdo->prepare('UPDATE users SET upah_harian = ? WHERE username = ? AND upah_harian = 0');
    foreach ($tarif as $username => $nominal) {
        $upd->execute([$nominal, $username]);
    }

    $sudahAda = (int) $pdo->query('SELECT COUNT(*) FROM absensi')->fetchColumn();
    if ($sudahAda > 0) {
        return;
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 8
        || (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() !== 2) {
        return;
    }

    // Upah borongan contoh: pekerjaan 1 sudah selesai, pekerjaan 3 masih berjalan
    $borongan = [
        [1, 4, 1250000], [1, 7, 1000000],
        [3, 6, 1500000], [3, 4, 900000],
    ];
    $setB = $pdo->prepare('UPDATE pekerjaan_pekerja SET upah_borongan = ? WHERE pekerjaan_id = ? AND user_id = ?');
    foreach ($borongan as $b) {
        $setB->execute([$b[2], $b[0], $b[1]]);
    }

    // userId => [pekerjaanId, projectId]
    $tim = [
        4 => [1, 1], 5 => [4, 1], 6 => [3, 1], 7 => [5, 1], 8 => [1, 1],
    ];
    $stmt = $pdo->prepare('SELECT upah_harian FROM users WHERE id = ?');
    $ins = $pdo->prepare(
        'INSERT OR IGNORE INTO absensi (user_id, project_id, pekerjaan_id, tanggal, hari, upah, keterangan, created_by)
         VALUES (?,?,?,?,?,?,?,2)'
    );

    for ($i = 21; $i >= 1; $i--) {
        $ts = strtotime('-' . $i . ' day');
        $tanggal = date('Y-m-d', $ts);
        if (date('N', $ts) === '7') { // Minggu libur
            continue;
        }
        foreach ($tim as $userId => $info) {
            if (crc32($userId . $tanggal) % 10 < 2) { // sesekali tidak masuk
                continue;
            }
            $stmt->execute([$userId]);
            $tarifHari = (float) $stmt->fetchColumn();
            $hari = (crc32('h' . $userId . $tanggal) % 10 === 0) ? 0.5 : 1.0;
            $ins->execute([$userId, $info[1], $info[0], $tanggal, $hari, $tarifHari, '']);
        }
    }
}

/**
 * Contoh master harga satuan + harga jasa/upah untuk pekerjaan demo.
 *
 * Hanya diisi bila tabel harga_satuan masih kosong DAN isi database masih
 * persis data demo (8 akun & 2 project) — jadi database milik user tidak
 * pernah ditambahi data karangan.
 */
function seed_harga_satuan(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM harga_satuan')->fetchColumn() > 0) {
        return;
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 8
        || (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() !== 2) {
        return;
    }

    // nama | kategori | satuan | harga jasa ke perusahaan | upah dasar ke pekerja
    $items = [
        ['Penarikan Kabel Listrik & Data', 'MEP', 'm', 5000, 2500],
        ['Pemasangan CCTV',                'MEP', 'unit', 350000, 150000],
        ['Pekerjaan Persiapan & Pembersihan', 'Persiapan', 'ls', 3500000, 2000000],
        ['Bongkar Partisi Ruang Meeting',  'Pembongkaran', 'm2', 45000, 25000],
        ['Instalasi Titik Listrik & Data', 'MEP', 'titik', 350000, 150000],
        ['Pemasangan Plafon Gypsum',       'Arsitektur', 'm2', 180000, 95000],
        ['Pengecatan Dinding & Plafon',    'Finishing', 'm2', 55000, 30000],
        ['Pekerjaan Sanitair Toilet',      'MEP', 'unit', 1250000, 650000],
        ['Pembersihan Lahan & Pengukuran', 'Persiapan', 'm2', 15000, 7000],
        ['Pondasi Telapak & Sloof',        'Struktur', 'titik', 2500000, 1100000],
        ['Struktur Baja Rangka Gudang',    'Struktur', 'ls', 380000000, 95000000],
    ];

    $ins = $pdo->prepare(
        'INSERT INTO harga_satuan (nama, kategori, satuan, harga_jasa, harga_upah, keterangan)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($items as $it) {
        $ins->execute([$it[0], $it[1], $it[2], $it[3], $it[4], 'Contoh harga — silakan sesuaikan dengan tarif perusahaan.']);
    }

    // Sambungkan pekerjaan demo ke master + isi harga jasa/upahnya
    $map = [];
    foreach ($pdo->query('SELECT id, nama FROM harga_satuan') as $r) {
        $map[strtolower($r['nama'])] = (int) $r['id'];
    }
    $upd = $pdo->prepare(
        'UPDATE pekerjaan SET harga_satuan_id = ?, harga_jasa = ?, harga_upah = ?
         WHERE nama = ? AND harga_jasa = 0 AND harga_upah = 0'
    );
    $sel = $pdo->prepare('SELECT id, nama, satuan FROM pekerjaan WHERE harga_jasa = 0 AND harga_upah = 0');
    $sel->execute();
    $per = [];
    foreach ($pdo->query('SELECT nama, satuan, harga_jasa, harga_upah FROM harga_satuan') as $r) {
        $per[strtolower($r['nama'])] = $r;
    }
    foreach ($sel->fetchAll() as $pj) {
        $key = strtolower($pj['nama']);
        if (isset($map[$key])) {
            $upd->execute([$map[$key], (float) $per[$key]['harga_jasa'], (float) $per[$key]['harga_upah'], $pj['nama']]);
        }
    }

    // Volume akhir contoh: pekerjaan demo yang sudah selesai (id 1) -> realisasi = volume
    $pdo->exec("UPDATE pekerjaan SET volume_realisasi = volume, realisasi_tanggal = date('now','localtime')
                WHERE status = 'selesai' AND volume_realisasi = 0");

    // Borongan nominal tetap demo dikosongkan agar contoh memakai skema per satuan
    $pdo->exec('UPDATE pekerjaan_pekerja SET upah_borongan = 0 WHERE upah_borongan > 0');

    // Absensi DEMO (created_by = 2, pembuat seed) dilepas dari kaitan pekerjaan
    // supaya contoh perhitungan margin item selesai tidak terlihat seperti biaya ganda.
    $pdo->exec('UPDATE absensi SET pekerjaan_id = NULL WHERE created_by = 2');
}

/**
 * Contoh jadwal harian + laporan hasil kerja untuk data demo.
 *
 * Hanya jalan bila: tabel jadwal masih kosong, isi database masih persis
 * data demo (8 akun & 2 project), dan ada item pekerjaan ber-harga.
 * Skema borongan (andi, joko, rudi) mendapat hasil kerja berupa volume;
 * pekerja harian (dewi, slamet) juga melapor, volumenya jadi dasar tagihan
 * ke perusahaan sedangkan upahnya dari absensi.
 */
function seed_jadwal_kerja(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM jadwal')->fetchColumn() > 0) {
        return;
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 8
        || (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() !== 2) {
        return;
    }

    $items = [];
    foreach ($pdo->query("SELECT id, project_id, nama, satuan, harga_upah, volume
                          FROM pekerjaan
                          WHERE harga_upah > 0 AND satuan <> 'ls' AND volume > 0
                          ORDER BY id") as $r) {
        $items[] = $r;
    }
    if (count($items) < 3) {
        return;
    }

    // user_id => project_id yang dikerjakan pada contoh jadwal
    $tim = [4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1];

    $skema = [];
    foreach ($pdo->query('SELECT id, skema FROM users') as $r) {
        $skema[(int) $r['id']] = $r['skema'];
    }

    $insJadwal = $pdo->prepare(
        'INSERT OR IGNORE INTO jadwal (tanggal, user_id, project_id, pekerjaan_id, lokasi_id, catatan, created_by)
         VALUES (?,?,?,?,?,?,2)'
    );
    $insLapor = $pdo->prepare(
        'INSERT INTO laporan_kerja (tanggal, user_id, project_id, pekerjaan_id, volume, upah_satuan, keterangan, created_by)
         VALUES (?,?,?,?,?,?,?,2)'
    );
    $updPj = $pdo->prepare('UPDATE pekerjaan SET volume_realisasi = ?, realisasi_tanggal = ? WHERE id = ? AND volume_realisasi < ?');

    // 6 hari kerja ke belakang + hari ini + besok
    for ($i = 6; $i >= -1; $i--) {
        $ts = strtotime(($i >= 0 ? '-' : '+') . abs($i) . ' day');
        if ($i === 0) {
            $ts = time();
        }
        $tanggal = date('Y-m-d', $ts);
        if (date('N', $ts) === '7') {
            continue;
        }
        $besok = $i < 0;

        foreach ($tim as $userId => $projectId) {
            if (crc32($userId . $tanggal) % 10 < 2) {
                continue; // sesekali libur
            }
            $item = $items[(int) (crc32('i' . $userId . $tanggal) % count($items))];
            if ((int) $item['project_id'] !== $projectId) {
                $item = $items[0];
                if ((int) $item['project_id'] !== $projectId) {
                    // ambil item pertama milik project yang sesuai
                    foreach ($items as $cand) {
                        if ((int) $cand['project_id'] === $projectId) {
                            $item = $cand;
                            break;
                        }
                    }
                }
            }
            $insJadwal->execute([
                $tanggal, $userId, $projectId, (int) $item['id'], null,
                $besok ? 'Direncanakan besok' : '',
            ]);

            if ($besok || $i === 0) {
                continue; // hari ini & besok belum ada laporan
            }

            // volume per hari (1-15% dari volume kontrak, dibulatkan 2 desimal)
            $pctItem = 1 + (crc32('v' . $userId . $tanggal) % 15);
            $volume = round((float) $item['volume'] * $pctItem / 100, 2);
            if ($volume <= 0) {
                continue;
            }
            // upah_satuan hanya untuk pekerja BORONGAN (pekerja harian digaji via absensi)
            $skemaUser = $skema[$userId] ?? 'harian';
            $insLapor->execute([
                $tanggal, $userId, $projectId, (int) $item['id'],
                $volume, $skemaUser === 'borongan' ? (float) $item['harga_upah'] : 0,
                '',
            ]);
            $updPj->execute([$volume, $tanggal, (int) $item['id'], $volume]);
        }
    }

    // Setelah volume kumulatif terkumpul, samakan volume_realisasi dengan total laporan
    $pdo->exec(
        "UPDATE pekerjaan SET
            volume_realisasi = (SELECT COALESCE(SUM(l.volume),0) FROM laporan_kerja l WHERE l.pekerjaan_id = pekerjaan.id),
            realisasi_tanggal = (SELECT MAX(l.tanggal) FROM laporan_kerja l WHERE l.pekerjaan_id = pekerjaan.id)
         WHERE EXISTS (SELECT 1 FROM laporan_kerja l WHERE l.pekerjaan_id = pekerjaan.id)"
    );
}

/**
 * Contoh skema upah (harian/borongan) untuk akun demo.
 * Hanya dijalankan bila database masih persis data demo dan skema belum
 * pernah diubah user (semuanya masih nilai default 'harian').
 */
function seed_skema_demo(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 8
        || (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() !== 2) {
        return;
    }
    $borongan = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE skema = 'borongan'")->fetchColumn();
    if ($borongan > 0) {
        return;
    }
    $pdo->exec("UPDATE users SET skema = 'borongan' WHERE username IN ('andi', 'joko', 'rudi')");
}
