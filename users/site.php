<?php
require '../config/db.php';
require '../config/auth.php';
require '../lib/Validator.php';
require '../lib/Sanitizer.php';
require '../notif/notification.php';

// enforce user login
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// Get current user's personnel_id for ownership filtering
$stmt = $pdo->prepare("SELECT personnel_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: ../index.php');
    exit;
}
$_SESSION['personnel_id'] = $user['personnel_id'];
$personnelId = $user['personnel_id'];

// preload all locations for client-side filtering
// the dropdowns still use the locations table but sites now store province/municipality text directly
$allLocStmt = $pdo->query("SELECT id, province, municipality FROM locations ORDER BY province, municipality");
$allLocations = $allLocStmt->fetchAll(PDO::FETCH_ASSOC);
// compute unique provinces for autocomplete
$provinces = [];
foreach ($allLocations as $l) {
    if (!in_array($l['province'], $provinces)) {
        $provinces[] = $l['province'];
    }
}
sort($provinces);
// autocomplete sources from user's own site data
$projStmt = $pdo->prepare("SELECT DISTINCT project_name FROM sites WHERE created_by = ? AND project_name IS NOT NULL AND project_name != '' ORDER BY project_name");
$projStmt->execute([$personnelId]);
$allProjects = $projStmt->fetchAll(PDO::FETCH_COLUMN);
$ispStmt = $pdo->prepare("SELECT DISTINCT isp FROM sites WHERE created_by = ? AND isp IS NOT NULL AND isp != '' ORDER BY isp");
$ispStmt->execute([$personnelId]);
$allISP = $ispStmt->fetchAll(PDO::FETCH_COLUMN);

$locNameStmt = $pdo->prepare("SELECT DISTINCT location_name FROM sites WHERE created_by = ? AND location_name IS NOT NULL AND location_name != '' ORDER BY location_name");
$locNameStmt->execute([$personnelId]);
$allLocationNames = $locNameStmt->fetchAll(PDO::FETCH_COLUMN);
$siteNameStmt = $pdo->prepare("SELECT DISTINCT site_name FROM sites WHERE created_by = ? AND site_name IS NOT NULL AND site_name != '' ORDER BY site_name");
$siteNameStmt->execute([$personnelId]);
$allSiteNames = $siteNameStmt->fetchAll(PDO::FETCH_COLUMN);
$apCodeStmt = $pdo->prepare("SELECT DISTINCT ap_site_code FROM sites WHERE created_by = ? AND ap_site_code IS NOT NULL AND ap_site_code != '' ORDER BY ap_site_code");
$apCodeStmt->execute([$personnelId]);
$allApCodes = $apCodeStmt->fetchAll(PDO::FETCH_COLUMN);
$munStmt = $pdo->prepare("SELECT DISTINCT municipality FROM sites WHERE created_by = ? AND municipality IS NOT NULL AND municipality != '' ORDER BY municipality");
$munStmt->execute([$personnelId]);
$allMunicipalities = $munStmt->fetchAll(PDO::FETCH_COLUMN);
$provStmt = $pdo->prepare("SELECT DISTINCT province FROM sites WHERE created_by = ? AND province IS NOT NULL AND province != '' ORDER BY province");
$provStmt->execute([$personnelId]);
$allProvinces = $provStmt->fetchAll(PDO::FETCH_COLUMN);

$action = $_POST['action'] ?? '';
// status selections reused throughout
$statusOptions = ['REBATES','ONGOING','EXPIRED','REMOVED','ACTIVE','SUPPORT'];
// mapping for color codes (used by helper)
$statusColors = [
    'REBATES' => 'violet',
    'ONGOING' => 'blue',
    'EXPIRED' => 'red',
    'REMOVED' => 'maroon',
    'ACTIVE'  => 'green',
    'SUPPORT' => 'white',
];
// helper to render status with colored badge
function statusBadge($status) {
    global $statusColors;
    $color = $statusColors[$status] ?? 'gray';
    $textColor = $color === 'white' ? 'black' : 'white';
    return "<span class='badge' style='background-color:{$color}; color:{$textColor};'>" . htmlspecialchars($status) . "</span>";
}

// duplicate checker used by both single and bulk operations - scoped to current user
function isDuplicateSite($project, $location, $siteName, $apSiteCode, $province, $municipality, $excludeId = null, $personnelId = null) {
    global $pdo;
    $sql = "SELECT COUNT(*) FROM sites WHERE project_name=? AND location_name=? AND site_name=? AND ap_site_code=? AND province=? AND municipality=? AND created_by=?";
    if ($excludeId !== null) {
        $sql .= " AND id<>?";
    }
    $stmt = $pdo->prepare($sql);
    if ($excludeId !== null) {
        $stmt->execute([$project, $location, $siteName, $apSiteCode, $province, $municipality, $personnelId, $excludeId]);
    } else {
        $stmt->execute([$project, $location, $siteName, $apSiteCode, $province, $municipality, $personnelId]);
    }
    return $stmt->fetchColumn() > 0;
}
// handlers
if ($action == 'add_form') {
    // pull list of locations for dropdown (used later by client JS)
    $locStmt = $pdo->query("SELECT id, province, municipality FROM locations ORDER BY province, municipality");
    $locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="card">
      <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Add New Site</h5>
        <button type="button" class="btn-close btn-close-white" onclick="closePanel()"></button>
      </div>
      <div class="card-body">
        <form id="addSiteForm" onsubmit="saveSite(event)">
          <div class="mb-3">
            <label class="form-label">Project Name</label>
            <input type="text" name="project_name" class="form-control" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Location Name</label>
            <input type="text" name="location_name" class="form-control" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Site Name</label>
            <input type="text" name="site_name" class="form-control" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">AP Site Code</label>
            <input type="text" name="ap_site_code" class="form-control" required maxlength="50">
          </div>
          <div class="mb-3">
            <label class="form-label">ISP</label>
            <input type="text" name="isp" class="form-control" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" required>
              <option value="">Select Status</option>
              <?php foreach ($statusOptions as $st): ?>
                <option value="<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars($st); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Province</label>
            <input type="text" name="province" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Municipality</label>
            <input type="text" name="municipality" class="form-control" required>
          </div>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
          <button type="submit" class="btn btn-success w-100">Save Site</button>
        </form>
      </div>
    </div>
    <?php
    exit;
}

elseif ($action == 'view_details') {
    $id = $_POST['id'];
    // sites table stores province/municipality directly - verify ownership
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ? AND created_by = ?");
    $stmt->execute([$id, $personnelId]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);
    ?>
    <div class="card">
      <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Site Details</h5>
        <button type="button" class="btn-close btn-close-white" onclick="closePanel()"></button>
      </div>
      <div class="card-body">
        <p><strong>Project Name:</strong> <?php echo htmlspecialchars($site['project_name']); ?></p>
        <p><strong>Status:</strong> <?php echo statusBadge($site['status'] ?? ''); ?></p>
        <p><strong>Location Name:</strong> <?php echo htmlspecialchars($site['location_name']); ?></p>
        <p><strong>Site Name:</strong> <?php echo htmlspecialchars($site['site_name']); ?></p>
        <p><strong>AP Site Code:</strong> <?php echo htmlspecialchars($site['ap_site_code']); ?></p>
        <p><strong>ISP:</strong> <?php echo htmlspecialchars($site['isp']); ?></p>
        <p><strong>Province:</strong> <?php echo htmlspecialchars($site['province']); ?></p>
        <p><strong>Municipality:</strong> <?php echo htmlspecialchars($site['municipality']); ?></p>
        <button class="btn btn-warning w-100" onclick="loadEditForm(<?php echo $site['id']; ?>)">Edit</button>
      </div>
    </div>
    <?php
    exit;
}

