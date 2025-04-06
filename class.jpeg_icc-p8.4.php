<?php

/**
 * PHP JPEG ICC profile manipulator class
 *
 * @author Richard Toth aka risko (risko@risko.org)
 * @version 0.3 - Updated for PHP 8+ compatibility
 */
class JPEG_ICC
{
    /**
     * ICC header size in APP2 segment
     * 'ICC_PROFILE' 0x00 chunk_no chunk_cnt
     */
    const ICC_HEADER_LEN = 14;

    /**
     * Maximum data len of a JPEG marker payload
     * (Marker length field is 2 bytes, max value 65535, includes the 2 bytes for the length itself)
     */
    const MAX_BYTES_IN_MARKER_PAYLOAD = 65535 - 2; // 65533

    /**
     * ICC header marker
     */
    const ICC_MARKER = "ICC_PROFILE\x00";

    /**
     * Rendering intent field (Bytes 64 to 67 in ICC profile data)
     */
    const ICC_RI_PERCEPTUAL = 0x00000000;
    const ICC_RI_RELATIVE_COLORIMETRIC = 0x00000001;
    const ICC_RI_SATURATION = 0x00000002;
    const ICC_RI_ABSOLUTE_COLORIMETRIC = 0x00000003;

    /**
     * ICC profile data
     */
    private string $icc_profile = '';

    /**
     * ICC profile data size
     */
    private int $icc_size = 0;

    /**
     * ICC profile data chunks count
     */
    private int $icc_chunks = 0;

    /**
     * Class constructor
     */
    public function __construct()
    {
        // No initialization needed beyond property defaults
    }

    /**
     * Load ICC profile from JPEG file.
     *
     * Returns true if profile successfully loaded, false otherwise.
     *
     * @throws Exception If file cannot be read or is malformed.
     */
    public function LoadFromJPEG(string $fname): bool
    {
        if (!file_exists($fname) || !is_readable($fname)) {
             throw new Exception("File '$fname' does not exist or is not readable.");
        }

        $f = file_get_contents($fname);
        if ($f === false) {
            throw new Exception("Failed to read file content from '$fname'.");
        }

        $len = strlen($f);
        $pos = 0;
        $counter = 0; // Safety counter
        $profile_chunks = []; // Use short array syntax

        while ($pos < $len && $counter < 10000) { // Increased safety counter limit slightly
            $markerPos = strpos($f, "\xff", $pos);
            if ($markerPos === false) {
                break; // No more markers found
            }
            $pos = $markerPos;

            if ($pos + 4 > $len) { // Need at least marker (2 bytes) + size (2 bytes)
                 break; // Not enough data left for a segment header
            }

            $segmentType = $this->getJPEGSegmentType($f, $pos);
            if ($segmentType === false) {
                // Malformed marker or end of data, advance past marker and continue search
                $pos += 2;
                $counter++;
                continue;
            }

            // Segments without length field
            if ($segmentType >= 0xd0 && $segmentType <= 0xd9) { // SOI, EOI, RSTn, TEM
                 $pos += 2;
                 $counter++;
                 continue;
            }
            if ($segmentType === 0x01) { // TEM
                $pos += 2;
                $counter++;
                continue;
            }

            $segmentSize = $this->getJPEGSegmentSize($f, $pos);
            if ($segmentSize === false || $segmentSize < 2) {
                // Invalid size or not enough data for size field, treat as marker-only
                $pos += 2; // Advance past the marker itself
                $counter++;
                continue;
            }

            $segmentEndPos = $pos + $segmentSize + 2; // Position after this segment (marker + size + payload)
             if ($segmentEndPos > $len) {
                 // Segment size claims to extend beyond file data, indicates corruption
                 // echo "Warning: Segment 0x".dechex($segmentType)." at pos $pos claims size $segmentSize which exceeds file length $len.\n";
                 break; // Stop processing
             }

            switch ($segmentType) {
                case 0xe2: // APP2
                    if ($this->getJPEGSegmentContainsICC($f, $pos, $segmentSize)) {
                        $chunkInfo = $this->getJPEGSegmentICCChunkInfo($f, $pos);
                        if ($chunkInfo === false) {
                            // echo "Warning: Found ICC marker in APP2 at pos $pos but failed to read chunk info.\n";
                            break; // Skip this segment, move to next
                        }
                        list($chunk_no, $chunk_cnt) = $chunkInfo;

                        if ($chunk_no > 0 && $chunk_no <= $chunk_cnt) {
                            $chunkData = $this->getJPEGSegmentICCChunk($f, $pos, $segmentSize);
                            if ($chunkData !== false) {
                                $profile_chunks[$chunk_no] = $chunkData;

                                // Check if we have collected all chunks
                                if (count($profile_chunks) === $chunk_cnt) {
                                    ksort($profile_chunks);
                                    $this->SetProfile(implode('', $profile_chunks));
                                    return true; // Profile successfully loaded and assembled
                                }
                            } else {
                                // echo "Warning: Failed to extract ICC chunk data from segment at pos $pos.\n";
                            }
                        } else {
                            // echo "Warning: Invalid ICC chunk number ($chunk_no / $chunk_cnt) at pos $pos.\n";
                        }
                    }
                    break;

                // Other segments with size field - just skip them
                case 0xe0: case 0xe1: case 0xe3: case 0xe4: case 0xe5:
                case 0xe6: case 0xe7: case 0xe8: case 0xe9: case 0xea:
                case 0xeb: case 0xec: case 0xed: case 0xee: case 0xef:
                case 0xc0: case 0xc2: case 0xc4: case 0xdb: case 0xda:
                case 0xfe: case 0xdd: // DRI also has size
                    // Correctly handled by advancing pos below
                    break;

                default:
                    // Unknown segment marker with size, handled by advancing pos below
                    break;
            }

            // Advance position to the end of the current segment
            $pos = $segmentEndPos;
            $counter++;
        }

         if (!empty($profile_chunks)) {
             // We found some chunks but maybe not all? Decide if this is an error or partial success.
             // For now, treat as failure if not all chunks were found.
             // echo "Warning: Found some ICC chunks but failed to assemble the complete profile.\n";
         }

        return false; // Profile not found or incomplete
    }

