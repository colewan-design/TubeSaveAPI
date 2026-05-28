<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    private string $ytDlpPath;
    private string $downloadDir;
    private bool $isWindows;

    public function __construct()
    {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        // On Linux shared hosting, /home is often noexec — use /tmp instead
        $binaryName      = $this->isWindows ? 'yt-dlp.exe' : 'yt-dlp_linux';
        $this->ytDlpPath = $this->isWindows
            ? storage_path('app/' . $binaryName)
            : '/tmp/' . $binaryName;

        $this->downloadDir = storage_path('app/downloads');

        if (!is_dir($this->downloadDir)) {
            mkdir($this->downloadDir, 0755, true);
        }
    }

    // POST /api/info
    public function info(Request $request): JsonResponse
    {
        $url = $request->input('url');
        if (!$url) {
            return response()->json(['error' => 'URL required'], 422);
        }

        $this->ensureYtDlp();

        $output = [];
        $code   = 0;
        exec($this->quote($this->ytDlpPath) . ' --dump-json --no-playlist ' . escapeshellarg($url) . ' 2>&1', $output, $code);

        if ($code !== 0) {
            return response()->json(['error' => implode("\n", $output)], 500);
        }

        $info = json_decode(implode('', $output), true);
        if (!$info) {
            return response()->json(['error' => 'Failed to parse video info'], 500);
        }

        return response()->json([
            'title'     => $info['title']     ?? 'Unknown',
            'thumbnail' => $info['thumbnail'] ?? null,
            'duration'  => $info['duration']  ?? null,
            'uploader'  => $info['uploader']  ?? null,
        ]);
    }

    // GET /api/progress?url=&format=
    public function progress(Request $request): StreamedResponse
    {
        $url    = $request->query('url');
        $format = $request->query('format', 'audio');

        return response()->stream(function () use ($url, $format) {
            if (!$url) {
                $this->sseEvent(['type' => 'error', 'message' => 'URL required']);
                return;
            }

            $this->ensureYtDlp();

            $output = [];
            exec($this->quote($this->ytDlpPath) . ' --dump-json --no-playlist ' . escapeshellarg($url) . ' 2>&1', $output);
            $info      = json_decode(implode('', $output), true);
            $title     = $info['title'] ?? 'download';
            $safeTitle = preg_replace('/[\\\\\\/:*?"<>|]/', '_', $title);
            $safeTitle = substr($safeTitle, 0, 80);

            $ext        = $format === 'audio' ? 'mp3' : 'mp4';
            $timestamp  = time();
            $outputFile = $this->downloadDir . DIRECTORY_SEPARATOR . $timestamp . '_' . $safeTitle . '.' . $ext;

            if ($format === 'audio') {
                $args = '-x --audio-format mp3 --audio-quality 0';
            } else {
                $args = '-f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best"';
            }

            $cmd     = $this->quote($this->ytDlpPath) . ' ' . $args . ' --no-playlist --newline -o ' . escapeshellarg($outputFile) . ' ' . escapeshellarg($url) . ' 2>&1';
            $process = popen($cmd, 'r');

            if (!$process) {
                $this->sseEvent(['type' => 'error', 'message' => 'Failed to start download']);
                return;
            }

            while (!feof($process)) {
                $line = fgets($process);
                if ($line === false) break;

                if (preg_match('/(\d+\.?\d*)%/', $line, $matches)) {
                    $this->sseEvent(['type' => 'progress', 'percent' => (float) $matches[1]]);
                }
            }

            pclose($process);

            if (file_exists($outputFile)) {
                $this->sseEvent([
                    'type'     => 'done',
                    'file'     => basename($outputFile),
                    'filename' => $safeTitle . '.' . $ext,
                ]);
            } else {
                $this->sseEvent(['type' => 'error', 'message' => 'Output file not found after download']);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // GET /api/file/{filename}
    public function file(string $filename): mixed
    {
        $path = $this->downloadDir . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        $mime = $ext === 'mp3' ? 'audio/mpeg' : 'video/mp4';

        return response()->download($path, $filename, ['Content-Type' => $mime])
            ->deleteFileAfterSend(true);
    }

    private function quote(string $path): string
    {
        return $this->isWindows ? '"' . $path . '"' : escapeshellarg($path);
    }

    private function sseEvent(array $data): void
    {
        echo 'data: ' . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }

    private function ensureYtDlp(): void
    {
        if (file_exists($this->ytDlpPath)) return;

        $releases = json_decode(file_get_contents(
            'https://api.github.com/repos/yt-dlp/yt-dlp/releases/latest',
            false,
            stream_context_create(['http' => ['header' => "User-Agent: TubeSaveApi\r\n"]])
        ), true);

        // yt-dlp_linux is the standalone binary (bundles Python 3.10+)
        // yt-dlp (no suffix) is a Python script that requires system Python 3.10+
        $assetName   = $this->isWindows ? 'yt-dlp.exe' : 'yt-dlp_linux';
        $downloadUrl = null;

        foreach ($releases['assets'] ?? [] as $asset) {
            if ($asset['name'] === $assetName) {
                $downloadUrl = $asset['browser_download_url'];
                break;
            }
        }

        if (!$downloadUrl) {
            throw new \RuntimeException('Could not find ' . $assetName . ' in latest release');
        }

        file_put_contents($this->ytDlpPath, file_get_contents($downloadUrl));

        if (!$this->isWindows) {
            chmod($this->ytDlpPath, 0755);
        }
    }
}