elseif ($action == 'edit_form') {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ? AND created_by = ?");
    $stmt->execute([$id, $personnelId]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);
    // site now stores province and municipality directly
    $currentProvince = $site['province'] ?? '';
    $currentMunicipality = $site['municipality'] ?? '';
    // load locations list for dropdown
    $locStmt = $pdo->query("SELECT id, province, municipality FROM locations ORDER BY province, municipality");
    $locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);
    // compute unique provinces
    $provinces = [];
    foreach ($locations as $l) {
        if (!in_array($l['province'], $provinces)) {
            $provinces[] = $l['province'];
        }
    }
    ?>
    <div class="card">
      <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Edit Site</h5>
        <button type="button" class="btn-close" onclick="closePanel()"></button>
      </div>
      <div class="card-body">
        <form id="editSiteForm" onsubmit="updateSite(event)">
          <input type="hidden" name="id" value="<?php echo $site['id']; ?>">
          <!-- datalist only for provinces (we keep this for locations) -->
          <datalist id="provinceList">
            <?php foreach ($provinces as $prov): ?>
              <option value="<?php echo htmlspecialchars($prov); ?>">
            <?php endforeach; ?>
          </datalist>
          <datalist id="projectList">
            <?php foreach ($allProjects as $proj): ?>
              <option value="<?php echo htmlspecialchars($proj); ?>">
            <?php endforeach; ?>
          </datalist>
          <div class="mb-3">
            <label class="form-label">Project Name</label>
            <input type="text" name="project_name" class="form-control" value="<?php echo htmlspecialchars($site['project_name']); ?>" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Location Name</label>
            <input type="text" name="location_name" class="form-control" value="<?php echo htmlspecialchars($site['location_name']); ?>" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Site Name</label>
            <input type="text" name="site_name" class="form-control" value="<?php echo htmlspecialchars($site['site_name']); ?>" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">AP Site Code</label>
            <input type="text" name="ap_site_code" class="form-control" value="<?php echo htmlspecialchars($site['ap_site_code']); ?>" required maxlength="50">
          </div>
          <div class="mb-3">
            <label class="form-label">ISP</label>
            <input type="text" name="isp" class="form-control" value="<?php echo htmlspecialchars($site['isp']); ?>" required maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" required>
              <option value="">Select Status</option>
              <?php foreach ($statusOptions as $st): ?>
                <option value="<?php echo htmlspecialchars($st); ?>" <?php if($st === $site['status']) echo 'selected'; ?>><?php echo htmlspecialchars($st); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Province</label>
            <input type="text" name="province" class="form-control" value="<?php echo htmlspecialchars($currentProvince); ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Municipality</label>
            <input type="text" name="municipality" class="form-control" value="<?php echo htmlspecialchars($currentMunicipality); ?>" required>
          </div>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
          <button type="submit" class="btn btn-primary w-100">Update Site</button>
        </form>
      </div>
    </div>
    <?php
    exit;
}

elseif ($action == 'save') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) { echo 'Error: Invalid security token.'; exit; }
    try {
        $project = trim($_POST['project_name'] ?? '');
        $location = trim($_POST['location_name'] ?? '');
        $site = trim($_POST['site_name'] ?? '');
        $apSiteCode = trim($_POST['ap_site_code'] ?? '');
        $isp = trim($_POST['isp'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $municipality = trim($_POST['municipality'] ?? '');
        if (!Validator::string($project,1,150)) { echo 'Error: Invalid project name.'; exit; }
        if (!Validator::string($location,1,150)) { echo 'Error: Invalid location name.'; exit; }
        if (!Validator::string($site,1,150)) { echo 'Error: Invalid site name.'; exit; }
        if (!Validator::string($apSiteCode,1,50)) { echo 'Error: Invalid AP Site Code.'; exit; }
        if (!Validator::string($isp,1,150)) { echo 'Error: Invalid ISP.'; exit; }
        if (!Validator::inList($status, $statusOptions)) { echo 'Error: Invalid status.'; exit; }
        if (!Validator::string($province,1,150)) { echo 'Error: Invalid province.'; exit; }
        if (!Validator::string($municipality,1,150)) { echo 'Error: Invalid municipality.'; exit; }
        // check for duplicate (scoped to current user)
        if (isDuplicateSite($project, $location, $site, $apSiteCode, $province, $municipality, null, $personnelId)) {
            echo 'Error: Duplicate site record.'; exit;
        }
        $project = Sanitizer::normalize($project);
        $location = Sanitizer::normalize($location);
        $site = Sanitizer::normalize($site);
        $apSiteCode = Sanitizer::normalize($apSiteCode);
        $isp = Sanitizer::normalize($isp);
        $province = Sanitizer::normalize($province);
        $municipality = Sanitizer::normalize($municipality);
        
        // Use current user's personnel_id as creator
        $stmt = $pdo->prepare("INSERT INTO sites (project_name, location_name, site_name, ap_site_code, isp, status, province, municipality, created_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$project,$location,$site,$apSiteCode,$isp,$status,$province,$municipality,$personnelId]);
        
        // Get inserted site ID for notifications
        $siteId = $pdo->lastInsertId();
        
        // Send notification
        $creatorStmt = $pdo->prepare("SELECT p.id, p.fullname FROM personnels p JOIN users u ON p.id = u.personnel_id WHERE u.id = ?");
        $creatorStmt->execute([$_SESSION['user_id']]);
        $creator = $creatorStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($creator) {
            $notifier = new NotificationManager($pdo);
            $notifier->notifySiteCreated($siteId, $creator);
        }
        
        echo 'success';
    } catch (PDOException $e) {
        echo 'Error: Database error.'; error_log('Site save error: '.$e->getMessage());
    }
    exit;
}

elseif ($action == 'update') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) { echo 'Error: Invalid security token.'; exit; }
    try {
        $id = $_POST['id'] ?? '';
        if (!Validator::positiveInteger($id)) { echo 'Error: Invalid ID.'; exit; }
        
        // Verify user owns this site before updating
        $checkStmt = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND created_by = ?");
        $checkStmt->execute([$id, $personnelId]);
        if (!$checkStmt->fetch()) {
            echo 'Error: Access denied.'; exit;
        }
        $project = trim($_POST['project_name'] ?? '');
        $location = trim($_POST['location_name'] ?? '');
        $site = trim($_POST['site_name'] ?? '');
        $apSiteCode = trim($_POST['ap_site_code'] ?? '');
        $isp = trim($_POST['isp'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $municipality = trim($_POST['municipality'] ?? '');
        if (!Validator::string($project,1,150)) { echo 'Error: Invalid project name.'; exit; }
        if (!Validator::string($location,1,150)) { echo 'Error: Invalid location name.'; exit; }
        if (!Validator::string($site,1,150)) { echo 'Error: Invalid site name.'; exit; }
        if (!Validator::string($apSiteCode,1,50)) { echo 'Error: Invalid AP Site Code.'; exit; }
        if (!Validator::string($isp,1,150)) { echo 'Error: Invalid ISP.'; exit; }
        if (!Validator::inList($status, $statusOptions)) { echo 'Error: Invalid status.'; exit; }
        if (!Validator::string($province,1,150)) { echo 'Error: Invalid province.'; exit; }
        if (!Validator::string($municipality,1,150)) { echo 'Error: Invalid municipality.'; exit; }
        // check duplicates excluding current record (scoped to current user)
        if (isDuplicateSite($project, $location, $site, $apSiteCode, $province, $municipality, $id, $personnelId)) {
            echo 'Error: Duplicate site record.'; exit;
        }
        $project = Sanitizer::normalize($project);
        $location = Sanitizer::normalize($location);
        $site = Sanitizer::normalize($site);
        $apSiteCode = Sanitizer::normalize($apSiteCode);
        $isp = Sanitizer::normalize($isp);
        $province = Sanitizer::normalize($province);
        $municipality = Sanitizer::normalize($municipality);
        $stmt = $pdo->prepare("UPDATE sites SET project_name=?, location_name=?, site_name=?, ap_site_code=?, isp=?, status=?, province=?, municipality=? WHERE id=?");
        $stmt->execute([$project,$location,$site,$apSiteCode,$isp,$status,$province,$municipality,$id]);
        echo 'success';
    } catch (PDOException $e) {
        echo 'Error: Database error.'; error_log('Site update error: '.$e->getMessage());
    }
    exit;
}

elseif ($action == 'delete') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) { echo 'Error: Invalid security token.'; exit; }
    $id = $_POST['id'] ?? '';
    if (!Validator::positiveInteger($id)) { echo 'Error: Invalid ID.'; exit; }
    try {
        // Only delete if user owns this site
        $stmt = $pdo->prepare("DELETE FROM sites WHERE id=? AND created_by=?");
        $stmt->execute([$id, $personnelId]);
        echo 'success';
    } catch (PDOException $e) {
        echo 'Error: Database error.'; error_log('Site delete error: '.$e->getMessage());
    }
    exit;
}

