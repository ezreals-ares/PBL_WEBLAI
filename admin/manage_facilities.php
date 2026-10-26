<?php
ob_start();
$page_title = 'Kelola Fasilitas';
include 'includes/auth.php';
include 'includes/admin_header.php';


$success = '';
$error = null;

// Handle Delete
if (isset($_GET['delete'])) {
    $uuid = $_GET['delete'];
    try {
        // Get image path to delete file
        $stmt = $pdo->prepare('SELECT path_gambar FROM fasilitas WHERE uuid = ?');
        $stmt->execute([$uuid]);
        $facility = $stmt->fetch();

        if ($facility && $facility['path_gambar']) {
            delete_image($facility['path_gambar']);
        }

        $stmt = $pdo->prepare('DELETE FROM fasilitas WHERE uuid = ?');
        $stmt->execute([$uuid]);
        
        $_SESSION['flash_success'] = 'Fasilitas berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus fasilitas: ' . $e->getMessage();
    } finally {
        header('Location: manage_facilities.php');
        exit();
    }
}

// Handle Bulk Delete
if (($_POST['action'] ?? '') === 'bulk_delete' && !empty($_POST['selected'])) {
    $uuids = $_POST['selected'];

    try {
        // Buat placeholder dinamis sebanyak jumlah UUID
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $query = "DELETE FROM fasilitas WHERE uuid IN ($placeholders)";
        $stmt = $pdo->prepare($query);

        // Eksekusi semua UUID
        $stmt->execute($uuids);

        $_SESSION['flash_success'] = count($uuids) . ' fasilitas berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus fasilitas: ' . $e->getMessage();
    } finally {
        header('Location: manage_facilities.php');
        exit();
    }
}

// Handle Insert/Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'save') {
    $nama = clean_input($_POST['nama'] ?? '');
    $deskripsi = clean_input($_POST['deskripsi'] ?? '');
    $kuantitas = clean_input($_POST['kuantitas'] ?? '');

    try {
        $path_gambar = null;

        // Handle file upload
        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] == 0) {
            $upload_result = upload_file($_FILES['gambar']);
            if ($upload_result['success']) {
                $path_gambar = $upload_result['filename'];
            } else {
                $_SESSION['flash_error'] = 'Gagal mengupload gambar: ' . $upload_result['message'];
            }
        }

        if (!isset($_SESSION['flash_error'])) {
            if (isset($_POST['uuid']) && !empty($_POST['uuid'])) {
                // Update
                $uuid = $_POST['uuid'];

                // Delete old image if new one uploaded
                if ($path_gambar) {
                    $stmt = $pdo->prepare('SELECT path_gambar FROM fasilitas WHERE uuid = ?');
                    $stmt->execute([$uuid]);
                    $old = $stmt->fetch();
                    if ($old && $old['path_gambar']) {
                        delete_image($old['path_gambar']);
                    }
                }

                if ($path_gambar) {
                    $stmt = $pdo->prepare(
                        'UPDATE fasilitas SET nama = ?, deskripsi = ?, kuantitas = ?, path_gambar = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?',
                    );
                    $stmt->execute([$nama, $deskripsi, $kuantitas, $path_gambar, $uuid]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE fasilitas SET nama = ?, deskripsi = ?, kuantitas = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?',
                    );
                    $stmt->execute([$nama, $deskripsi, $kuantitas, $uuid]);
                }
                $_SESSION['flash_success'] = 'Fasilitas berhasil diperbarui!';
            } else {
                // Insert
                try {
                    $stmt = $pdo->prepare('SELECT sp_insert_fasilitas(?, ?, ?, ?)');
                    $stmt->execute([$nama, $deskripsi, $kuantitas, $path_gambar]);
                    $stmt->fetch();

                    $_SESSION['flash_success'] = 'Fasilitas berhasil ditambahkan!';
                } catch (PDOException $e) {
                    $_SESSION['flash_error'] = 'Gagal menyimpan fasilitas: ' . $e->getMessage();
                }
            }
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menyimpan fasilitas: ' . $e->getMessage();
    } 
    
    header('Location: manage_facilities.php');
    exit();
    
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

// Get all facilities
$stmt = $pdo->query('SELECT * FROM fasilitas ORDER BY nama');
$facilities = $stmt->fetchAll();

// Get data for edit
$edit_data = null;
if (isset($_GET['edit'])) {
    $uuid = $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM fasilitas WHERE uuid = ?');
    $stmt->execute([$uuid]);
    $edit_data = $stmt->fetch();
}
?>

