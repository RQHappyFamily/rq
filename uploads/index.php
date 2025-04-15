<?php
// ======================================================================
// == PHP: Setup, Config, Helpers, Init, Request Handling ==
// ======================================================================
ini_set('display_errors', 1); // แสดงข้อผิดพลาด (สำหรับ Development) - ควรปิดใน Production
error_reporting(E_ALL);      // แสดงข้อผิดพลาดทั้งหมด (สำหรับ Development)
// ตั้งค่า Internal Encoding เป็น UTF-8 (Requires mbstring extension)
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding("UTF-8");
}

// --- Configuration ---
$uploadDir = 'uploads/';             // *** สำคัญ: โฟลเดอร์สำหรับเก็บไฟล์ (ต้องมีอยู่จริง และมี permission ให้ Web Server เขียน/ลบได้) ***
$maxFileSize = 2 * 1024 * 1024 * 1024; // ขนาดไฟล์สูงสุดที่สคริปต์นี้อนุญาต (2GB) - ต้องไม่เกินค่าใน php.ini
$maxFiles = 10;                      // จำนวนไฟล์สูงสุดที่อัพโหลดได้พร้อมกัน
$allowedMimeTypes = [                // MIME types ที่อนุญาต
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/bmp' => 'bmp',
    'image/webp' => 'webp',
    'video/mp4' => 'mp4',
    'video/x-msvideo' => 'avi',
    'video/quicktime' => 'mov',
    'video/x-ms-wmv' => 'wmv',
    'video/x-matroska' => 'mkv',
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',
];
$metadataFile = $uploadDir . 'file_metadata.json'; // ไฟล์สำหรับเก็บข้อมูล Metadata

// --- Helper Functions ---

/**
 * แปลงค่าขนาดไฟล์จาก php.ini
 */
function parse_size($size_str) {
    $size_str = trim($size_str); if (empty($size_str)) return 0;
    $last = strtolower(substr($size_str, -1)); $val = intval($size_str);
    switch($last) { case 'g': $val *= 1024; case 'm': $val *= 1024; case 'k': $val *= 1024; }
    return $val > 0 ? $val : 0;
}

/**
 * แปลง timestamp เป็นวันที่-เวลา ภาษาไทย
 */
function thaiDateTime($timestamp) {
    if (!is_numeric($timestamp) || $timestamp <= 0) return 'N/A';
    $thaiMonths = [ 1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.' ];
    $monthIndex = (int)date('n', $timestamp); if ($monthIndex < 1 || $monthIndex > 12) return 'Invalid Date';
    $year = date('Y', $timestamp) + 543; $month = $thaiMonths[$monthIndex]; $day = date('d', $timestamp); $time = date('H:i', $timestamp);
    return "$day $month $year $time";
}

/**
 * สร้างชื่อไฟล์สำหรับจัดเก็บบน Server (Sanitized)
 */
function generateUniqueFileName(string $originalName, string $uploadDir): string {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $baseName = preg_replace('/[^\pL\pN_\-\.]/u', '_', $baseName); // Allow Unicode letters/numbers, underscore, hyphen, dot
    $baseName = trim(substr($baseName, 0, 100));
    $baseName = preg_replace('/\s+/', '_', $baseName); // Replace spaces with underscores FOR STORED NAME ONLY
    if (empty($baseName)) $baseName = 'file';

    $counter = 0; $storedFileName = $baseName . ($extension ? '.' . $extension : ''); $targetPath = $uploadDir . $storedFileName;
    while (file_exists($targetPath)) {
        $counter++; $storedFileName = $baseName . '_' . $counter . ($extension ? '.' . $extension : ''); $targetPath = $uploadDir . $storedFileName;
        if ($counter > 1000) { $storedFileName = uniqid($baseName . '_', true) . ($extension ? '.' . $extension : ''); error_log("Collision fallback for $originalName"); break; }
    }
    return $storedFileName;
}

/**
 * โหลดข้อมูล Metadata
 */
function loadMetadata(string $metadataFile): array {
    if (file_exists($metadataFile) && is_readable($metadataFile)) { $json = @file_get_contents($metadataFile); if ($json === false) { error_log("Read failed: " . $metadataFile); return []; } if (empty(trim($json))) { return []; } $data = json_decode($json, true); if (json_last_error() !== JSON_ERROR_NONE) { error_log("JSON decode failed: " . $metadataFile . " - Error: " . json_last_error_msg()); return []; } return is_array($data) ? $data : []; } return [];
}

/**
 * บันทึกข้อมูล Metadata (ใช้ atomic rename)
 */
function saveMetadata(string $metadataFile, array $metadata): bool {
     $dir = dirname($metadataFile); if (!is_dir($dir)) { if (!@mkdir($dir, 0755, true) && !is_dir($dir)) { error_log("Mkdir failed: " . $dir); return false; } } if (!is_writable($dir)) { error_log("Dir not writable: " . $dir); return false; }
    ksort($metadata); $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); if ($json === false) { error_log("JSON encode failed: " . json_last_error_msg()); return false; }
    $tempFile = $metadataFile . '.' . bin2hex(random_bytes(6)) . '.tmp'; if (@file_put_contents($tempFile, $json, LOCK_EX) === false) { error_log("Write temp failed: " . $tempFile); @unlink($tempFile); return false; } @chmod($tempFile, 0644);
    if (!@rename($tempFile, $metadataFile)) { error_log("Rename failed: " . $tempFile . " to " . $metadataFile); @unlink($tempFile); if (@file_put_contents($metadataFile, $json, LOCK_EX) === false) { error_log("Fallback write failed: " . $metadataFile); return false; } } return true;
}