elseif ($action == 'import_csv') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) { echo 'Error: Invalid security token.'; exit; }
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo 'Error: Upload failed.'; exit;
    }
    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $row = 0;
    $errors = [];
    $inserted = 0;
    // Get current user's personnel_id for CSV import
    $creatorStmt = $pdo->prepare("SELECT p.id FROM personnels p JOIN users u ON p.id = u.personnel_id WHERE u.id = ?");
    $creatorStmt->execute([$_SESSION['user_id']]);
    $creatorResult = $creatorStmt->fetch(PDO::FETCH_ASSOC);
    $createdBy = $creatorResult ? $creatorResult['id'] : $personnelId;
    
    while (($data = fgetcsv($handle, 1000, ',')) !== false) {
        $row++;
        // skip header
        if ($row === 1 && strcasecmp($data[0],'project_name') === 0) continue;
        $project = trim($data[0] ?? '');
        $location = trim($data[1] ?? '');
        $site = trim($data[2] ?? '');
        $isp = trim($data[3] ?? '');
        $status = trim($data[4] ?? '');
        $province = trim($data[5] ?? '');
        $municipality = trim($data[6] ?? '');
        if (!Validator::string($project,1,150) || !Validator::string($location,1,150) || !Validator::string($site,1,150) || !Validator::string($isp,1,150) || !Validator::inList($status,$statusOptions)) {
            $errors[] = "Row $row: invalid data";
            continue;
        }
        if (!Validator::string($province,1,150) || !Validator::string($municipality,1,150)) {
            $errors[] = "Row $row: invalid province/municipality";
            continue;
        }
        // duplicate detection (scoped to current user)
        if (isDuplicateSite($project, $location, $site, $province, $municipality, null, $personnelId)) {
            $errors[] = "Row $row: duplicate record";
            continue;
        }
        try {
            // Get creator's personnel ID for CSV import
            $creatorStmt = $pdo->prepare("SELECT p.id FROM personnels p JOIN users u ON p.id = u.personnel_id WHERE u.id = ?");
            $creatorStmt->execute([$_SESSION['user_id']]);
            $creatorResult = $creatorStmt->fetch(PDO::FETCH_ASSOC);
            $createdBy = $creatorResult ? $creatorResult['id'] : 1; // Default to 1 if not found
            
            $stmt = $pdo->prepare("INSERT INTO sites (project_name, location_name, site_name, isp, status, province, municipality, created_by) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$project,$location,$site,$isp,$status,$province,$municipality,$createdBy]);
            $inserted++;
        } catch (PDOException $e) {
            $errors[] = "Row $row: db error";
        }
    }
    fclose($handle);
    echo json_encode(['inserted'=>$inserted,'errors'=>$errors]);
    exit;
}

elseif (isset($_GET['action']) && $_GET['action'] === 'download_template') {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="sites-template.csv"');
  echo "project_name,location_name,site_name,ap_site_code,isp,status,province,municipality\n";
  exit;
}

elseif ($action == 'bulk_delete') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) { echo json_encode(['error' => 'Invalid security token.']); exit; }
    $selectedIds = $_POST['selected_ids'] ?? [];
    if (empty($selectedIds) || !is_array($selectedIds)) {
        echo json_encode(['error' => 'No sites selected.']); exit;
    }
    foreach ($selectedIds as $id) {
        if (!Validator::positiveInteger($id)) {
            echo json_encode(['error' => 'Invalid site ID.']); exit;
        }
        // ensure user owns site
        $chk = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND created_by = ?");
        $chk->execute([$id,$personnelId]);
        if (!$chk->fetch()) {
            echo json_encode(['error' => 'Access denied.']); exit;
        }
    }
    try {
        $pdo->beginTransaction();
        $placeholders = str_repeat('?, ', count($selectedIds) - 1) . '?';
        $sql = "DELETE FROM sites WHERE id IN ($placeholders) AND created_by = ?";
        $stmt = $pdo->prepare($sql);
        $params = array_merge($selectedIds, [$personnelId]);
        $stmt->execute($params);
        $deleted = $stmt->rowCount();
        $pdo->commit();
        echo json_encode(['success' => true, 'deleted' => $deleted]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Bulk delete error: '.$e->getMessage());
        echo json_encode(['error' => 'Database error.']);
    }
    exit;
}

elseif ($action == 'bulk_update') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) { echo json_encode(['error' => 'Invalid security token.']); exit; }
    
    $selectedIds = $_POST['selected_ids'] ?? [];
    if (empty($selectedIds) || !is_array($selectedIds)) {
        echo json_encode(['error' => 'No sites selected.']); exit;
    }
    
    // Validate IDs and ownership
    foreach ($selectedIds as $id) {
        if (!Validator::positiveInteger($id)) {
            echo json_encode(['error' => 'Invalid site ID.']); exit;
        }
        // Check ownership
        $checkStmt = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND created_by = ?");
        $checkStmt->execute([$id, $personnelId]);
        if (!$checkStmt->fetch()) {
            echo json_encode(['error' => 'Access denied to one or more sites.']); exit;
        }
    }
    
    // Build updates
    $updates = [];
    if (!empty($_POST['status']) && $_POST['status'] !== 'keep') {
        $status = trim($_POST['status']);
        if (!Validator::inList($status, $statusOptions)) {
            echo json_encode(['error' => 'Invalid status.']); exit;
        }
        $updates['status'] = Sanitizer::normalize($status);
    }
    if (!empty($_POST['isp'])) {
        $isp = trim($_POST['isp']);
        if (!Validator::string($isp, 1, 150)) {
            echo json_encode(['error' => 'Invalid ISP.']); exit;
        }
        $updates['isp'] = Sanitizer::normalize($isp);
    }
    if (!empty($_POST['province'])) {
        $province = trim($_POST['province']);
        if (!Validator::string($province, 1, 150)) {
            echo json_encode(['error' => 'Invalid province.']); exit;
        }
        $updates['province'] = Sanitizer::normalize($province);
    }
    if (!empty($_POST['municipality'])) {
        $municipality = trim($_POST['municipality']);
        if (!Validator::string($municipality, 1, 150)) {
            echo json_encode(['error' => 'Invalid municipality.']); exit;
        }
        $updates['municipality'] = Sanitizer::normalize($municipality);
    }
    
    if (empty($updates)) {
        echo json_encode(['error' => 'No fields to update.']); exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Build update query
        $setClause = implode(' = ?, ', array_keys($updates)) . ' = ?';
        $placeholders = str_repeat('?, ', count($selectedIds) - 1) . '?';
        
        $sql = "UPDATE sites SET $setClause WHERE id IN ($placeholders) AND created_by = ?";
        $stmt = $pdo->prepare($sql);
        $params = array_merge(array_values($updates), $selectedIds, [$personnelId]);
        $stmt->execute($params);
        
        $affectedRows = $stmt->rowCount();
        $pdo->commit();
        
        echo json_encode(['success' => true, 'updated' => $affectedRows]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Bulk update error: ' . $e->getMessage());
        echo json_encode(['error' => 'Database error.']);
    }
    exit;
}

// search, sorting and pagination
$searchQuery = $_GET['search'] ?? '';
// column filters (including status)
$filters = [];
$cols = ['project_name','location_name','site_name','ap_site_code','isp','province','municipality','status'];
foreach ($cols as $col) {
    if (!empty($_GET["filter_$col"])) {
        $vals = explode(',', $_GET["filter_$col"]);
        $vals = array_filter($vals, fn($v) => trim($v) !== '');
        if ($col === 'status') {
            $vals = array_filter($vals, fn($v) => in_array($v, $statusOptions));
        }
        if (count($vals) > 0) {
            $filters[$col] = $vals;
        }
    }
}

$sortBy = $_GET['sort'] ?? 'id';
$sortDir = $_GET['dir'] ?? 'DESC';
if (!in_array($sortBy, ['project_name', 'status', 'municipality', 'province', 'id'])) $sortBy = 'id';
if (!in_array($sortDir, ['ASC', 'DESC'])) $sortDir = 'DESC';
$perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
if (!in_array($perPage, [10,20,30,100])) $perPage = 10;
$currentPage = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($currentPage < 1) $currentPage = 1;
$offset = ($currentPage - 1) * $perPage;

// build search filter - always filter by created_by (user's own sites)
$whereClause = 'WHERE created_by = ?';
$params = [$personnelId];
if (!empty($searchQuery)) {
    $searchTerm = '%' . $searchQuery . '%';
    // wrap the OR-conditions in parentheses so additional AND clauses apply correctly
    $whereClause .= " AND (project_name LIKE ? OR status LIKE ? OR location_name LIKE ? OR site_name LIKE ? OR ap_site_code LIKE ? OR isp LIKE ? OR municipality LIKE ? OR province LIKE ?)";
    $params = array_merge($params, array_fill(0, 8, $searchTerm));
}
// apply column filters
foreach ($filters as $col => $vals) {
    $placeholders = implode(',', array_fill(0, count($vals), '?'));
    $whereClause .= ($whereClause ? ' AND ' : 'WHERE ') . $col . ' IN (' . $placeholders . ')';
    $params = array_merge($params, $vals);
}

// get total matching records
$countSql = "SELECT COUNT(*) FROM sites " . $whereClause;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalSites = $countStmt->fetchColumn();
$totalPages = ceil($totalSites / $perPage);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

  <title>FPIAP-Service Management and Response Ticketing System</title>
</head>
<body class="d-flex flex-column min-vh-100">

