<?php
$nonce = base64_encode(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">
    <meta http-equiv="Strict-Transport-Security" content="max-age=31536000;">
    <meta http-equiv="Content-Security-Policy" 
          content="default-src 'self'; script-src 'self' 'nonce-<?php echo $nonce; ?>'; style-src 'self'; font-src 'self'; img-src 'self';">
    <title>Metadata Scrambler</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h1>Metadata Scrambler</h1>
    <p>Upload a file to scramble its metadata. Supported formats: JPEG, PNG, GIF, BMP, WebP, PDF, MP3, DOC, DOCX.</p>
    <div id="dropZone">Drag & drop files here or click to upload</div>
    <input type="file" id="fileInput" multiple accept="image/jpeg,image/png,image/gif,image/bmp,image/webp,application/pdf,audio/mpeg,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
    <div id="status"></div>
    <div id="metadata-container"></div>
    <script nonce="<?php echo $nonce; ?>" src="script.js"></script>
</body>
</html>
