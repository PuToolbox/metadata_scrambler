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
            'use_same_timestamp' => true,
            'manipulate_system_time' => false,
            'system_time_method' => 'auto'
        ], $options);
        $this->writeLog("MetadataScrambler initialized with options: " . json_encode($this->options));
    }

    private function writeLog($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
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

    private function validateExifStructure($exifData)
    {
        // Log first 10 bytes for debugging
        $this->writeLog("Validating EXIF structure, first 10 bytes: " . bin2hex(substr($exifData, 0, 10)));
        
        // Check APP1 marker (\xFF\xE1) and length
        if (strlen($exifData) < 10 || substr($exifData, 0, 2) !== "\xFF\xE1") {
            $this->writeLog("Invalid APP1 marker");
            return false;
        }
        
        // Extract length and verify EXIF header at offset 4
        $length = unpack('n', substr($exifData, 2, 2))[1];
        if (strlen($exifData) < $length + 2) {
            $this->writeLog("EXIF segment too short: length=$length, actual=" . strlen($exifData));
            return false;
        }
        if (substr($exifData, 4, 6) !== "Exif\x00\x00") {
            $this->writeLog("Invalid EXIF header at offset 4");
            return false;
        }
        
        // Check TIFF header
        $tiffHeader = substr($exifData, 10, 8);
        if (!in_array(substr($tiffHeader, 0, 2), ["II", "MM"])) {
            $this->writeLog("Invalid TIFF byte order");
            return false;
        }
        if (unpack('v', substr($tiffHeader, 2, 2))[1] !== 42) {
            $this->writeLog("Invalid TIFF identifier");
            return false;
        }
        
        // Check GPS IFD presence
        if (strpos($exifData, pack('v', 0x8825)) !== false) {
            $gpsOffset = unpack('V', substr($exifData, strpos($exifData, pack('v', 0x8825)) + 8, 4))[1];
            if ($gpsOffset > 0 && $gpsOffset < strlen($exifData) - 6) {
                $numGpsTags = unpack('v', substr($exifData, $gpsOffset + 6, 2))[1];
                if ($numGpsTags < 5) {
                    $this->writeLog("GPS IFD has too few tags: $numGpsTags");
                    return false;
                }
            } else {
                $this->writeLog("Invalid GPS IFD offset: $gpsOffset");
                return false;
            }
        }
        
        $this->writeLog("EXIF structure validated successfully");
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
        
        // GPS IFD pointer (0x8825)
        if ($this->options['add_fake_gps']) {
            $gpsIfdOffset = $currentOffset;
            $ifd0 .= pack('vvVV', 0x8825, 4, 1, $gpsIfdOffset);
            $currentOffset += 2 + (11 * 12) + 4; // GPS IFD: 11 tags
        } else {
            $ifd0 .= pack('vvVV', 0x010F, 2, 8, $currentOffset); // Camera make
            $make = "Unknown\x00";
            $currentOffset += strlen($make);
        }
        
        // Orientation (0x0112)
        $ifd0 .= pack('vvVV', 0x0112, 3, 1, 1); // Normal orientation
        // Resolution (0x011A)
        $ifd0 .= pack('vvVV', 0x011A, 5, 1, $currentOffset); // X Resolution
        $currentOffset += 8; // Rational = 8 bytes
        
        // Next IFD offset (0)
        $ifd0 .= pack('V', 0);
        
        // Build data section
        $dataSection = $dateTime . $dateTime . $artist . $software . $camera;
        
        if (!$this->options['add_fake_gps']) {
            $dataSection .= "Unknown\x00";
        }
        
        // Add resolution data (72 DPI)
        $dataSection .= pack('VV', 72, 1);
        
        // Add GPS IFD
        if ($this->options['add_fake_gps']) {
            $gpsIfd = $this->buildGpsIfd($metadata);
            $dataSection .= $gpsIfd;
        }
        
        $tiffData = $tiffHeader . $ifd0 . $dataSection;
        $totalLength = strlen($exifHeader . $tiffData);
        
        // APP1 segment
        $app1 = "\xFF\xE1" . pack('n', $totalLength + 2) . $exifHeader . $tiffData;
        
        if (!$this->validateExifStructure($app1)) {
            $this->writeLog("Failed to validate EXIF structure");
            return false;
        }
        
        $this->writeLog("Built comprehensive EXIF segment: " . strlen($app1) . " bytes");
        return $app1;
    }
    
    private function buildGpsIfd($metadata)
    {
        $numGpsTags = 11;
        $gpsIfd = pack('v', $numGpsTags);
        
        $dataStart = 2 + ($numGpsTags * 12) + 4;
        $currentOffset = $dataStart;
        
        // GPS Version ID (0x0000)
        $gpsIfd .= pack('vvVV', 0x0000, 1, 4, 0x02020000); // Version 2.2.0.0
        
        // GPS Latitude Ref (0x0001)
        $latRef = $metadata['latitude'] >= 0 ? "N\x00" : "S\x00";
        $gpsIfd .= pack('vvVV', 0x0001, 2, 2, $currentOffset);
        $currentOffset += 2;
        
        // GPS Latitude (0x0002)
        $gpsIfd .= pack('vvVV', 0x0002, 5, 3, $currentOffset);
        $currentOffset += 24; // 3 rationals = 24 bytes
        
        // GPS Longitude Ref (0x0003)
        $lonRef = $metadata['longitude'] >= 0 ? "E\x00" : "W\x00";
        $gpsIfd .= pack('vvVV', 0x0003, 2, 2, $currentOffset);
        $currentOffset += 2;
        
        // GPS Longitude (0x0004)
        $gpsIfd .= pack('vvVV', 0x0004, 5, 3, $currentOffset);
        $currentOffset += 24; // 3 rationals = 24 bytes
        
        // GPS Altitude Ref (0x0005)
        $gpsIfd .= pack('vvVV', 0x0005, 1, 1, 0); // 0 = above sea level
        
        // GPS Altitude (0x0006)
        $gpsIfd .= pack('vvVV', 0x0006, 5, 1, $currentOffset);
        $currentOffset += 8; // 1 rational = 8 bytes
        
        // GPS TimeStamp (0x0007)
        $gpsIfd .= pack('vvVV', 0x0007, 5, 3, $currentOffset);
        $currentOffset += 24; // 3 rationals = 24 bytes
        
        // GPS Satellites (0x0008)
        $satellites = "12\x00"; // Dummy value
        $gpsIfd .= pack('vvVV', 0x0008, 2, 3, $currentOffset);
        $currentOffset += 3;
        
        // GPS Status (0x0009)
        $status = "A\x00"; // A = measurement active
        $gpsIfd .= pack('vvVV', 0x0009, 2, 2, $currentOffset);
        $currentOffset += 2;
        
        // GPS Measure Mode (0x000A)
        $measureMode = "2\x00"; // 2 = 2D measurement
        $gpsIfd .= pack('vvVV', 0x000A, 2, 2, $currentOffset);
        $currentOffset += 2;
        
        // GPS DateStamp (0x001D)
        $gpsIfd .= pack('vvVV', 0x001D, 2, 11, $currentOffset);
        $currentOffset += 11;
        
        // Next IFD (none)
        $gpsIfd .= pack('V', 0);
        
        // GPS data section
        $absLat = abs($metadata['latitude']);
        $latDeg = floor($absLat);
        $latMin = floor(($absLat - $latDeg) * 60);
        $latSec = (($absLat - $latDeg) * 60 - $latMin) * 60;
        $gpsIfd .= $latRef;
        $gpsIfd .= pack('VV', $latDeg, 1);          // Degrees
        $gpsIfd .= pack('VV', $latMin, 1);          // Minutes
        $gpsIfd .= pack('VV', round($latSec * 1000), 1000); // Seconds
        
        $absLon = abs($metadata['longitude']);
        $lonDeg = floor($absLon);
        $lonMin = floor(($absLon - $lonDeg) * 60);
        $lonSec = (($absLon - $lonDeg) * 60 - $lonMin) * 60;
        $gpsIfd .= $lonRef;
        $gpsIfd .= pack('VV', $lonDeg, 1);          // Degrees
        $gpsIfd .= pack('VV', $lonMin, 1);          // Minutes
        $gpsIfd .= pack('VV', round($lonSec * 1000), 1000); // Seconds
        
        $altitude = mt_rand(0, 1000);
        $gpsIfd .= pack('VV', $altitude * 1000, 1000); // Altitude in meters
        
        $timeParts = explode(' ', $metadata['date'])[1];
        list($hour, $min, $sec) = explode(':', $timeParts);
        $gpsIfd .= pack('VV', (int)$hour, 1);       // Hour
        $gpsIfd .= pack('VV', (int)$min, 1);        // Minute
        $gpsIfd .= pack('VV', round((float)$sec * 1000), 1000); // Second
        
        $gpsIfd .= $satellites;
        $gpsIfd .= $status;
        $gpsIfd .= $measureMode;
        
        $dateParts = explode(':', explode(' ', $metadata['date'])[0]);
        $dateStamp = sprintf("%04d:%02d:%02d\x00", $dateParts[0], $dateParts[1], $dateParts[2]);
        $gpsIfd .= $dateStamp;
        
        $this->writeLog("Built GPS IFD with " . strlen($gpsIfd) . " bytes, offsets: [LatRef=$dataStart, Lat=" . ($dataStart+2) . ", LonRef=" . ($dataStart+26) . ", Lon=" . ($dataStart+28) . ", Alt=" . ($dataStart+52) . ", Time=" . ($dataStart+60) . ", Sat=" . ($dataStart+84) . ", Status=" . ($dataStart+87) . ", Mode=" . ($dataStart+89) . ", Date=" . ($dataStart+91) . "]");
        return $gpsIfd;
    }

    private function addComprehensiveExifToJpeg($filePath, $metadata)
    {
        $content = file_get_contents($filePath);
        if ($content === false || substr($content, 0, 2) !== "\xFF\xD8") {
            $this->writeLog("Invalid JPEG for EXIF injection: $filePath");
            return false;
        }

        // Remove all existing APP1 segments
        $newContent = substr($content, 0, 2);
        $offset = 2;
        while ($offset < strlen($content)) {
            if (substr($content, $offset, 2) === "\xFF\xE1") {
                $segmentLength = unpack('n', substr($content, $offset + 2, 2))[1] + 2;
                $offset += $segmentLength;
            } else {
                $newContent .= substr($content, $offset);
                break;
            }
        }
        
        // Build EXIF data
        $exifData = $this->buildComprehensiveExifSegment($metadata);
        if ($exifData === false) {
            $this->writeLog("Failed to build EXIF segment for $filePath");
            return false;
        }
        
        // Insert new EXIF after SOI marker
        $newContent = substr($newContent, 0, 2) . $exifData . substr($newContent, 2);
        
        if (file_put_contents($filePath, $newContent) === false) {
            $this->writeLog("Failed to write EXIF data to $filePath");
            return false;
        }

        $this->writeLog("Comprehensive EXIF data added to $filePath");
        return true;
    }

private function scrambleJpeg($inputPath, $outputPath, $metadata)
{
    if (!function_exists('imagecreatefromjpeg')) {
        $this->writeLog("GD extension not available for JPEG processing");
        return false;
    }

    try {
        $image = imagecreatefromjpeg($inputPath);
        if ($image === false) {
            $this->writeLog("Failed to create image resource from $inputPath");
            return false;
        }

        $quality = $this->options['preserve_quality'] ? $this->options['jpeg_quality'] : 90;
        if (!imagejpeg($image, $outputPath, $quality)) {
            imagedestroy($image);
            $this->writeLog("Failed to save processed JPEG to $outputPath");
            return false;
        }
        imagedestroy($image);

        if (!$this->options['strip_only'] && $metadata) {
            if (!$this->addComprehensiveExifToJpeg($outputPath, $metadata)) {
                $this->writeLog("Failed to add EXIF data to $outputPath");
                return false;
            }
        }

        // Remove all JPEG comments after EXIF injection
        if (!$this->removeJpegComment($outputPath)) {
            $this->writeLog("Failed to remove JPEG comments from $outputPath");
            return false;
        }

        $this->writeLog("JPEG processing completed for $inputPath");
        return true;
    } catch (Exception $e) {
        $this->writeLog("JPEG processing failed for $inputPath: " . $e->getMessage());
        return false;
    }
}

private function removeJpegComment($filePath)
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        $this->writeLog("Failed to read JPEG for comment removal: $filePath");
        return false;
    }

    // Skip SOI (0xFFD8)
    if (substr($content, 0, 2) !== "\xFF\xD8") {
        $this->writeLog("Invalid JPEG header in $filePath");
        return false;
    }

    $newContent = substr($content, 0, 2);
    $offset = 2;
    $commentCount = 0;

    // Iterate through all segments, removing all COM (0xFFFE) segments
    while ($offset < strlen($content)) {
        if (substr($content, $offset, 2) === "\xFF\xFE") { // COM segment
            $segmentLength = unpack('n', substr($content, $offset + 2, 2))[1] + 2;
            $commentData = substr($content, $offset + 4, $segmentLength - 2);
            $this->writeLog("Found COM segment at offset $offset, length $segmentLength: " . substr($commentData, 0, 50));
            $commentCount++;
            $offset += $segmentLength; // Skip the COM segment
        } else {
            // Copy the rest of the file, checking for valid segment markers
            $marker = ord($content[$offset]);
            if ($marker === 0xFF && $offset + 1 < strlen($content)) {
                $nextByte = ord($content[$offset + 1]);
                if ($nextByte !== 0x00 && $nextByte !== 0xFF) { // Valid segment
                    $segmentLength = unpack('n', substr($content, $offset + 2, 2))[1] + 2;
                    $newContent .= substr($content, $offset, $segmentLength);
                    $offset += $segmentLength;
                } else {
                    $newContent .= substr($content, $offset);
                    break; // End of segments or invalid marker
                }
            } else {
                $newContent .= substr($content, $offset);
                break; // End of segments
            }
        }
    }

    if ($commentCount === 0) {
        $this->writeLog("No COM segments found in $filePath");
    } else {
        $this->writeLog("Removed $commentCount COM segment(s) from $filePath");
    }

    if (file_put_contents($filePath, $newContent) === false) {
        $this->writeLog("Failed to write JPEG after comment removal: $filePath");
        return false;
    }

    return true;
}

    private function checkSystemTimePermissions()
    {
        $this->writeLog("Checking system time manipulation permissions...");
        
        $currentTime = time();
        $testTime = $currentTime;
        
        if ($this->setSystemTime($testTime)) {
            $this->writeLog("System time manipulation permissions: AVAILABLE");
            return true;
        } else {
            $this->writeLog("System time manipulation permissions: NOT AVAILABLE (requires admin/root)");
            return false;
        }
    }

    public function getSystemTimeCapabilities()
    {
        $os = strtolower(PHP_OS);
        $capabilities = [
            'os' => PHP_OS,
            'supported' => false,
            'methods' => [],
            'requires_privileges' => true,
            'warnings' => []
        ];

        if (strpos($os, 'win') === 0) {
            $capabilities['supported'] = true;
            $capabilities['methods'] = ['powershell', 'date'];
            $capabilities['warnings'][] = 'Requires Administrator privileges';
            $capabilities['warnings'][] = 'May trigger antivirus/security software';
        } elseif (strpos($os, 'linux') === 0 || strpos($os, 'unix') === 0) {
            $capabilities['supported'] = true;
            $capabilities['methods'] = ['date', 'hwclock'];
            $capabilities['warnings'][] = 'Requires root privileges (sudo)';
            $capabilities['warnings'][] = 'May affect system stability';
        } elseif (strpos($os, 'darwin') === 0) {
            $capabilities['supported'] = true;
            $capabilities['methods'] = ['date'];
            $capabilities['warnings'][] = 'Requires admin privileges';
            $capabilities['warnings'][] = 'System Integrity Protection may block this';
        }

        return $capabilities;
    }

    public function executeWithFakeSystemTime($metadata, $callback)
    {
        $this->writeLog("Attempting system time manipulation - THIS REQUIRES ADMIN/ROOT PRIVILEGES");
        
        $fakeTimestamp = strtotime(str_replace(':', '-', substr($metadata['date'], 0, 10)) . ' ' . substr($metadata['date'], 11));
        
        if ($fakeTimestamp === false) {
            $this->writeLog("Invalid date format for system time manipulation: {$metadata['date']}");
            return false;
        }

        $originalTime = time();
        $this->writeLog("Original system time: " . date('Y-m-d H:i:s', $originalTime));
        $this->writeLog("Target fake time: " . date('Y-m-d H:i:s', $fakeTimestamp));

        if (!$this->setSystemTime($fakeTimestamp)) {
            $this->writeLog("Failed to change system time - falling back to regular processing");
            return call_user_func($callback);
        }

        $this->writeLog("System time changed successfully");

        try {
            $result = call_user_func($callback);
            usleep(100000);
            return $result;
        } finally {
            $this->setSystemTime($originalTime);
            $this->writeLog("System time restored to: " . date('Y-m-d H:i:s', $originalTime));
        }
    }

    private function setSystemTime($timestamp)
    {
        $dateString = date('Y-m-d H:i:s', $timestamp);
        $os = strtolower(PHP_OS);

        try {
            if (strpos($os, 'win') === 0) {
                return $this->setWindowsTime($timestamp);
            } elseif (strpos($os, 'linux') === 0 || strpos($os, 'unix') === 0) {
                return $this->setLinuxTime($timestamp);
            } elseif (strpos($os, 'darwin') === 0) {
                return $this->setMacTime($timestamp);
            } else {
                $this->writeLog("Unsupported operating system for time manipulation: " . PHP_OS);
                return false;
            }
        } catch (Exception $e) {
            $this->writeLog("System time manipulation failed: " . $e->getMessage());
            return false;
        }
    }

    private function setWindowsTime($timestamp)
    {
        $dateStr = date('m/d/Y', $timestamp);
        $timeStr = date('H:i:s', $timestamp);

        if ($this->options['system_time_method'] === 'powershell' || $this->options['system_time_method'] === 'auto') {
            $psDate = date('Y-m-d\TH:i:s', $timestamp);
            $cmd = "powershell.exe -Command \"Set-Date -Date '$psDate'\"";
            $output = [];
            $returnCode = 0;
            exec($cmd . ' 2>&1', $output, $returnCode);
            if ($returnCode === 0) {
                $this->writeLog("Windows time set via PowerShell: $psDate");
                return true;
            } else {
                $this->writeLog("PowerShell time setting failed: " . implode(' ', $output));
            }
        }

        if ($this->options['system_time_method'] === 'date' || $this->options['system_time_method'] === 'auto') {
            $cmd = "date $dateStr && time $timeStr";
            $output = [];
            $returnCode = 0;
            exec($cmd . ' 2>&1', $output, $returnCode);
            if ($returnCode === 0) {
                $this->writeLog("Windows time set via date command");
                return true;
            } else {
                $this->writeLog("Date command failed: " . implode(' ', $output));
            }
        }

        return false;
    }

    private function setLinuxTime($timestamp)
    {
        $dateString = date('Y-m-d H:i:s', $timestamp);
        $methods = [];
        
        if ($this->options['system_time_method'] === 'date' || $this->options['system_time_method'] === 'auto') {
            $methods[] = "date -s '$dateString'";
        }
        
        if ($this->options['system_time_method'] === 'hwclock' || $this->options['system_time_method'] === 'auto') {
            $methods[] = "date -s '$dateString' && hwclock --systohc";
        }

        foreach ($methods as $cmd) {
            $output = [];
            $returnCode = 0;
            exec($cmd . ' 2>&1', $output, $returnCode);
            if ($returnCode === 0) {
                $this->writeLog("Linux time set successfully: $dateString");
                return true;
            } else {
                $this->writeLog("Command failed ($cmd): " . implode(' ', $output));
            }
        }

        return false;
    }

    private function setMacTime($timestamp)
    {
        $dateString = date('m/d/Y H:i:s', $timestamp);
        $cmd = "date -f '%m/%d/%Y %H:%M:%S' '$dateString'";
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        if ($returnCode === 0) {
            $this->writeLog("macOS time set successfully: $dateString");
            return true;
        } else {
            $this->writeLog("macOS date command failed: " . implode(' ', $output));
            $altDateString = date('Y-m-d H:i:s', $timestamp);
            $altCmd = "date -j -f '%Y-%m-%d %H:%M:%S' '$altDateString' '+%Y%m%d%H%M.%S' | xargs date";
            exec($altCmd . ' 2>&1', $output, $returnCode);
            if ($returnCode === 0) {
                $this->writeLog("macOS time set via alternative method");
                return true;
            }
        }

        return false;
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

        if ($this->options['manipulate_system_time'] && $metadata) {
            $result = $this->executeWithFakeSystemTime($metadata, function() use ($inputPath, $outputPath, $fileType, $metadata) {
                return $this->processFile($inputPath, $outputPath, $fileType, $metadata);
            });
        } else {
            $result = $this->processFile($inputPath, $outputPath, $fileType, $metadata);
        }

        if ($result && $this->options['scramble_file_timestamps'] && !$this->options['manipulate_system_time'] && $metadata) {
            $this->scrambleFileSystemTimestamps($outputPath, $metadata);
        }

        if ($result && $this->options['validate_output']) {
            $result = $this->validateOutput($outputPath, $fileType);
        }

        return $result;
    }

    private function processFile($inputPath, $outputPath, $fileType, $metadata)
    {
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

            if (!imagepng($image, $outputPath)) {
                imagedestroy($image);
                $this->writeLog("Failed to save PNG to $outputPath");
                return false;
            }
            imagedestroy($image);

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

        $iendPos = strrpos($content, "IEND");
        if ($iendPos === false) {
            $this->writeLog("IEND chunk not found in PNG: $filePath");
            return false;
        }

        $textChunks = '';
        $authorText = "Author\x00" . $metadata['author'];
        $authorChunk = pack('N', strlen($authorText)) . 'tEXt' . $authorText;
        $authorChunk .= pack('N', crc32('tEXt' . $authorText));
        $textChunks .= $authorChunk;
        
        $timeText = "Creation Time\x00" . $metadata['date'];
        $timeChunk = pack('N', strlen($timeText)) . 'tEXt' . $timeText;
        $timeChunk .= pack('N', crc32('tEXt' . $timeText));
        $textChunks .= $timeChunk;
        
        $softwareText = "Software\x00" . $metadata['software'];
        $softwareChunk = pack('N', strlen($softwareText)) . 'tEXt' . $softwareText;
        $softwareChunk .= pack('N', crc32('tEXt' . $softwareText));
        $textChunks .= $softwareChunk;

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
        try {
            $content = file_get_contents($inputPath);
            if ($content === false) {
                $this->writeLog("Failed to read TIFF file: $inputPath");
                return false;
            }

            $header = substr($content, 0, 4);
            if ($header !== "II*\x00" && $header !== "MM\x00*") {
                $this->writeLog("Invalid TIFF header in $inputPath");
                return false;
            }

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

            if (!$this->options['strip_only'] && $metadata) {
                $newInfo = "<<\n";
                $newInfo .= "/Title (Document)\n";
                $newInfo .= "/Author ({$metadata['author']})\n";
                $newInfo .= "/Subject (Processed Document)\n";
                $newInfo .= "/Creator ({$metadata['software']})\n";
                $newInfo .= "/Producer (MetadataScrambler)\n";
                $newInfo .= "/CreationDate (D:" . $this->formatPdfDate($metadata['date']) . ")\n";
                $newInfo .= "/ModDate (D:" . $this->formatPdfDate(date('Y:m:d H:i:s')) . ")\n";
                $newInfo .= ">>";

                $content = preg_replace_callback(
                    '/(\d+\s+\d+\s+obj\s*<<[^>]*\/Title[^>]*>>)/s',
                    function($matches) use ($newInfo) {
                        return preg_replace('/<<.*?>>/s', $newInfo, $matches[0]);
                    },
                    $content
                );
            } else {
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
            $offset = $this->stripId3v2Tags($content);
            $hasId3v1 = false;
            if ($fileSize >= 128) {
                $lastChunk = substr($content, -128);
                if (substr($lastChunk, 0, 3) === 'TAG') {
                    $hasId3v1 = true;
                    $content = substr($content, 0, -128);
                    $this->writeLog("Removed existing ID3v1 tag from $inputPath");
                }
            }

            if (!$this->options['strip_only'] && $metadata) {
                $id3v1 = $this->buildId3v1Tag($metadata);
                $content .= $id3v1;
                $this->writeLog("Added new ID3v1 tag to MP3");
            }

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
        if (substr($content, 0, 3) !== 'ID3') {
            return 0;
        }

        $version = ord($content[3]);
        $revision = ord($content[4]);
        $flags = ord($content[5]);
        $size = (ord($content[6]) << 21) | (ord($content[7]) << 14) | (ord($content[8]) << 7) | ord($content[9]);
        $totalSize = 10 + $size;
        $this->writeLog("Found ID3v2.$version.$revision tag, size: $size bytes (total: $totalSize)");
        return $totalSize;
    }

    private function buildId3v1Tag($metadata)
    {
        $tag = str_repeat("\0", 128);
        $tag = substr_replace($tag, 'TAG', 0, 3);
        $tag = substr_replace($tag, str_pad('Processed Audio', 30, "\0"), 3, 30);
        $tag = substr_replace($tag, str_pad($metadata['author'], 30, "\0"), 33, 30);
        $tag = substr_replace($tag, str_pad('Unknown Album', 30, "\0"), 63, 30);
        $year = substr($metadata['date'], 0, 4);
        $tag = substr_replace($tag, str_pad($year, 4, "\0"), 93, 4);
        $tag = substr_replace($tag, str_pad('Processed by MetadataScrambler', 30, "\0"), 97, 30);
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

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $this->writeLog("Failed to read file from DOCX: $filename");
                continue;
            }

            if ($filename === 'docProps/core.xml') {
                $content = $this->processDocxCoreProperties($content, $metadata);
            } elseif ($filename === 'docProps/app.xml') {
                $content = $this->processDocxAppProperties($content, $metadata);
            } elseif ($filename === 'docProps/custom.xml') {
                if ($this->options['strip_only']) {
                    continue;
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
            $xml = preg_replace('/<dc:creator>.*?</dc:creator>/', '<dc:creator></dc:creator>', $xml);
            $xml = preg_replace('/<dc:title>.*?</dc:title>/', '<dc:title></dc:title>', $xml);
            $xml = preg_replace('/<dc:subject>.*?</dc:subject>/', '<dc:subject></dc:subject>', $xml);
            $xml = preg_replace('/<dcterms:created.*?>.*?</dcterms:created>/', '', $xml);
            $xml = preg_replace('/<dcterms:modified.*?>.*?</dcterms:modified>/', '', $xml);
        } else {
            $xml = preg_replace(
                '/<dc:creator>.*?</dc:creator>/',
                "<dc:creator>{$metadata['author']}</dc:creator>",
                $xml
            );
            $xml = preg_replace(
                '/<dc:title>.*?</dc:title>/',
                '<dc:title>Document</dc:title>',
                $xml
            );
            $xml = preg_replace(
                '/<dc:subject>.*?</dc:subject>/',
                '<dc:subject>Processed Document</dc:subject>',
                $xml
            );
            $isoDate = str_replace(' ', 'T', $metadata['date']) . 'Z';
            $xml = preg_replace(
                '/<dcterms:created.*?>.*?</dcterms:created>/',
                "<dcterms:created xsi:type=\"dcterms:W3CDTF\">$isoDate</dcterms:created>",
                $xml
            );
            $xml = preg_replace(
                '/<dcterms:modified.*?>.*?</dcterms:modified>/',
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
            $xml = preg_replace('/<Application>.*?</Application>/', '<Application></Application>', $xml);
            $xml = preg_replace('/<Company>.*?</Company>/', '<Company></Company>', $xml);
            $xml = preg_replace('/<Manager>.*?</Manager>/', '<Manager></Manager>', $xml);
        } else {
            $xml = preg_replace(
                '/<Application>.*?</Application>/',
                '<Application>' . $metadata['software'] . '</Application>',
                $xml
            );
            $xml = preg_replace(
                '/<Company>.*?</Company>/',
                '<Company>Generic Company</Company>',
                $xml
            );
            $xml = preg_replace(
                '/<Manager>.*?</Manager>/',
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
        $content = file_get_contents($inputPath);
        if ($content === false) {
            $this->writeLog("Failed to read DOC file: $inputPath");
            return false;
        }

        if ($this->options['strip_only']) {
            $content = preg_replace('/\x05SummaryInformation.*?\x00{4,}/s', '', $content);
            $content = preg_replace('/\x05DocumentSummaryInformation.*?\x00{4,}/s', '', $content);
        } else {
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

        $supportedTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff',
            'application/pdf', 'audio/mpeg',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        
        $info['supported'] = in_array($info['mime_type'], $supportedTypes);

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
            $fakeTimestamp = strtotime(str_replace(':', '-', substr($metadata['date'], 0, 10)) . ' ' . substr($metadata['date'], 11));
            
            if ($fakeTimestamp === false) {
                $this->writeLog("Invalid date format for file system timestamp: {$metadata['date']}");
                return false;
            }

            if ($this->options['use_same_timestamp']) {
                if (touch($filePath, $fakeTimestamp, $fakeTimestamp)) {
                    $this->writeLog("File system timestamps updated for $filePath: " . date('Y-m-d H:i:s', $fakeTimestamp));
                    return true;
                } else {
                    $this->writeLog("Failed to update file system timestamps for $filePath");
                    return false;
                }
            } else {
                $accessTimestamp = $fakeTimestamp + mt_rand(-86400, 86400);
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
            'change_time' => date('Y-m-d H:i:s', filectime($filePath)),
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

// ADVANCED: System time manipulation (requires admin/root privileges)
// WARNING: This temporarily changes your system clock!
$scrambler = new MetadataScrambler('advanced.log', [
    'manipulate_system_time' => true,
    'system_time_method' => 'auto'
]);

// Check if system time manipulation is possible
$capabilities = $scrambler->getSystemTimeCapabilities();
if ($capabilities['supported']) {
    echo "System time manipulation supported on {$capabilities['os']}\n";
    echo "Available methods: " . implode(', ', $capabilities['methods']) . "\n";
    echo "Warnings: " . implode(', ', $capabilities['warnings']) . "\n";
    
    if ($scrambler->checkSystemTimePermissions()) {
        echo "Permission check passed - can manipulate system time\n";
        $result = $scrambler->scramble('photo.jpg', 'deeply_scrambled.jpg');
        if ($result) {
            echo "File processed with fake creation time!\n";
        }
    } else {
        echo "Insufficient privileges for system time manipulation\n";
        echo "Run as Administrator (Windows) or with sudo (Linux/Mac)\n";
    }
} else {
    echo "System time manipulation not supported on this platform\n";
}

// Standard file system timestamp scrambling (doesn't require admin)
$scrambler = new MetadataScrambler('standard.log', [
    'scramble_file_timestamps' => true,
    'manipulate_system_time' => false
]);

$timestamps = $scrambler->getFileSystemTimestamps('original.jpg');
echo "Before: Mod={$timestamps['modification_time']}, Access={$timestamps['access_time']}\n";

$scrambler->scramble('original.jpg', 'scrambled.jpg');

$newTimestamps = $scrambler->getFileSystemTimestamps('scrambled.jpg');
echo "After: Mod={$newTimestamps['modification_time']}, Access={$newTimestamps['access_time']}\n";

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