<nav class="navbar sticky-top navbar-expand-lg navbar-light shadow-sm" style="background-color: #0ef;">
  <div class="container-fluid">

    <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
      <img src="../assets/freewifilogo.png" alt="Logo" width="100" height="100" class="me-2">
      <img src="../assets/FPIAP-SMARTs.png" alt="Logo" width="100" height="100" class="me-2">
      <div class="d-flex flex-column ms-0">
        <span class="fw-bold">FPIAP-SMARTs</span>
      </div>
    </a>

  <hr class="mx-0 my-2 opacity-25">

  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
    <span class="navbar-toggler-icon"></span>
  </button>

  <!-- Navigation Links -->
  <div class="collapse navbar-collapse" id="mainNavbar">
    <ul class="navbar-nav me-auto mb-2 mb-lg-0">

    <li class="nav-item">
      <a class="nav-link" href="dashboard.php">Dashboard</a>
    </li>

    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      Tickets
      </a>
      <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
      <li><a class="dropdown-item" href="viewtickets.php">View Tickets</a></li>
      <li><a class="dropdown-item" href="ticket.php">Create Ticket</a></li> 
      </ul>
    </li>

    <li class="nav-item dropdown">
      <a class="nav-link active dropdown-toggle" id="navbarDropdown" role="button" href="#" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      Sites
      </a>
      <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
      <li><a class="dropdown-item" href="site.php">Manage Sites</a></li>
      </ul>
    </li>

    <li class="nav-item">
      <a class="nav-link" href="#">Reports</a>
    </li>
        
    </ul>

    <!-- Right Icons -->
    <ul class="navbar-nav ms-auto align-items-center">

    <!-- Notification Bell -->
    <li class="nav-item dropdown me-3">
          <a id="notificationBell" class="nav-link position-relative dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-bell fs-5"></i>
            <span id="notificationBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger visually-hidden">0</span>
          </a>
          <ul id="notificationDropdown" class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationBell">
            <li class="dropdown-item text-center text-muted small">Loading...</li>
          </ul>
    </li>

    <!-- Profile Dropdown -->
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
      <i class="bi bi-person-circle fs-4 me-1"></i>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item" href="#">My Account</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="../logout.php">Logout</a></li>
      </ul>
    </li>

    </ul>
  </div>
  </div>
</nav>

