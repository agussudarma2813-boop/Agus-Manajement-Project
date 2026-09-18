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
              'progress_log', 'absensi', 'harga_satuan', 'jadwal', 'laporan_kerja',
              'harga_satuan_pekerja', 'pengajuan', 'pengajuan_item',
              'kasbon', 'penggajian', 'penggajian_item'];
    if (!in_array($tabel, $boleh, true) || $id <= 0) {
        return;
    }
    $kolomId = $tabel === 'pekerjaan_pekerja' ? 'rowid' : 'id';
    db()->prepare('UPDATE ' . $tabel . ' SET perusahaan_id = ? WHERE ' . $kolomId . ' = ? AND perusahaan_id = 0')
        ->execute([tenant_id(), $id]);
}

/* ==========================================================================
   SUB-DOMAIN PER PERUSAHAAN
   --------------------------------------------------------------------------
   Mis. domain dasar "agsapkkreatif.my.id": pelanggan membuka
       https://pt-maju.agsapkkreatif.my.id
   → kata "pt-maju" dibaca dari host, lalu dipakai untuk MENGUNCI aplikasi ke
     perusahaan dengan slug "pt-maju": branding, login, dan seluruh data
     (absensi, gaji, penggajian, kasbon) hanya milik perusahaan itu.

   Catatan keamanan:
   - Host header TIDAK dipercaya begitu saja: hanya huruf kecil/angka/minus,
     hanya satu label, dan WAJIB cocok dengan slug perusahaan yang ada.
   - Domain dasar diisi oleh pengelola aplikasi (tidak di-hardcode).
   - Yang benar-benar mengunci data adalah `perusahaan_id` milik user yang login
     (isolasi tenant yang sudah ada). Sub-domain hanya menentukan gerbang login,
     jadi salah/tipu host tidak bisa membocorkan data perusahaan lain.
   ========================================================================== */

/** Nilai pengaturan aplikasi (mis. 'subdomain_base') */
function app_setting(string $kunci, string $bawaan = ''): string
{
    static $cache = [];
    if (array_key_exists($kunci, $cache)) {
        return $cache[$kunci];
    }
    try {
        $st = db()->prepare('SELECT nilai FROM pengaturan_app WHERE kunci = ?');
        $st->execute([$kunci]);
        $v = $st->fetchColumn();
        return $cache[$kunci] = ($v === false ? $bawaan : (string) $v);
    } catch (Throwable $e) {
        return $cache[$kunci] = $bawaan;
    }
}

function app_setting_set(string $kunci, string $nilai): void
{
    db()->prepare('INSERT INTO pengaturan_app (kunci, nilai) VALUES (?,?)
                   ON CONFLICT(kunci) DO UPDATE SET nilai = excluded.nilai')
        ->execute([$kunci, $nilai]);
}

/** Daftar domain dasar yang boleh dipakai untuk sub-domain (bisa lebih dari satu) */
function subdomain_dasar(): array
{
    $mentah = strtolower(app_setting('subdomain_base', ''));
    $out = [];
    foreach (preg_split('/[,\s]+/', $mentah) ?: [] as $d) {
        $d = ltrim(trim($d), '.');
        if ($d !== '' && preg_match('/^[a-z0-9.-]+$/', $d)) {
            $out[] = $d;
        }
    }
    return array_values(array_unique($out));
}

/** Host yang sedang diakses, tanpa port, huruf kecil */
function host_saat_ini(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    return rtrim($host, '.');
}

/** Sub-domain yang tidak boleh dipakai (nama teknis server/hosting) */
const SLUG_TERLARANG = [
    'www', 'mail', 'email', 'smtp', 'pop', 'pop3', 'imap', 'mx', 'ftp', 'sftp',
    'cpanel', 'whm', 'webmail', 'webdisk', 'autodiscover', 'autoconfig',
    'ns1', 'ns2', 'ns3', 'dns', 'localhost', 'admin', 'administrator',
    'api', 'app', 'apps', 'login', 'logout', 'test', 'testing', 'dev', 'staging',
    'demo', 'static', 'cdn', 'assets', 'img', 'images', 'blog', 'shop', 'store',
    'panel', 'server', 'status', 'support', 'user', 'www2',
];

/** Apakah slug termasuk nama yang dilarang (dipakai sistem) */
function slug_terlarang(string $slug): bool
{
    return in_array(strtolower(trim($slug)), SLUG_TERLARANG, true);
}

/** Apakah slug sudah dipakai sebuah perusahaan (dengan cache per request) */
function slug_perusahaan_ada(string $slug): bool
{
    static $cache = [];
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return false;
    }
    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }
    try {
        $st = db()->prepare("SELECT 1 FROM perusahaan WHERE lower(slug) = ? AND slug <> '' LIMIT 1");
        $st->execute([$slug]);
        return $cache[$slug] = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$slug] = false;
    }
}

