<?php

namespace App\Support\Upload18;

use App\Http\Controllers\Api\Private\Traits\Upload18ServerStreamTrait;
use App\Models\Video;
use App\Support\MediaBinary;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Thumbnails the server makes for an Upload18 video whose member's browser sent none (a codec the browser cannot
 * decode, or the page was closed before the upload finished): frames of the Upload18 stream with ffmpeg when this
 * server may run it, else "poster cards" drawn with GD (a gradient, a play sign and the title: never a blank). Used by
 * the draft page (POST /api/videos/{id}/generate-server-thumbnails) and by the scheduler's upload18:finish-pending.
 * Moved here unchanged from Api\Private\VideoController (1.2.3).
 */
final class ServerThumbnails
{
    use Upload18ServerStreamTrait;

    /**
     * Up to $count JPEG pictures (bytes), each at most $maxThumbnailBytes; empty when none could be made.
     *
     * @return list<string>
     */
    public function generate(Video $video, int $count, int $maxThumbnailBytes): array
    {
        $count = max(1, min(5, $count));
        $images = [];

        // Attempt stream extraction only if the server environment supports running CLI processes
        if ($video->embed_code && MediaBinary::canRunProcesses() && MediaBinary::hasFfmpeg()) {
            $streamData = $this->resolveUpload18Stream($video);

            if (!empty($streamData['stream_url'])) {
                $streamUrl = $streamData['stream_url'];
                $tempDir = storage_path('app/tmp/server-thumbnails/' . $video->id . '-' . Str::uuid());

                if (is_dir($tempDir) || mkdir($tempDir, 0775, true)) {
                    try {
                        $images = $this->captureServerThumbnailsFromSegments($video, $streamUrl, $count, $tempDir, $maxThumbnailBytes);

                        if (count($images) === 0 && MediaBinary::hasFfprobe()) {
                            $duration = $this->probeStreamDuration($streamUrl, $video);
                            $positions = $this->thumbnailPositions($duration, $count);

                            foreach ($positions as $index => $position) {
                                $output = $tempDir . DIRECTORY_SEPARATOR . 'thumb-hls-' . ($index + 1) . '.jpg';

                                if (!$this->captureServerThumbnail($streamUrl, $position, $output, $video)) {
                                    continue;
                                }

                                $imageData = $this->readGeneratedJpeg($output, $maxThumbnailBytes);
                                if ($imageData !== null) {
                                    $images[] = $imageData;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Server thumbnail capture exception for video ' . $video->id . ': ' . $e->getMessage());
                    } finally {
                        $this->deleteDirectory($tempDir);
                    }
                }
            }
        }

        // If ffmpeg / stream capture was unavailable or yielded 0 images (e.g. Cloudflare blocked datacenter IP or proc_open disabled),
        // generate branded 16:9 poster cards using PHP GD so thumbnails are NEVER left in stuck pending state!
        if (count($images) === 0) {
            $images = $this->generateFallbackCardThumbnails($video, $count, $maxThumbnailBytes);
        }

        return $images;
    }

    private function captureServerThumbnailsFromSegments(Video $video, string $streamUrl, int $count, string $tempDir, int $maxThumbnailBytes): array
    {
        $manifest = $this->fetchUpload18StreamBody($video, $streamUrl);
        if ($manifest === null || !str_contains($manifest, '#EXTM3U')) {
            return [];
        }

        $segmentUrls = $this->collectHlsSegmentUrls($video, $streamUrl, $manifest);

        $images = [];
        $positions = [0.75, 1.5, 2.25, 3.25, 4.5, 6.0];

        foreach ($segmentUrls as $segmentIndex => $segmentUrl) {
            if (count($images) >= $count) {
                break;
            }

            $segmentBody = $this->fetchUpload18StreamBody($video, $segmentUrl);
            if ($segmentBody === null || strlen($segmentBody) < 1000) {
                continue;
            }

            $segmentPath = $tempDir . DIRECTORY_SEPARATOR . 'segment-' . ($segmentIndex + 1) . '.ts';
            file_put_contents($segmentPath, $segmentBody);

            $remaining = $count - count($images);
            $seriesPattern = $tempDir . DIRECTORY_SEPARATOR . 'thumb-segment-' . ($segmentIndex + 1) . '-%02d.jpg';
            if ($this->captureServerThumbnailSeries($segmentPath, $remaining, $seriesPattern)) {
                $seriesFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'thumb-segment-' . ($segmentIndex + 1) . '-*.jpg') ?: [];
                sort($seriesFiles);

                foreach ($seriesFiles as $seriesFile) {
                    if (count($images) >= $count) {
                        break 2;
                    }

                    $imageData = $this->readGeneratedJpeg($seriesFile, $maxThumbnailBytes);
                    if ($imageData !== null) {
                        $images[] = $imageData;
                    }
                }
            }

            foreach ($positions as $positionIndex => $position) {
                if (count($images) >= $count) {
                    break 2;
                }

                $output = $tempDir . DIRECTORY_SEPARATOR . 'thumb-segment-' . ($segmentIndex + 1) . '-' . ($positionIndex + 1) . '.jpg';
                if (!$this->captureServerThumbnail($segmentPath, $position, $output)) {
                    continue;
                }

                $imageData = $this->readGeneratedJpeg($output, $maxThumbnailBytes);
                if ($imageData !== null) {
                    $images[] = $imageData;
                }
            }
        }

        return $images;
    }

    private function collectHlsSegmentUrls(Video $video, string $playlistUrl, string $manifest, int $depth = 0): array
    {
        if ($depth > 2) {
            return [];
        }

        $segments = [];
        $variants = [];
        $urlBase = substr($playlistUrl, 0, strrpos($playlistUrl, '/') + 1);
        $previousLine = '';

        foreach (preg_split('/\r?\n/', $manifest) as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#')) {
                $previousLine = $line;
                continue;
            }

            $absoluteUrl = $this->absoluteStreamUrl($line, $urlBase);
            $path = strtolower(parse_url($absoluteUrl, PHP_URL_PATH) ?? '');

            if (str_contains($path, '.m3u8') || str_contains(strtolower($line), 'm3u8') || str_starts_with($previousLine, '#EXT-X-STREAM-INF')) {
                $variants[] = $absoluteUrl;
            } else {
                $segments[] = $absoluteUrl;
            }

            $previousLine = $line;
        }

        if (count($segments) > 0) {
            return $segments;
        }

        foreach (array_slice($variants, 0, 3) as $variantUrl) {
            $variantManifest = $this->fetchUpload18StreamBody($video, $variantUrl);
            if ($variantManifest === null || !str_contains($variantManifest, '#EXTM3U')) {
                continue;
            }

            $segments = $this->collectHlsSegmentUrls($video, $variantUrl, $variantManifest, $depth + 1);
            if (count($segments) > 0) {
                return $segments;
            }
        }

        return [];
    }

    private function captureServerThumbnailSeries(string $input, int $count, string $outputPattern): bool
    {
        if (!MediaBinary::canRunProcesses() || !MediaBinary::hasFfmpeg() || $count <= 0) {
            return false;
        }

        $process = new Process([
            MediaBinary::ffmpeg(),
            '-y',
            '-hide_banner',
            '-loglevel', 'error',
            '-i', $input,
            '-vf', 'fps=1,scale=640:-2',
            '-frames:v', (string) $count,
            '-an',
            '-q:v', '3',
            $outputPattern,
        ]);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && count(glob(str_replace('%02d', '*', $outputPattern)) ?: []) > 0;
    }

    private function readGeneratedJpeg(string $path, int $maxThumbnailBytes): ?string
    {
        $imageData = @file_get_contents($path);

        if (!is_string($imageData) || $imageData === '' || strlen($imageData) > $maxThumbnailBytes || !str_starts_with($imageData, "\xff\xd8")) {
            return null;
        }

        return $imageData;
    }

    private function probeStreamDuration(string $streamUrl, ?Video $video = null): ?float
    {
        if (!MediaBinary::canRunProcesses() || !MediaBinary::hasFfprobe()) {
            return null;
        }

        $command = [
            MediaBinary::ffprobe(),
            '-v', 'error',
        ];

        if (str_starts_with($streamUrl, 'http://') || str_starts_with($streamUrl, 'https://')) {
            array_push($command,
                '-user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            );

            if ($video) {
                array_push($command, '-headers', $this->upload18FfmpegHeaders($video));
            }
        }

        array_push($command,
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $streamUrl,
        );

        $process = new Process($command);
        $process->setTimeout(75);
        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $duration = (float) trim($process->getOutput());

        return $duration > 0 ? $duration : null;
    }

    private function thumbnailPositions(?float $duration, int $count): array
    {
        if ($duration === null || $duration <= 1) {
            return array_slice([0.75, 1.5, 2.25, 3.0, 4.0], 0, $count);
        }

        $positions = [];
        $safeDuration = max(1.0, $duration - 0.5);

        for ($i = 1; $i <= $count; $i++) {
            $positions[] = max(0.5, min($safeDuration, $duration * ($i / ($count + 1))));
        }

        return $positions;
    }

    private function captureServerThumbnail(string $streamUrl, float $position, string $output, ?Video $video = null): bool
    {
        if (!MediaBinary::canRunProcesses() || !MediaBinary::hasFfmpeg()) {
            return false;
        }

        $command = [
            MediaBinary::ffmpeg(),
            '-y',
            '-hide_banner',
            '-loglevel', 'error',
        ];

        if (str_starts_with($streamUrl, 'http://') || str_starts_with($streamUrl, 'https://')) {
            $command[] = '-user_agent';
            $command[] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

            if ($video) {
                $command[] = '-headers';
                $command[] = $this->upload18FfmpegHeaders($video);
            }
        }

        array_push($command,
            '-ss', number_format($position, 3, '.', ''),
            '-i', $streamUrl,
            '-frames:v', '1',
            '-an',
            '-vf', 'scale=640:-2',
            '-q:v', '3',
            $output,
        );

        $process = new Process($command);
        $process->setTimeout(120);
        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && is_file($output) && filesize($output) > 1024;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    private function generateFallbackCardThumbnails(Video $video, int $count, int $maxThumbnailBytes): array
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return [];
        }

        $themes = [
            [
                'bg_top' => [28, 12, 20],
                'bg_bot' => [12, 8, 14],
                'accent' => [239, 68, 68],
            ],
            [
                'bg_top' => [16, 20, 40],
                'bg_bot' => [8, 10, 20],
                'accent' => [99, 102, 241],
            ],
            [
                'bg_top' => [12, 26, 34],
                'bg_bot' => [6, 14, 20],
                'accent' => [14, 165, 233],
            ],
            [
                'bg_top' => [24, 20, 12],
                'bg_bot' => [12, 10, 6],
                'accent' => [234, 179, 8],
            ],
            [
                'bg_top' => [14, 26, 18],
                'bg_bot' => [6, 14, 10],
                'accent' => [34, 197, 94],
            ],
        ];

        $width = 640;
        $height = 360;
        $images = [];
        $title = $video->title ?: 'Video';

        for ($i = 0; $i < $count; $i++) {
            $theme = $themes[$i % count($themes)];
            $im = @imagecreatetruecolor($width, $height);
            if (!$im) {
                continue;
            }

            // Vertical subtle gradient
            for ($y = 0; $y < $height; $y++) {
                $ratio = $y / $height;
                $r = (int) ($theme['bg_top'][0] + ($theme['bg_bot'][0] - $theme['bg_top'][0]) * $ratio);
                $g = (int) ($theme['bg_top'][1] + ($theme['bg_bot'][1] - $theme['bg_top'][1]) * $ratio);
                $b = (int) ($theme['bg_top'][2] + ($theme['bg_bot'][2] - $theme['bg_top'][2]) * $ratio);
                $color = imagecolorallocate($im, $r, $g, $b);
                imageline($im, 0, $y, $width, $y, $color);
            }

            // Ambient glow circle in center
            $glowColor = imagecolorallocatealpha($im, $theme['accent'][0], $theme['accent'][1], $theme['accent'][2], 100);
            imagefilledellipse($im, (int) ($width / 2), (int) ($height / 2), 150, 150, $glowColor);

            // Center button circle
            $buttonColor = imagecolorallocatealpha($im, $theme['accent'][0], $theme['accent'][1], $theme['accent'][2], 25);
            imagefilledellipse($im, (int) ($width / 2), (int) ($height / 2), 76, 76, $buttonColor);

            // Center play triangle
            $white = imagecolorallocate($im, 255, 255, 255);
            $cx = (int) ($width / 2);
            $cy = (int) ($height / 2);
            $points = [
                $cx - 10, $cy - 16,
                $cx - 10, $cy + 16,
                $cx + 16, $cy,
            ];
            imagefilledpolygon($im, $points, $white);

            // Clean bottom title label
            $safeTitle = mb_strimwidth($title, 0, 46, '...');
            $textColor = imagecolorallocate($im, 220, 225, 235);
            $font = 4;
            $textWidth = imagefontwidth($font) * strlen($safeTitle);
            $tx = max(20, (int) (($width - $textWidth) / 2));
            imagestring($im, $font, $tx, $height - 36, $safeTitle, $textColor);

            ob_start();
            imagejpeg($im, null, 85);
            $data = ob_get_clean();
            unset($im);

            if (is_string($data) && strlen($data) > 100 && strlen($data) <= $maxThumbnailBytes && str_starts_with($data, "\xff\xd8")) {
                $images[] = $data;
            }
        }

        return $images;
    }
}