<div class="container-fluid mt-4">
  <div class="row">
    <!-- Table Column -->
    <div class="col-12" id="tableContainer" style="transition: all 0.3s ease;">
      <div id="alertContainer"></div>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>MY SITES</h3>
        <div>
          <button class="btn btn-danger me-2" id="bulkDeleteBtn" onclick="confirmBulkDelete()" disabled>
            <i class="bi bi-trash"></i> Bulk Delete
          </button>
          <button class="btn btn-warning me-2" id="bulkUpdateBtn" onclick="openBulkUpdateModal()" disabled>
            <i class="bi bi-pencil-square"></i> Bulk Update
          </button>
          <button class="btn btn-success me-2" onclick="loadImportForm()">
            <i class="bi bi-upload"></i> Import CSV
          </button>
          <button class="btn btn-primary" onclick="loadAddForm()">
            <i class="bi bi-plus-lg"></i> Add New Site
          </button>
        </div>
      </div>
      <div class="mb-3">
        <input type="text" id="searchInput" class="form-control form-control-lg" placeholder="Search by Project, Status, Location, Site, AP Site Code, ISP, Municipality or Province..." value="<?php echo htmlspecialchars($searchQuery); ?>">
      </div>

      <!-- Filter Bar -->
      <div class="filter-bar bg-light p-3 mb-3 border rounded">
        <div class="row g-2">
          <div class="col-lg-3 col-md-4 col-sm-6">
            <div class="dropdown">
              <button class="btn btn-outline-secondary w-100" type="button" onclick="toggleFilterMenu(event, 'project_name')" style="position: relative;">
                Project <span class="badge bg-primary" id="project_count">0</span>
                <i class="bi bi-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);"></i>
              </button>
              <div id="filterMenu_project_name" class="dropdown-menu p-2 w-100 fs-6 show" style="max-height: 200px; overflow-y: auto; display: none;">
                <?php $filterVals = isset($_GET['filter_project_name']) ? explode(',', $_GET['filter_project_name']) : []; ?>
                <?php foreach ($allProjects as $p): ?>
                  <div class="form-check">
                    <input class="form-check-input col-filter-chk" type="checkbox" value="<?php echo htmlspecialchars($p); ?>" data-col="project_name" id="filterChk_project_name_<?php echo md5($p); ?>" <?php if(in_array($p, $filterVals)) echo 'checked'; ?> />
                    <label class="form-check-label" for="filterChk_project_name_<?php echo md5($p); ?>"><?php echo htmlspecialchars($p); ?></label>
                  </div>
                <?php endforeach; ?>
                <div class="mt-2 text-end"><button class="btn btn-sm btn-link" onclick="clearColumnFilter('project_name')">Clear</button></div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-4 col-sm-6">
            <div class="dropdown">
              <button class="btn btn-outline-secondary w-100" type="button" onclick="toggleFilterMenu(event, 'location_name')" style="position: relative;">
                Location <span class="badge bg-primary" id="location_count">0</span>
                <i class="bi bi-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);"></i>
              </button>
              <div id="filterMenu_location_name" class="dropdown-menu p-2 w-100 fs-6 show" style="max-height: 200px; overflow-y: auto; display: none;">
                <?php $filterVals = isset($_GET['filter_location_name']) ? explode(',', $_GET['filter_location_name']) : []; ?>
                <?php foreach ($allLocationNames as $l): ?>
                  <div class="form-check">
                    <input class="form-check-input col-filter-chk" type="checkbox" value="<?php echo htmlspecialchars($l); ?>" data-col="location_name" id="filterChk_location_name_<?php echo md5($l); ?>" <?php if(in_array($l, $filterVals)) echo 'checked'; ?> />
                    <label class="form-check-label" for="filterChk_location_name_<?php echo md5($l); ?>"><?php echo htmlspecialchars($l); ?></label>
                  </div>
                <?php endforeach; ?>
                <div class="mt-2 text-end"><button class="btn btn-sm btn-link" onclick="clearColumnFilter('location_name')">Clear</button></div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-4 col-sm-6">
            <div class="dropdown">
              <button class="btn btn-outline-secondary w-100" type="button" onclick="toggleFilterMenu(event, 'site_name')" style="position: relative;">
                Site <span class="badge bg-primary" id="site_count">0</span>
                <i class="bi bi-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);"></i>
              </button>
              <div id="filterMenu_site_name" class="dropdown-menu p-2 w-100 fs-6 show" style="max-height: 200px; overflow-y: auto; display: none;">
                <?php $filterVals = isset($_GET['filter_site_name']) ? explode(',', $_GET['filter_site_name']) : []; ?>
                <?php foreach ($allSiteNames as $s): ?>
                  <div class="form-check">
                    <input class="form-check-input col-filter-chk" type="checkbox" value="<?php echo htmlspecialchars($s); ?>" data-col="site_name" id="filterChk_site_name_<?php echo md5($s); ?>" <?php if(in_array($s, $filterVals)) echo 'checked'; ?> />
                    <label class="form-check-label" for="filterChk_site_name_<?php echo md5($s); ?>"><?php echo htmlspecialchars($s); ?></label>
                  </div>
                <?php endforeach; ?>
                <div class="mt-2 text-end"><button class="btn btn-sm btn-link" onclick="clearColumnFilter('site_name')">Clear</button></div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-4 col-sm-6">
            <div class="dropdown">
              <button class="btn btn-outline-secondary w-100" type="button" onclick="toggleFilterMenu(event, 'ap_site_code')" style="position: relative;">
                AP Code <span class="badge bg-primary" id="ap_code_count">0</span>
                <i class="bi bi-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);"></i>
              </button>
              <div id="filterMenu_ap_site_code" class="dropdown-menu p-2 w-100 fs-6 show" style="max-height: 200px; overflow-y: auto; display: none;">
                <?php $filterVals = isset($_GET['filter_ap_site_code']) ? explode(',', $_GET['filter_ap_site_code']) : []; ?>
                <?php foreach ($allApCodes as $a): ?>
                  <div class="form-check">
                    <input class="form-check-input col-filter-chk" type="checkbox" value="<?php echo htmlspecialchars($a); ?>" data-col="ap_site_code" id="filterChk_ap_site_code_<?php echo md5($a); ?>" <?php if(in_array($a, $filterVals)) echo 'checked'; ?> />
                    <label class="form-check-label" for="filterChk_ap_site_code_<?php echo md5($a); ?>"><?php echo htmlspecialchars($a); ?></label>
                  </div>
                <?php endforeach; ?>
                <div class="mt-2 text-end"><button class="btn btn-sm btn-link" onclick="clearColumnFilter('ap_site_code')">Clear</button></div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-4 col-sm-6">
            <div class="dropdown">
              <button class="btn btn-outline-secondary w-100" type="button" onclick="toggleFilterMenu(event, 'isp')" style="position: relative;">
                ISP <span class="badge bg-primary" id="isp_count">0</span>
                <i class="bi bi-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);"></i>
              </button>
              <div id="filterMenu_isp" class="dropdown-menu p-2 w-100 fs-6 show" style="max-height: 200px; overflow-y: auto; display: none;">
                <?php $filterVals = isset($_GET['filter_isp']) ? explode(',', $_GET['filter_isp']) : []; ?>
                <?php foreach ($allISP as $i): ?>
                  <div class="form-check">
                    <input class="form-check-input col-filter-chk" type="checkbox" value="<?php echo htmlspecialchars($i); ?>" data-col="isp" id="filterChk_isp_<?php echo md5($i); ?>" <?php if(in_array($i, $filterVals)) echo 'checked'; ?> />
                    <label class="form-check-label" for="filterChk_isp_<?php echo md5($i); ?>"><?php echo htmlspecialchars($i); ?></label>
                  </div>
                <?php endforeach; ?>
                <div class="mt-2 text-end"><button class="btn btn-sm btn-link" onclick="clearColumnFilter('isp')">Clear</button></div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-4 col-sm-6">
            <div class="dropdown">
              <button class="btn btn-outline-secondary w-100" type="button" onclick="toggleFilterMenu(event, 'municipality')" style="position: relative;">
                Municipality <span class="badge bg-primary" id="municipality_count">0</span>
                <i class="bi bi-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);"></i>
              </button>
              <div id="filterMenu_municipality" class="dropdown-menu p-2 w-100 fs-6 show" style="max-height: 200px; overflow-y: auto; display: none;">
                <?php $filterVals = isset($_GET['filter_municipality']) ? explode(',', $_GET['filter_municipality']) : []; ?>
                <?php foreach ($allMunicipalities as $m): ?>
                  <div class="form-check">
                    <input class="form-check-input col-filter-chk" type="checkbox" value="<?php echo htmlspecialchars($m); ?>" data-col="municipality" id="filterChk_municipality_<?php echo md5($m); ?>" <?php if(in_array($m, $filterVals)) echo 'checked'; ?> />
                    <label class="form-check-label" for="filterChk_municipality_<?php echo md5($m); ?>"><?php echo htmlspecialchars($m); ?></label>
                  </div>
                <?php endforeach; ?>
                <div class="mt-2 text-end"><button class="btn btn-sm btn-link" onclick="clearColumnFilter('municipality')">Clear</button></div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-4 col-sm-6">
            <div class="dropdown">
              <button class="btn btn-outline-secondary w-100" type="button" onclick="toggleStatusMenu(event)" style="position: relative;">
                Status <span class="badge bg-primary" id="status_count">0</span>
                <i class="bi bi-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);"></i>
              </button>
              <div id="statusMenu" class="dropdown-menu p-2 w-100 fs-6 show" style="max-height: 200px; overflow-y: auto; display: none;">
                <?php $filterVals = isset($_GET['filter_status']) ? explode(',', $_GET['filter_status']) : []; ?>
                <?php foreach ($statusOptions as $st): ?>
                  <div class="form-check">
                    <input class="form-check-input status-checkbox" type="checkbox" value="<?php echo htmlspecialchars($st); ?>" id="statusChk_<?php echo htmlspecialchars($st); ?>" <?php if(in_array($st,$filterVals)) echo 'checked'; ?> />
                    <label class="form-check-label" for="statusChk_<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars($st); ?></label>
                  </div>
                <?php endforeach; ?>
                <div class="mt-2 text-end"><button class="btn btn-sm btn-link" onclick="clearColumnFilter('status')">Clear</button></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover shadow-sm">
          <thead class="table-dark text-center">
            <tr>
              <th><input type="checkbox" id="selectAll"></th>
              <th style="cursor: pointer;" onclick="sortTable('project_name')">
                Project Name
                <?php if($sortBy === 'project_name') echo $sortDir === 'ASC' ? '▲' : '▼'; ?>
              </th>
              <th style="cursor: pointer;" onclick="sortTable('status')">
                Status
                <?php if($sortBy === 'status') echo $sortDir === 'ASC' ? '▲' : '▼'; ?>
              </th>
              <th style="cursor: pointer;" onclick="sortTable('location_name')">
                Location Name
                <?php if($sortBy === 'location_name') echo $sortDir === 'ASC' ? '▲' : '▼'; ?>
              </th>
              <th style="cursor: pointer;" onclick="sortTable('site_name')">
                Site Name
                <?php if($sortBy === 'site_name') echo $sortDir === 'ASC' ? '▲' : '▼'; ?>
              </th>
              <th style="cursor: pointer;" onclick="sortTable('ap_site_code')">
                AP Site Code
                <?php if($sortBy === 'ap_site_code') echo $sortDir === 'ASC' ? '▲' : '▼'; ?>
              </th>
              <th style="cursor: pointer;" onclick="sortTable('isp')">
                ISP
                <?php if($sortBy === 'isp') echo $sortDir === 'ASC' ? '▲' : '▼'; ?>
              </th>
              <th style="cursor: pointer;" onclick="sortTable('municipality')">
                Municipality 
                <?php if($sortBy === 'municipality') echo $sortDir === 'ASC' ? '▲' : '▼'; ?>
              </th>
              <th style="cursor: pointer;" onclick="sortTable('province')">
                Province 
                <?php if($sortBy === 'province') echo $sortDir === 'ASC' ? '▲' : '▼'; ?>
              </th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // determine ORDER BY with sensible secondary ordering so rows are
            // grouped predictably when sorting by province or municipality
            switch ($sortBy) {
              case 'province':
                // primary: province (user direction), then municipality and project name ascending
                $orderBy = "province $sortDir, municipality ASC, project_name ASC";
                break;
              case 'municipality':
                // primary: municipality (user direction), then province and project name ascending
                $orderBy = "municipality $sortDir, province ASC, project_name ASC";
                break;
              case 'project_name':
                $orderBy = "project_name $sortDir";
                break;
              case 'location_name':
                $orderBy = "location_name $sortDir";
                break;
              case 'site_name':
                $orderBy = "site_name $sortDir";
                break;
              case 'ap_site_code':
                $orderBy = "ap_site_code $sortDir";
                break;
              case 'isp':
                $orderBy = "isp $sortDir";
                break;
              default:
                $orderBy = "id $sortDir";
            }
            $sql = "SELECT * FROM sites " . $whereClause . " ORDER BY " . $orderBy . " LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            $count = count($params);
            for ($i = 0; $i < $count; $i++) {
              $stmt->bindValue($i + 1, $params[$i]);
            }
            $stmt->bindValue($count + 1, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($count + 2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
              echo "<tr>
                <td><input type=\"checkbox\" class=\"site-checkbox\" value=\"{$row['id']}\"></td>
                <td>".htmlspecialchars($row['project_name'])."</td>
                <td>".statusBadge($row['status'] ?? '')."</td>
                <td>".htmlspecialchars($row['location_name'])."</td>
                <td>".htmlspecialchars($row['site_name'])."</td>
                <td>".htmlspecialchars($row['ap_site_code'])."</td>
                <td>".htmlspecialchars($row['isp'])."</td>
                <td>".htmlspecialchars($row['municipality'])."</td>
                <td>".htmlspecialchars($row['province'])."</td>
                <td class='text-center'>
                  <button class='btn btn-sm btn-info text-white' onclick='loadView({$row['id']})'><i class='bi bi-eye'></i></button>
                  <button class='btn btn-sm btn-warning' onclick='loadEditForm({$row['id']})'><i class='bi bi-pencil'></i></button>
                  <button class='btn btn-sm btn-danger' onclick='deleteSite({$row['id']})'><i class='bi bi-trash'></i></button>
                </td>
              </tr>";
            }
            ?>
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div>
          <label for="perPageSelect" class="me-2">Records per page:</label>
          <select id="perPageSelect" class="form-select d-inline-block" style="width: auto;">
            <option value="10" <?php echo $perPage === 10 ? 'selected' : ''; ?>>10</option>
            <option value="20" <?php echo $perPage === 20 ? 'selected' : ''; ?>>20</option>
            <option value="30" <?php echo $perPage === 30 ? 'selected' : ''; ?>>30</option>
            <option value="100" <?php echo $perPage === 100 ? 'selected' : ''; ?>>100</option>
          </select>
        </div>
        <div class="text-muted small">
          Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $perPage, $totalSites); ?> of <?php echo $totalSites; ?> records
        </div>
        <div>
          <button class="btn btn-sm btn-outline-secondary" <?php echo $currentPage <= 1 ? 'disabled' : ''; ?> onclick="goToPage(<?php echo $currentPage - 1; ?>)">Previous</button>
          <span class="mx-2">Page <span id="currentPageNum"><?php echo $currentPage; ?></span> of <?php echo $totalPages; ?></span>
          <button class="btn btn-sm btn-outline-secondary" <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?> onclick="goToPage(<?php echo $currentPage + 1; ?>)">Next</button>
        </div>
      </div>
    </div>

    <!-- Side Panel Column -->
    <div class="col-4 d-none" id="sidePanel" style="transition: all 0.3s ease;">
      <div id="sidePanelContent"></div>
    </div>
  </div>