/**
 * แปลงขนาดไฟล์เป็นหน่วยที่อ่านง่าย
 */
function formatBytes($bytes, $precision = 2) {
    if (!is_numeric($bytes) || $bytes < 0) { return 'N/A'; } $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']; $bytes = max($bytes, 0); if ($bytes == 0) return '0 B';
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); $pow = min($pow, count($units) - 1); $bytes /= pow(1024, $pow); if (!is_finite($bytes)) { return 'N/A'; } return round($bytes, $precision) . ' ' . $units[$pow];
}

// --- Initialization ---
$successMessages = []; $errorMessages = [];
if (!is_dir($uploadDir)) { if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) { error_log("FATAL: Cannot create dir '$uploadDir'"); $errorMessages[] = "Error: DIR_CREATE"; goto end_processing; } }
if (!is_writable($uploadDir)) { error_log("FATAL: Dir not writable '$uploadDir'"); $errorMessages[] = "Error: DIR_WRITE"; goto end_processing; }
$metadata = loadMetadata($metadataFile);
$postMaxSize = parse_size(ini_get('post_max_size')); $uploadMaxFilesize = parse_size(ini_get('upload_max_filesize'));
$effectiveMaxFileSize = $maxFileSize; if ($uploadMaxFilesize > 0) { $effectiveMaxFileSize = min($effectiveMaxFileSize, $uploadMaxFilesize); } if ($postMaxSize > 0) { $effectiveMaxFileSize = min($effectiveMaxFileSize, $postMaxSize); }

// --- Request Handling ---