    /**
     * Save previously loaded ICC profile into JPEG file.
     *
     * @throws Exception If no profile is loaded, file doesn't exist, or write fails.
     */
    public function SaveToJPEG(string $fname): void
    {
        if ($this->icc_profile === '') {
            throw new Exception("No ICC profile loaded to save.");
        }
        if (!file_exists($fname)) {
            throw new Exception("File '$fname' doesn't exist.");
        }
        if (!is_readable($fname)) {
            throw new Exception("File '$fname' isn't readable.");
        }
        $dir = dirname($fname);
        if (!is_dir($dir) || !is_writable($dir)) {
            // Check directory writability directly
            throw new Exception("Directory '$dir' for file '$fname' isn't writable or does not exist.");
        }

        $f = file_get_contents($fname);
         if ($f === false) {
             throw new Exception("Failed to read file content from '$fname'.");
         }

        // First, remove any existing profile to avoid duplicates
        $this->removeProfile($f); // Modify $f by reference

        // Then, insert the new profile
        if ($this->insertProfile($f)) { // Modify $f by reference
            $fsize = strlen($f);
            $ret = file_put_contents($fname, $f);
            if ($ret === false) {
                throw new Exception("Write failed for '$fname'. Check permissions and disk space.");
            }
            if ($ret < $fsize) {
                // This might indicate a partial write, which is problematic
                throw new Exception("Write partially failed for '$fname' (wrote $ret of $fsize bytes).");
            }
        } else {
            // This should ideally not happen if removeProfile worked and insertProfile logic is sound
            throw new Exception("Failed to insert ICC profile into JPEG data for '$fname'.");
        }
    }

    /**
     * Load profile from ICC file.
     *
     * @throws Exception If file cannot be read.
     */
    public function LoadFromICC(string $fname): void
    {
        if (!file_exists($fname)) {
            throw new Exception("ICC file '$fname' doesn't exist.");
        }
        if (!is_readable($fname)) {
            throw new Exception("ICC file '$fname' isn't readable.");
        }

        $data = file_get_contents($fname);
         if ($data === false) {
             throw new Exception("Failed to read ICC profile from '$fname'.");
         }
        $this->SetProfile($data);
    }

