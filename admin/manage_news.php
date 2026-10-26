<?php
ob_start();
$page_title = 'Kelola Berita & Agenda';
include 'includes/auth.php';
include 'includes/admin_header.php';

$success = '';
$error = '';

// DELETE FOTO
if (isset($_GET['delete_foto'])) {
    $uuid = $_GET['delete_foto'];
    try {
        // Ambil path file
        $stmt = $pdo->prepare("SELECT file_path FROM berita_foto WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $foto = $stmt->fetch();

        // Hapus file fisik
        if ($foto && $foto['file_path']) {
            delete_image($foto['file_path'], 'berita');
        }

        // Hapus dari database
        $stmt = $pdo->prepare("DELETE FROM berita_foto WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $_SESSION['flash_success'] = 'Foto berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus foto: ' . $e->getMessage();
    } finally {
        header("Location: manage_news.php?edit=" . $_GET['berita_id']);
        exit;
    }
}

// DELETE single berita
if (isset($_GET['delete'])) {
    $uuid = $_GET['delete'];
    try {
        // Hapus foto-foto terkait
        $stmt = $pdo->prepare("SELECT file_path FROM berita_foto WHERE berita_id = ?");
        $stmt->execute([$uuid]);
        $fotos = $stmt->fetchAll();

        foreach ($fotos as $foto) {
            delete_image($foto['file_path'], 'berita');
        }

        $stmt = $pdo->prepare("DELETE FROM berita WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $_SESSION['flash_success'] = 'Berita beserta foto berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus berita: ' . $e->getMessage();
    } finally {
        header("Location: manage_news.php");
        exit;
    }
}

// BULK DELETE
if (($_POST['action'] ?? '') === 'bulk_delete' && !empty($_POST['selected'])) {
    $uuids = $_POST['selected'];
    try {
        // Hapus foto-foto terkait
        foreach ($uuids as $uuid) {
            $stmt = $pdo->prepare("SELECT file_path FROM berita_foto WHERE berita_id = ?");
            $stmt->execute([$uuid]);
            $fotos = $stmt->fetchAll();

            foreach ($fotos as $foto) {
                delete_image($foto['file_path'], 'berita');
            }
        }

        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $query = "DELETE FROM berita WHERE uuid IN ($placeholders)";
        $stmt = $pdo->prepare($query);
        $stmt->execute($uuids);
        $_SESSION['flash_success'] = count($uuids) . ' berita berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus beberapa berita: ' . $e->getMessage();
    } finally {
        header("Location: manage_news.php");
        exit;
    }
}

// INSERT / UPDATE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'save') {
    $judul     = clean_input($_POST['judul'] ?? '');
    $penulis   = clean_input($_POST['penulis'] ?? '');
    $tanggal   = clean_input($_POST['tanggal'] ?? '');
    $tempat    = clean_input($_POST['tempat'] ?? '');
    $deskripsi = clean_input($_POST['deskripsi'] ?? '');
    $kategori  = clean_input($_POST['kategori'] ?? '');

    try {
        if (isset($_POST['uuid']) && !empty($_POST['uuid'])) {
            $uuid = $_POST['uuid'];
            $stmt = $pdo->prepare(
                "UPDATE berita SET judul = ?, penulis = ?, tanggal = ?, tempat = ?, deskripsi = ?, kategori = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?"
            );
            $stmt->execute([$judul, $penulis, $tanggal, $tempat, $deskripsi, $kategori, $uuid]);
            $berita_id = $uuid;
            $_SESSION['flash_success'] = 'Berita berhasil diupdate!';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO berita (judul, penulis, tanggal, tempat, deskripsi, kategori) VALUES (?, ?, ?, ?, ?, ?) RETURNING uuid"
            );
            $stmt->execute([$judul, $penulis, $tanggal, $tempat, $deskripsi, $kategori]);
            $berita_id = $stmt->fetchColumn();
            $_SESSION['flash_success'] = 'Berita berhasil ditambahkan!';
        }

        // Handle upload foto
        if (!empty($_FILES['foto']['name'][0])) {
            $max_file_size = (int)$_ENV['MAX_FILE_SIZE'];
            $total_files = count($_FILES['foto']['name']);

            // Ambil caption dan split berdasarkan baris baru
            $captions = [];
            if (!empty($_POST['caption'][0])) {
                $captions = array_filter(array_map('trim', explode("\n", $_POST['caption'][0])));
            }

            for ($i = 0; $i < $total_files; $i++) {
                if ($_FILES['foto']['error'][$i] == 0) {
                    $file = [
                        'name' => $_FILES['foto']['name'][$i],
                        'type' => $_FILES['foto']['type'][$i],
                        'tmp_name' => $_FILES['foto']['tmp_name'][$i],
                        'error' => $_FILES['foto']['error'][$i],
                        'size' => $_FILES['foto']['size'][$i],
                    ];

                    $upload_result = upload_file($file, 'berita', $max_file_size);

                    if ($upload_result['success']) {
                        // Ambil caption sesuai index, jika tidak ada gunakan string kosong
                        $caption = isset($captions[$i]) ? clean_input($captions[$i]) : '';
                        $stmt = $pdo->prepare(
                            "INSERT INTO berita_foto (berita_id, file_path, caption) VALUES (?, ?, ?)"
                        );
                        $stmt->execute([$berita_id, $upload_result['filename'], $caption]);
                    } else {
                        $_SESSION['flash_error'] = 'Gagal mengupload ' . $file['name'] . ': ' . $upload_result['message'];
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Terjadi kesalahan: ' . $e->getMessage();
    } finally {
        header("Location: manage_news.php" . (isset($berita_id) ? "?edit=$berita_id" : ""));
        exit;
    }
}

// Ambil data berita untuk ditampilkan
$stmt = $pdo->query("SELECT * FROM berita ORDER BY tanggal DESC");
$news_list = $stmt->fetchAll();

// Ambil data edit jika mode edit
$edit_data = null;
$berita_fotos = [];
if (isset($_GET['edit'])) {
    $uuid = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM berita WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $edit_data = $stmt->fetch();

    // Ambil foto-foto berita
    if ($edit_data) {
        $stmt = $pdo->prepare("SELECT * FROM berita_foto WHERE berita_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$uuid]);
        $berita_fotos = $stmt->fetchAll();
    }
}

// Ambil flash message jika ada
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
?>

<!-- Form Tambah/Edit -->
<div class="card mb-4 shadow-sm border-0 animate__animated animate__fadeInUp">
    <div class="card-header bg-white">
        <h5 class="mb-0 fw-bold">
            <i class="bi bi-<?php echo $edit_data ? 'pencil' : 'plus'; ?>-circle me-2"></i>
            <?php echo $edit_data ? 'Edit' : 'Tambah'; ?> Berita
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <?php if ($edit_data): ?>
                <input type="hidden" name="uuid" value="<?php echo $edit_data['uuid']; ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Judul <span class="text-danger">*</span></label>
                    <input type="text"
                        name="judul"
                        class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['judul']) : ''; ?>"
                        placeholder="Contoh: Workshop AI 2024"
                        required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Penulis <span class="text-danger">*</span></label>
                    <input type="text"
                        name="penulis"
                        class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['penulis']) : ''; ?>"
                        placeholder="Contoh: Najla Nuricia"
                        required>
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Kategori <span class="text-danger">*</span></label>
                    <select name="kategori" class="form-select select-enhanced" required>
                        <option value="">Pilih Kategori</option>
                        <option value="agenda" <?php echo ($edit_data && $edit_data['kategori'] == 'agenda') ? 'selected' : ''; ?>>Agenda</option>
                        <option value="pengumuman" <?php echo ($edit_data && $edit_data['kategori'] == 'pengumuman') ? 'selected' : ''; ?>>Pengumuman</option>
                        <option value="berita" <?php echo ($edit_data && $edit_data['kategori'] == 'berita') ? 'selected' : ''; ?>>Berita</option>
                    </select>
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                    <input type="date"
                        name="tanggal"
                        class="form-control flatpickr-date"
                        value="<?php echo $edit_data ? $edit_data['tanggal'] : date('Y-m-d'); ?>"
                        required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Tempat</label>
                    <input type="text"
                        name="tempat"
                        class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['tempat']) : ''; ?>"
                        placeholder="Lokasi acara">
                </div>

                <div class="col-md-12 mb-3">
                    <label class="form-label">Deskripsi <span class="text-danger">*</span></label>
                    <textarea name="deskripsi"
                        class="form-control"
                        rows="5"
                        placeholder="Deskripsi lengkap tentang berita..."
                        required><?php echo $edit_data ? htmlspecialchars($edit_data['deskripsi']) : ''; ?></textarea>
                </div>

                <div class="col-md-12 mb-3">
                    <label class="form-label">Upload Foto</label>
                    <div id="foto-container">
                        <div class="foto-item mb-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="file" name="foto[]" class="form-control" accept="image/*">
                                    <small class="text-muted">Format: JPG, PNG, GIF, WEBP. Max 5MB (Multiple Up)</small>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" name="caption[]" class="form-control" placeholder="Caption foto (opsional)">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($edit_data && !empty($berita_fotos)): ?>
                    <div class="col-md-12 mb-3">
                        <label class="form-label fw-bold">Foto yang Sudah Diupload</label>
                        <div class="row g-3">
                            <?php foreach ($berita_fotos as $foto): ?>
                                <div class="col-md-3">
                                    <div class="card">
                                        <?php
                                        $foto_path = rtrim($_ENV['UPLOAD_DIR'], '/') . '/berita/' . htmlspecialchars($foto['file_path']);
                                        ?>
                                        <img src="<?php echo $foto_path; ?>"
                                            class="card-img-top"
                                            style="height: 200px; object-fit: cover;"
                                            alt="<?php echo htmlspecialchars($foto['caption'] ?: 'Foto berita'); ?>"
                                            onerror="this.src='../assets/img/placeholder.jpg'">
                                        <div class="card-body p-2">
                                            <?php if (!empty($foto['caption'])): ?>
                                                <small class="text-muted d-block mb-2">
                                                    <?php echo htmlspecialchars($foto['caption']); ?>
                                                </small>
                                            <?php endif; ?>
                                            <a href="?delete_foto=<?php echo $foto['uuid']; ?>&berita_id=<?php echo $edit_data['uuid']; ?>"
                                                class="btn btn-sm btn-danger w-100"
                                                onclick="return confirmDelete('Hapus foto ini?');">
                                                <i class="bi bi-trash"></i> Hapus
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Simpan
                </button>
                <?php if ($edit_data): ?>
                    <a href="manage_news.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>Batal
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Daftar berita -->
<div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold">
            <i class="bi bi-grid me-2"></i>Daftar Berita
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($news_list)): ?>
            <div class="card shadow-sm border-0 text-center animate__animated animate__fadeInUp">
                <div class="card-body py-5">
                    <i class="bi bi-emoji-frown text-info" style="font-size: 3rem;"></i>
                    <h5 class="mt-3 text-muted">Belum ada Berita</h5>
                    <p class="text-secondary small">Yuk tambahkan Berita baru untuk ditampilkan di sini!</p>
                </div>
            </div>

        <?php else: ?>

            <link rel="stylesheet" href="../assets/css/swipejs.css">
            <!-- Swipeable Table -->
            <div class="swipeable-table" id="swipeTable">
                <form method="POST" id="bulkDeleteForm" action="">
                    <input type="hidden" name="action" value="bulk_delete">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th width="30">
                                    <input type="checkbox" id="selectAll">
                                </th>
                                <th width="50">No</th>
                                <th>Tanggal</th>
                                <th>Judul</th>
                                <th>Penulis</th>
                                <th>Kategori</th>
                                <th>Foto</th>
                                <th width="150">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($news_list as $index => $news):
                                $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM berita_foto WHERE berita_id = ?");
                                $stmt->execute([$news['uuid']]);
                                $foto_count = $stmt->fetchColumn();
                            ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected[]" value="<?= $news['uuid']; ?>" class="rowCheckbox">
                                    </td>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($news['tanggal'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($news['judul']); ?></strong><br>
                                        <small class="text-muted">
                                            <?php echo substr(htmlspecialchars($news['deskripsi']), 0, 80) . '...'; ?>
                                        </small>
                                    </td>
                                    <td><?php echo htmlspecialchars($news['penulis'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $news['kategori'] == 'agenda' ? 'success' : ($news['kategori'] == 'pengumuman' ? 'warning' : 'primary'); ?>">
                                            <?php echo ucfirst($news['kategori']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <i class="bi bi-images"></i> <?php echo $foto_count; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?edit=<?php echo $news['uuid']; ?>"
                                            class="btn btn-sm btn-warning"
                                            title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="?delete=<?php echo $news['uuid']; ?>"
                                            class="btn btn-sm btn-danger"
                                            onclick="return confirmDelete('Apakah Anda yakin ingin menghapus berita ini beserta semua fotonya?');"
                                            title="Hapus">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="bulkAction" class="mt-3 d-none">
                        <button type="button" id="bulkDeleteBtn" class="btn btn-danger">
                            <i class="bi bi-trash3 me-2"></i>Hapus Terpilih
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const successMessage = "<?= addslashes($success ?? '') ?>";
        const errorMessage = "<?= addslashes($error ?? '') ?>";

        if (successMessage) showSuccess(successMessage);
        if (errorMessage) showError(errorMessage);
    });
</script>

<?php
include 'includes/admin_footer.php';
ob_end_flush();
?>