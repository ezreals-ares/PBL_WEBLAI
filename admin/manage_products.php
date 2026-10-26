<?php
ob_start();
$page_title = 'Kelola Produk';
include 'includes/auth.php';
include 'includes/admin_header.php';

$success = '';
$error = '';

// Handle Delete
if (isset($_GET['delete'])) {
    $uuid = $_GET['delete'];
    try {
        // Get image path to delete file
        $stmt = $pdo->prepare("SELECT path_gambar FROM produk WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $product = $stmt->fetch();

        if ($product && $product['path_gambar']) {
            delete_image($product['path_gambar']);
        }

        // Delete akan cascade ke anggota_produk
        $stmt = $pdo->prepare("DELETE FROM produk WHERE uuid = ?");
        $stmt->execute([$uuid]);
        $_SESSION['flash_success'] = 'Produk berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus produk: ' . $e->getMessage();
    } finally {
        header("Location: manage_products.php");
        exit;
    }
}

// Handle Bulk Delete
if (($_POST['action'] ?? '') === 'bulk_delete' && !empty($_POST['selected'])) {
    $uuids = $_POST['selected'];

    try {
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $query = "DELETE FROM produk WHERE uuid IN ($placeholders)";
        $stmt = $pdo->prepare($query);
        $stmt->execute($uuids);

        $_SESSION['flash_success'] = count($uuids) . ' produk berhasil dihapus!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Gagal menghapus beberapa produk: ' . $e->getMessage();
    } finally {
        header("Location: manage_products.php");
        exit;
    }
}

// Handle Insert/Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') === 'save') {
    $nama = clean_input($_POST['nama'] ?? '');
    $tahun = clean_input($_POST['tahun'] ?? '');
    $pembuat_ids = $_POST['pembuat_id'] ?? []; // Array of UUIDs
    $deskripsi = clean_input($_POST['deskripsi'] ?? '');
    $link_demo = clean_input($_POST['link_demo'] ?? '');

    try {
        $pdo->beginTransaction();

        $path_gambar = null;

        // Handle file upload
        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] == 0) {
            $upload_result = upload_file($_FILES['gambar']);
            if ($upload_result['success']) {
                $path_gambar = $upload_result['filename'];
            } else {
                throw new Exception($upload_result['message']);
            }
        }

        if (isset($_POST['uuid']) && !empty($_POST['uuid'])) {
            // Update
            $uuid = $_POST['uuid'];

            // Delete old image if new one uploaded
            if ($path_gambar) {
                $stmt = $pdo->prepare("SELECT path_gambar FROM produk WHERE uuid = ?");
                $stmt->execute([$uuid]);
                $old = $stmt->fetch();
                if ($old && $old['path_gambar']) {
                    delete_image($old['path_gambar']);
                }
            }

            if ($path_gambar) {
                $stmt = $pdo->prepare("UPDATE produk SET nama = ?, tahun = ?, deskripsi = ?, link_demo = ?, path_gambar = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?");
                $stmt->execute([$nama, $tahun, $deskripsi, $link_demo, $path_gambar, $uuid]);
            } else {
                $stmt = $pdo->prepare("UPDATE produk SET nama = ?, tahun = ?, deskripsi = ?, link_demo = ?, updated_at = CURRENT_TIMESTAMP WHERE uuid = ?");
                $stmt->execute([$nama, $tahun, $deskripsi, $link_demo, $uuid]);
            }

            // Delete existing pembuat relations
            $stmt = $pdo->prepare("DELETE FROM anggota_produk WHERE produk_uuid = ?");
            $stmt->execute([$uuid]);
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO produk (nama, tahun, deskripsi, link_demo, path_gambar) VALUES (?, ?, ?, ?, ?) RETURNING uuid");
            $stmt->execute([$nama, $tahun, $deskripsi, $link_demo, $path_gambar]);
            $uuid = $stmt->fetchColumn();
        }

        // Insert pembuat relations
        if (!empty($pembuat_ids)) {
            $stmt = $pdo->prepare("INSERT INTO anggota_produk (anggota_uuid, produk_uuid) VALUES (?, ?)");
            foreach ($pembuat_ids as $anggota_uuid) {
                if (!empty($anggota_uuid)) {
                    $stmt->execute([$anggota_uuid, $uuid]);
                }
            }
        }

        $pdo->commit();
        $_SESSION['flash_success'] = isset($_POST['uuid']) ? 'Produk berhasil diperbarui!' : 'Produk berhasil ditambahkan!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_error'] = 'Terjadi kesalahan: ' . $e->getMessage();
    } finally {
        header("Location: manage_products.php");
        exit;
    }
}