// --- >> SECTION: Handle File Deletion (SINGLE FILE ONLY) << ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_action']) && $_POST['delete_action'] === 'delete_single') {
    $metadataChanged = false;
    if (isset($_POST['file_to_delete']) && is_string($_POST['file_to_delete']) && !empty($_POST['file_to_delete'])) {
        $storedFileName = basename($_POST['file_to_delete']);
        if (!isset($metadata) || !is_array($metadata)) { $errorMessages[] = "Error: DEL_META_MISSING"; error_log("CRITICAL: Metadata array not available during single delete."); }
        elseif (isset($metadata[$storedFileName])) {
            $filePath = $uploadDir . $storedFileName; $originalFileName = $metadata[$storedFileName]['original_name'] ?? $storedFileName;
            $realUploadDir = realpath($uploadDir); $realFilePath = realpath($filePath);
            if ($realFilePath && $realUploadDir && strpos($realFilePath, $realUploadDir) === 0) {
                if (@unlink($filePath)) { unset($metadata[$storedFileName]); $successMessages[] = "ลบไฟล์ '" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "' สำเร็จ!"; $metadataChanged = true; }
                else { $unlinkError = error_get_last(); $errorMessages[] = "เกิดพลาดในการลบ '" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "'"; error_log("Unlink failed (Single): " . $filePath . ($unlinkError ? " | Error: " . $unlinkError['message'] : "")); }
            } else {
                 if (!file_exists($filePath)) { $errorMessages[] = "ไม่พบไฟล์ '" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "'"; }
                 else { $errorMessages[] = "Path ของ '" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "' ไม่ถูกต้อง"; error_log("Invalid path (Single): " . $filePath); }
                 if(isset($metadata[$storedFileName])){ unset($metadata[$storedFileName]); $metadataChanged = true; }
            }
        } else { $errorMessages[] = "ไม่พบข้อมูล '" . htmlspecialchars($storedFileName, ENT_QUOTES, 'UTF-8') . "'"; }
        if ($metadataChanged) { if (!saveMetadata($metadataFile, $metadata)) { $errorMessages[] = "เกิดพลาดในการบันทึกข้อมูลหลังลบ!"; error_log("Save metadata failed (Single Delete): " . $metadataFile); } }
    } else { $errorMessages[] = "ชื่อไฟล์ที่จะลบไม่ถูกต้อง"; }
} // End if POST delete_single


