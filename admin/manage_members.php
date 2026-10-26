<?php
ob_start();
$page_title = 'Kelola Anggota Tim';
include 'includes/auth.php';
include 'includes/admin_header.php';

$success = '';
$error = '';

// Handle Delete
if (isset($_GET['delete'])) {
    $uuid = $_GET['delete'];
    try {
        // Get image path to delete file
        $stmt = $pdo->prepare("SELECT path_gambar FROM anggota WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $member = $stmt->fetch();

        if ($member && $member['path_gambar']) {
            delete_image($member['path_gambar']);
        }

        $stmt = $pdo->prepare("DELETE FROM anggota WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $_SESSION['flash_success'] = "Anggota berhasil dihapus!";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Gagal menghapus anggota: " . $e->getMessage();
    } finally {
        header("Location: manage_members.php");
        exit;
    }
}
// Handle Bulk Delete
if (($_POST['action'] ?? '') === 'bulk_delete' && !empty($_POST['selected'])) {
    $uuids = $_POST['selected'];

    try {
        // Buat placeholder dinamis sebanyak jumlah UUID
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $query = "DELETE FROM anggota WHERE uuid IN ($placeholders)";
        $stmt = $pdo->prepare($query);

        // Eksekusi semua UUID
        $stmt->execute($uuids);

        $_SESSION['flash_success'] = count($uuids) . " Anggota berhasil dihapus!";
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Gagal menghapus beberapa anggota: " . $e->getMessage();
    } finally {
        header("Location: manage_members.php");
        exit;
    }
}
// Handle Insert/Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'save') {
    $nama = clean_input($_POST['nama'] ?? '');
    $nidn = clean_input($_POST['nidn'] ?? '');
    $jabatan = clean_input($_POST['jabatan'] ?? '');
    $status = clean_input($_POST['status'] ?? '');
    $keahlian = clean_input($_POST['keahlian'] ?? '');


    try {
        $path_gambar = null;

        // Handle file upload
        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] == 0) {
            $upload_result = upload_file($_FILES['gambar']);
            if ($upload_result['success']) {
                $path_gambar = $upload_result['filename'];
            } else {
                $_SESSION['flash_error'] = "Gagal mengupload gambar: " . $upload_result['message'];
            }
        }
        // Only proceed if no upload error
        if (!isset($_SESSION['flash_error'])) {
            // Check if updating or inserting
            if (isset($_POST['uuid']) && !empty($_POST['uuid'])) {
                // Update
                $uuid = $_POST['uuid'];

                // Delete old image if new one uploaded
                if ($path_gambar) {
                    $stmt = $pdo->prepare("SELECT path_gambar FROM anggota WHERE uuid = ?");
                    $stmt->execute([$uuid]);
                    $old = $stmt->fetch();
                    if ($old && $old['path_gambar']) {
                        delete_image($old['path_gambar']);
                    }
                }

                if ($path_gambar) {
                    $stmt = $pdo->prepare("UPDATE anggota SET nama = ?, nidn = ?, jabatan = ?, status = ?, path_gambar = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?");
                    $stmt->execute([$nama, $nidn, $jabatan, $status, $path_gambar, $uuid]);
                } else {
                    $stmt = $pdo->prepare("UPDATE anggota SET nama = ?, nidn = ?, jabatan = ?, keahlian= ? , status = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?");
                    $stmt->execute([$nama, $nidn, $jabatan, $keahlian, $status, $uuid]);
                }
                $_SESSION['flash_success'] = "Anggota berhasil diperbarui!";
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO anggota (nama, nidn, jabatan, keahlian, status, path_gambar) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nama, $nidn, $jabatan, $keahlian, $status, $path_gambar]);
                $_SESSION['flash_success'] = "Anggota baru berhasil ditambahkan!";
            }
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = "Gagal menyimpan anggota: " . $e->getMessage();
    } finally {
        header("Location: manage_members.php");
        exit;
    }
}

// Get all members
$stmt = $pdo->query("SELECT * FROM anggota where jabatan is not null ORDER BY jabatan, nama  ");
$members = $stmt->fetchAll();

