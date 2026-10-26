<?php

require_once __DIR__ . '/media.php';

/**
 * Validates an uploaded image and stores it.
 *
 * On a serverless host the file goes to Supabase Storage; locally it is written
 * to assets/img so development keeps working without credentials.
 *
 * @param array       $file          One entry of $_FILES
 * @param string|null $target_dir    Folder name, e.g. "berita" or "dashboard"
 * @param int|null    $max_file_size Overrides MAX_FILE_SIZE
 *
 * @return array{success: bool, filename?: string, message?: string}
 */
function upload_file($file, $target_dir = null, $max_file_size = null)
{
    $max_size = $max_file_size ?? (int) ($_ENV['MAX_FILE_SIZE'] ?? 2097152);
    $folder = normalize_media_folder($target_dir);

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (!in_array($file['type'], $allowed_types, true)) {
        return ['success' => false, 'message' => 'Tipe file tidak diizinkan.'];
    }

    if ($file['size'] > $max_size) {
        return [
            'success' => false,
            'message' => 'Ukuran file maksimal ' . round($max_size / 1048576, 1) . 'MB.',
        ];
    }

    if (($file['error'] ?? 0) !== 0) {
        return ['success' => false, 'message' => 'Upload gagal (kode ' . $file['error'] . ').'];
    }

    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new_filename = uniqid() . '_' . time() . '.' . $file_extension;

    if (storage_ready()) {
        return storage_upload(
            ['name' => $new_filename, 'tmp_name' => $file['tmp_name'], 'type' => $file['type']],
            $folder
        );
    }

    // Local development: write to the asset directory as before.
    $upload_dir = __DIR__ . '/../assets/img/' . $folder;
    $upload_dir = rtrim($upload_dir, '/');

    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
        return ['success' => false, 'message' => 'Folder upload tidak bisa dibuat.'];
    }

    if (move_uploaded_file($file['tmp_name'], $upload_dir . '/' . $new_filename)) {
        return ['success' => true, 'filename' => $new_filename];
    }

    return ['success' => false, 'message' => 'Gagal menyimpan file ke disk.'];
}