// --- >> SECTION: Handle File Upload << ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileToUpload'])) {
    if ($postMaxSize > 0 && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > $postMaxSize) { $errorMessages[] = "ข้อมูลใหญ่เกิน Server (" . ini_get('post_max_size') . ")"; }
    else {
        $files = $_FILES['fileToUpload']; $metadataChanged = false; $normalizedFiles = [];
        if (isset($files['name']) && is_array($files['name'])) { $fileKeys = array_keys($files['name']); foreach ($fileKeys as $i) { if ($files['error'][$i] !== UPLOAD_ERR_NO_FILE) { $normalizedFiles[] = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i],]; } } }
        elseif (isset($files['name']) && $files['error'] !== UPLOAD_ERR_NO_FILE) { $normalizedFiles[] = $files; }
        $uploadCounter = count($normalizedFiles);
        if ($uploadCounter > $maxFiles) { $errorMessages[] = "อัพโหลดได้สูงสุด $maxFiles ไฟล์ (เลือก $uploadCounter ไฟล์)"; }
        elseif ($uploadCounter > 0) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (!$finfo) { $errorMessages[] = "Error: Cannot init fileinfo"; error_log("Failed finfo_open."); }
            else {
                foreach ($normalizedFiles as $file) {
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $tmpName = $file['tmp_name'];
                        // ** Use original name directly from $_FILES **
                        $originalFileName = $file['name'];
                        $fileSize = $file['size'];
                        if (empty(trim($originalFileName))) { $errorMessages[] = "ชื่อไฟล์ต้นฉบับไม่ถูกต้อง"; continue; }

                        // ** Optional Debugging Block (Commented Out) **
                        /*
                        echo '<pre style="background: #ffc; border: 2px solid red; padding: 10px; margin: 10px; text-align: left; direction: ltr; font-size: 14px; line-height: 1.6;">';
                        echo '<strong>DEBUG UPLOAD - VALUES BEFORE SAVING METADATA</strong><hr>';
                        echo '<strong>$originalFileName (Raw):</strong><br><span style="color: blue;">' . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "</span><br>";
                        echo 'Hex: '; for ($i = 0; $i < strlen($originalFileName); $i++) { echo dechex(ord($originalFileName[$i])) . ' '; } echo '<br>';
                        echo '------------------------------------<br>';
                        // Generate stored name here only for debug comparison
                        $_debug_storedName = generateUniqueFileName($originalFileName, $uploadDir);
                        echo '<strong>$storedFileName (Generated - For Debug):</strong><br><span style="color: green;">' . htmlspecialchars($_debug_storedName, ENT_QUOTES, 'UTF-8') . "</span><br>";
                        echo 'Hex: '; for ($i = 0; $i < strlen($_debug_storedName); $i++) { echo dechex(ord($_debug_storedName[$i])) . ' '; } echo '<br>';
                        echo '</pre>';
                        */

                        if (!is_uploaded_file($tmpName)) { $errorMessages[] = "Security error: '" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "'"; continue; }
                        if ($fileSize == 0) { $errorMessages[] = "'" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "' ขนาด 0 bytes"; continue; }
                        if ($fileSize > $effectiveMaxFileSize) { $errorMessages[] = "'" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "' ใหญ่เกินไป (" . number_format($effectiveMaxFileSize/1024/1024, 2) . " MB)"; continue; }
                        $mimeType = finfo_file($finfo, $tmpName);
                        if ($mimeType === false) { $errorMessages[] = "Cannot check type: '" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "'"; continue; }
                        if (!array_key_exists($mimeType, $allowedMimeTypes)) { $errorMessages[] = "'" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "' ประเภท ($mimeType) ไม่ได้รับอนุญาต"; continue; }

                        // Generate unique STORED filename (sanitized)
                        $storedFileName = generateUniqueFileName($originalFileName, $uploadDir);
                        $targetPath = $uploadDir . $storedFileName;
                        if (move_uploaded_file($tmpName, $targetPath)) {
                            $successMessages[] = "อัพโหลด '" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "' สำเร็จ!";
                            // ** Store the UNMODIFIED $originalFileName in metadata **
                            $metadata[$storedFileName] = [
                                'original_name' => $originalFileName, // <-- Store raw name
                                'stored_name'   => $storedFileName,
                                'mime_type'     => $mimeType,
                                'size'          => $fileSize,
                                'upload_time'   => time()
                            ];
                            $metadataChanged = true; @chmod($targetPath, 0644);
                        } else { $errorMessages[] = "เกิดพลาดในการบันทึกไฟล์ '" . htmlspecialchars($originalFileName, ENT_QUOTES, 'UTF-8') . "'"; error_log("move_uploaded_file failed: $tmpName to $targetPath"); }
                    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) { /* ... error handling ... */
                         $errorMsg = "เกิดพลาดกับ '" . htmlspecialchars(basename($file['name']), ENT_QUOTES, 'UTF-8') . "': "; switch ($file['error']) { case UPLOAD_ERR_INI_SIZE: $errorMsg .= "ใหญ่เกิน Server"; break; case UPLOAD_ERR_FORM_SIZE: $errorMsg .= "ใหญ่เกินฟอร์ม"; break; case UPLOAD_ERR_PARTIAL: $errorMsg .= "อัพฯไม่สมบูรณ์"; break; case UPLOAD_ERR_NO_TMP_DIR: $errorMsg .= "ไม่พบ tmp dir"; error_log("PHP Upload Error: UPLOAD_ERR_NO_TMP_DIR"); break; case UPLOAD_ERR_CANT_WRITE: $errorMsg .= "เขียน disk ไม่ได้"; error_log("PHP Upload Error: UPLOAD_ERR_CANT_WRITE"); break; case UPLOAD_ERR_EXTENSION: $errorMsg .= "Extension หยุด"; error_log("PHP Upload Error: UPLOAD_ERR_EXTENSION"); break; default: $errorMsg .= "(Code: " . $file['error'] . ")"; break; } $errorMessages[] = $errorMsg;
                    }
                } // End foreach
                finfo_close($finfo);
                if ($metadataChanged) { if (!saveMetadata($metadataFile, $metadata)) { $errorMessages[] = "เกิดพลาดในการบันทึกข้อมูลหลังอัพโหลด!"; error_log("Save metadata failed (Upload): " . $metadataFile); } }
            } // End finfo check
        } // End uploadCounter check
    } // End POST size check
} // End if POST fileToUpload


