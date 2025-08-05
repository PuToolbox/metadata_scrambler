<?php
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

    // Security: Validate file type and size
    $allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/webp',
        'application/pdf',
        'audio/mpeg',
        'application/msword', // DOC
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' // DOCX
    ];
    if (!in_array($file['type'], $allowedTypes)) {
        writeLog("Invalid file type for {$file['name']}: {$file['type']}");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file type. Supported: JPEG, PNG, GIF, BMP, WebP, PDF, MP3, DOC, DOCX']);
        exit;
    }
    if ($file['size'] > $maxFileSize) {
        writeLog("File too large for {$file['name']}: {$file['size']} bytes");
        http_response_code(400);
        echo json_encode(['error' => 'File too large. Max size: 10MB']);
        exit;
    }

    // Security: Sanitize file name
    $fileName = preg_replace('/[^A-Za-z0-9._-]/', '', basename($file['name']));
    $uploadPath = $uploadDir . $fileName;
    writeLog("Sanitized file name: $fileName, Upload path: $uploadPath");

    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        writeLog("File uploaded successfully to $uploadPath");
        $outputPath = $processedDir . 'scrambled_' . $fileName;

        // Use MetadataScrambler library
        $scrambler = new MetadataScrambler($logFile);
        $result = $scrambler->scramble($uploadPath, $outputPath, $file['type']);

        if ($result) {
            writeLog("Serving processed file: $outputPath");
            // Serve the file for download
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($outputPath) . '"');
            readfile($outputPath);

            // Clean up both files
            writeLog("Cleaning up files: $uploadPath, $outputPath");
            unlink($uploadPath);
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            exit;
        } else {
            writeLog("Metadata scrambling failed for $uploadPath");
            unlink($uploadPath); // Clean up uploaded file on failure
            http_response_code(500);
            echo json_encode(['error' => 'Metadata scrambling failed. Check server logs for details.']);
            exit;
        }
    } else {
        writeLog("File upload failed for {$file['name']}");
        http_response_code(500);
        echo json_encode(['error' => 'File upload failed']);
        exit;
    }
}
?>