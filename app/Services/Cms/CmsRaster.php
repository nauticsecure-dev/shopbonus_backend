<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;

/** Bounded raster operations only; no imagescale dependency, metadata/HTML/SVG or remote fetch. */
class CmsRaster
{
    /** @return array{width:int,height:int,mime:string} */
    public function inspect(string $bytes): array
    {
        $i = @getimagesizefromstring($bytes);
        if (strlen($bytes) > 4194304 || $i === false || ! in_array($i[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $i[0] < 16 || $i[1] < 16 || $i[0] > 6000 || $i[1] > 6000 || $i[0] * $i[1] > 12000000) {
            throw new ApiException('Use a supported raster image up to4MiB and12million pixels.', 422, 'media_image_invalid');
        }

        return ['width' => $i[0], 'height' => $i[1], 'mime' => $i['mime']];
    }

    /** @param array<string,mixed>|null $transform
     * @return array{bytes:string,width:int,height:int,mime:string}
     */
    public function encode(string $bytes, string $mime, ?array $transform = null): array
    {
        $info = $this->inspect($bytes);
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new ApiException('The image codec is unavailable.', 503, 'media_codec_unavailable');
        }$src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new ApiException('The image cannot be decoded.', 422, 'media_image_invalid');
        }
        $crop = null;
        $out = null;
        try {
            $t = $transform ?? ['x' => 0, 'y' => 0, 'width' => $info['width'], 'height' => $info['height'], 'rotate' => 0, 'flip_h' => false, 'flip_v' => false, 'output_width' => $info['width']];
            $crop = imagecrop($src, ['x' => $t['x'], 'y' => $t['y'], 'width' => $t['width'], 'height' => $t['height']]);
            if ($crop === false) {
                throw new \RuntimeException('Crop failed.');
            }
            if ($t['flip_h']) {
                imageflip($crop, IMG_FLIP_HORIZONTAL);
            }
            if ($t['flip_v']) {
                imageflip($crop, IMG_FLIP_VERTICAL);
            }
            if ($t['rotate'] !== 0) {
                $rotated = imagerotate($crop, -$t['rotate'], imagecolorallocatealpha($crop, 0, 0, 0, 127));
                if ($rotated === false) {
                    throw new \RuntimeException('Rotation failed.');
                }imagedestroy($crop);
                $crop = $rotated;
            }
            $scale = $transform === null ? 1 : min(1, $t['output_width'] / imagesx($crop), 2048 / imagesy($crop));
            $w = max(1, (int) round(imagesx($crop) * $scale));
            $h = max(1, (int) round(imagesy($crop) * $scale));
            $out = imagecreatetruecolor($w, $h);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            if (! imagecopyresampled($out, $crop, 0, 0, 0, 0, $w, $h, imagesx($crop), imagesy($crop))) {
                throw new \RuntimeException('Resample failed.');
            }
            ob_start();
            try {
                $ok = match ($mime) {
                    'image/png' => imagepng($out, null, 6),'image/jpeg' => imagejpeg($out, null, 85),default => imagewebp($out, null, 82)
                };
                $result = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! $ok || ! is_string($result) || $result === '' || strlen($result) > 8388608 || @getimagesizefromstring($result) === false) {
                throw new \RuntimeException('Encode failed.');
            }

            return ['bytes' => $result, 'width' => $w, 'height' => $h, 'mime' => $mime];
        } finally {
            imagedestroy($src);
            if ($crop instanceof \GdImage) {
                imagedestroy($crop);
            }if ($out instanceof \GdImage) {
                imagedestroy($out);
            }
        }
    }
}