/**
 * Mengambil kata sub-domain dari host yang sedang diakses.
 *
 * Dua mode:
 *  1. Domain dasar diatur (pengaturan_app.subdomain_base) → paling akurat,
 *     hanya sub-domain di bawah domain itu yang dibaca.
 *  2. Domain dasar BELUM diatur → deteksi otomatis: host harus punya minimal
 *     3 label (mis. pt-maju.agsapkkreatif.my.id) dan label pertamanya harus
 *     cocok dengan slug perusahaan yang benar-benar ada. Jadi
 *     "example.com" atau "www.example.com" tidak pernah dianggap sub-domain.
 *
 * @return string|null  null bila tidak ada sub-domain yang cocok
 */
function subdomain_slug(): ?string
{
    // Sengaja TANPA cache statis: hasilnya harus selalu mengikuti host request
    // (host tidak berubah dalam satu request, tapi cache statis menyulitkan
    // pengujian dan berisiko memakai hasil host yang salah). Yang di-cache adalah
    // query DB-nya (subdomain_dasar() & slug_perusahaan_ada()).
    $host = host_saat_ini();
    if ($host === '') {
        return null;
    }

    $dasar = subdomain_dasar();
    if ($dasar) {
        foreach ($dasar as $d) {
            if ($host === $d) {
                return null; // domain utama, bukan sub-domain pelanggan
            }
            if (str_ends_with($host, '.' . $d)) {
                $sub = substr($host, 0, strlen($host) - strlen('.' . $d));
                if ($sub === '' || str_contains($sub, '.')) {
                    return null; // kosong atau terlalu banyak label (mis. a.b.domain)
                }
                if (preg_match('/^[a-z0-9-]+$/', $sub) === 1 && !slug_terlarang($sub)) {
                    return $sub;
                }
                return null;
            }
        }
        return null; // host di luar domain dasar yang dikenal
    }

    // --- Mode otomatis (belum ada pengaturan domain dasar) ---
    if (substr_count($host, '.') < 2) {
        return null; // hanya 2 label → domain utama
    }
    $label = substr($host, 0, (int) strpos($host, '.'));
    if (preg_match('/^[a-z0-9-]+$/', $label) !== 1 || slug_terlarang($label)) {
        return null;
    }
    return slug_perusahaan_ada($label) ? $label : null;
}

/**
 * Perusahaan yang cocok dengan sub-domain yang sedang diakses.
 * Tidak memakai sesi (dipakai juga di halaman login sebelum user masuk).
 */
function perusahaan_dari_host(bool $hanyaAktif = true): ?array
{
    $slug = subdomain_slug();
    if ($slug === null) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM perusahaan WHERE lower(slug) = ? AND slug <> '' LIMIT 1");
    $st->execute([$slug]);
    $p = $st->fetch() ?: null;
    if (!$p) {
        return null;
    }
    if ($hanyaAktif && tenant_alasan_tutup($p) !== '') {
        return $p; // tetap dikembalikan supaya bisa ditampilkan alasannya
    }
    return $p;
}

/**
 * Tautan login siap dibagikan untuk sebuah perusahaan.
 * Kalau domain dasar sudah diatur → pakai sub-domain; kalau belum → pakai ?p=slug.
 */
function tautan_perusahaan(array $p, string $skema = 'https', string $subpath = ''): string
{
    $slug = trim((string) $p['slug']);
    if ($slug === '') {
        return 'login.php';
    }
    $dasar = subdomain_dasar()[0] ?? '';
    if ($dasar !== '') {
        return $skema . '://' . $slug . '.' . $dasar . ($subpath !== '' ? '/' . ltrim($subpath, '/') : '') . '/';
    }
    return 'login.php?p=' . rawurlencode($slug);
}

/** Apakah sub-domain sedang dipakai (untuk pesan/informasi) */
function mode_subdomain(): bool
{
    return subdomain_slug() !== null;
}