// Get all products with authors
$stmt = $pdo->query("
    SELECT p.*, 
           STRING_AGG(DISTINCT a.nama, ', ' ORDER BY a.nama) as pembuat_nama,
           ARRAY_AGG(DISTINCT a.uuid) as pembuat_ids
    FROM produk p 
    LEFT JOIN anggota_produk ap ON p.uuid = ap.produk_uuid
    LEFT JOIN anggota a ON ap.anggota_uuid = a.uuid 
    GROUP BY p.uuid
    ORDER BY p.created_at DESC
");
$products = $stmt->fetchAll();

// Get all members for dropdown
$stmt_members = $pdo->query("SELECT uuid, nama FROM anggota ORDER BY nama");
$members = $stmt_members->fetchAll();

// Get data for edit
$edit_data = null;
$edit_pembuat_ids = [];
if (isset($_GET['edit'])) {
    $uuid = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM produk WHERE uuid = ?");
    $stmt->execute([$uuid]);
    $edit_data = $stmt->fetch();

    // Get pembuat IDs
    $stmt = $pdo->prepare("SELECT anggota_uuid FROM anggota_produk WHERE produk_uuid = ?");
    $stmt->execute([$uuid]);
    $edit_pembuat_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
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
            <?php echo $edit_data ? 'Edit' : 'Tambah'; ?> Produk
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <?php if ($edit_data): ?>
                <input type="hidden" name="uuid" value="<?php echo $edit_data['uuid']; ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8 mb-3">
                    <label class="form-label">Nama Produk <span class="text-danger">*</span></label>
                    <input type="text" name="nama" class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['nama']) : ''; ?>"
                        placeholder="Nama aplikasi/sistem" required>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Tahun <span class="text-danger">*</span></label>
                    <input type="number" name="tahun" class="form-control"
                        min="2000" max="<?php echo date('Y'); ?>"
                        value="<?php echo $edit_data ? $edit_data['tahun'] : date('Y'); ?>" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Pembuat (dapat pilih lebih dari 1)</label>
                    <select name="pembuat_id[]" class="form-select select-enhanced" multiple>
                        <?php foreach ($members as $member): ?>
                            <option value="<?php echo $member['uuid']; ?>"
                                <?php echo in_array($member['uuid'], $edit_pembuat_ids) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($member['nama']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Tekan Ctrl/Cmd untuk pilih lebih dari satu</small>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">Link Demo</label>
                    <input type="url" name="link_demo" class="form-control"
                        value="<?php echo $edit_data ? htmlspecialchars($edit_data['link_demo']) : ''; ?>"
                        placeholder="https://...">
                </div>

                <div class="col-12 mb-3">
                    <label class="form-label">Deskripsi <span class="text-danger">*</span></label>
                    <textarea name="deskripsi" class="form-control" rows="3" required><?php echo $edit_data ? htmlspecialchars($edit_data['deskripsi']) : ''; ?></textarea>
                </div>

                <div class="col-12 mb-3">
                    <label class="form-label">Screenshot/Gambar</label>
                    <input type="file" name="gambar" class="form-control" accept="image/*" onchange="previewImage(this, 'preview')">
                    <small class="text-muted">Max 2MB, rekomendasi: 800x600px</small>

                    <?php if ($edit_data && $edit_data['path_gambar']): ?>
                        <div class="mt-2">
                            <img src="../assets/img/<?php echo htmlspecialchars($edit_data['path_gambar']); ?>"
                                id="preview" class="img-thumbnail" style="max-width: 300px;">
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
                    <a href="manage_products.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>Batal
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Daftar Produk -->
<div class="card shadow-sm border-0 animate__animated animate__fadeInUp">
    <div class="card-header bg-white">
        <h5 class="mb-0 fw-bold">
            <i class="bi bi-list-ul me-2"></i>Daftar Produk
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($products)): ?>
            <div class="card shadow-sm border-0 text-center animate__animated animate__fadeInUp">
                <div class="card-body py-5">
                    <i class="bi bi-emoji-frown text-info" style="font-size: 3rem;"></i>
                    <h5 class="mt-3 text-muted">Belum ada produk</h5>
                    <p class="text-secondary small">Yuk tambahkan produk baru untuk ditampilkan di sini!</p>
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
                                <th width="100">Gambar</th>
                                <th>Nama Produk</th>
                                <th width="80">Tahun</th>
                                <th width="200">Pembuat</th>
                                <th width="150">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $index => $product): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected[]" value="<?= $product['uuid']; ?>" class="rowCheckbox">
                                    </td>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <?php if ($product['path_gambar']): ?>
                                            <img src="../assets/img/<?php echo htmlspecialchars($product['path_gambar']); ?>"
                                                style="width: 80px; height: 60px; object-fit: cover; border-radius: 5px;">
                                        <?php else: ?>
                                            <div class="bg-light d-flex align-items-center justify-content-center"
                                                style="width: 80px; height: 60px; border-radius: 5px;">
                                                <i class="bi bi-image text-muted"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($product['nama']); ?></strong><br>
                                        <small class="text-muted"><?php echo substr(htmlspecialchars($product['deskripsi']), 0, 60) . '...'; ?></small>
                                    </td>
                                    <td><?php echo $product['tahun']; ?></td>
                                    <td>
                                        <?php if ($product['pembuat_nama']): ?>
                                            <?php
                                            $pembuats = explode(', ', $product['pembuat_nama']);
                                            foreach ($pembuats as $pembuat):
                                            ?>
                                                <span class="badge bg-secondary mb-1"><?php echo htmlspecialchars($pembuat); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($product['link_demo']): ?>
                                            <a href="<?php echo htmlspecialchars($product['link_demo']); ?>"
                                                target="_blank" class="btn btn-sm btn-info" title="Demo">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?edit=<?php echo $product['uuid']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="?delete=<?php echo $product['uuid']; ?>" class="btn btn-sm btn-danger"
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