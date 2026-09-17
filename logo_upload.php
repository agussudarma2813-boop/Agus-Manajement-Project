<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/auth.php';

/**
 * Unggah logo perusahaan lewat media proxy platform (server-side).
 * Token media TIDAK pernah dikirim ke browser.
 */
header('Content-Type: application/json; charset=UTF-8');

$u = require_owner();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['logo']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'pesan' => 'Tidak ada berkas yang diunggah.']);
    exit;
}

csrf_check();

$f = $_FILES['logo'];
if ((int) $f['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'pesan' => 'Gagal membaca berkas (kode ' . (int) $f['error'] . ').']);
    exit;
}
if ((int) $f['size'] > 15 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'pesan' => 'Ukuran logo maksimal 15 MB.']);
    exit;
}

$tokenFile = __DIR__ . '/.vibecoder-media-token';
if (!is_file($tokenFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'pesan' => 'Fitur unggah belum aktif. Isi URL logo secara manual.']);
    exit;
}
$token = trim((string) file_get_contents($tokenFile));

$nama = (string) ($f['name'] ?? 'logo.png');
$isi = (string) file_get_contents((string) $f['tmp_name']);
if ($isi === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'pesan' => 'Berkas kosong.']);
    exit;
}

$url = 'http://127.0.0.1:4310/api/app-media/upload?filename=' . rawurlencode($nama);
$respon = false;
$kode = 0;
$galat = '';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $isi,
        CURLOPT_HTTPHEADER => [
            'X-App-Media-Token: ' . $token,
            'Content-Type: application/octet-stream',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $respon = curl_exec($ch);
    $kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $galat = (string) curl_error($ch);
    curl_close($ch);
} elseif ((bool) ini_get('allow_url_fopen')) {
    // Cadangan bila ekstensi cURL tidak tersedia di server
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "X-App-Media-Token: {$token}\r\nContent-Type: application/octet-stream\r\n",
            'content' => $isi,
            'timeout' => 60,
            'ignore_errors' => true,
        ],
    ]);
    $respon = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $kode = (int) $m[1];
    }
    if ($respon === false) {
        $galat = 'tidak bisa menghubungi layanan unggah';
    }
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'pesan' => 'Server ini tidak mendukung unggah berkas. Isi URL logo secara manual.']);
    exit;
}

if ($respon === false || $respon === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'pesan' => 'Gagal menghubungi layanan unggah: ' . $galat]);
    exit;
}

$data = json_decode((string) $respon, true);
if ($kode >= 400 || !is_array($data) || empty($data['url'])) {
    $layanan = is_array($data) ? (string) ($data['message'] ?? $data['error'] ?? '') : '';
    http_response_code(400);
    echo json_encode(['ok' => false, 'pesan' => $layanan !== '' ? $layanan : 'Layanan unggah menolak berkas ini.']);
    exit;
}

echo json_encode(['ok' => true, 'url' => (string) $data['url']]);