    /**
     * Save profile to ICC file.
     *
     * @throws Exception If no profile loaded, directory not writable, or write fails.
     */
    public function SaveToICC(string $fname, bool $force_overwrite = false): void
    {
        if ($this->icc_profile === '') {
            throw new Exception("No ICC profile loaded to save.");
        }
        $dir = dirname($fname);
         if (!is_dir($dir) || !is_writable($dir)) {
             throw new Exception("Directory '$dir' for file '$fname' isn't writable or does not exist.");
         }
        if (!$force_overwrite && file_exists($fname)) {
            throw new Exception("File '$fname' already exists. Use force_overwrite option.");
        }

        $ret = file_put_contents($fname, $this->icc_profile);
        if ($ret === false) {
            throw new Exception("Write failed for '$fname'. Check permissions and disk space.");
        }
        if ($ret < $this->icc_size) {
             throw new Exception("Write partially failed for '$fname' (wrote $ret of {$this->icc_size} bytes).");
         }
    }

    /**
     * Remove profile from JPEG file and save it as a new file.
     * Overwriting destination file can be forced.
     *
     * @throws Exception On file errors or write failures.
     */
    public function RemoveFromJPEG(string $input, string $output, bool $force_overwrite = false): bool
    {
        if (!file_exists($input)) {
            throw new Exception("Input file '$input' doesn't exist.");
        }
        if (!is_readable($input)) {
            throw new Exception("Input file '$input' isn't readable.");
        }
        $output_dir = dirname($output);
         if (!is_dir($output_dir) || !is_writable($output_dir)) {
             throw new Exception("Directory '$output_dir' for output file '$output' isn't writable or does not exist.");
         }
        if (!$force_overwrite && file_exists($output)) {
            throw new Exception("Output file '$output' exists. Use force_overwrite option.");
        }

        $f = file_get_contents($input);
         if ($f === false) {
             throw new Exception("Failed to read file content from '$input'.");
         }

        $removed = $this->removeProfile($f); // Modify $f by reference

        // Even if no profile was found to remove, we still write the output file
        // The original logic returned true, so we maintain that behavior.
        // if (!$removed) {
        //     // Optionally warn or handle the case where no profile was found to remove
        //     // echo "Warning: No ICC profile found in '$input' to remove.\n";
        // }

        $fsize = strlen($f);
        $ret = file_put_contents($output, $f);
         if ($ret === false) {
             throw new Exception("Write failed for '$output'. Check permissions and disk space.");
         }
         if ($ret < $fsize) {
             throw new Exception("Write partially failed for '$output' (wrote $ret of $fsize bytes).");
         }

        return true; // Indicates the operation (reading, processing, writing) completed without throwing an exception.
    }

    /**
     * Set profile directly
     */
    public function SetProfile(string $data): void
    {
        $this->icc_profile = $data;
        $this->icc_size = strlen($data);
        $this->countChunks();
    }

    /**
     * Get profile directly
     */
    public function GetProfile(): string
    {
        return $this->icc_profile;
    }

    /**
     * Count in how many chunks we need to divide the profile to store it in JPEG APP2 segments
     */
    private function countChunks(): void
    {
        if ($this->icc_size === 0) {
            $this->icc_chunks = 0;
            return;
        }
        // Calculate max data payload per chunk
        $max_payload_per_chunk = self::MAX_BYTES_IN_MARKER_PAYLOAD - self::ICC_HEADER_LEN;
        if ($max_payload_per_chunk <= 0) {
            // This should not happen with current constants, but safeguard
            throw new Exception("Internal configuration error: MAX_BYTES_IN_MARKER_PAYLOAD is too small.");
        }
        $this->icc_chunks = (int) ceil($this->icc_size / $max_payload_per_chunk);
    }

