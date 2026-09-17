<?php
declare(strict_types=1);

/**
 * Multi-perusahaan (multi-tenant).
 *
 * Aplikasi ini dipakai beberapa perusahaan pelanggan sekaligus. Setiap baris data
 * ditandai `perusahaan_id`, dan SEMUA query wajib dibatasi ke perusahaan pengguna
 * yang sedang login (lihat `tenant_scope()` / `tenant_ok()`).
 *
 * - Pemilik aplikasi (users.is_owner = 1) mengelola daftar perusahaan lewat
 *   `perusahaan.php`, dan bisa "masuk sebagai" perusahaan pelanggan untuk membantu
 *   (mode bantu) tanpa perlu tahu kata sandinya.
 */

/** ID perusahaan yang datanya sedang dilihat (0 kalau tidak ada). */
function tenant_id(): int
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $u = current_user();
    if (!$u) {
        return $cache = 0;
    }
    // Mode bantu: pemilik aplikasi melihat data perusahaan lain
    if (!empty($_SESSION['as_perusahaan']) && (int) $u['is_owner'] === 1) {
        $cache = (int) $_SESSION['as_perusahaan'];
        tenant_konteks_sync();
        return $cache;
    }
    $cache = (int) ($u['perusahaan_id'] ?? 0);
    tenant_konteks_sync();
    return $cache;
}

/**
 * Sinkronkan tabel `_konteks` dengan perusahaan aktif. Dipakai trigger untuk
 * menandai baris baru. Dipanggil otomatis dari tenant_id().
 */
function tenant_konteks_sync(): void
{
    static $sudah = false;
    if ($sudah) {
        return;
    }
    $sudah = true;
    $id = tenant_id();
    try {
        $st = db()->query('SELECT perusahaan_id FROM _konteks WHERE id = 1');
        if ((int) $st->fetchColumn() === $id) {
            return;
        }
        db()->prepare('UPDATE _konteks SET perusahaan_id = ? WHERE id = 1')->execute([$id]);
    } catch (Throwable $e) {
        // tabel konteks belum ada (migrasi awal) — abaikan
    }
}

/** Baris perusahaan yang sedang aktif (null bila tidak ada). */
function tenant(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $id = tenant_id();
    if ($id <= 0) {
        return $cache = null;
    }
    $st = db()->prepare('SELECT * FROM perusahaan WHERE id = ?');
    $st->execute([$id]);
    return $cache = ($st->fetch() ?: null);
}

/** Apakah pengguna yang login adalah pemilik aplikasi (pengelola pelanggan)? */
function is_owner(): bool
{
    $u = current_user();
    return $u !== null && (int) ($u['is_owner'] ?? 0) === 1;
}

function require_owner(): array
{
    $u = require_login();
    if ((int) ($u['is_owner'] ?? 0) !== 1) {
        http_response_code(403);
        render_forbidden();
        exit;
    }
    return $u;
}

/** Sedang memakai mode bantu (pemilik mengelola data pelanggan)? */
function mode_bantu(): bool
{
    return !empty($_SESSION['as_perusahaan']) && is_owner();
}

/**
 * Potongan SQL untuk membatasi query ke perusahaan aktif.
 * Pemakaian: $sql .= tenant_scope('pj', $args);
 */
function tenant_scope(string $alias, array &$args): string
{
    $id = tenant_id();
    if ($id <= 0) {
        // Tanpa perusahaan aktif: jangan tampilkan data apa pun.
        return ' AND 1 = 0';
    }
    $args[] = $id;
    return ' AND ' . $alias . '.perusahaan_id = ?';
}

/** Sama seperti tenant_scope, tapi untuk kondisi pertama (setelah WHERE). */
function tenant_where(string $alias, array &$args): string
{
    $id = tenant_id();
    if ($id <= 0) {
        return '1 = 0';
    }
    $args[] = $id;
    return $alias . '.perusahaan_id = ?';
}

/** Verifikasi satu baris data memang milik perusahaan aktif. */
function tenant_ok(?array $row, string $kolom = 'perusahaan_id'): bool
{
    if (!$row) {
        return false;
    }
    $id = tenant_id();
    return $id > 0 && (int) ($row[$kolom] ?? 0) === $id;
}

