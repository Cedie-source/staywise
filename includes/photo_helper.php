<?php
/**
 * Shared helper: process uploaded photo into a base64 data URI.
 * Resizes to max 200x200px using GD if available, otherwise stores as-is.
 * Returns the data URI string or throws on error.
 */
function process_photo_to_base64(array $file): string {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed)) throw new Exception("Invalid image type.");
    if ($file['size'] > 3 * 1024 * 1024) throw new Exception("Image too large (max 3MB).");

    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false) throw new Exception("Could not read uploaded file.");

    // Resize to max 200x200 using GD if available
    if (function_exists('imagecreatefromstring')) {
        $src = imagecreatefromstring($raw);
        if ($src) {
            $sw = imagesx($src);
            $sh = imagesy($src);
            $max = 200;
            if ($sw > $max || $sh > $max) {
                $ratio = min($max / $sw, $max / $sh);
                $nw = (int)round($sw * $ratio);
                $nh = (int)round($sh * $ratio);
                $dst = imagecreatetruecolor($nw, $nh);
                // Preserve transparency for PNG
                if ($mime === 'image/png') {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                }
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
                ob_start();
                if ($mime === 'image/png') imagepng($dst, null, 6);
                else imagejpeg($dst, null, 82);
                $raw = ob_get_clean();
                imagedestroy($src);
                imagedestroy($dst);
                $mime = ($mime === 'image/png') ? 'image/png' : 'image/jpeg';
            }
        }
    }

    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

/**
 * Returns the correct src for an <img> tag.
 * Handles both old filenames and new base64 data URIs.
 */
function photo_src(string $val, string $fallback = ''): string {
    if (empty($val)) return $fallback;
    if (str_starts_with($val, 'data:')) return $val;
    return '/uploads/profiles/' . $val;
}