    /**
     * Set Rendering Intent of the profile.
     *
     * Possible values are ICC_RI_PERCEPTUAL, ICC_RI_RELATIVE_COLORIMETRIC, ICC_RI_SATURATION or ICC_RI_ABSOLUTE_COLORIMETRIC.
     *
     * @param int $newRI rendering intent constant
     */
    public function setRenderingIntent(int $newRI): void // Made public for potential use
    {
        if ($this->icc_size >= 68) {
            // Correctly assign the result of substr_replace back to the property
            $packedRI = pack('N', $newRI);
            if ($packedRI !== false) {
                 $this->icc_profile = substr_replace($this->icc_profile, $packedRI, 64, 4);
                 // Note: icc_size does not change
            } else {
                 // Handle potential pack error, though unlikely for 'N'
                 throw new Exception("Failed to pack rendering intent value.");
            }
        }
        // Optionally: else { throw new Exception("ICC profile is too small to contain rendering intent field."); }
    }

    /**
     * Get value of Rendering Intent field in ICC profile
     *
     * @return int|null Rendering intent constant or null if profile too small or read fails.
     */
    public function getRenderingIntent(): ?int // Made public for potential use
    {
        if ($this->icc_size >= 68) {
            $riBytes = substr($this->icc_profile, 64, 4);
            if (strlen($riBytes) === 4) {
                $arr = unpack('Nint', $riBytes);
                // unpack returns array or false
                if ($arr !== false && isset($arr['int'])) {
                    return $arr['int'];
                }
            }
        }
        return null; // Return null if profile too small or unpack failed
    }

    /**
     * Size of JPEG segment payload (value in the 2-byte length field).
     * Returns the size (int) or false on error (e.g., not enough data).
     */
    private function getJPEGSegmentSize(string $f, int $pos): int|false
    {
        // Need 2 bytes for marker type + 2 bytes for size = 4 bytes total minimum
        if ($pos + 4 > strlen($f)) {
            return false;
        }
        $sizeBytes = substr($f, $pos + 2, 2);
        if (strlen($sizeBytes) !== 2) {
            return false; // Should not happen if previous check passed, but safeguard
        }
        $arr = unpack('nint', $sizeBytes); // segment size has offset 2 and length 2B
        // unpack returns array or false
        if ($arr === false || !isset($arr['int'])) {
             return false; // Unpack failed
        }
        // The size field includes the 2 bytes for the size field itself
        return $arr['int'];
    }

    /**
     * Type of JPEG segment (the byte after 0xFF).
     * Returns the type (int) or false on error.
     */
    private function getJPEGSegmentType(string $f, int $pos): int|false
    {
        // Need 1 byte for 0xFF + 1 byte for type = 2 bytes total minimum
        if ($pos + 2 > strlen($f)) {
            return false;
        }
        $typeByte = substr($f, $pos + 1, 1);
         if (strlen($typeByte) !== 1) {
             return false;
         }
        $arr = unpack('Cchar', $typeByte); // segment type has offset 1 and length 1B
        // unpack returns array or false
        if ($arr === false || !isset($arr['char'])) {
             return false; // Unpack failed
        }
        return $arr['char'];
    }

    /**
     * Check if segment contains ICC profile marker.
     * $segmentSize is the value *from* the segment's length field.
     */
    private function getJPEGSegmentContainsICC(string $f, int $pos, int $segmentSize): bool
    {
        // Minimum data needed in payload: ICC_MARKER + chunk_no + chunk_cnt = 14 bytes
        // The segmentSize includes the 2 bytes for the size field itself.
        // So, payload size is segmentSize - 2.
        $payloadSize = $segmentSize - 2;
        if ($payloadSize < self::ICC_HEADER_LEN) {
            return false;
        }

        // Check if enough data exists in the string from the start of the marker
        $requiredLength = $pos + 4 + self::ICC_HEADER_LEN; // pos + FF E2 Size1 Size2 + ICC_HEADER
        if ($requiredLength > strlen($f)) {
             return false; // Not enough data in the file string itself
        }

        // Compare marker: Offset 4 within the segment data (after FF E2 Size1 Size2)
        // The marker itself is 12 bytes ("ICC_PROFILE\x00")
        $markerInFile = substr($f, $pos + 4, strlen(self::ICC_MARKER));
        return $markerInFile === self::ICC_MARKER;
    }