/** Jumlah user sebuah perusahaan (untuk penegakan batas jumlah user). */
function tenant_user_count(int $perusahaanId): int
{
    if ($perusahaanId <= 0) {
        return 0;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE perusahaan_id = ?');
    $st->execute([$perusahaanId]);
    return (int) $st->fetchColumn();
}

/**
 * Apakah perusahaan masih boleh dipakai? Mengembalikan alasan bila tidak.
 *
 * @param array|null $p baris perusahaan
 * @return string '' kalau boleh, atau alasan penolakan
 */
function tenant_alasan_tutup(?array $p): string
{
    if (!$p) {
        return 'Perusahaan tidak ditemukan. Hubungi pengelola aplikasi.';
    }
    if ((int) $p['aktif'] !== 1) {
        return 'Akun perusahaan ini sedang dinonaktifkan. Hubungi pengelola aplikasi.';
    }
    $sampai = trim((string) $p['masa_aktif_sampai']);
    if ($sampai !== '' && $sampai < date('Y-m-d')) {
        return 'Masa aktif perusahaan ini sudah berakhir pada ' . tgl($sampai) . '. Hubungi pengelola aplikasi untuk perpanjangan.';
    }
    return '';
}

/** Batas jumlah user tercapai? (0 = tanpa batas) */
function tenant_batas_user(array $p): bool
{
    $maks = (int) $p['maks_user'];
    return $maks > 0 && tenant_user_count((int) $p['id']) >= $maks;
}

/** Ringkasan pemakaian sebuah perusahaan (dipakai halaman pengelola). */
function tenant_ringkas(int $perusahaanId): array
{
    $q = static function (string $sql) use ($perusahaanId) {
        $st = db()->prepare($sql);
        $st->execute([$perusahaanId]);
        return $st->fetchColumn();
    };
    $tglSampai = $q('SELECT MAX(masa_aktif_sampai) FROM perusahaan WHERE id = ?');
    $sisa = 0;
    if ($tglSampai) {
        $sisa = (int) (new DateTime(date('Y-m-d')))->diff(new DateTime((string) $tglSampai))->format('%r%a');
    }
    return [
        'users'      => (int) $q('SELECT COUNT(*) FROM users WHERE perusahaan_id = ?'),
        'projects'   => (int) $q('SELECT COUNT(*) FROM projects WHERE perusahaan_id = ?'),
        'pekerjaan'  => (int) $q('SELECT COUNT(*) FROM pekerjaan WHERE perusahaan_id = ?'),
        'harga'      => (int) $q('SELECT COUNT(*) FROM harga_satuan WHERE perusahaan_id = ?'),
        'jadwal'     => (int) $q('SELECT COUNT(*) FROM jadwal WHERE perusahaan_id = ?'),
        'laporan'    => (int) $q('SELECT COUNT(*) FROM laporan_kerja WHERE perusahaan_id = ?'),
        'sisa_hari'  => $sisa,
    ];
}

/** Daftar seluruh perusahaan (khusus pemilik aplikasi). */
function fetch_perusahaan(string $q = ''): array
{
    $sql = 'SELECT * FROM perusahaan';
    $args = [];
    if ($q !== '') {
        $sql .= ' WHERE nama LIKE ? OR slug LIKE ? OR kontak_nama LIKE ?';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like);
    }
    $sql .= ' ORDER BY aktif DESC, nama';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function get_perusahaan(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM perusahaan WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Slug unik untuk link login perusahaan. */
function slug_unik(string $nama, int $kecualiId = 0): string
{
    $dasar = slugify($nama);
    $slug = $dasar;
    $n = 1;
    while (true) {
        $st = db()->prepare('SELECT id FROM perusahaan WHERE slug = ? AND id <> ?');
        $st->execute([$slug, $kecualiId]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
        $slug = $dasar . '-' . (++$n);
    }
}

/** Branding untuk tampilan (nama & logo aplikasi pelanggan). */
function brand(array $opt = []): array
{
    $p = $opt['perusahaan'] ?? tenant();
    if (!$p) {
        return ['nama' => 'ProyekKita', 'sub' => 'Manajemen Project', 'logo' => '', 'slug' => ''];
    }
    return [
        'nama' => (string) $p['nama'],
        'sub' => 'Manajemen Project',
        'logo' => (string) $p['logo'],
        'slug' => (string) $p['slug'],
    ];
}

/**
 * Menandai satu baris data milik perusahaan aktif (cadangan bila trigger tidak
 * sempat jalan, mis. baris yang disisipkan sebelum konteks tersinkron).
 */
function tk_tandai(string $tabel, int $id): void
{
    $boleh = ['users', 'projects', 'lokasi', 'pekerjaan', 'pekerjaan_pekerja',
              'progress_log', 'absensi', 'harga_satuan', 'jadwal', 'laporan_kerja'];
    if (!in_array($tabel, $boleh, true) || $id <= 0) {
        return;
    }
    $kolomId = $tabel === 'pekerjaan_pekerja' ? 'rowid' : 'id';
    db()->prepare('UPDATE ' . $tabel . ' SET perusahaan_id = ? WHERE ' . $kolomId . ' = ? AND perusahaan_id = 0')
        ->execute([tenant_id(), $id]);
}
