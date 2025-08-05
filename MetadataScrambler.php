<?php

class MetadataScrambler
{
    private $logFile;
    private $options;

    public function __construct($logFile, $options = [])
    {
        $this->logFile = $logFile;
        $this->options = array_merge([
            'strip_only' => false,
            'preserve_quality' => true,
            'jpeg_quality' => 95,
            'add_fake_gps' => true,
            'validate_output' => true,
            'scramble_file_timestamps' => false,
            'use_same_timestamp' => true  // Use same timestamp for all file system dates
        ], $options);
        $this->writeLog("MetadataScrambler initialized with options: " . json_encode($this->options));
    }

    private function writeLog($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    private function generateRandomMetadata()
    {
        $metadata = [
            'latitude' => number_format(mt_rand(-900000, 900000) / 10000, 6),
            'longitude' => number_format(mt_rand(-1800000, 1800000) / 10000, 6),
            'date' => date('Y:m:d H:i:s', mt_rand(strtotime('2000-01-01'), time())),
            'author' => 'Anonymous_' . bin2hex(random_bytes(4)),
            'camera' => $this->getRandomCamera(),
            'software' => 'ImageProcessor ' . mt_rand(1, 9) . '.' . mt_rand(0, 9)
        ];
        $this->writeLog("Generated metadata: " . json_encode($metadata));
        return $metadata;
    }

    private function getRandomCamera()
    {
        $cameras = [
            'Canon EOS R5', 'Nikon D850', 'Sony A7R IV', 'Fujifilm X-T4',
            'Olympus OM-D E-M1 Mark III', 'Panasonic GH5', 'iPhone 14 Pro',
            'Samsung Galaxy S23', 'Google Pixel 7', 'OnePlus 11'
        ];
        return $cameras[array_rand($cameras)];
    }

    public function scramble($inputPath, $outputPath, $fileType = null)
    {
        if (!file_exists($inputPath)) {
            $this->writeLog("Input file not found: $inputPath");
            return false;
        }

        if ($fileType === null) {
            $fileType = $this->detectFileType($inputPath);
        }

        $this->writeLog("Scrambling metadata for $inputPath, Type: $fileType");
        $metadata = $this->options['strip_only'] ? null : $this->generateRandomMetadata();

        $result = false;
        switch ($fileType) {
            case 'image/jpeg':
                $result = $this->scrambleJpeg($inputPath, $outputPath, $metadata);
                break;
            case 'image/png':
                $result = $this->scramblePng($inputPath, $outputPath, $metadata);
                break;
            case 'image/gif':
                $result = $this->scrambleGif($inputPath, $outputPath, $metadata);
                break;
            case 'image/webp':
                $result = $this->scrambleWebp($inputPath, $outputPath, $metadata);
                break;
            case 'image/tiff':
                $result = $this->scrambleTiff($inputPath, $outputPath, $metadata);
                break;
            case 'application/pdf':
                $result = $this->scramblePdf($inputPath, $outputPath, $metadata);
                break;
            case 'audio/mpeg':
                $result = $this->scrambleMp3($inputPath, $outputPath, $metadata);
                break;
            case 'application/msword':
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                $result = $this->scrambleWord($inputPath, $outputPath, $fileType, $metadata);
                break;
            default:
                $this->writeLog("Unsupported file type: $fileType");
                return false;
        }

        if ($result && $this->options['validate_output']) {
            $result = $this->validateOutput($outputPath, $fileType);
        }

        // Scramble file system timestamps if enabled
        if ($result && $this->options['scramble_file_timestamps'] && $metadata) {
            $this->scrambleFileSystemTimestamps($outputPath, $metadata);
        }

        return $result;
    }

    private function detectFileType($filePath)
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        // Handle some edge cases
        if ($mimeType === 'application/octet-stream') {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    return 'image/jpeg';
                case 'png':
                    return 'image/png';
                case 'gif':
                    return 'image/gif';
                case 'webp':
                    return 'image/webp';
                case 'tif':
                case 'tiff':
                    return 'image/tiff';
            }
        }
        