// --- >> SECTION: Data Preparation for Display << ---
$currentMetadata = loadMetadata($metadataFile); $uploadedFilesData = []; $metadataWasCleaned = false;
foreach ($currentMetadata as $storedName => $data) {
    if (!is_array($data) || !isset($data['original_name']) || !is_string($storedName) || empty($storedName)) { error_log("Invalid metadata entry removed: " . $storedName); unset($currentMetadata[$storedName]); $metadataWasCleaned = true; continue; }
    $filePath = $uploadDir . $storedName;
    if (file_exists($filePath) && is_file($filePath)) { $uploadedFilesData[$storedName] = ['original_name' => $data['original_name'], 'stored_name' => $storedName, 'time' => $data['upload_time'] ?? @filemtime($filePath), 'size' => $data['size'] ?? @filesize($filePath)]; }
    else { error_log("Stale metadata entry removed: " . $filePath); unset($currentMetadata[$storedName]); $metadataWasCleaned = true; }
}
if ($metadataWasCleaned) { if (!saveMetadata($metadataFile, $currentMetadata)) { $errorMessages[] = "เกิดพลาดในการล้างข้อมูลไฟล์เก่า"; error_log("Failed to save cleaned metadata: " . $metadataFile); } $metadata = $currentMetadata; }
uasort($uploadedFilesData, function($a, $b) { return ($b['time'] ?? 0) <=> ($a['time'] ?? 0); });