    /**
     * Get ICC segment chunk info {chunk_no, chunk_cnt}.
     * Returns array [chunk_no, chunk_cnt] or false on error.
     */
    private function getJPEGSegmentICCChunkInfo(string $f, int $pos): array|false
    {
        // Chunk info starts after FF E2 Size1 Size2 ICC_PROFILE\x00 (4 + 12 = 16 bytes offset)
        // Needs 2 bytes for the info itself.
        $infoOffset = $pos + 4 + strlen(self::ICC_MARKER); // Offset from start of file data
        if ($infoOffset + 2 > strlen($f)) {
            return false; // Not enough data in the file string
        }

        $chunkInfoBytes = substr($f, $infoOffset, 2);
        if (strlen($chunkInfoBytes) !== 2) {
            return false;
        }

        $a = unpack('Cchunk_no/Cchunk_count', $chunkInfoBytes);
        // unpack returns array or false
        if ($a === false || !isset($a['chunk_no']) || !isset($a['chunk_count'])) {
            return false;
        }
        // Basic sanity check: chunk number should not be zero or greater than count
        if ($a['chunk_no'] === 0 || $a['chunk_no'] > $a['chunk_count']) {
            return false;
        }
        return [$a['chunk_no'], $a['chunk_count']]; // Return as indexed array
    }

    /**
     * Returns chunk of ICC profile data from segment.
     * $segmentSize is the value *from* the segment's length field.
     * Returns the data string or false on error.
     */
    private function getJPEGSegmentICCChunk(string $f, int $pos, int $segmentSize): string|false
    {
        // Data starts after FF E2 Size1 Size2 ICC_PROFILE\x00 ChunkNo ChunkCnt
        $dataOffset = $pos + 4 + self::ICC_HEADER_LEN; // Offset from start of file data
        // Size of the data payload = Segment Size Field - Size Field Bytes (2) - ICC Header Bytes (14)
        $dataSize = $segmentSize - 2 - self::ICC_HEADER_LEN;

        if ($dataSize < 0) {
            return false; // Segment size is impossibly small
        }
        if ($dataOffset + $dataSize > strlen($f)) {
             return false; // Declared data size exceeds available file data
        }

        if ($dataSize === 0) {
            return ""; // Valid case for an empty chunk (though unusual)
        }

        $chunkData = substr($f, $dataOffset, $dataSize);
        if (strlen($chunkData) !== $dataSize) {
            // Should not happen if previous checks passed, but safeguard
            return false;
        }
        return $chunkData;
    }

    /**
     * Get data of a specific chunk number from the loaded profile.
     */
    private function getChunk(int $chunk_no): string
    {
        if ($chunk_no <= 0 || $chunk_no > $this->icc_chunks || $this->icc_profile === '') {
            return '';
        }

        $max_payload_per_chunk = self::MAX_BYTES_IN_MARKER_PAYLOAD - self::ICC_HEADER_LEN;
        if ($max_payload_per_chunk <= 0) {
             // Should be caught by countChunks, but safeguard
             return '';
        }

        $from = ($chunk_no - 1) * $max_payload_per_chunk;

        // Calculate bytes for this chunk
        $bytes = $max_payload_per_chunk;
        if ($chunk_no === $this->icc_chunks) {
            // Last chunk might be smaller
            $bytes = $this->icc_size - $from;
        }

        // Ensure calculated bytes is not negative (can happen if icc_size is smaller than expected)
        if ($bytes < 0) $bytes = 0;

        return substr($this->icc_profile, $from, $bytes);
    }

