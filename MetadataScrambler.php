<?php

class MetadataScrambler
{
    private $logFile;

    public function __construct($logFile)
    {
        $this->logFile = $logFile;
        $this->writeLog("MetadataScrambler initialized");
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
            'latitude' => number_format(mt_rand(-900000, 900000) / 10000, 6), // -90 to 90
            'longitude' => number_format(mt_rand(-1800000, 1800000) / 10000, 6), // -180 to 180
            'date' => date('Y:m:d H:i:s', mt_rand(strtotime('2000-01-01'), time())),
            'author' => 'Anonymous_' . bin2hex(random_bytes(4))
        ];
        $this->writeLog("Generated metadata: Lat={$metadata['latitude']}, Lon={$metadata['longitude']}, Date={$metadata['date']}, Author={$metadata['author']}");
        return $metadata;
    }

    public function scramble($inputPath, $outputPath, $fileType)
    {
        $this->writeLog("Scrambling metadata for $inputPath, Type: $fileType");
        $metadata = $this->generateRandomMetadata();

        switch ($fileType) {
            case 'image/jpeg':
                return $this->scrambleJpeg($inputPath, $outputPath, $metadata);
            case 'image/png':
            case 'image/gif':
            case 'image/bmp':
            case 'image/webp':
                return $this->scrambleImage($inputPath, $outputPath, $fileType, $metadata);
            case 'application/pdf':
                return $this->scramblePdf($inputPath, $outputPath, $metadata);
            case 'audio/mpeg':
                return $this->scrambleMp3($inputPath, $outputPath, $metadata);
            case 'application/msword': // DOC
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': // DOCX
                return $this->scrambleWord($inputPath, $outputPath, $fileType, $metadata);
            default:
                $this->writeLog("Unsupported file type: $fileType");
                return false;
        }
    }

    private function scrambleJpeg($inputPath, $outputPath, $metadata)
    {
        if (!function_exists('imagecreatefromjpeg')) {
            $this->writeLog("GD extension not available for $inputPath");
            return false;
        }

        try {
            // Strip metadata using GD
            $image = imagecreatefromjpeg($inputPath);
            imagejpeg($image, $outputPath, 90);
            imagedestroy($image);
            $this->writeLog("GD JPEG processing succeeded for $inputPath");

            // Add randomized EXIF data
            if ($this->addExifToJpeg($outputPath, $metadata)) {
                $this->writeLog("EXIF data added successfully for $outputPath");
            } else {
                $this->writeLog("Failed to add EXIF data for $outputPath");
            }

            return file_exists($outputPath);
        } catch (Exception $e) {
            $this->writeLog("JPEG processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function addExifToJpeg($filePath, $metadata)
    {
        $this->writeLog("Constructing EXIF segment for $filePath");

        // Read the JPEG file
        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->writeLog("Failed to read JPEG: $filePath");
            return false;
        }

        // Ensure JPEG starts with SOI (Start of Image)
        if (substr($content, 0, 2) !== "\xFF\xD8") {
            $this->writeLog("Invalid JPEG format: $filePath");
            return false;
        }

        // Build EXIF APP1 segment
        $exifData = $this->buildExifSegment($metadata);
        $this->writeLog("EXIF segment length: " . strlen($exifData) . " bytes");

        // Insert EXIF after SOI
        $newContent = substr($content, 0, 2) . $exifData . substr($content, 2);

        if (file_put_contents($filePath, $newContent) === false) {
            $this->writeLog("Failed to write EXIF to JPEG: $filePath");
            return false;
        }

        return true;
    }

    private function buildExifSegment($metadata)
    {
        $exifHeader = "Exif\x00\x00";
        $tiffHeader = "II" . pack('v', 42) . pack('V', 8);
        $numTags = 2;
        $ifd0 = pack('v', $numTags);

        $dateTimeOffset = 8 + 2 + 12 * $numTags + 4; // TIFF header + numTags + tags + next IFD
        $dateTimeTag = pack('vvVV', 0x9003, 2, 20, $dateTimeOffset);

        $artistLength = strlen($metadata['author']) + 1;
        $artistOffset = $dateTimeOffset + 20;
        $artistTag = pack('vvVV', 0x013B, 2, $artistLength, $artistOffset);

        $dateTimeValue = str_pad($metadata['date'], 19, "\0") . "\x00";
        $artistValue = $metadata['author'] . "\x00";

        $ifdData = $ifd0 . $dateTimeTag . $artistTag . pack('V', 0) . $dateTimeValue . $artistValue;

        $length = strlen($exifHeader . $tiffHeader . $ifdData);
        $app1 = "\xFF\xE1" . pack('n', $length + 2) . $exifHeader . $tiffHeader . $ifdData;

        $this->writeLog("EXIF segment built: DateTimeOriginal={$metadata['date']}, Artist={$metadata['author']}, ArtistOffset=$artistOffset, ArtistLength=$artistLength");
        return $app1;
    }

    private function scrambleImage($inputPath, $outputPath, $fileType, $metadata)
    {
        if (!function_exists('imagecreatefromjpeg') || !function_exists('imagecreatefrompng')) {
            $this->writeLog("GD extension not available for $inputPath");
            return false;
        }

        try {
            switch ($fileType) {
                case 'image/png':
                    $image = imagecreatefrompng($inputPath);
                    imagepng($image, $outputPath);
                    break;
                case 'image/gif':
                    if (!function_exists('imagecreatefromgif')) {
                        $this->writeLog("GD GIF support not available for $inputPath");
                        return false;
                    }
                    $image = imagecreatefromgif($inputPath);
                    imagegif($image, $outputPath);
                    break;
                case 'image/bmp':
                    if (!function_exists('imagecreatefrombmp')) {
                        $this->writeLog("GD BMP support not available for $inputPath");
                        return false;
                    }
                    $image = imagecreatefrombmp($inputPath);
                    imagebmp($image, $outputPath);
                    break;
                case 'image/webp':
                    if (!function_exists('imagecreatefromwebp')) {
                        $this->writeLog("GD WebP support not available for $inputPath");
                        return false;
                    }
                    $image = imagecreatefromwebp($inputPath);
                    imagewebp($image, $outputPath);
                    break;
            }
            imagedestroy($image);
            $this->writeLog("GD processing succeeded for $inputPath ($fileType)");
            return file_exists($outputPath);
        } catch (Exception $e) {
            $this->writeLog("GD processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function scramblePdf($inputPath, $outputPath, $metadata)
    {
        $this->writeLog("Attempting PDF metadata scrambling for $inputPath");

        try {
            $content = file_get_contents($inputPath);
            if ($content === false) {
                $this->writeLog("Failed to read PDF: $inputPath");
                return false;
            }

            $newInfo = "<<\n";
            $newInfo .= "/Title (Scrambled PDF)\n";
            $newInfo .= "/Author ({$metadata['author']})\n";
            $newInfo .= "/CreationDate (D:{$this->formatPdfDate($metadata['date'])})\n";
            $newInfo .= "/Producer (MetadataScrambler)\n";
            $newInfo .= ">>";
            $this->writeLog("Generated new PDF Info: $newInfo");

            $content = preg_replace('/<<\s*\/Title.*?>>\s*endobj/s', $newInfo . "\nendobj", $content);

            if (file_put_contents($outputPath, $content) === false) {
                $this->writeLog("Failed to write PDF: $outputPath");
                return false;
            }

            $this->writeLog("PDF processing succeeded for $inputPath");
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
        $this->writeLog("Attempting MP3 ID3v1 metadata scrambling for $inputPath");

        try {
            $content = file_get_contents($inputPath);
            if ($content === false) {
                $this->writeLog("Failed to read MP3: $inputPath");
                return false;
            }

            $fileSize = filesize($inputPath);
            if ($fileSize < 128) {
                $this->writeLog("MP3 file too small for ID3v1: $inputPath");
                return false;
            }

            $id3v1 = substr($content, -128);
            if (strlen($id3v1) === 128 && substr($id3v1, 0, 3) === 'TAG') {
                $this->writeLog("Found existing ID3v1 tag in $inputPath");
            } else {
                $this->writeLog("No ID3v1 tag found; appending new tag for $inputPath");
                $id3v1 = str_repeat("\0", 128);
                $id3v1 = substr_replace($id3v1, 'TAG', 0, 3);
            }

            $id3v1 = substr_replace($id3v1, str_pad('Scrambled Song', 30, "\0"), 3, 30); // Title
            $id3v1 = substr_replace($id3v1, str_pad($metadata['author'], 30, "\0"), 33, 30); // Artist
            $id3v1 = substr_replace($id3v1, str_pad(substr($metadata['date'], 0, 4), 4, "\0"), 93, 4); // Year
            $id3v1 = substr_replace($id3v1, str_pad('Scrambled by PHP', 30, "\0"), 97, 30); // Comment

            $outputContent = substr($content, 0, -128) . $id3v1;
            if (file_put_contents($outputPath, $outputContent) === false) {
                $this->writeLog("Failed to write MP3: $outputPath");
                return false;
            }

            $this->writeLog("MP3 ID3v1 processing succeeded for $inputPath");
            return true;
        } catch (Exception $e) {
            $this->writeLog("MP3 processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function scrambleWord($inputPath, $outputPath, $fileType, $metadata)
    {
        $this->writeLog("Attempting Word ($fileType) metadata scrambling for $inputPath");

        try {
            if ($fileType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                if (!class_exists('ZipArchive')) {
                    $this->writeLog("ZipArchive not available for DOCX: $inputPath");
                    return false;
                }

                $zip = new ZipArchive();
                if ($zip->open($inputPath) !== true) {
                    $this->writeLog("Failed to open DOCX as ZIP: $inputPath");
                    return false;
                }

                $coreXml = $zip->getFromName('docProps/core.xml');
                if ($coreXml === false) {
                    $this->writeLog("Failed to read docProps/core.xml in $inputPath");
                    $zip->close();
                    return false;
                }

                $coreXml = preg_replace(
                    '/<dc:creator>.*?<\/dc:creator>/',
                    "<dc:creator>{$metadata['author']}</dc:creator>",
                    $coreXml
                );
                $coreXml = preg_replace(
                    '/<dcterms:created.*?>.*?</dcterms:created>/',
                    "<dcterms:created xsi:type=\"dcterms:W3CDTF\">{$metadata['date']}</dcterms:created>",
                    $coreXml
                );
                $this->writeLog("Updated DOCX metadata in core.xml");

                $newZip = new ZipArchive();
                if ($newZip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    $this->writeLog("Failed to create output DOCX: $outputPath");
                    $zip->close();
                    return false;
                }

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if ($filename === 'docProps/core.xml') {
                        $newZip->addFromString($filename, $coreXml);
                    } else {
                        $content = $zip->getFromIndex($i);
                        $newZip->addFromString($filename, $content);
                    }
                }

                $zip->close();
                $newZip->close();
                $this->writeLog("DOCX processing succeeded for $inputPath");
                return file_exists($outputPath);
            } else {
                $content = file_get_contents($inputPath);
                if ($content === false) {
                    $this->writeLog("Failed to read DOC: $inputPath");
                    return false;
                }

                $author = str_pad($metadata['author'], 32, "\0");
                $date = str_pad($this->formatDocDate($metadata['date']), 32, "\0");
                $content = preg_replace('/\x05SummaryInformation.*?\x00{32}/s', "\x05SummaryInformation" . $author, $content, 1);
                $this->writeLog("Updated DOC metadata (simplified)");

                if (file_put_contents($outputPath, $content) === false) {
                    $this->writeLog("Failed to write DOC: $outputPath");
                    return false;
                }

                $this->writeLog("DOC processing succeeded for $inputPath");
                return true;
            }
        } catch (Exception $e) {
            $this->writeLog("Word processing failed for $inputPath: " . $e->getMessage());
            return false;
        }
    }

    private function formatDocDate($date)
    {
        return str_replace([':', ' '], '', $date);
    }
}