// Get data for edit
$edit_data = null;
if (isset($_GET['edit'])) {
    $uuid = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM anggota WHERE uuid = ?");
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
            <?php echo $edit_data ? 'Edit' : 'Tambah'; ?> Anggota
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
                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                    <input type="text" name="nama" class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['nama']) : ''; ?>"
                        placeholder="Nama lengkap dengan gelar" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">NIDN</label>
                    <input type="text" name="nidn" class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['nidn']) : ''; ?>"
                        placeholder="Nomor Induk Dosen Nasional">
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Jabatan <span class="text-danger">*</span></label>
                    <select name="jabatan" class="form-select select-enhanced" required>
                        <option value="">Pilih Jabatan</option>
                        <option value="ketua"
                            <?php echo ($edit_data && $edit_data['jabatan'] == 'ketua') ? 'selected' : ''; ?>>Ketua
                        </option>
                        <option value="anggota"
                            <?php echo ($edit_data && $edit_data['jabatan'] == 'anggota') ? 'selected' : ''; ?>>Anggota
                        </option>
                    </select>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Keahlian (pisahkan dengan koma)</label>
                    <input type="text" name="keahlian" class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['keahlian'] ?? '') : ''; ?>"
                        placeholder="Contoh: AI, Machine Learning, Data Mining">
                </div>


                <div class="col-md-6 mb-3">
                    <label class="form-label">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select select-enhanced" required>
                        <option value="">Pilih Status</option>
                        <option value="dosen"
                            <?php echo ($edit_data && $edit_data['status'] == 'dosen') ? 'selected' : ''; ?>>Dosen
                        </option>
                        <option value="mahasiswa"
                            <?php echo ($edit_data && $edit_data['status'] == 'mahasiswa') ? 'selected' : ''; ?>>
                            Mahasiswa</option>
                    </select>
                </div>

                <div class="col-12 mb-3">
                    <label class="form-label">Foto</label>
                    <input type="file" name="gambar" class="form-control" accept="image/*"
                        onchange="previewImage(this, 'preview')">
                    <small class="text-muted">Max 2MB. Rekomendasi: Foto formal rasio 1:1 (500x500px)</small>

                    <?php if ($edit_data && $edit_data['path_gambar']): ?>
                        <div class="mt-2">
                            <img src="../assets/img/<?php echo htmlspecialchars($edit_data['path_gambar']); ?>" id="preview"
                                class="img-thumbnail rounded-circle"
                                style="width: 120px; height: 120px; object-fit: cover;">
                        </div>
                    <?php else: ?>
                        <img id="preview" class="img-thumbnail rounded-circle mt-2"
                            style="width: 120px; height: 120px; object-fit: cover; display: none;">
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Simpan
                </button>
                <?php if ($edit_data): ?>
                    <a href="manage_members.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>Batal
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Daftar Anggota Tim -->
<div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
    <div class="card-header bg-white">
        <h5 class="mb-0 fw-bold">
            <i class="bi bi-list-ul me-2"></i>Daftar Anggota Tim
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($members)): ?>
            <div class="card shadow-sm border-0 text-center animate__animated animate__fadeInUp">
                <div class="card-body py-5">
                    <i class="bi bi-emoji-frown text-info" style="font-size: 3rem;"></i>
                    <h5 class="mt-3 text-muted">Belum ada anggota</h5>
                    <p class="text-secondary small">Yuk tambahkan anggota baru untuk ditampilkan di sini!</p>
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
                                <th width="80">Foto</th>
                                <th>Nama</th>
                                <th width="120">NIDN</th>
                                <th width="100">Jabatan</th>
                                <th>Keahlian</th>
                                <th width="100">Status</th>
                                <th width="120">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $index => $member): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected[]" value="<?= $member['uuid']; ?>"
                                            class="rowCheckbox">
                                    </td>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php if ($member['path_gambar']): ?>
                                            <img src="../assets/img/<?php echo htmlspecialchars($member['path_gambar']); ?>"
                                                class="rounded-circle" width="50" height="50" style="object-fit: cover;">
                                        <?php else: ?>
                                            <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center"
                                                style="width: 50px; height: 50px;">
                                                <i class="bi bi-person text-white"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($member['nama']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($member['nidn']); ?></td>
                                    <td>
                                        <span
                                            class="badge bg-<?php echo $member['jabatan'] == 'ketua' ? 'primary' : 'secondary'; ?>">

                                            <?php echo ucfirst($member['jabatan'] ?? ''); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($member['keahlian'] ?? ''); ?></td>

                                    <td>
                                        <span class="badge bg-<?php echo $member['status'] == 'dosen' ? 'success' : 'info'; ?>">
                                            <?php echo ucfirst($member['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?edit=<?php echo $member['uuid']; ?>" class="btn btn-sm btn-warning"
                                            title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="?delete=<?php echo $member['uuid']; ?>" class="btn btn-sm btn-danger"
                                            onclick="return confirmDelete();" title="Hapus">
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