</div>

<!-- Bulk Update Modal -->
<div class="modal fade" id="bulkUpdateModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Bulk Update Sites</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="bulkUpdateForm">
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="keep">Keep Current</option>
              <?php foreach ($statusOptions as $st): ?>
                <option value="<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars($st); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">ISP</label>
            <input type="text" name="isp" class="form-control" placeholder="Leave empty to keep current">
          </div>
          <div class="mb-3">
            <label class="form-label">Province</label>
            <input type="text" name="province" class="form-control" placeholder="Leave empty to keep current" list="bulkProvinceList">
            <datalist id="bulkProvinceList">
              <?php foreach ($provinces as $prov): ?>
                <option value="<?php echo htmlspecialchars($prov); ?>">
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="mb-3">
            <label class="form-label">Municipality</label>
            <input type="text" name="municipality" class="form-control" placeholder="Leave empty to keep current">
          </div>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="submitBulkUpdate()">Update Selected Sites</button>
      </div>
    </div>
  </div>
</div>

<footer class="bg-dark text-light text-center py-3 mt-auto">
  <div class="container">
  <small>
    <?php echo date('Y'); ?> &copy; FREE PUBLIC INTERNET ACCESS PROGRAM - SERVICE MANAGEMENT AND RESPONSE TICKETING SYSTEM (FPIAP-SMARTs). All Rights Reserved.
  </small>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // tooltips
  document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    fetchNotifications();
    setInterval(fetchNotifications, 60000);
    const bellToggle = document.getElementById('notificationBell');
    if (bellToggle) {
      bellToggle.addEventListener('show.bs.dropdown', fetchNotifications);
    }
    // Initialize filter badges
    updateFilterBadges();
  });

  function showPanel() {
    document.getElementById('tableContainer').classList.remove('col-12');
    document.getElementById('tableContainer').classList.add('col-8');
    document.getElementById('sidePanel').classList.remove('d-none');
  }

  function closePanel() {
    document.getElementById('sidePanel').classList.add('d-none');
    document.getElementById('tableContainer').classList.remove('col-8');
    document.getElementById('tableContainer').classList.add('col-12');
    document.getElementById('sidePanelContent').innerHTML = '';
  }

  function loadAddForm() {
    showPanel();
    fetch('site.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=add_form'})
    .then(r=>r.text()).then(html=>{
      document.getElementById('sidePanelContent').innerHTML=html;
      initLocationSelectors();
      // after panel loads, attach paged autocomplete to new inputs
      setupPagedAutocomplete('input[name="project_name"]', projectList);
      setupPagedAutocomplete('input[name="isp"]', ispList);
    });
  }

  function loadView(id) {
    showPanel();
    fetch('site.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=view_details&id='+id})
    .then(r=>r.text()).then(html=>{document.getElementById('sidePanelContent').innerHTML=html;});
  }

  function loadEditForm(id) {
    showPanel();
    fetch('site.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=edit_form&id='+id})
    .then(r=>r.text()).then(html=>{
      document.getElementById('sidePanelContent').innerHTML=html;
      initLocationSelectors();
      // bind autocomplete after edit form is added
      setupPagedAutocomplete('input[name="project_name"]', projectList);
      setupPagedAutocomplete('input[name="isp"]', ispList);
    });
  }

  function saveSite(evt) {
    evt.preventDefault();
    const fd=new FormData(evt.target);fd.append('action','save');
    fetch('site.php',{method:'POST',body:fd}).then(r=>r.text()).then(res=>{
      if(res.trim()==='success'){showAlert('Site added','success');closePanel();setTimeout(()=>location.reload(),1500);}else{showAlert(res,'error');}
    });
  }

  function updateSite(evt) {
    evt.preventDefault();
    const fd=new FormData(evt.target);fd.append('action','update');
    fetch('site.php',{method:'POST',body:fd}).then(r=>r.text()).then(res=>{
      if(res.trim()==='success'){showAlert('Site updated','update');closePanel();setTimeout(()=>location.reload(),1500);}else{showAlert(res,'error');}
    });
  }

  function deleteSite(id) {
    if(!confirm('Delete this site?')) return;
    const fd=new FormData();fd.append('action','delete');fd.append('id',id);fd.append('csrf_token', '<?php echo htmlspecialchars(generateCSRFToken()); ?>');
    fetch('site.php',{method:'POST',body:fd}).then(r=>r.text()).then(res=>{if(res.trim()==='success'){showAlert('Deleted','success');setTimeout(()=>location.reload(),1500);}else{showAlert(res,'error');}});
  }

  function loadImportForm() {
    showPanel();
    const html=`<div class="card"><div class="card-header bg-secondary text-white d-flex justify-content-between"><h5 class="mb-0">Import Sites CSV</h5><button class="btn-close btn-close-white" onclick="closePanel()"></button></div><div class="card-body"><form id="importForm" onsubmit="importCsv(event)"><div class="mb-3"><a href="site.php?action=download_template">Download template</a></div><div class="mb-3"><input type="file" name="csv_file" class="form-control" accept=".csv" required></div><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>"><button type="submit" class="btn btn-primary w-100">Upload</button></form></div></div>`;
    document.getElementById('sidePanelContent').innerHTML=html;
  }

  function importCsv(evt) {
    evt.preventDefault();
    const fd=new FormData(evt.target);fd.append('action','import_csv');
    fetch('site.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
      if (data.inserted!==undefined) {
          let summary = `Imported ${data.inserted} rows`;
          if (Array.isArray(data.errors) && data.errors.length) {
              // count error types
              let dup=0, inval=0, other=0;
              data.errors.forEach(e=>{
                  if (/duplicate/i.test(e)) dup++;
                  else if (/invalid/i.test(e)) inval++;
                  else other++;
              });
              const parts=[];
              if (dup) parts.push(`${dup} duplicate${dup>1?'s':''}`);
              if (inval) parts.push(`${inval} invalid${inval>1?' rows':''}`);
              if (other) parts.push(`${other} error${other>1?'s':''}`);
              summary += `. Skipped ${parts.join(', ')}`;
          }
          showAlert(summary, 'success');
          closePanel();
          setTimeout(()=>location.reload(),1500);
      } else {
          showAlert('Import failed','error');
      }
    }).catch(err=>{
      console.error('Import request failed',err);
      showAlert('Import request failed','error');
    });
  }

  function showAlert(message,type){
    const c=document.getElementById('alertContainer');const d=document.createElement('div');let cls='alert-success';if(type==='error')cls='alert-danger';if(type==='update')cls='alert-info';d.className=`alert ${cls} alert-dismissible fade show`;d.role='alert';d.innerHTML=message+`<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;c.appendChild(d);setTimeout(()=>new bootstrap.Alert(d).close(),3000);
  }

  document.getElementById('searchInput').addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
      performSearch(this.value);
    }
  });
  // status filter menu handlers
  function toggleStatusMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('statusMenu');
    // Close other open filter menus
    document.querySelectorAll('[id^="filterMenu_"]').forEach(m => {
      m.style.display = 'none';
    });
    // Toggle status menu
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
  }
  document.addEventListener('click', function(e) {
    const menu = document.getElementById('statusMenu');
    if (!menu.contains(e.target) && !e.target.closest('[onclick*="toggleStatusMenu"]')) {
      menu.style.display = 'none';
    }
    // close filter menus
    ['project_name','location_name','site_name','ap_site_code','isp','province','municipality'].forEach(col=>{
      const fmenu = document.getElementById('filterMenu_' + col);
      if (fmenu && !fmenu.contains(e.target) && !e.target.closest(`[onclick*="toggleFilterMenu(event, '${col}')"]`)) {
        fmenu.style.display = 'none';
      }
    });
  });
  // on load, adjust menu position if header is close to right edge
  window.addEventListener('resize', function(){
    // Status filter is now in filter bar, no position adjustment needed
  });
  function applyColumnFilters() {
    const params = new URLSearchParams(window.location.search);
    ['project_name','location_name','site_name','ap_site_code','isp','province','municipality','status'].forEach(col=>{
      let checked;
      if (col === 'status') {
        checked = Array.from(document.querySelectorAll('.status-checkbox:checked')).map(cb=>cb.value);
      } else {
        checked = Array.from(document.querySelectorAll(`.col-filter-chk[data-col="${col}"]:checked`)).map(cb=>cb.value);
      }
      if (checked.length > 0) {
        params.set(`filter_${col}`, checked.join(','));
      } else {
        params.delete(`filter_${col}`);
      }
    });
    // keep existing sorting/search/per_page/page params
    window.location.search = params.toString();
  }

  document.querySelectorAll('.col-filter-chk').forEach(cb=>{
    cb.addEventListener('change', function() {
      updateFilterBadges();
      applyColumnFilters();
    });
  });
  document.querySelectorAll('.status-checkbox').forEach(cb=>{
    cb.addEventListener('change', function() {
      updateFilterBadges();
      applyColumnFilters();
    });
  });
  function toggleFilterMenu(e, col) {
    e.stopPropagation();
    const menu = document.getElementById('filterMenu_' + col);
    // Close other open filter menus
    document.querySelectorAll('[id^="filterMenu_"]').forEach(m => {
      if (m.id !== 'filterMenu_' + col) {
        m.style.display = 'none';
      }
    });
    // Toggle current menu
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
  }
  function clearColumnFilter(col) {
    document.querySelectorAll(`.col-filter-chk[data-col="${col}"]`).forEach(cb => cb.checked = false);
    updateFilterBadges();
    applyColumnFilters();
  }
  function updateFilterBadges() {
    const badgeIds = {
      'project_name': 'project_count',
      'location_name': 'location_count',
      'site_name': 'site_count',
      'ap_site_code': 'ap_code_count',
      'isp': 'isp_count',
      'municipality': 'municipality_count',
      'province': 'province_count'
    };
    ['project_name','location_name','site_name','ap_site_code','isp','province','municipality'].forEach(col=>{
      const count = document.querySelectorAll(`.col-filter-chk[data-col="${col}"]:checked`).length;
      const badgeId = badgeIds[col];
      const badge = document.getElementById(badgeId);
      if (badge) {
        badge.textContent = count;
      }
    });
    // Update status filter badge
    const statusCount = document.querySelectorAll('.status-checkbox:checked').length;
    const statusBadge = document.getElementById('status_count');
    if (statusBadge) {
      statusBadge.textContent = statusCount;
    }
  }

  function performSearch(query) {
    const searchParam = query.trim() ? `search=${encodeURIComponent(query.trim())}&` : '';
    const perPage = document.getElementById('perPageSelect').value;
    const sort = new URLSearchParams(window.location.search).get('sort') || 'id';
    const dir = new URLSearchParams(window.location.search).get('dir') || 'DESC';
    // include filter params
    const filterParams = [];
    ['project_name','location_name','site_name','ap_site_code','isp','province','municipality','status'].forEach(col=>{
      let checked;
      if (col === 'status') {
        checked = Array.from(document.querySelectorAll('.status-checkbox:checked')).map(cb=>cb.value);
      } else {
        checked = Array.from(document.querySelectorAll(`.col-filter-chk[data-col="${col}"]:checked`)).map(cb=>cb.value);
      }
      if (checked.length > 0) {
        filterParams.push(`filter_${col}=${encodeURIComponent(checked.join(','))}`);
      }
    });
    const filterParamStr = filterParams.join('&') + (filterParams.length ? '&' : '');
    if (searchParam || filterParamStr) {
      window.location.href = `site.php?${searchParam}${filterParamStr}sort=${sort}&dir=${dir}&page=1&per_page=${perPage}`;
    } else {
      window.location.href = `site.php?sort=${sort}&dir=${dir}&page=1&per_page=${perPage}`;
    }
  }

  function goToPage(pageNum) {
    const currentPerPage = document.getElementById('perPageSelect').value;
    const searchInput = document.getElementById('searchInput').value.trim();
    const sort = new URLSearchParams(window.location.search).get('sort') || 'id';
    const dir = new URLSearchParams(window.location.search).get('dir') || 'DESC';
    const searchParam = searchInput ? `search=${encodeURIComponent(searchInput)}&` : '';
    // include filter params
    const filterParams = [];
    ['project_name','location_name','site_name','ap_site_code','isp','province','municipality','status'].forEach(col=>{
      let checked;
      if (col === 'status') {
        checked = Array.from(document.querySelectorAll('.status-checkbox:checked')).map(cb=>cb.value);
      } else {
        checked = Array.from(document.querySelectorAll(`.col-filter-chk[data-col="${col}"]:checked`)).map(cb=>cb.value);
      }
      if (checked.length > 0) {
        filterParams.push(`filter_${col}=${encodeURIComponent(checked.join(','))}`);
      }
    });
    const filterParamStr = filterParams.join('&') + (filterParams.length ? '&' : '');
    window.location.href = `site.php?${searchParam}${filterParamStr}sort=${sort}&dir=${dir}&page=${pageNum}&per_page=${currentPerPage}`;
  }

  document.getElementById('perPageSelect').addEventListener('change', function(){
    const searchInput = document.getElementById('searchInput').value.trim();
    const sort = new URLSearchParams(window.location.search).get('sort') || 'id';
    const dir = new URLSearchParams(window.location.search).get('dir') || 'DESC';
    const searchParam = searchInput ? `search=${encodeURIComponent(searchInput)}&` : '';
    // include filter params
    const filterParams = [];
    ['project_name','location_name','site_name','ap_site_code','isp','province','municipality','status'].forEach(col=>{
      let checked;
      if (col === 'status') {
        checked = Array.from(document.querySelectorAll('.status-checkbox:checked')).map(cb=>cb.value);
      } else {
        checked = Array.from(document.querySelectorAll(`.col-filter-chk[data-col="${col}"]:checked`)).map(cb=>cb.value);
      }
      if (checked.length > 0) {
        filterParams.push(`filter_${col}=${encodeURIComponent(checked.join(','))}`);
      }
    });
    const filterParamStr = filterParams.join('&') + (filterParams.length ? '&' : '');
    window.location.href = `site.php?${searchParam}${filterParamStr}sort=${sort}&dir=${dir}&page=1&per_page=${this.value}`;
  });

  function sortTable(column) {
    const currentSort = new URLSearchParams(window.location.search).get('sort') || 'id';
    const currentDir = new URLSearchParams(window.location.search).get('dir') || 'DESC';
    const newDir = (currentSort === column && currentDir === 'ASC') ? 'DESC' : 'ASC';
    const searchInput = document.getElementById('searchInput').value.trim();
    const perPage = document.getElementById('perPageSelect').value;
    const searchParam = searchInput ? `search=${encodeURIComponent(searchInput)}&` : '';
    // include filter params
    const filterParams = [];
    ['project_name','location_name','site_name','ap_site_code','isp','province','municipality','status'].forEach(col=>{
      let checked;
      if (col === 'status') {
        checked = Array.from(document.querySelectorAll('.status-checkbox:checked')).map(cb=>cb.value);
      } else {
        checked = Array.from(document.querySelectorAll(`.col-filter-chk[data-col="${col}"]:checked`)).map(cb=>cb.value);
      }
      if (checked.length > 0) {
        filterParams.push(`filter_${col}=${encodeURIComponent(checked.join(','))}`);
      }
    });
    const filterParamStr = filterParams.join('&') + (filterParams.length ? '&' : '');
    window.location.href = `site.php?${searchParam}${filterParamStr}sort=${column}&dir=${newDir}&page=1&per_page=${perPage}`;
  }

  async function fetchNotifications(){
    const drop = document.getElementById('notificationDropdown');
    const badge = document.getElementById('notificationBadge');
    if (!drop) return;
    try {
      const resp = await fetch('notification.php', { method: 'GET', cache: 'no-cache' });
      const html = await resp.text();
      drop.innerHTML = html;
      
      // Attach click handlers to notification items
      drop.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function(e) {
          const notificationId = this.getAttribute('data-notification-id');
          if (notificationId) {
            fetch('../notif/api.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: 'action=mark_read&notification_id=' + notificationId
            }).catch(err => console.error('Failed to mark notification as read:', err));
            this.classList.remove('unread');
          }
        });
      });
      
      const unread = drop.querySelectorAll('.notification-item.unread, li[data-unread="1"]').length;
      if (unread > 0) {
        badge.textContent = unread;
        badge.classList.remove('visually-hidden');
      } else {
        badge.classList.add('visually-hidden');
      }
    } catch (e) {
      drop.innerHTML = '<li class="dropdown-item text-danger small">Error loading notifications</li>';
      if (badge) badge.classList.add('visually-hidden');
      console.error(e);
    }
  }

  // location helpers
  const locData = <?php echo json_encode($allLocations); ?>;
  // derive unique province list from locData for paging
  const provinceList = Array.from(new Set(locData.map(l => l.province))).sort();

  // autocomplete sources for projects/ISP
  const projectList = <?php echo json_encode($allProjects); ?>;
  const ispList = <?php echo json_encode($allISP); ?>;

  function initLocationSelectors() {
    setupProvinceAutocomplete('input[name="province"]');
    setupMunicipalityAutocomplete('input[name="province"]', 'input[name="municipality"]');
  }

  function setupProvinceAutocomplete(inputSelector) {
    setupPagedAutocomplete(inputSelector, provinceList);
    const input = document.querySelector(inputSelector);
    if (input) {
      input.addEventListener('input', () => {
        const muni = document.querySelector('input[name="municipality"]');
        if (muni) muni.value = '';
      });
    }
  }

  function setupMunicipalityAutocomplete(provSelector, muniSelector) {
    const provInput = document.querySelector(provSelector);
    const muniInput = document.querySelector(muniSelector);
    if (!provInput || !muniInput) return;

    let page = 0;
    const pageSize = 20;
    let currentMatches = [];
    const container = document.createElement('div');
    container.className = 'autocomplete-container bg-white border position-absolute';
    container.style.zIndex = '1000';
    container.style.width = '100%';
    muniInput.parentNode.style.position = 'relative';
    muniInput.parentNode.appendChild(container);

    function render() {
      container.innerHTML = '';
      if (currentMatches.length === 0) return;
      const start = page * pageSize;
      const slice = currentMatches.slice(start, start + pageSize);
      slice.forEach(v => {
        const div = document.createElement('div');
        div.className = 'px-2 py-1 autocomplete-item';
        div.style.cursor = 'pointer';
        div.textContent = v;
        div.addEventListener('mousedown', () => {
          muniInput.value = v;
          container.innerHTML = '';
          fillProvinceFromMunicipality(v);
        });
        container.appendChild(div);
      });
      if (currentMatches.length > pageSize) {
        const nav = document.createElement('div');
        nav.className = 'd-flex justify-content-between border-top mt-1';
        const prev = document.createElement('button');
        prev.textContent = '◀';
        prev.disabled = page === 0;
        prev.className = 'btn btn-sm';
        prev.addEventListener('click', (e) => { e.stopPropagation(); page--; render(); });
        const next = document.createElement('button');
        next.textContent = '▶';
        next.disabled = (start + pageSize) >= currentMatches.length;
        next.className = 'btn btn-sm';
        next.addEventListener('click', (e) => { e.stopPropagation(); page++; render(); });
        nav.appendChild(prev);
        nav.appendChild(next);
        container.appendChild(nav);
      }
    }

    function filterMatches() {
      const prov = provInput.value.trim().toLowerCase();
      const muniQ = muniInput.value.trim().toLowerCase();
      let matches = locData
        .filter(l => prov === '' || l.province.trim().toLowerCase().startsWith(prov))
        .map(l => l.municipality);
      if (muniQ) {
        matches = matches.filter(m => m.toLowerCase().includes(muniQ));
      }
      currentMatches = Array.from(new Set(matches));
    }

    function fillProvinceFromMunicipality(m) {
      const name = m.trim().toLowerCase();
      if (!name) return;
      const match = locData.find(l=>l.municipality.trim().toLowerCase() === name);
      if (match) provInput.value = match.province;
    }

    muniInput.addEventListener('input', () => {
      filterMatches();
      page = 0;
      render();
      fillProvinceFromMunicipality(muniInput.value);
    });
    muniInput.addEventListener('focus', () => {
      filterMatches();
      page = 0;
      render();
    });
    provInput.addEventListener('input', () => {
      currentMatches = [];
      container.innerHTML = '';
      muniInput.value = '';
    });

    document.addEventListener('click', e => {
      if (!container.contains(e.target) && e.target !== muniInput) container.innerHTML = '';
    });
  }

  // paged autocomplete mechanism for large lists
  function setupPagedAutocomplete(inputSelector, dataArray) {
    const input = document.querySelector(inputSelector);
    if (!input) return;
    let page = 0;
    const pageSize = 20;
    let currentMatches = [];
    const container = document.createElement('div');
    container.className = 'autocomplete-container bg-white border position-absolute';
    container.style.zIndex = '1000';
    container.style.width = '100%';
    input.parentNode.style.position = 'relative';
    input.parentNode.appendChild(container);

    function render() {
      container.innerHTML = '';
      if (currentMatches.length === 0) return;
      const start = page * pageSize;
      const slice = currentMatches.slice(start, start + pageSize);
      slice.forEach(v => {
        const div = document.createElement('div');
        div.className = 'px-2 py-1 autocomplete-item';
        div.style.cursor = 'pointer';
        div.textContent = v;
        div.addEventListener('mousedown', () => {
          input.value = v;
          container.innerHTML = '';
        });
        container.appendChild(div);
      });
      if (currentMatches.length > pageSize) {
        const nav = document.createElement('div');
        nav.className = 'd-flex justify-content-between border-top mt-1';
        const prev = document.createElement('button');
        prev.textContent = '◀';
        prev.disabled = page === 0;
        prev.className = 'btn btn-sm';
        prev.addEventListener('click', (e) => { e.stopPropagation(); page--; render(); });
        const next = document.createElement('button');
        next.textContent = '▶';
        next.disabled = (start + pageSize) >= currentMatches.length;
        next.className = 'btn btn-sm';
        next.addEventListener('click', (e) => { e.stopPropagation(); page++; render(); });
        nav.appendChild(prev);
        nav.appendChild(next);
        container.appendChild(nav);
      }
    }

    input.addEventListener('input', () => {
      const q = input.value.toLowerCase();
      currentMatches = dataArray.filter(v => v.toLowerCase().includes(q));
      page = 0;
      render();
    });
    input.addEventListener('focus', () => {
      const q = input.value.toLowerCase();
      currentMatches = dataArray.filter(v => v.toLowerCase().includes(q));
      page = 0;
      render();
    });
    document.addEventListener('click', e => {
      if (!container.contains(e.target) && e.target !== input) container.innerHTML = '';
    });
  }

  document.addEventListener('DOMContentLoaded', function() {
    setupPagedAutocomplete('input[name="project_name"]', projectList);
    setupPagedAutocomplete('input[name="isp"]', ispList);

    // Bulk update event listeners
    document.getElementById('selectAll').addEventListener('change', function() {
      const checkboxes = document.querySelectorAll('.site-checkbox');
      checkboxes.forEach(cb => cb.checked = this.checked);
      updateBulkButton();
    });

    document.addEventListener('change', function(e) {
      if (e.target.classList.contains('site-checkbox')) {
        updateBulkButton();
        updateSelectAll();
      }
    });
  });

  function updateBulkButton() {
    const checkedBoxes = document.querySelectorAll('.site-checkbox:checked');
    const disabled = checkedBoxes.length === 0;
    document.getElementById('bulkUpdateBtn').disabled = disabled;
    document.getElementById('bulkDeleteBtn').disabled = disabled;
  }

  function updateSelectAll() {
    const allBoxes = document.querySelectorAll('.site-checkbox');
    const checkedBoxes = document.querySelectorAll('.site-checkbox:checked');
    const selectAll = document.getElementById('selectAll');
    selectAll.checked = allBoxes.length > 0 && checkedBoxes.length === allBoxes.length;
    selectAll.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < allBoxes.length;
  }

  function openBulkUpdateModal() {
    const checkedBoxes = document.querySelectorAll('.site-checkbox:checked');
    if (checkedBoxes.length === 0) {
      showAlert('Please select at least one site.', 'warning');
      return;
    }
    const modal = new bootstrap.Modal(document.getElementById('bulkUpdateModal'));
    modal.show();
    // Setup autocomplete for ISP in modal
    setupPagedAutocomplete('#bulkUpdateModal input[name="isp"]', ispList);
    // Setup location selectors
    initLocationSelectors();
  }

  function submitBulkUpdate() {
    const form = document.getElementById('bulkUpdateForm');
    const formData = new FormData(form);
    const selectedIds = Array.from(document.querySelectorAll('.site-checkbox:checked')).map(cb => cb.value);
    selectedIds.forEach(id => formData.append('selected_ids[]', id));
    formData.append('action', 'bulk_update');

    fetch('site.php', {method: 'POST', body: formData})
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert(`Successfully updated ${data.updated} sites.`, 'success');
        bootstrap.Modal.getInstance(document.getElementById('bulkUpdateModal')).hide();
        setTimeout(() => location.reload(), 1500);
      } else {
        showAlert(data.error || 'Update failed.', 'error');
      }
    })
    .catch(err => {
      showAlert('Network error.', 'error');
    });
  }

  function confirmBulkDelete() {
    const checked = document.querySelectorAll('.site-checkbox:checked');
    if (checked.length === 0) return;
    if (!confirm(`Are you sure you want to delete ${checked.length} site(s)? This cannot be undone.`)) return;
    submitBulkDelete();
  }

  function submitBulkDelete() {
    const formData = new FormData();
    const selectedIds = Array.from(document.querySelectorAll('.site-checkbox:checked')).map(cb => cb.value);
    selectedIds.forEach(id => formData.append('selected_ids[]', id));
    formData.append('csrf_token', '<?php echo htmlspecialchars(generateCSRFToken()); ?>');
    formData.append('action', 'bulk_delete');

    fetch('site.php', {method: 'POST', body: formData})
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showAlert(`Deleted ${data.deleted} sites.`, 'success');
        setTimeout(() => location.reload(), 1500);
      } else {
        showAlert(data.error || 'Delete failed.', 'error');
      }
    })
    .catch(err => {
      showAlert('Network error.', 'error');
    });
  }

</script>
</body>
</html>