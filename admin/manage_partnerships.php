<?php
ob_start();
$page_title = 'Kelola Partnership';
include 'includes/auth.php';
include 'includes/admin_header.php';

$success = '';
$error = '';

// Handle Delete
if (isset($_GET['delete'])) {
    $uuid = $_GET['delete'];
    try {
        // Get logo path to delete file
        $stmt = $pdo->prepare("SELECT logo FROM partnership WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $partner = $stmt->fetch();

        // Delete logo file if exists
        if ($partner && $partner['logo']) {
            delete_image($partner['logo']);
        }

        $stmt = $pdo->prepare("DELETE FROM partnership WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $_SESSION['flash_success'] = 'Partnership berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus partnership: ' . $e->getMessage();
    } finally {
        header("Location: manage_partnerships.php");
        exit;
    }
}

// Handle Bulk Delete
// Penambahan pengecekan action
if (($_POST['action'] ?? '') === 'bulk_delete' && !empty($_POST['selected'])) {
    $uuids = $_POST['selected'];

    try {
        // Buat placeholder dinamis sebanyak jumlah UUID
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $query = "DELETE FROM partnership WHERE uuid IN ($placeholders)";
        $stmt = $pdo->prepare($query);

        // Eksekusi semua UUID
        $stmt->execute($uuids);

        $_SESSION['flash_success'] = count($uuids) . ' partner berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus beberapa partner: ' . $e->getMessage();
    } finally {
        header("Location: manage_partnerships.php");
        exit;
    }
}

// Handle Insert/Update
// Penambahan pengecekan action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'save') {
    $nama = clean_input($_POST['nama'] ?? '');
    $website = clean_input($_POST['website'] ?? '');

    try {
        $logo = null;

        // Handle file upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $upload_result = upload_file($_FILES['logo']);
            if ($upload_result['success']) {
                $logo = $upload_result['filename'];
            } else {
                $_SESSION['flash_error'] = "Gagal mengupload logo: " . $upload_result['message'];
            }
        }

        if (!isset($_SESSION['flash_error'])) {
            if (isset($_POST['uuid']) && !empty($_POST['uuid'])) {
                // Update
                $uuid = $_POST['uuid'];

                // Delete old logo if new one uploaded
                if ($logo) {
                    $stmt = $pdo->prepare("SELECT logo FROM partnership WHERE uuid = ?");
                    $stmt->execute([$uuid]);
                    $old = $stmt->fetch();
                    // Hapus file logo lama
                    if ($old && $old['logo']) {
                        delete_image($old['logo']);
                    }
                }

                if ($logo) {
                    $stmt = $pdo->prepare("UPDATE partnership SET nama = ?, logo = ?, website = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?");
                    $stmt->execute([$nama, $logo, $website, $uuid]);
                } else {
                    $stmt = $pdo->prepare("UPDATE partnership SET nama = ?, website = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?");
                    $stmt->execute([$nama, $website, $uuid]);
                }
                $_SESSION['flash_success'] = 'Partnership berhasil diperbarui!';
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO partnership (nama, logo, website) VALUES (?, ?, ?)");
                $stmt->execute([$nama, $logo, $website]);
                $_SESSION['flash_success'] = 'Partnership berhasil ditambahkan!';
            }
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Terjadi kesalahan: ' . $e->getMessage();
    } finally {
        header("Location: manage_partnerships.php");
        exit;
    }
}

// Get all partnerships
$stmt = $pdo->query("SELECT * FROM partnership ORDER BY nama");
$partnerships = $stmt->fetchAll();

// Get data for edit
$edit_data = null;
if (isset($_GET['edit'])) {
    $uuid = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM partnership WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $edit_data = $stmt->fetch();
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
            <?php echo $edit_data ? 'Edit' : 'Tambah'; ?> Partnership
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
                <div class="col-md-6 mb-3">
                    <label class="form-label">Nama Mitra/Partner <span class="text-danger">*</span></label>
                    <input type="text"
                        name="nama"
                        class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['nama']) : ''; ?>"
                        placeholder="Contoh: PT Telkom Indonesia, Google Cloud"
                        required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Website</label>
                    <input type="url"
                        name="website"
                        class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['website']) : ''; ?>"
                        placeholder="https://...">
                    <small class="text-muted">URL website partner (opsional)</small>
                </div>

                <div class="col-12 mb-3">
                    <label class="form-label">Logo Mitra</label>
                    <input type="file"
                        name="logo"
                        class="form-control"
                        accept="image/*"
                        onchange="previewImage(this, 'preview')">
                    <small class="text-muted">Max 2MB. Rekomendasi: PNG transparan, ukuran 300x150px untuk hasil terbaik</small>

                    <?php if ($edit_data && $edit_data['logo']): ?>
                        <div class="mt-2 p-3 bg-light text-center rounded">
                            <img src="../assets/img/<?php echo htmlspecialchars($edit_data['logo']); ?>"
                                id="preview"
                                class="img-thumbnail"
                                style="max-height: 100px; max-width: 200px; object-fit: contain;">
                        </div>
                    <?php else: ?>
                        <div class="mt-2 p-3 bg-light text-center rounded" id="previewContainer" style="display: none;">
                            <img id="preview" class="img-thumbnail" style="max-height: 100px; max-width: 200px; object-fit: contain;">
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Simpan
                </button>
                <?php if ($edit_data): ?>
                    <a href="manage_partnerships.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>Batal
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Daftar Partnership -->
<div class="card shadow-sm border-0 animate__animated animate__fadeInUp container-fluid px-4">
    <div class="card-header bg-white d-flex">
        <h5 class="mb-0 mt-1 fw-bold">
            <i class="bi bi-list-ul me-2"></i>Daftar Partnership
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($partnerships)): ?>
            <div class="card shadow-sm border-0 text-center animate__animated animate__fadeInUp">
                <div class="card-body py-5">
                    <i class="bi bi-emoji-frown text-info" style="font-size: 3rem;"></i>
                    <h5 class="mt-3 text-muted">Belum ada partner</h5>
                    <p class="text-secondary small">Yuk tambahkan partner baru untuk ditampilkan di sini!</p>
                </div>
            <?php else: ?>
            </div>
            <link rel="stylesheet" href="../assets/css/swipejs.css">
            <div class="swipeable-table" id="swipeTable">
                <form method="POST" id="bulkDeleteForm" action="">
                    <!-- Penambahan action form -->
                    <input type="hidden" name="action" value="bulk_delete">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th width="10">
                                    <input type="checkbox" id="selectAll">
                                </th>
                                <th width="10">No</th>
                                <th width="25">Logo</th>
                                <th width="200">Nama Partner</th>
                                <th width="100">Website</th>
                                <th width="100">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($partnerships as $index => $partner): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected[]" value="<?= $partner['uuid']; ?>" class="rowCheckbox">
                                    </td>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php if ($partner['logo']): ?>
                                            <img src="../assets/img/<?php echo htmlspecialchars($partner['logo']); ?>"
                                                alt="<?php echo htmlspecialchars($partner['nama']); ?>"
                                                style="max-height: 50px; max-width: 100px; object-fit: contain; border-radius: 5px;">
                                        <?php else: ?>
                                            <div class="bg-light p-2 rounded text-center" style="width: 100px; height: 50px; border-radius: 5px;">
                                                <i class="bi bi-building text-muted" style="font-size: 1.5rem;"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($partner['nama']); ?></strong></td>
                                    <td>
                                        <?php if ($partner['website']): ?>
                                            <a href="<?php echo htmlspecialchars($partner['website']); ?>"
                                                target="_blank"
                                                class="text-primary small">
                                                <i class="bi bi-link-45deg"></i> Visit Website
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="?edit=<?php echo $partner['uuid']; ?>"
                                            class="btn btn-sm btn-warning"
                                            title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="?delete=<?php echo $partner['uuid']; ?>"
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

<script>
    function previewImage(input, previewId) {
        const preview = document.getElementById(previewId);
        const container = document.getElementById('previewContainer');

        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                if (container) {
                    container.style.display = 'block';
                } else {
                    preview.style.display = 'block';
                }
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const successMessage = "<?= addslashes($success ?? '') ?>";
        const errorMessage = "<?= addslashes($error ?? '') ?>";

        if (successMessage) showSuccess(successMessage);
        if (errorMessage) showError(errorMessage);
    });
</script>