        return $mimeType;
    }

    private function validateOutput($filePath, $fileType)
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            $this->writeLog("Output validation failed: file missing or empty - $filePath");
            return false;
        }

        // Basic format validation
        switch ($fileType) {
            case 'image/jpeg':
                $content = file_get_contents($filePath, false, null, 0, 4);
                if (substr($content, 0, 2) !== "\xFF\xD8") {
                    $this->writeLog("JPEG validation failed: invalid header - $filePath");
                    return false;
                }
                break;
            case 'image/png':
                $content = file_get_contents($filePath, false, null, 0, 8);
                if ($content !== "\x89PNG\x0D\x0A\x1A\x0A") {
                    $this->writeLog("PNG validation failed: invalid header - $filePath");
                    return false;
                }
                break;
            case 'application/pdf':
                $content = file_get_contents($filePath, false, null, 0, 4);
                if (substr($content, 0, 4) !== '%PDF') {
                    $this->writeLog("PDF validation failed: invalid header - $filePath");
                    return false;
                }
                break;
        }

        $this->writeLog("Output validation passed for $filePath");
        return true;
    }

    private function scrambleJpeg($inputPath, $outputPath, $metadata)
    {
        if (!function_exists('imagecreatefromjpeg')) {
            $this->writeLog("GD extension not available for JPEG processing");
            return false;
        }

        try {
            // Read and process image
            $image = imagecreatefromjpeg($inputPath);
            if ($image === false) {
                $this->writeLog("Failed to create image resource from $inputPath");
                return false;
            }

            // Save with specified quality
            $quality = $this->options['preserve_quality'] ? $this->options['jpeg_quality'] : 90;
            if (!imagejpeg($image, $outputPath, $quality)) {
                imagedestroy($image);
                $this->writeLog("Failed to save processed JPEG to $outputPath");
                return false;
            }
            imagedestroy($image);

            // Add scrambled EXIF data if not strip-only mode
            if (!$this->options['strip_only'] && $metadata) {
                $this->addComprehensiveExifToJpeg($outputPath, $metadata);
            }

            $this->writeLog("JPEG processing completed for $inputPath");
            return true;
        } catch (Exception $e) {
            $this->writeLog("JPEG processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function addComprehensiveExifToJpeg($filePath, $metadata)
    {
        $content = file_get_contents($filePath);
        if ($content === false || substr($content, 0, 2) !== "\xFF\xD8") {
            $this->writeLog("Invalid JPEG for EXIF injection: $filePath");
            return false;
        }

        // Build comprehensive EXIF data
        $exifData = $this->buildComprehensiveExifSegment($metadata);
        
        // Insert after SOI marker
        $newContent = substr($content, 0, 2) . $exifData . substr($content, 2);
        
        if (file_put_contents($filePath, $newContent) === false) {
            $this->writeLog("Failed to write EXIF data to $filePath");
            return false;
        }

        $this->writeLog("Comprehensive EXIF data added to $filePath");
        return true;
    }

    private function buildComprehensiveExifSegment($metadata)
    {
        $exifHeader = "Exif\x00\x00";
        $tiffHeader = "II" . pack('v', 42) . pack('V', 8); // Little-endian TIFF header
        
        // Calculate offsets
        $numTags = 8;
        $ifd0Start = 8; // After TIFF header
        $dataStart = $ifd0Start + 2 + ($numTags * 12) + 4; // After IFD0
        
        $currentOffset = $dataStart;
        
        // Build IFD0 entries
        $ifd0 = pack('v', $numTags);
        
        // DateTime (0x0132)
        $dateTime = str_pad($metadata['date'], 19, "\0") . "\x00";
        $ifd0 .= pack('vvVV', 0x0132, 2, 20, $currentOffset);
        $currentOffset += 20;
        
        // DateTimeOriginal (0x9003)
        $ifd0 .= pack('vvVV', 0x9003, 2, 20, $currentOffset);
        $currentOffset += 20;
        
        // Artist (0x013B)
        $artist = $metadata['author'] . "\x00";
        $ifd0 .= pack('vvVV', 0x013B, 2, strlen($artist), $currentOffset);
        $currentOffset += strlen($artist);
        
        // Software (0x0131)
        $software = $metadata['software'] . "\x00";
        $ifd0 .= pack('vvVV', 0x0131, 2, strlen($software), $currentOffset);
        $currentOffset += strlen($software);
        
        // Camera Model (0x0110)
        $camera = $metadata['camera'] . "\x00";
        $ifd0 .= pack('vvVV', 0x0110, 2, strlen($camera), $currentOffset);
        $currentOffset += strlen($camera);
        
        // GPS IFD pointer (0x8825) - only if adding fake GPS
        if ($this->options['add_fake_gps']) {
            $gpsIfdOffset = $currentOffset;
            $ifd0 .= pack('vvVV', 0x8825, 4, 1, $gpsIfdOffset);
            
            // Calculate GPS IFD size (4 tags * 12 bytes + 2 bytes for count + 4 bytes for next IFD)
            $currentOffset += 2 + (4 * 12) + 4;
        } else {
            // Placeholder entry
            $ifd0 .= pack('vvVV', 0x010F, 2, 8, $currentOffset); // Camera make
            $make = "Unknown\x00";
            $currentOffset += strlen($make);
        }
        
        // Orientation (0x0112)
        $ifd0 .= pack('vvVV', 0x0112, 3, 1, 1); // Normal orientation, stored directly
        
        // Resolution (0x011A)
        $ifd0 .= pack('vvVV', 0x011A, 5, 1, $currentOffset); // X Resolution
        $currentOffset += 8; // Rational = 8 bytes
        
        // Next IFD offset (0 = no next IFD)
        $ifd0 .= pack('V', 0);
        
        // Build data section
        $dataSection = $dateTime . $dateTime . $artist . $software . $camera;
        
        if (!$this->options['add_fake_gps']) {
            $dataSection .= "Unknown\x00";
        }
        
        // Add resolution data (72 DPI as rational)
        $dataSection .= pack('VV', 72, 1);
        
        // Add GPS IFD if enabled
        if ($this->options['add_fake_gps']) {
            $gpsIfd = $this->buildGpsIfd($metadata);
            $dataSection .= $gpsIfd;
        }
        
        $tiffData = $tiffHeader . $ifd0 . $dataSection;
        $totalLength = strlen($exifHeader . $tiffData);
        
        // APP1 segment
        $app1 = "\xFF\xE1" . pack('n', $totalLength + 2) . $exifHeader . $tiffData;
        
        $this->writeLog("Built comprehensive EXIF segment: " . strlen($app1) . " bytes");
        return $app1;
    }
    
    private function buildGpsIfd($metadata)
    {
        $numGpsTags = 4;
        $gpsIfd = pack('v', $numGpsTags);
        
        $dataStart = 2 + ($numGpsTags * 12) + 4;
        $currentOffset = $dataStart;
        
        // GPS Version (0x0000)
        $gpsIfd .= pack('vvVV', 0x0000, 1, 4, 0x02020000); // Version 2.2.0.0
        
        // GPS Latitude Ref (0x0001)
        $latRef = $metadata['latitude'] >= 0 ? "N\x00\x00\x00" : "S\x00\x00\x00";
        $gpsIfd .= pack('vvVV', 0x0001, 2, 2, unpack('V', $latRef)[1]);
        
        // GPS Latitude (0x0002)
        $gpsIfd .= pack('vvVV', 0x0002, 5, 3, $currentOffset);
        $currentOffset += 24; // 3 rationals = 24 bytes
        
        // GPS Longitude Ref (0x0003)
        $lonRef = $metadata['longitude'] >= 0 ? "E\x00\x00\x00" : "W\x00\x00\x00";
        $gpsIfd .= pack('vvVV', 0x0003, 2, 2, unpack('V', $lonRef)[1]);
        
        // Next IFD (none)
        $gpsIfd .= pack('V', 0);
        
        // GPS coordinate data
        $absLat = abs($metadata['latitude']);
        $latDeg = floor($absLat);
        $latMin = floor(($absLat - $latDeg) * 60);
        $latSec = (($absLat - $latDeg) * 60 - $latMin) * 60;
        
        $gpsIfd .= pack('VV', $latDeg, 1);          // Degrees
        $gpsIfd .= pack('VV', $latMin, 1);          // Minutes  
        $gpsIfd .= pack('VV', round($latSec * 1000), 1000); // Seconds
        
        return $gpsIfd;
    }

    private function scramblePng($inputPath, $outputPath, $metadata)
    {
        if (!function_exists('imagecreatefrompng')) {
            $this->writeLog("GD PNG support not available");
            return false;
        }

        try {
            $image = imagecreatefrompng($inputPath);
            if ($image === false) {
                $this->writeLog("Failed to create PNG image resource from $inputPath");
                return false;
            }

            // PNG processing automatically strips metadata when using GD
            if (!imagepng($image, $outputPath)) {
                imagedestroy($image);
                $this->writeLog("Failed to save PNG to $outputPath");
                return false;
            }
            imagedestroy($image);

            // Add text chunks with scrambled data if not strip-only
            if (!$this->options['strip_only'] && $metadata) {
                $this->addPngTextChunks($outputPath, $metadata);
            }

            $this->writeLog("PNG processing completed for $inputPath");
            return true;
        } catch (Exception $e) {
            $this->writeLog("PNG processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function addPngTextChunks($filePath, $metadata)
    {
        $content = file_get_contents($filePath);
        if ($content === false || substr($content, 0, 8) !== "\x89PNG\x0D\x0A\x1A\x0A") {
            $this->writeLog("Invalid PNG for text chunk injection: $filePath");
            return false;
        }

        // Find IEND chunk position
        $iendPos = strrpos($content, "IEND");
        if ($iendPos === false) {
            $this->writeLog("IEND chunk not found in PNG: $filePath");
            return false;
        }

        // Build text chunks
        $textChunks = '';
        
        // Author chunk
        $authorText = "Author\x00" . $metadata['author'];
        $authorChunk = pack('N', strlen($authorText)) . 'tEXt' . $authorText;
        $authorChunk .= pack('N', crc32('tEXt' . $authorText));
        $textChunks .= $authorChunk;
        
        // Creation time chunk
        $timeText = "Creation Time\x00" . $metadata['date'];
        $timeChunk = pack('N', strlen($timeText)) . 'tEXt' . $timeText;
        $timeChunk .= pack('N', crc32('tEXt' . $timeText));
        $textChunks .= $timeChunk;
        
        // Software chunk
        $softwareText = "Software\x00" . $metadata['software'];
        $softwareChunk = pack('N', strlen($softwareText)) . 'tEXt' . $softwareText;
        $softwareChunk .= pack('N', crc32('tEXt' . $softwareText));
        $textChunks .= $softwareChunk;

        // Insert before IEND
        $newContent = substr($content, 0, $iendPos - 4) . $textChunks . substr($content, $iendPos - 4);
        
        if (file_put_contents($filePath, $newContent) === false) {
            $this->writeLog("Failed to write PNG text chunks to $filePath");
            return false;
        }

        $this->writeLog("PNG text chunks added to $filePath");
        return true;
    }

    private function scrambleGif($inputPath, $outputPath, $metadata)
    {
        if (!function_exists('imagecreatefromgif')) {
            $this->writeLog("GD GIF support not available");
            return false;
        }

        try {
            $image = imagecreatefromgif($inputPath);
            if ($image === false) {
                $this->writeLog("Failed to create GIF image resource from $inputPath");
                return false;
            }

            if (!imagegif($image, $outputPath)) {
                imagedestroy($image);
                $this->writeLog("Failed to save GIF to $outputPath");
                return false;
            }
            imagedestroy($image);

            $this->writeLog("GIF processing completed for $inputPath (metadata stripped)");
            return true;
        } catch (Exception $e) {
            $this->writeLog("GIF processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function scrambleWebp($inputPath, $outputPath, $metadata)
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->writeLog("GD WebP support not available");
            return false;
        }

        try {
            $image = imagecreatefromwebp($inputPath);
            if ($image === false) {
                $this->writeLog("Failed to create WebP image resource from $inputPath");
                return false;
            }

            if (!imagewebp($image, $outputPath, 90)) {
                imagedestroy($image);
                $this->writeLog("Failed to save WebP to $outputPath");
                return false;
            }
            imagedestroy($image);

            $this->writeLog("WebP processing completed for $inputPath (metadata stripped)");
            return true;
        } catch (Exception $e) {
            $this->writeLog("WebP processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function scrambleTiff($inputPath, $outputPath, $metadata)
    {
        // TIFF is complex, so we'll do a basic copy with metadata removal
        // In a real implementation, you'd want a proper TIFF library
        try {
            $content = file_get_contents($inputPath);
            if ($content === false) {
                $this->writeLog("Failed to read TIFF file: $inputPath");
                return false;
            }

            // Basic TIFF header check
            $header = substr($content, 0, 4);
            if ($header !== "II*\x00" && $header !== "MM\x00*") {
                $this->writeLog("Invalid TIFF header in $inputPath");
                return false;
            }

            // For now, just copy the file (this strips some metadata depending on the source)
            if (copy($inputPath, $outputPath)) {
                $this->writeLog("TIFF file copied (basic processing): $inputPath");
                return true;
            } else {
                $this->writeLog("Failed to copy TIFF file: $inputPath");
                return false;
            }
        } catch (Exception $e) {
            $this->writeLog("TIFF processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function scramblePdf($inputPath, $outputPath, $metadata)
    {
        try {
            $content = file_get_contents($inputPath);
            if ($content === false) {
                $this->writeLog("Failed to read PDF: $inputPath");
                return false;
            }

            if (substr($content, 0, 4) !== '%PDF') {
                $this->writeLog("Invalid PDF header in $inputPath");
                return false;
            }

            // More robust PDF metadata replacement
            if (!$this->options['strip_only'] && $metadata) {
                // Build new info dictionary
                $newInfo = "<<\n";
                $newInfo .= "/Title (Document)\n";
                $newInfo .= "/Author ({$metadata['author']})\n";
                $newInfo .= "/Subject (Processed Document)\n";
                $newInfo .= "/Creator ({$metadata['software']})\n";
                $newInfo .= "/Producer (MetadataScrambler)\n";
                $newInfo .= "/CreationDate (D:" . $this->formatPdfDate($metadata['date']) . ")\n";
                $newInfo .= "/ModDate (D:" . $this->formatPdfDate(date('Y:m:d H:i:s')) . ")\n";
                $newInfo .= ">>";

                // Replace Info dictionary more carefully
                $content = preg_replace_callback(
                    '/(\d+\s+\d+\s+obj\s*<<[^>]*\/Title[^>]*>>)/s',
                    function($matches) use ($newInfo) {
                        return preg_replace('/<<.*?>>/s', $newInfo, $matches[0]);
                    },
                    $content
                );
            } else {
                // Strip metadata by removing Info dictionary references
                $content = preg_replace('/\/Info\s+\d+\s+\d+\s+R/', '', $content);
            }

            if (file_put_contents($outputPath, $content) === false) {
                $this->writeLog("Failed to write PDF: $outputPath");
                return false;
            }

            $this->writeLog("PDF processing completed for $inputPath");
            return true;
        } catch (Exception $e) {
            $this->writeLog("PDF processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function formatPdfDate($date)
    {
        // Convert to PDF date format: YYYYMMDDHHMMSSZ
        return str_replace([':', ' '], '', $date) . 'Z';
    }

    private function scrambleMp3($inputPath, $outputPath, $metadata)
    {
        try {
            $content = file_get_contents($inputPath);
            if ($content === false) {
                $this->writeLog("Failed to read MP3: $inputPath");
                return false;
            }

            $fileSize = strlen($content);
            
            // Handle ID3v2 tags (at beginning of file)
            $offset = $this->stripId3v2Tags($content);
            
            // Handle ID3v1 tags (last 128 bytes)
            $hasId3v1 = false;
            if ($fileSize >= 128) {
                $lastChunk = substr($content, -128);
                if (substr($lastChunk, 0, 3) === 'TAG') {
                    $hasId3v1 = true;
                    $content = substr($content, 0, -128); // Remove existing ID3v1
                    $this->writeLog("Removed existing ID3v1 tag from $inputPath");
                }
            }

            // Add new ID3v1 tag if not strip-only mode
            if (!$this->options['strip_only'] && $metadata) {
                $id3v1 = $this->buildId3v1Tag($metadata);
                $content .= $id3v1;
                $this->writeLog("Added new ID3v1 tag to MP3");
            }

            // Remove content from offset if ID3v2 was present
            if ($offset > 0) {
                $content = substr($content, $offset);
                $this->writeLog("Removed ID3v2 tag (offset: $offset bytes) from $inputPath");
            }

            if (file_put_contents($outputPath, $content) === false) {
                $this->writeLog("Failed to write MP3: $outputPath");
                return false;
            }

            $this->writeLog("MP3 processing completed for $inputPath");
            return true;
        } catch (Exception $e) {
            $this->writeLog("MP3 processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function stripId3v2Tags($content)
    {
        // Check for ID3v2 header
        if (substr($content, 0, 3) !== 'ID3') {
            return 0;
        }

        // Read ID3v2 header
        $version = ord($content[3]);
        $revision = ord($content[4]);
        $flags = ord($content[5]);
        
        // Calculate size (synchsafe integer)
        $size = (ord($content[6]) << 21) | (ord($content[7]) << 14) | (ord($content[8]) << 7) | ord($content[9]);
        
        $totalSize = 10 + $size; // Header + data
        
        $this->writeLog("Found ID3v2.$version.$revision tag, size: $size bytes (total: $totalSize)");
        
        return $totalSize;
    }

    private function buildId3v1Tag($metadata)
    {
        $tag = str_repeat("\0", 128);
        
        // TAG identifier
        $tag = substr_replace($tag, 'TAG', 0, 3);
        
        // Title (30 bytes)
        $tag = substr_replace($tag, str_pad('Processed Audio', 30, "\0"), 3, 30);
        
        // Artist (30 bytes)
        $tag = substr_replace($tag, str_pad($metadata['author'], 30, "\0"), 33, 30);
        
        // Album (30 bytes)
        $tag = substr_replace($tag, str_pad('Unknown Album', 30, "\0"), 63, 30);
        
        // Year (4 bytes)
        $year = substr($metadata['date'], 0, 4);
        $tag = substr_replace($tag, str_pad($year, 4, "\0"), 93, 4);
        
        // Comment (30 bytes)
        $tag = substr_replace($tag, str_pad('Processed by MetadataScrambler', 30, "\0"), 97, 30);
        
        // Genre (1 byte) - 12 = Other
        $tag = substr_replace($tag, chr(12), 127, 1);
        
        return $tag;
    }

    private function scrambleWord($inputPath, $outputPath, $fileType, $metadata)
    {
        try {
            if ($fileType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                return $this->scrambleDocx($inputPath, $outputPath, $metadata);
            } else {
                return $this->scrambleDoc($inputPath, $outputPath, $metadata);
            }
        } catch (Exception $e) {
            $this->writeLog("Word processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function scrambleDocx($inputPath, $outputPath, $metadata)
    {
        if (!class_exists('ZipArchive')) {
            $this->writeLog("ZipArchive not available for DOCX processing");
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($inputPath) !== true) {
            $this->writeLog("Failed to open DOCX as ZIP: $inputPath");
            return false;
        }

        $newZip = new ZipArchive();
        if ($newZip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->writeLog("Failed to create output DOCX: $outputPath");
            $zip->close();
            return false;
        }

        // Process all files in the ZIP
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $content = $zip->getFromIndex($i);
            
            if ($content === false) {
                $this->writeLog("Failed to read file from DOCX: $filename");
                continue;
            }

            // Process metadata files
            if ($filename === 'docProps/core.xml') {
                $content = $this->processDocxCoreProperties($content, $metadata);
            } elseif ($filename === 'docProps/app.xml') {
                $content = $this->processDocxAppProperties($content, $metadata);
            } elseif ($filename === 'docProps/custom.xml') {
                // Remove custom properties entirely or replace with dummy data
                if ($this->options['strip_only']) {
                    continue; // Skip adding this file
                } else {
                    $content = $this->createDummyCustomProperties($metadata);
                }
            }

            $newZip->addFromString($filename, $content);
        }

        $zip->close();
        $newZip->close();
        
        $this->writeLog("DOCX processing completed for $inputPath");
        return file_exists($outputPath);
    }

    private function processDocxCoreProperties($xml, $metadata)
    {
        if ($this->options['strip_only']) {
            // Remove sensitive properties
            $xml = preg_replace('/<dc:creator>.*?<\/dc:creator>/', '<dc:creator></dc:creator>', $xml);
            $xml = preg_replace('/<dc:title>.*?<\/dc:title>/', '<dc:title></dc:title>', $xml);
            $xml = preg_replace('/<dc:subject>.*?<\/dc:subject>/', '<dc:subject></dc:subject>', $xml);
            $xml = preg_replace('/<dcterms:created.*?>.*?<\/dcterms:created>/', '', $xml);
            $xml = preg_replace('/<dcterms:modified.*?>.*?<\/dcterms:modified>/', '', $xml);
        } else {
            // Replace with scrambled data
            $xml = preg_replace(
                '/<dc:creator>.*?<\/dc:creator>/',
                "<dc:creator>{$metadata['author']}</dc:creator>",
                $xml
            );
            $xml = preg_replace(
                '/<dc:title>.*?<\/dc:title>/',
                '<dc:title>Document</dc:title>',
                $xml
            );
            $xml = preg_replace(
                '/<dc:subject>.*?<\/dc:subject>/',
                '<dc:subject>Processed Document</dc:subject>',
                $xml
            );
            
            $isoDate = str_replace(' ', 'T', $metadata['date']) . 'Z';
            $xml = preg_replace(
                '/<dcterms:created.*?>.*?<\/dcterms:created>/',
                "<dcterms:created xsi:type=\"dcterms:W3CDTF\">$isoDate</dcterms:created>",
                $xml
            );
            $xml = preg_replace(
                '/<dcterms:modified.*?>.*?<\/dcterms:modified>/',
                "<dcterms:modified xsi:type=\"dcterms:W3CDTF\">$isoDate</dcterms:modified>",
                $xml
            );
        }
        
        $this->writeLog("Processed DOCX core properties");
        return $xml;
    }

    private function processDocxAppProperties($xml, $metadata)
    {
        if ($this->options['strip_only']) {
            // Remove application-specific properties
            $xml = preg_replace('/<Application>.*?<\/Application>/', '<Application></Application>', $xml);
            $xml = preg_replace('/<Company>.*?<\/Company>/', '<Company></Company>', $xml);
            $xml = preg_replace('/<Manager>.*?<\/Manager>/', '<Manager></Manager>', $xml);
        } else {
            // Replace with generic data
            $xml = preg_replace(
                '/<Application>.*?<\/Application>/',
                '<Application>' . $metadata['software'] . '</Application>',
                $xml
            );
            $xml = preg_replace(
                '/<Company>.*?<\/Company>/',
                '<Company>Generic Company</Company>',
                $xml
            );
            $xml = preg_replace(
                '/<Manager>.*?<\/Manager>/',
                '<Manager></Manager>',
                $xml
            );
        }
        
        $this->writeLog("Processed DOCX app properties");
        return $xml;
    }

    private function createDummyCustomProperties($metadata)
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
<property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="2" name="ProcessedBy">
<vt:lpwstr>' . $metadata['software'] . '</vt:lpwstr>
</property>
</Properties>';
    }

    private function scrambleDoc($inputPath, $outputPath, $metadata)
    {
        // DOC format is very complex, so this is a simplified approach
        $content = file_get_contents($inputPath);
        if ($content === false) {
            $this->writeLog("Failed to read DOC file: $inputPath");
            return false;
        }

        // DOC files have a complex binary structure
        // This is a very basic approach that may not work for all DOC files
        if ($this->options['strip_only']) {
            // Try to remove some common metadata patterns
            $content = preg_replace('/\x05SummaryInformation.*?\x00{4,}/s', '', $content);
            $content = preg_replace('/\x05DocumentSummaryInformation.*?\x00{4,}/s', '', $content);
        } else {
            // Very basic replacement - this is not reliable for all DOC files
            $author = str_pad($metadata['author'], 32, "\0");
            $content = preg_replace('/\x05SummaryInformation.*?\x00{32}/s', 
                "\x05SummaryInformation" . $author, $content, 1);
        }

        if (file_put_contents($outputPath, $content) === false) {
            $this->writeLog("Failed to write DOC file: $outputPath");
            return false;
        }

        $this->writeLog("DOC processing completed (basic) for $inputPath");
        return true;
    }

    // Batch processing method
    public function scrambleDirectory($inputDir, $outputDir, $recursive = false)
    {
        if (!is_dir($inputDir)) {
            $this->writeLog("Input directory does not exist: $inputDir");
            return false;
        }

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                $this->writeLog("Failed to create output directory: $outputDir");
                return false;
            }
        }

        $iterator = $recursive ? 
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inputDir)) :
            new DirectoryIterator($inputDir);

        $processed = 0;
        $failed = 0;

        foreach ($iterator as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            $inputPath = $file->getPathname();
            $relativePath = $recursive ? 
                str_replace($inputDir . DIRECTORY_SEPARATOR, '', $inputPath) :
                $file->getFilename();
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $relativePath;

            // Create subdirectories if needed
            $outputSubdir = dirname($outputPath);
            if (!is_dir($outputSubdir)) {
                mkdir($outputSubdir, 0755, true);
            }

            $fileType = $this->detectFileType($inputPath);
            if ($this->scramble($inputPath, $outputPath, $fileType)) {
                $processed++;
                $this->writeLog("Successfully processed: $relativePath");
            } else {
                $failed++;
                $this->writeLog("Failed to process: $relativePath");
            }
        }

        $this->writeLog("Batch processing completed. Processed: $processed, Failed: $failed");
        return ['processed' => $processed, 'failed' => $failed];
    }

    // Utility method to get file info
    public function analyzeFile($filePath)
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $info = [
            'path' => $filePath,
            'size' => filesize($filePath),
            'mime_type' => $this->detectFileType($filePath),
            'supported' => false,
            'metadata_found' => []
        ];

        // Check if file type is supported
        $supportedTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff',
            'application/pdf', 'audio/mpeg',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        
        $info['supported'] = in_array($info['mime_type'], $supportedTypes);

        // Basic metadata detection
        switch ($info['mime_type']) {
            case 'image/jpeg':
                $info['metadata_found'] = $this->detectJpegMetadata($filePath);
                break;
            case 'image/png':
                $info['metadata_found'] = $this->detectPngMetadata($filePath);
                break;
            case 'application/pdf':
                $info['metadata_found'] = $this->detectPdfMetadata($filePath);
                break;
            case 'audio/mpeg':
                $info['metadata_found'] = $this->detectMp3Metadata($filePath);
                break;
        }

        return $info;
    }

    private function detectJpegMetadata($filePath)
    {
        $found = [];
        $content = file_get_contents($filePath, false, null, 0, 1024);
        
        if (strpos($content, 'Exif') !== false) {
            $found[] = 'EXIF data';
        }
        if (strpos($content, 'http://ns.adobe.com/xap/1.0/') !== false) {
            $found[] = 'XMP data';
        }
        if (strpos($content, 'Photoshop') !== false) {
            $found[] = 'Photoshop data';
        }
        
        return $found;
    }

    private function scrambleFileSystemTimestamps($filePath, $metadata)
    {
        try {
            // Convert metadata date to timestamp
            $fakeTimestamp = strtotime(str_replace(':', '-', substr($metadata['date'], 0, 10)) . ' ' . substr($metadata['date'], 11));
            
            if ($fakeTimestamp === false) {
                $this->writeLog("Invalid date format for file system timestamp: {$metadata['date']}");
                return false;
            }

            if ($this->options['use_same_timestamp']) {
                // Set both modification and access time to the same fake timestamp
                if (touch($filePath, $fakeTimestamp, $fakeTimestamp)) {
                    $this->writeLog("File system timestamps updated for $filePath: " . date('Y-m-d H:i:s', $fakeTimestamp));
                    return true;
                } else {
                    $this->writeLog("Failed to update file system timestamps for $filePath");
                    return false;
                }
            } else {
                // Use different timestamps for modification and access
                $accessTimestamp = $fakeTimestamp + mt_rand(-86400, 86400); // ±1 day variation
                
                if (touch($filePath, $fakeTimestamp, $accessTimestamp)) {
                    $this->writeLog("File system timestamps updated for $filePath - Mod: " . 
                        date('Y-m-d H:i:s', $fakeTimestamp) . ", Access: " . date('Y-m-d H:i:s', $accessTimestamp));
                    return true;
                } else {
                    $this->writeLog("Failed to update file system timestamps for $filePath");
                    return false;
                }
            }
        } catch (Exception $e) {
            $this->writeLog("File system timestamp scrambling failed for $filePath: " . $e->getMessage());
            return false;
        }
    }

    public function getFileSystemTimestamps($filePath)
    {
        if (!file_exists($filePath)) {
            return false;
        }

        return [
            'modification_time' => date('Y-m-d H:i:s', filemtime($filePath)),
            'access_time' => date('Y-m-d H:i:s', fileatime($filePath)),
            'change_time' => date('Y-m-d H:i:s', filectime($filePath)), // Note: This is change time on Unix, creation time on Windows
            'modification_timestamp' => filemtime($filePath),
            'access_timestamp' => fileatime($filePath),
            'change_timestamp' => filectime($filePath)
        ];
    }

    private function detectPngMetadata($filePath)
    {
        $found = [];
        $content = file_get_contents($filePath, false, null, 0, 2048);
        
        if (strpos($content, 'tEXt') !== false) {
            $found[] = 'Text chunks';
        }
        if (strpos($content, 'zTXt') !== false) {
            $found[] = 'Compressed text chunks';
        }
        if (strpos($content, 'iTXt') !== false) {
            $found[] = 'International text chunks';
        }
        if (strpos($content, 'tIME') !== false) {
            $found[] = 'Timestamp';
        }
        
        return $found;
    }

    private function detectPdfMetadata($filePath)
    {
        $found = [];
        $content = file_get_contents($filePath, false, null, 0, 4096);
        
        if (strpos($content, '/Info') !== false) {
            $found[] = 'Info dictionary';
        }
        if (strpos($content, '/Title') !== false) {
            $found[] = 'Title';
        }
        if (strpos($content, '/Author') !== false) {
            $found[] = 'Author';
        }
        if (strpos($content, '/Creator') !== false) {
            $found[] = 'Creator';
        }
        if (strpos($content, '/Producer') !== false) {
            $found[] = 'Producer';
        }
        
        return $found;
    }

    private function detectMp3Metadata($filePath)
    {
        $found = [];
        $content = file_get_contents($filePath);
        
        if (substr($content, 0, 3) === 'ID3') {
            $found[] = 'ID3v2 tags';
        }
        if (strlen($content) >= 128 && substr($content, -128, 3) === 'TAG') {
            $found[] = 'ID3v1 tags';
        }
        
        return $found;
    }
}

// Usage Examples
/*

// Basic usage - scramble with fake metadata
$scrambler = new MetadataScrambler('scrambler.log');
$result = $scrambler->scramble('input.jpg', 'output.jpg');

// Strip-only mode (remove metadata without adding fake data)
$scrambler = new MetadataScrambler('scrambler.log', [
    'strip_only' => true
]);
$result = $scrambler->scramble('input.pdf', 'output.pdf');

// High quality preservation mode
$scrambler = new MetadataScrambler('scrambler.log', [
    'preserve_quality' => true,
    'jpeg_quality' => 98,
    'add_fake_gps' => true
]);
$result = $scrambler->scramble('photo.jpg', 'clean_photo.jpg');

// Batch processing
$results = $scrambler->scrambleDirectory('input_folder', 'output_folder', true);
echo "Processed: {$results['processed']}, Failed: {$results['failed']}\n";

// File analysis
$analysis = $scrambler->analyzeFile('suspicious.jpg');
if ($analysis) {
    echo "File: {$analysis['path']}\n";
    echo "Type: {$analysis['mime_type']}\n";
    echo "Supported: " . ($analysis['supported'] ? 'Yes' : 'No') . "\n";
    echo "Metadata found: " . implode(', ', $analysis['metadata_found']) . "\n";
}

// Advanced configuration with file system timestamp scrambling
$scrambler = new MetadataScrambler('detailed.log', [
    'strip_only' => false,
    'preserve_quality' => true,
    'jpeg_quality' => 95,
    'add_fake_gps' => true,
    'validate_output' => true,
    'scramble_file_timestamps' => true,
    'use_same_timestamp' => true
]);

// Check file timestamps before processing
$timestamps = $scrambler->getFileSystemTimestamps('original.jpg');
echo "Before: Mod={$timestamps['modification_time']}, Access={$timestamps['access_time']}\n";

// Process file
$scrambler->scramble('original.jpg', 'scrambled.jpg');

// Check timestamps after processing
$newTimestamps = $scrambler->getFileSystemTimestamps('scrambled.jpg');
echo "After: Mod={$newTimestamps['modification_time']}, Access={$newTimestamps['access_time']}\n";

// Process different file types
$files = [
    'document.pdf' => 'clean_document.pdf',
    'photo.jpg' => 'clean_photo.jpg',
    'song.mp3' => 'clean_song.mp3',
    'report.docx' => 'clean_report.docx',
    'image.png' => 'clean_image.png'
];

foreach ($files as $input => $output) {
    if ($scrambler->scramble($input, $output)) {
        echo "Successfully processed: $input -> $output\n";
    } else {
        echo "Failed to process: $input\n";
    }
}

*/