    /**
     * Prepare all APP2 segment data needed to represent the loaded ICC profile.
     * Returns the binary string or empty string if no profile loaded.
     */
    private function prepareJPEGProfileData(): string
    {
        if ($this->icc_profile === '' || $this->icc_chunks === 0) {
            return '';
        }

        $data = '';

        for ($i = 1; $i <= $this->icc_chunks; $i++) {
            $chunk = $this->getChunk($i);
            $chunk_size = strlen($chunk);

            // Total segment payload size = ICC Header (14) + chunk data size
            $payload_size = self::ICC_HEADER_LEN + $chunk_size;
            // Segment length field value = payload size + 2 bytes for length field itself
            $segment_len = $payload_size + 2;

            if ($segment_len > 65535) {
                 // This indicates an issue with MAX_BYTES_IN_MARKER_PAYLOAD or chunking logic
                 throw new Exception("Calculated segment length exceeds JPEG limit for chunk $i.");
            }

            $packedLen = pack('n', $segment_len);
            $packedChunkInfo = pack('CC', $i, $this->icc_chunks);

            if ($packedLen === false || $packedChunkInfo === false) {
                 throw new Exception("Failed to pack segment length or chunk info for chunk $i.");
            }

            $data .= "\xff\xe2" . $packedLen; // APP2 segment marker + size field
            $data .= self::ICC_MARKER . $packedChunkInfo; // profile marker inside segment
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Removes profile from JPEG data (passed by reference).
     * Returns true if any profile chunks were removed, false otherwise.
     */
    private function removeProfile(string &$jpeg_data): bool
    {
        $len = strlen($jpeg_data);
        $pos = 0;
        $counter = 0; // Safety counter
        $removed_something = false;
        $original_length = $len;

        while ($pos < $len && $counter < 10000) {
            $markerPos = strpos($jpeg_data, "\xff", $pos);
            if ($markerPos === false) {
                break; // No more markers
            }
            $pos = $markerPos;

            if ($pos + 4 > $len) break; // Not enough data for segment header

            $segmentType = $this->getJPEGSegmentType($jpeg_data, $pos);
             if ($segmentType === false) {
                 $pos += 2; // Skip invalid marker
                 $counter++;
                 continue;
             }

            // Segments without length field
            if (($segmentType >= 0xd0 && $segmentType <= 0xd9) || $segmentType === 0x01) {
                 $pos += 2;
                 $counter++;
                 continue;
            }

            $segmentSize = $this->getJPEGSegmentSize($jpeg_data, $pos);
             if ($segmentSize === false || $segmentSize < 2) {
                 $pos += 2; // Skip invalid segment size
                 $counter++;
                 continue;
             }

            $segmentTotalLength = $segmentSize + 2; // Size field value + 2 bytes for marker
            $segmentEndPos = $pos + $segmentTotalLength;

            if ($segmentEndPos > $len) {
                 // Corrupted segment size, stop processing
                 break;
            }

            if ($segmentType === 0xe2) { // APP2
                if ($this->getJPEGSegmentContainsICC($jpeg_data, $pos, $segmentSize)) {
                    // Remove this APP2 segment
                    $jpeg_data = substr_replace($jpeg_data, '', $pos, $segmentTotalLength);
                    $len = strlen($jpeg_data); // Update length
                    $removed_something = true;
                    // IMPORTANT: Do not advance $pos here, the next iteration should
                    // re-evaluate from the current position as data has shifted.
                    $counter++;
                    continue; // Continue loop from the same position
                }
            }

            // Advance position past the current segment if it wasn't removed
            $pos = $segmentEndPos;
            $counter++;
        }

        return $removed_something;
    }

    /**
     * Inserts profile into JPEG data (passed by reference).
     * Inserts profile immediately after SOI marker.
     * Returns true on success, false on failure (e.g., SOI not found).
     */
    private function insertProfile(string &$jpeg_data): bool
    {
        $len = strlen($jpeg_data);
        if ($len < 2) return false; // Need at least SOI

        // Check for SOI marker at the beginning
        if (substr($jpeg_data, 0, 2) !== "\xff\xd8") {
            // throw new Exception("Invalid JPEG: Missing SOI marker at the beginning.");
            return false; // Or handle as appropriate
        }

        $pos = 2; // Position after SOI

        $profileDataToInsert = $this->prepareJPEGProfileData();
        if ($profileDataToInsert === '') {
            // No profile loaded or profile is empty, nothing to insert.
            // This could be considered success (nothing to do) or failure depending on context.
            // Let's return true, indicating the operation completed (by doing nothing).
            return true;
        }

        // Insert the prepared profile data right after the SOI marker
        $jpeg_data = substr_replace($jpeg_data, $profileDataToInsert, $pos, 0);

        return true;
    }
}

// Note: Removed the closing ?> tag as per PSR standards.