<!-- Form Tambah/Edit -->
<div class="card mb-4 shadow-sm border-0 animate__animated animate__fadeInUp">
    <div class="card-header bg-white">
        <h5 class="mb-0 fw-bold">
            <i class="bi bi-<?php echo $edit_data ? 'pencil' : 'plus'; ?>-circle me-2"></i>
            <?php echo $edit_data ? 'Edit' : 'Tambah'; ?> Fasilitas
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data">
            <!-- Penambahan action form -->
            <input type="hidden" name="action" value="save">
            <?php if ($edit_data): ?>
            <input type="hidden" name="uuid" value="<?php echo $edit_data['uuid']; ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8 mb-3">
                    <label class="form-label">Nama Fasilitas <span class="text-danger">*</span></label>
                    <input type="text" name="nama" class="form-control" value="<?php echo $edit_data
                            ? htmlspecialchars($edit_data['nama'])
                            : ''; ?>" placeholder="Contoh: Komputer High-End, Server Komputasi" required>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Jumlah Unit</label>
                    <input type="number" name="kuantitas" class="form-control" min="0" value="<?php echo $edit_data
                            ? $edit_data['kuantitas']
                            : '1'; ?>" placeholder="0">
                    <small class="text-muted">minimal 1</small>
                </div>

                <div class="col-12 mb-3">
                    <label class="form-label">Deskripsi/Spesifikasi <span class="text-danger">*</span></label>
                    <textarea name="deskripsi" class="form-control" rows="3"
                        placeholder="Spesifikasi detail fasilitas (RAM, Processor, dll)" required><?php echo $edit_data
                            ? htmlspecialchars($edit_data['deskripsi'])
                            : ''; ?></textarea>
                </div>

                <div class="col-12 mb-3">
                    <label class="form-label">Foto Fasilitas</label>
                    <input type="file" name="gambar" class="form-control" accept="image/*"
                        onchange="previewImage(this, 'preview')">
                    <small class="text-muted">Max 2MB, format: JPG, PNG, GIF. Rekomendasi: 800x600px</small>

                    <?php if ($edit_data && $edit_data['path_gambar']): ?>
                    <div class="mt-2">
                        <img src="../assets/img/<?php echo htmlspecialchars(
                            $edit_data['path_gambar'],
                        ); ?>" id="preview" class="img-thumbnail" style="max-width: 300px;">
                    </div>
                    <?php else: ?>
                    <img id="preview" class="img-thumbnail mt-2" style="max-width: 300px; display: none;">
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Simpan
                </button>
                <?php if ($edit_data): ?>
                <a href="manage_facilities.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle me-2"></i>Batal
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Daftar Fasilitas -->
<div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
    <div class="card-header bg-white">
        <h5 class="mb-0 fw-bold">
            <i class="bi bi-list-ul me-2"></i>Daftar Fasilitas
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($facilities)): ?>
        <div class="card shadow-sm border-0 text-center animate__animated animate__fadeInUp">
            <div class="card-body py-5">
                <i class="bi bi-emoji-frown text-info" style="font-size: 3rem;"></i>
                <h5 class="mt-3 text-muted">Belum ada fasilitas</h5>
                <p class="text-secondary small">Yuk tambahkan fasilitas baru untuk ditampilkan di sini!</p>
            </div>
        </div>
        <?php else: ?>
            <link rel="stylesheet" href="../assets/css/swipejs.css">
            <div class="swipeable-table" id="swipeTable">
                <form method="POST" id="bulkDeleteForm" action="">
                    <!-- Penambahan action form -->
                    <input type="hidden" name="action" value="bulk_delete">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th width="30">
                                    <input type="checkbox" id="selectAll">
                                </th>
                                <th width="50">No</th>
                                <th width="100">Foto</th>
                                <th>Nama Fasilitas</th>
                                <th>Deskripsi/Spesifikasi</th>
                                <th width="100">Jumlah</th>
                                <th width="120">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facilities as $index => $facility): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected[]" value="<?= $facility['uuid']; ?>" class="rowCheckbox">
                                    </td>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php if ($facility['path_gambar']): ?>
                                            <img src="../assets/img/<?php echo htmlspecialchars($facility['path_gambar']); ?>"
                                                alt="<?php echo htmlspecialchars($facility['nama']); ?>"
                                                style="width: 80px; height: 60px; object-fit: cover; border-radius: 5px;">
                                        <?php else: ?>
                                            <div class="bg-light d-flex align-items-center justify-content-center"
                                                style="width: 80px; height: 60px; border-radius: 5px;">
                                                <i class="bi bi-tools text-muted"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($facility['nama']); ?></strong></td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($facility['deskripsi']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($facility['kuantitas']): ?>
                                            <span class="badge bg-info">
                                                <?php echo $facility['kuantitas']; ?> unit
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="?edit=<?php echo $facility['uuid']; ?>"
                                            class="btn btn-sm btn-warning"
                                            title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="?delete=<?php echo $facility['uuid']; ?>"
                                            class="btn btn-sm btn-danger"
                                            onclick="return confirmDelete();"
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

<?php include 'includes/admin_footer.php'; ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const successMessage = "<?= addslashes($success ?? '') ?>";
    const errorMessage = "<?= addslashes($error ?? '') ?>";

    if (successMessage) showSuccess(successMessage);
    if (errorMessage) showError(errorMessage);
});
</script>