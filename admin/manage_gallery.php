<?php
ob_start();
$page_title = 'Kelola Galeri';
include 'includes/auth.php';
include 'includes/admin_header.php';

$success = '';
$error = '';

// Handle Delete Gallery
if (isset($_GET['delete_gallery'])) {
    $uuid = $_GET['delete_gallery'];
    try {
        // Delete all photos first
        $stmt = $pdo->prepare("SELECT path_gambar FROM foto WHERE id_galeri = ?");
        $stmt->execute([$uuid]);
        $photos = $stmt->fetchAll();

        foreach ($photos as $photo) {
            if ($photo['path_gambar']) {
                delete_image($photo['path_gambar']);
            }
        }

        // Delete gallery (cascade will delete photos)
        $stmt = $pdo->prepare("DELETE FROM galeri WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $_SESSION['flash_success'] = "Galeri dan semua fotonya berhasil dihapus!";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Gagal menghapus galeri: " . $e->getMessage();
    } finally {
        header("Location: manage_gallery.php");
        exit;
    }
}

// Handle Delete Single Photo
if (isset($_GET['delete_photo'])) {
    $uuid = $_GET['delete_photo'];
    try {
        $stmt = $pdo->prepare("SELECT path_gambar FROM foto WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $photo = $stmt->fetch();

        if ($photo && $photo['path_gambar']) {
            delete_image($photo['path_gambar']);
        }

        $stmt = $pdo->prepare("DELETE FROM foto WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $_SESSION['flash_success'] = "Foto berhasil dihapus!";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Gagal menghapus foto: " . $e->getMessage();
    } finally {
        $redirect_url = "manage_gallery.php";
        if (isset($_GET['view'])) {
            $redirect_url .= "?view=" . urlencode($_GET['view']);
        }
        header("Location: " . $redirect_url);
        exit;
    }
}

// Handle Bulk Delete Galleries
if (($_POST['action'] ?? '') === 'bulk_delete' && !empty($_POST['selected'])) {
    $uuids = $_POST['selected'];

    try {
        // Buat placeholder dinamis sebanyak jumlah UUID
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $query = "DELETE FROM galeri WHERE uuid IN ($placeholders)";
        $stmt = $pdo->prepare($query);

        // Eksekusi semua UUID
        $stmt->execute($uuids);
        $_SESSION['flash_success'] = count($uuids) . " galeri berhasil dihapus!";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Gagal menghapus beberapa galeri: " . $e->getMessage();
    } finally {
        header("Location: manage_gallery.php");
        exit;
    }
}

// Handle Insert/Update Gallery
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'save_gallery') {
        $judul = clean_input($_POST['judul'] ?? '');
        $deskripsi = clean_input($_POST['deskripsi'] ?? '');

        try {
            if (isset($_POST['uuid']) && !empty($_POST['uuid'])) {
                // Update
                $uuid = $_POST['uuid'];
                $stmt = $pdo->prepare("UPDATE galeri SET judul = ?, deskripsi = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?");
                $stmt->execute([$judul, $deskripsi, $uuid]);
                $_SESSION['flash_success'] = "Galeri berhasil diperbarui!";
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO galeri (judul, deskripsi) VALUES (?, ?) RETURNING uuid");
                $stmt->execute([$judul, $deskripsi]);
                $_SESSION['flash_success'] = "Galeri baru berhasil ditambahkan!";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Gagal menyimpan galeri: " . $e->getMessage();
        } finally {
            header("Location: manage_gallery.php");
            exit;
        }
    }

    // Handle Upload Photos to Gallery
    if ($_POST['action'] == 'upload_photos' && isset($_POST['gallery_id'])) {
        $gallery_id = $_POST['gallery_id'];

        if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
            $upload_count = 0;
            $total_files = count($_FILES['photos']['name']);

            for ($i = 0; $i < $total_files; $i++) {
                if ($_FILES['photos']['error'][$i] == 0) {
                    $file = [
                        'name' => $_FILES['photos']['name'][$i],
                        'type' => $_FILES['photos']['type'][$i],
                        'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                        'error' => $_FILES['photos']['error'][$i],
                        'size' => $_FILES['photos']['size'][$i]
                    ];

                    $upload_result = upload_file($file);

                    if ($upload_result['success']) {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO foto (path_gambar, id_galeri) VALUES (?, ?)");
                            $stmt->execute([$upload_result['filename'], $gallery_id]);
                            $upload_count++;
                        } catch (PDOException $e) {
                            // Jika gagal simpan ke database, hapus file yang sudah diupload
                            delete_image($upload_result['filename']);
                            $_SESSION['flash_error'] = "Gagal menyimpan foto ke database: " . $e->getMessage();
                        }
                    }
                }
            }

            if ($upload_count > 0) {
                $_SESSION['flash_success'] = "$upload_count foto berhasil diupload!";
            }
        } else {
            $_SESSION['flash_error'] = "Tidak ada foto yang dipilih untuk diupload.";
        }
        header("Location: manage_gallery.php?view=" . urlencode($gallery_id));
        exit;
    }
}

