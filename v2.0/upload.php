<?php
// Prevent output contamination
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start(); // Buffer output to catch stray errors

require_once 'MetadataScrambler.php';

// Security: Generate a random nonce for CSP
$nonce = base64_encode(random_bytes(16));

// Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000;');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self';");

// Enforce HTTPS
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

// Set directories and limits
$uploadDir = 'Uploads/';
$processedDir = 'processed/';
$maxFileSize = 10 * 1024 * 1024; // 10MB
$logFile = __DIR__ . '/logs/app.log';

// Create directories with proper permissions
foreach ([$uploadDir, $processedDir, dirname($logFile)] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Custom logging function
function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Clean up old files (older than 5 minutes)
function cleanUpFiles($dir) {
    $expiry = 300; // 5 minutes
    foreach (glob($dir . '*') as $file) {
        if (is_file($file) && filemtime($file) < time() - $expiry) {
            writeLog("Cleaning up old file: $file");
            unlink($file);
        }
    }
}
cleanUpFiles($uploadDir);
cleanUpFiles($processedDir);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    writeLog("Received file upload: {$file['name']}, Type: {$file['type']}, Size: {$file['size']}");

    // Validate file type and size
    $allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/webp',
        'application/pdf',
        'audio/mpeg',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    if (!in_array($file['type'], $allowedTypes)) {
        writeLog("Invalid file type for {$file['name']}: {$file['type']}");
        http_response_code(400);
        ob_end_clean();
        echo json_encode(['error' => 'Invalid file type. Supported: JPEG, PNG, GIF, BMP, WebP, PDF, MP3, DOC, DOCX']);
        exit;
    }
    if ($file['size'] > $maxFileSize) {
        writeLog("File too large for {$file['name']}: {$file['size']} bytes");
        http_response_code(400);
        ob_end_clean();
        echo json_encode(['error' => 'File too large. Max size: 10MB']);
        exit;
    }

    // Sanitize and store with temporary name
    $tempFileName = bin2hex(random_bytes(8)) . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
    $uploadPath = $uploadDir . $tempFileName;
    writeLog("Using temporary name: $tempFileName, Upload path: $uploadPath");

    // Generate random filename for processed file
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $randomFileName = bin2hex(random_bytes(8)) . '.' . $extension;
    $outputPath = $processedDir . $randomFileName;
    writeLog("Generated random filename: $randomFileName");

    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        writeLog("File uploaded successfully to $uploadPath");

        // Use MetadataScrambler with options
        $scrambler = new MetadataScrambler($logFile, [
            'strip_only' => false,
            'preserve_quality' => true,
            'jpeg_quality' => 95,
            'add_fake_gps' => true,
            'validate_output' => true,
            'scramble_file_timestamps' => false,
            'use_same_timestamp' => true,
            'manipulate_system_time' => false,
            'obfuscate_hashes' => true,
            'hash_obfuscation_methods' => ['noise', 'artifacts'],
            'noise_intensity' => 1,
            'log_hash_changes' => false
        ]);
        $metadata = $scrambler->generateRandomMetadata();
        $result = $scrambler->scramble($uploadPath, $outputPath, $file['type']);

        if ($result) {
            // Generate a token for downloading the file
            $token = bin2hex(random_bytes(16));
            file_put_contents($processedDir . $token, $outputPath);
            writeLog("Generated token $token for $outputPath");

            // Return metadata, new filename, and token
            header('Content-Type: application/json');
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'scrambledMetadata' => $metadata,
                'filename' => $randomFileName,
                'token' => $token
            ]);

            // Clean up uploaded file
            unlink($uploadPath);
        } else {
            writeLog("Metadata scrambling failed for $uploadPath");
            unlink($uploadPath);
            http_response_code(500);
            ob_end_clean();
            echo json_encode(['error' => 'Metadata scrambling failed. Check server logs for details.']);
        }
        exit;
    } else {
        writeLog("File upload failed for {$file['name']}");
        http_response_code(500);
        ob_end_clean();
        echo json_encode(['error' => 'File upload failed']);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
    $token = preg_replace('/[^A-Za-z0-9]/', '', $_GET['token']);
    $tokenFile = $processedDir . $token;
    if (file_exists($tokenFile)) {
        $outputPath = file_get_contents($tokenFile);
        if (file_exists($outputPath)) {
            writeLog("Serving processed file: $outputPath");
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($outputPath) . '"');
            ob_end_clean();
            readfile($outputPath);
            unlink($outputPath);
            unlink($tokenFile);
            exit;
        } else {
            writeLog("Processed file not found for token: $token");
            http_response_code(404);
            ob_end_clean();
            echo json_encode(['error' => 'File not found']);
            exit;
        }
    } else {
        writeLog("Invalid or expired token: $token");
        http_response_code(400);
        ob_end_clean();
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }
}
ob_end_clean();
?>