end_processing: // Label for goto jump
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <title>อัพโหลดและจัดการไฟล์</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📄</text></svg>">
    <style>
        /* CSS - Same as previous */
        body { font-family: 'Prompt', sans-serif; background: linear-gradient(135deg, #F5F6F5 0%, #E5E7EB 100%); color: #333; margin: 0; padding: 2rem; min-height: 100vh; display: flex; flex-direction: column; align-items: center; }
        .container { background-color: white; padding: 2.5rem; border-radius: 15px; box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1); width: 100%; max-width: 1200px; display: flex; flex-direction: column; gap: 2.5rem; }
        .upload-section, .file-list-section { width: 100%; text-align: left; }
        h2 { color: #00A859; margin-top: 0; margin-bottom: 1.5rem; font-size: 1.8rem; font-weight: 500; border-bottom: 2px solid #E5E7EB; padding-bottom: 0.5rem; display: flex; align-items: center; }
        h2 i { margin-right: 10px; color: #00A859; }
        .form-group { margin-bottom: 1.5rem; }
        input[type="file"] { display: block; width: 100%; padding: 0.75rem; border: 2px solid #E5E7EB; border-radius: 8px; margin-bottom: 0.75rem; background-color: #F9FAFB; transition: border-color 0.3s; font-family: inherit; font-size: 0.95rem; box-sizing: border-box; }
        input[type="file"]:focus { border-color: #00A859; outline: none; }
        input[type="file"]::file-selector-button { background-color: #00A859; color: white; padding: 0.75rem 1rem; border: none; border-radius: 6px; cursor: pointer; transition: background-color 0.3s; margin-right: 1rem; font-family: inherit; font-size: 0.9rem; }
        input[type="file"]::file-selector-button:hover { background-color: #008B47; }
        input[type="submit"], .action-button, button.action-button { background-color: #00A859; color: white; padding: 0.8rem 1.8rem; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.3s, transform 0.2s; font-size: 1rem; font-weight: 500; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 0.5rem; vertical-align: middle; line-height: 1; box-sizing: border-box; }
        input[type="submit"] { width: auto; }
        input[type="submit"]:hover, .action-button:hover, button.action-button:hover { background-color: #008B47; transform: translateY(-2px); }
        button:disabled, input[type="submit"]:disabled { background-color: #9CA3AF; color: #E5E7EB; cursor: not-allowed; transform: none; }
        .delete-button { background-color: #EF4444; padding: 0.6rem 1.2rem; font-size: 0.9rem; }
        .delete-button:hover { background-color: #DC2626; }
        .info { color: #6B7280; font-size: 0.9rem; margin-top: 1.5rem; line-height: 1.6; background-color: #F9FAFB; padding: 1rem 1.5rem; border-radius: 8px; border: 1px solid #E5E7EB; }
        .messages { position: fixed; top: 15px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 800px; z-index: 1050; opacity: 1; transition: opacity 0.5s ease-out 1s; }
        .messages.hidden { opacity: 0; pointer-events: none; }
        .message-item { padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 0.75rem; border-left-width: 5px; border-left-style: solid; animation: slideIn 0.4s ease-out forwards; box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1); display: flex; align-items: center; gap: 0.75rem; color: #333; line-height: 1.4; }
        .message-item .message-text { flex-grow: 1; word-break: break-word; } /* Allow message text to wrap */
        .message-item::before { font-family: "Font Awesome 5 Free"; font-weight: 900; font-size: 1.2rem; line-height: 1; flex-shrink: 0; }
        .success { background-color: #ECFDF5; color: #047857; border-left-color: #10B981; } .success::before { content: "\f058"; color: #10B981; }
        .error { background-color: #FEF2F2; color: #B91C1C; border-left-color: #EF4444; } .error::before { content: "\f071"; color: #EF4444; }
        .file-table-wrapper { overflow-x: auto; width: 100%; margin-top: 1rem; }
        .file-table { width: 100%; border-collapse: separate; border-spacing: 0; background-color: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05); min-width: 600px; }
        .file-table th, .file-table td { padding: 1rem 1.2rem; text-align: left; vertical-align: middle; white-space: nowrap; border-bottom: 1px solid #E5E7EB; }
        .file-table tr:last-child td { border-bottom: none; }
        .file-table th { background-color: #F9FAFB; color: #374151; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom-width: 2px; }
        .file-table td { font-size: 0.95rem; }
        .file-table tr:hover { background-color: #fcfcfc; }
        .action-buttons { display: flex; gap: 0.6rem; align-items: center; justify-content: center; }
        .file-table td.action-buttons { white-space: nowrap; }
        .view-link { background-color: #3B82F6; padding: 0.6rem 1.2rem; font-size: 0.9rem; }
        .view-link:hover { background-color: #2563EB; }
        .delete-form { margin: 0; padding: 0; display: inline-block; }
        .file-table th:nth-child(1), .file-table td:nth-child(1) { width: 60px; text-align: center; } /* Sequence */
        .file-table td:nth-child(2) { word-break: break-all; white-space: normal; max-width: 350px; min-width: 200px; } /* Filename */
        .file-table th:nth-child(3), .file-table td:nth-child(3) { width: 120px; text-align: right; } /* File Size */
        .file-table th:nth-child(4), .file-table td:nth-child(4) { min-width: 140px; } /* Date */
        .file-table th:nth-child(5), .file-table td:nth-child(5) { width: 160px; text-align: center; } /* Actions */
        @keyframes slideIn { from { transform: translateY(-15px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @media (max-width: 768px) { body { padding: 1.5rem; } .container { padding: 1.5rem; } .messages { width: 95%; } .file-table { min-width: initial; } .file-table th, .file-table td { padding: 0.8rem 0.6rem; white-space: normal; } .file-table td:nth-child(2) { max-width: 250px; min-width: 120px; } .file-table th:nth-child(4), .file-table td:nth-child(4) { display: none; } .file-table th:nth-child(1), .file-table td:nth-child(1) { display: none; } .file-table th:nth-child(3), .file-table td:nth-child(3) { width: 80px; text-align: right;} }
        @media (max-width: 480px) { body { padding: 1rem; } .container { padding: 1rem; } h2 { font-size: 1.5rem; } input[type="submit"], .action-button, button.action-button { padding: 0.7rem 1.2rem; font-size: 0.95rem; } .view-link, .delete-button { padding: 0.5rem 1rem; font-size: 0.85rem; } .action-buttons { flex-direction: column; align-items: stretch; gap: 0.4rem; } .action-buttons a, .action-buttons form, .action-buttons form button { width: 100%; text-align: center; margin: 0;} .file-table td:nth-child(2) { max-width: 180px; min-width: 100px; } .file-table th:nth-child(5), .file-table td:nth-child(5) { width: auto; min-width: auto; } .btn-text { display: none; } button.action-button i, a.action-button i { margin: 0 auto; } .file-table th:nth-child(3), .file-table td:nth-child(3) { width: 70px; font-size: 0.85rem;} }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2W4Rhis/DbILU74C1vSrLJxCq57o941Ym01SwNsOMqvEBFlcgUa6xLiPY/NS5R+E6ztJQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <div class="messages <?php echo (empty($successMessages) && empty($errorMessages)) ? 'hidden' : ''; ?>" id="messagesContainer">
        <?php if (!empty($successMessages)) { foreach ($successMessages as $msg) { echo "<div class='message-item success'><span class='message-text'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</span></div>"; } } ?>
        <?php if (!empty($errorMessages)) { foreach ($errorMessages as $msg) { echo "<div class='message-item error'><span class='message-text'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</span></div>"; } } ?>
    </div>

    <div class="container">
        <div class="upload-section">
            <h2><i class="fas fa-cloud-upload-alt"></i>อัพโหลดไฟล์</h2>
            <form action="" method="post" enctype="multipart/form-data" id="uploadForm">
                <div class="form-group">
                    <input type="file" name="fileToUpload[]" id="fileInput" multiple accept="<?php echo implode(',', array_keys($allowedMimeTypes)); ?>">
                    <p style="font-size: 0.85rem; color: #6B7280; margin-top: 0.5rem; margin-bottom: 1rem;">
                        เลือกได้สูงสุด <?php echo $maxFiles; ?> ไฟล์ (แต่ละไฟล์ไม่เกิน <?php echo number_format($effectiveMaxFileSize / 1024 / 1024, 2); ?> MB)
                    </p>
                    <div id="file-preview-list" style="font-size: 0.9em; margin-top: 10px; color: #555;"></div>
                </div>
                <button type="submit" class="action-button" id="uploadButton">
                     <i class="fas fa-upload"></i> อัพโหลดไฟล์
                </button>
            </form>
            <p class="info">
                <b>ประเภทไฟล์ที่อนุญาต:</b> <?php echo implode(', ', array_unique(array_values($allowedMimeTypes))); ?><br>
                <b>ขนาดไฟล์สูงสุดต่อไฟล์ (Server):</b> <?php echo ini_get('upload_max_filesize'); ?><br>
                <b>จำนวนไฟล์สูงสุดต่อครั้ง:</b> <?php echo $maxFiles; ?> ไฟล์<br>
                <b>ขนาดอัพโหลดรวมสูงสุด (Server):</b> <?php echo ini_get('post_max_size'); ?>
            </p>
        </div>

        <div class="file-list-section">
             <h2><i class="fas fa-list-ul"></i>รายการไฟล์ (<?php echo count($uploadedFilesData); ?> ไฟล์)</h2>
             <?php if (!empty($uploadedFilesData)): ?>
                 <div class="file-table-wrapper">
                     <table class="file-table">
                         <thead>
                             <tr>
                                 <th>ลำดับ</th>
                                 <th>ชื่อไฟล์เดิม</th>
                                 <th>ขนาดไฟล์</th>
                                 <th>วันที่อัพโหลด</th>
                                 <th>ดำเนินการ</th>
                             </tr>
                         </thead>
                         <tbody>
                             <?php $counter = 1; ?>
                             <?php foreach ($uploadedFilesData as $storedName => $file): ?>
                                 <tr>
                                     <td><?php echo $counter++; ?></td>
                                     <td><?php echo htmlspecialchars($file['original_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                     <td style="text-align: right;"><?php echo formatBytes($file['size'] ?? 0); ?></td>
                                     <td><?php echo thaiDateTime($file['time']); ?></td>
                                     <td class="action-buttons">
                                         <a href="<?php echo htmlspecialchars($uploadDir . $storedName, ENT_QUOTES, 'UTF-8'); ?>" class="action-button view-link" target="_blank" title="เปิดดูไฟล์ <?php echo htmlspecialchars($file['original_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                             <i class="fas fa-eye"></i> <span class="btn-text">ดู</span>
                                         </a>
                                         <form action="" method="post" class="delete-form" onsubmit="return confirmSingleDelete(event, '<?php echo htmlspecialchars($file['original_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>')">
                                              <input type="hidden" name="delete_action" value="delete_single">
                                              <input type="hidden" name="file_to_delete" value="<?php echo htmlspecialchars($storedName, ENT_QUOTES, 'UTF-8'); ?>">
                                              <button type="submit" class="action-button delete-button" title="ลบไฟล์ <?php echo htmlspecialchars($file['original_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                  <i class="fas fa-trash-alt"></i> <span class="btn-text">ลบ</span>
                                              </button>
                                         </form>
                                     </td>
                                 </tr>
                             <?php endforeach; ?>
                         </tbody>
                     </table>
                 </div>
             <?php else: ?>
                 <p style="text-align: center; color: #6B7280; margin-top: 2rem; padding: 1rem; background-color: #f9fafb; border-radius: 8px;"><i class="fas fa-info-circle" style="margin-right: 5px;"></i>ยังไม่มีไฟล์ที่อัพโหลด</p>
             <?php endif; ?>
        </div>
    </div>

    <script>
         // --- Event Listeners ---
        document.addEventListener('DOMContentLoaded', () => {
            adjustButtonText();
            const messagesContainer = document.getElementById('messagesContainer');
            if (messagesContainer && !messagesContainer.classList.contains('hidden')) { setTimeout(() => { messagesContainer.style.opacity = '0'; setTimeout(() => { if (messagesContainer) messagesContainer.style.display = 'none'; }, 500); }, 7000); }
             const fileInput = document.getElementById('fileInput'); const previewList = document.getElementById('file-preview-list');
             if (fileInput && previewList) { fileInput.addEventListener('change', function() { previewList.innerHTML = ''; if (this.files.length > 0) { const list = document.createElement('ul'); list.style.listStyle = 'none'; list.style.paddingLeft = '0'; list.style.marginTop = '0.5rem'; let totalSize = 0; const maxFilesToShow = 20; for (let i = 0; i < this.files.length; i++) { const file = this.files[i]; totalSize += file.size; if (i < maxFilesToShow) { const listItem = document.createElement('li'); listItem.style.marginBottom = '0.3rem'; listItem.innerHTML = `<i class="far fa-file" style="margin-right: 5px; color: #666;"></i> ${escapeHtml(file.name)} <span style="color: #888;">(${(file.size / 1024 / 1024).toFixed(2)} MB)</span>`; list.appendChild(listItem); } else if (i === maxFilesToShow) { const listItem = document.createElement('li'); listItem.style.marginTop = '0.5rem'; listItem.style.color = '#555'; listItem.textContent = `... และอีก ${this.files.length - maxFilesToShow} ไฟล์`; list.appendChild(listItem); } } const totalSizeItem = document.createElement('li'); totalSizeItem.style.marginTop = '0.7rem'; totalSizeItem.style.fontWeight = '500'; totalSizeItem.textContent = `ขนาดรวม: ${(totalSize / 1024 / 1024).toFixed(2)} MB (${this.files.length} ไฟล์)`; list.appendChild(totalSizeItem); previewList.appendChild(list); } }); }
             const uploadForm = document.getElementById('uploadForm'); const uploadButton = document.getElementById('uploadButton');
             if (uploadForm && uploadButton) { uploadForm.addEventListener('submit', function(event) { if (document.getElementById('fileInput').files.length > 0) { uploadButton.disabled = true; uploadButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังอัพโหลด...'; } else { event.preventDefault(); Swal.fire({ icon: 'info', title: 'ไม่ได้เลือกไฟล์', text: 'กรุณาเลือกไฟล์ก่อนกดอัพโหลด' }); } }); }
        });
        window.addEventListener('resize', adjustButtonText);

        // --- SweetAlert2 Functions ---
        function confirmSingleDelete(event, originalFileName) {
             event.preventDefault(); const form = event.target.closest('form'); if (!form) return false;
             Swal.fire({ title: 'ยืนยันการลบไฟล์', html: `คุณแน่ใจหรือไม่ที่จะลบไฟล์:<br><b>${escapeHtml(originalFileName)}</b>?<br><small>การกระทำนี้ไม่สามารถย้อนกลับได้</small>`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#EF4444', cancelButtonColor: '#6B7280', confirmButtonText: '<i class="fas fa-trash-alt"></i> ใช่, ลบเลย', cancelButtonText: 'ยกเลิก', reverseButtons: true }).then((result) => { if (result.isConfirmed) { form.submit(); } }); return false;
        }
         // --- Helper & UI Functions ---
         function escapeHtml(unsafe) { if (typeof unsafe !== 'string') { unsafe = String(unsafe); } return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;"); }
         function adjustButtonText() { const buttons = document.querySelectorAll('.action-buttons .action-button'); const isSmallScreen = window.innerWidth <= 480; buttons.forEach(button => { const textSpan = button.querySelector('.btn-text'); if (textSpan) { textSpan.style.display = isSmallScreen ? 'none' : 'inline'; } button.style.padding = ''; }); }
    </script>
</body>
</html>