// Get all galleries with photo count
$stmt = $pdo->query("
    SELECT *
    FROM view_galeri_with_foto_count
    ORDER BY created_at DESC
");
$galleries = $stmt->fetchAll();

// Get data for edit
$edit_data = null;
if (isset($_GET['edit'])) {
    $uuid = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM galeri WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $edit_data = $stmt->fetch();
}

// Get photos for view
$view_gallery = null;
$view_photos = [];
if (isset($_GET['view'])) {
    $uuid = $_GET['view'];
    $stmt = $pdo->prepare("SELECT * FROM galeri WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $view_gallery = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM foto WHERE id_galeri = ? ORDER BY created_at DESC");
    $stmt->execute([$uuid]);
    $view_photos = $stmt->fetchAll();
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

<?php if ($view_gallery): ?>
    <!-- Mode View: Upload & Manage Photos dalam Album -->
    <div class="mb-4 shadow-sm border-0 animate__animated animate__fadeInUp">
        <a href="manage_gallery.php" class="btn btn-secondary mb-3">
            <i class="bi bi-arrow-left me-2"></i>Kembali ke Daftar Album
        </a>

        <!-- Upload Form -->
        <div class="card mb-4 shadow-sm border-0 animate__animated animate__fadeInUp">
            <div class="card-header bg-white">
                <h5 class="mb-0 fw-bold">
                    <i class="bi bi-upload me-2"></i>Upload Foto ke: <?php echo htmlspecialchars($view_gallery['judul']); ?>
                </h5>
                <?php if ($view_gallery['deskripsi']): ?>
                    <p class="text-muted mb-0 small mt-1"><?php echo htmlspecialchars($view_gallery['deskripsi']); ?></p>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_photos">
                    <input type="hidden" name="gallery_id" value="<?php echo $view_gallery['uuid']; ?>">

                    <div class="row align-items-end">
                        <div class="col-md-10 mb-3 mb-md-0">
                            <label class="form-label">Pilih Foto <span class="text-danger">*</span></label>
                            <input type="file" name="photos[]" class="form-control" multiple accept="image/*" required>
                            <small class="text-muted">Pilih satu atau lebih foto (Max 2MB per file, format: JPG, PNG,
                                GIF)</small>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-upload me-2"></i>Upload
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Daftar Foto dalam Album -->
        <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold">
                    <i class="bi bi-images me-2"></i>Daftar Foto
                </h5>
                <span class="badge bg-info"><?php echo count($view_photos); ?> foto</span>
            </div>
            <div class="card-body">
                <?php if (empty($view_photos)): ?>
                    <div class="card shadow-sm border-0 text-center animate__animated animate__fadeInUp">
                        <div class="card-body py-5">
                            <i class="bi bi-emoji-frown text-info" style="font-size: 3rem;"></i>
                            <h5 class="mt-3 text-muted">Belum ada foto</h5>
                            <p class="text-secondary small">Yuk tambahkan foto baru untuk ditampilkan di sini!</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($view_photos as $photo): ?>
                            <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                                <div class="card h-100">
                                    <img src="../assets/img/<?php echo htmlspecialchars($photo['path_gambar']); ?>"
                                        class="card-img-top" style="height: 150px; object-fit: cover; cursor: pointer;"
                                        onclick="window.open('../assets/img/<?php echo htmlspecialchars($photo['path_gambar']); ?>', '_blank')">
                                    <div class="card-body p-2 text-center">
                                        <a href="?delete_photo=<?php echo $photo['uuid']; ?>&view=<?php echo $view_gallery['uuid']; ?>"
                                            class="btn btn-sm btn-danger w-100" onclick="return confirmDelete('Hapus foto ini?');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- Mode List: Kelola Album Galeri -->

    <!-- Form Tambah/Edit Album -->
    <div class="card mb-4 shadow-sm border-0 animate__animated animate__fadeInUp">
        <div class="card-header bg-white">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-<?php echo $edit_data ? 'pencil' : 'plus'; ?>-circle me-2"></i>
                <?php echo $edit_data ? 'Edit' : 'Tambah'; ?> Album Galeri
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_gallery">
                <?php if ($edit_data): ?>
                    <input type="hidden" name="uuid" value="<?php echo $edit_data['uuid']; ?>">
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Judul Album <span class="text-danger">*</span></label>
                        <input type="text" name="judul" class="form-control"
                            value="<?php echo $edit_data ? htmlspecialchars($edit_data['judul']) : ''; ?>"
                            placeholder="Contoh: Workshop Machine Learning 2024" required>
                    </div>

                    <div class="col-12 mb-3">
                        <label class="form-label">Deskripsi Album</label>
                        <textarea name="deskripsi" class="form-control" rows="2"
                            placeholder="Deskripsi singkat tentang album ini..."><?php echo $edit_data ? htmlspecialchars($edit_data['deskripsi']) : ''; ?></textarea>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>Simpan Album
                    </button>
                    <?php if ($edit_data): ?>
                        <a href="manage_gallery.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle me-2"></i>Batal
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Daftar Album Galeri -->
    <div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
        <div class="card-header bg-white">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-grid me-2"></i>Daftar Album Galeri
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($galleries)): ?>
                <div class="card shadow-sm border-0 text-center animate__animated animate__fadeInUp">
                    <div class="card-body py-5">
                        <i class="bi bi-emoji-frown text-info" style="font-size: 3rem;"></i>
                        <h5 class="mt-3 text-muted">Belum ada galeri</h5>
                        <p class="text-secondary small">Yuk tambahkan galeri baru untuk ditampilkan di sini!</p>
                    </div>
                </div>
            <?php else: ?>
                <link rel="stylesheet" href="../assets/css/swipejs.css">
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
                                    <th width="100">Cover</th>
                                    <th>Judul Album</th>
                                    <th>Deskripsi</th>
                                    <th width="100">Total Foto</th>
                                    <th width="180">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($galleries as $index => $gallery): ?>
                                    <?php
                                    // Get first photo as cover
                                    $stmt_cover = $pdo->prepare("SELECT path_gambar FROM foto WHERE id_galeri = ? LIMIT 1");
                                    $stmt_cover->execute([$gallery['uuid']]);
                                    $cover = $stmt_cover->fetch();
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="selected[]" value="<?= $gallery['uuid']; ?>"
                                                class="rowCheckbox">
                                        </td>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <?php if ($cover && $cover['path_gambar']): ?>
                                                <img src="../assets/img/<?php echo htmlspecialchars($cover['path_gambar']); ?>"
                                                    style="width: 80px; height: 60px; object-fit: cover; border-radius: 5px;">
                                            <?php else: ?>
                                                <div class="bg-light d-flex align-items-center justify-content-center"
                                                    style="width: 80px; height: 60px; border-radius: 5px;">
                                                    <i class="bi bi-images text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($gallery['judul']); ?></strong></td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo $gallery['deskripsi'] ? substr(htmlspecialchars($gallery['deskripsi']), 0, 60) . '...' : '-'; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $gallery['foto_count'] > 0 ? 'info' : 'secondary'; ?>">
                                                <?php echo $gallery['foto_count']; ?> foto
                                            </span>
                                        </td>
                                        <td>
                                            <a href="?view=<?php echo $gallery['uuid']; ?>" class="btn btn-sm btn-info"
                                                title="Lihat & Upload Foto">
                                                <i class="bi bi-images"></i>
                                            </a>
                                            <a href="?edit=<?php echo $gallery['uuid']; ?>" class="btn btn-sm btn-warning"
                                                title="Edit Album">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="?delete_gallery=<?php echo $gallery['uuid']; ?>" class="btn btn-sm btn-danger"
                                                onclick="return confirmDelete('Hapus album dan semua foto di dalamnya?');"
                                                title="Hapus Album">
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

    <!-- Statistics -->
    <?php if (!empty($galleries)): ?>
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h2 class="display-4 fw-bold text-primary mb-2">
                            <?php echo count($galleries); ?>
                        </h2>
                        <p class="text-muted mb-0">Total Album</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h2 class="display-4 fw-bold text-success mb-2">
                            <?php
                            $total_photos = 0;
                            foreach ($galleries as $g) {
                                $total_photos += $g['foto_count'];
                            }
                            echo $total_photos;
                            ?>
                        </h2>
                        <p class="text-muted mb-0">Total Foto</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include 'includes/admin_footer.php'; ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const successMessage = "<?= addslashes($success ?? '') ?>";
        const errorMessage = "<?= addslashes($error ?? '') ?>";

        if (successMessage) showSuccess(successMessage);
        if (errorMessage) showError(errorMessage);
    });
</script>