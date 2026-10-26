<?php

/**
 * Media storage.
 *
 * Uploaded images live in a public Supabase Storage bucket, because the
 * application runs on a serverless platform whose filesystem is read-only.
 *
 * Writes go through the `media-upload` Edge Function, which holds the
 * service_role key on the server side and requires a shared upload token.
 * The app therefore never ships a privileged key.
 *
 * When no upload token is configured (local development), the helpers fall
 * back to writing files to assets/img so nothing changes for local work.
 *
 * The bucket mirrors the layout of assets/img:
 *   img/<filename>
 *   img/berita/<filename>
 *   img/kegiatan/<filename>
 *   img/dashboard/<filename>
 */

function storage_bucket(): string
{
    return $_ENV['SUPABASE_STORAGE_BUCKET'] ?? 'media';
}

function storage_base_url(): string
{
    $url = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');

    return $url === '' ? '' : $url . '/storage/v1';
}

/**
 * Endpoint of the Edge Function that performs writes to the bucket.
 */
function storage_function_url(): string
{
    $url = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');

    return $url === '' ? '' : $url . '/functions/v1/media-upload';
}

function storage_token(): string
{
    return $_ENV['SUPABASE_UPLOAD_TOKEN'] ?? '';
}

function storage_key(string $filename, string $folder = ''): string
{
    $folder = normalize_media_folder($folder);

    return $folder === '' ? 'img/' . $filename : 'img/' . $folder . '/' . $filename;
}

/**
 * Accepts either a plain folder name ("berita") or a legacy upload directory
 * such as "../assets/img/berita/" and returns the folder segment.
 */
function normalize_media_folder(?string $folder): string
{
    if ($folder === null) {
        return '';
    }

    $folder = str_replace('\\', '/', trim($folder));
    $folder = preg_replace('#^.*assets/img#', '', $folder);

    return trim($folder, '/');
}

function storage_ready(): bool
{
    return storage_function_url() !== '' && storage_token() !== '';
}

function storage_public_url(string $filename, string $folder = ''): string
{
    return storage_base_url() . '/object/public/' . storage_bucket() . '/'
        . storage_key($filename, $folder);
}

/**
 * Writes an uploaded file to storage.
 *
 * @return array{success: bool, filename?: string, message?: string}
 */
function storage_upload(array $file, string $folder = ''): array
{
    $filename = $file['name'];

    $endpoint = storage_function_url() . '?path=' . rawurlencode(storage_key($filename, $folder));
    $contents = @file_get_contents($file['tmp_name']);

    if ($contents === false) {
        return ['success' => false, 'message' => 'Gagal membaca file sementara.'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $contents,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'x-upload-token: ' . storage_token(),
            'Content-Type: ' . ($file['type'] ?: 'application/octet-stream'),
        ],
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['success' => false, 'message' => 'Gagal menghubungi penyimpanan: ' . $curlError];
    }

    if ($status < 200 || $status >= 300) {
        $detail = json_decode($body, true)['error'] ?? substr((string) $body, 0, 200);
        return ['success' => false, 'message' => 'Penyimpanan menolak file (' . $status . '): ' . $detail];
    }

    return ['success' => true, 'filename' => $filename];
}

/**
 * Removes a file from storage. Returns false when the object did not exist.
 */
function storage_delete(string $filename, string $folder = ''): bool
{
    if ($filename === '' || !storage_ready()) {
        return false;
    }

    $endpoint = storage_function_url() . '?path=' . rawurlencode(storage_key($filename, $folder));

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'x-upload-token: ' . storage_token(),
        ],
    ]);

    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status >= 200 && $status < 300;
}

/**
 * Deletes a stored image, whether it lives in the bucket or on the local disk
 * (local development). Missing files are ignored.
 */
function delete_image(?string $filename, string $folder = ''): void
{
    if ($filename === null || $filename === '') {
        return;
    }

    storage_delete($filename, $folder);

    $local = __DIR__ . '/../assets/img/' . normalize_media_folder($folder);
    $local = rtrim($local, '/') . '/' . $filename;

    if (is_file($local)) {
        @unlink($local);
    }
}
