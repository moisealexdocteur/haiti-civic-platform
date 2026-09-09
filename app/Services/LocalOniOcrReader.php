<?php

namespace App\Services;

use RuntimeException;

final class LocalOniOcrReader implements OniOcrReader
{
    public function read(string $path, ?string $portraitPath = null): string
    {
        return $this->run($path, $portraitPath, false);
    }

    public function scan(string $path): string
    {
        return $this->run($path, null, true);
    }

    private function run(string $path, ?string $portraitPath, bool $scan): string
    {
        $path = realpath($path) ?: '';
        if ($path === '' || ! is_file($path) || ! is_readable($path)
            || filesize($path) > PublicDocumentStorageService::MAX_BYTES) {
            throw new RuntimeException('OCR input unavailable.');
        }
        if (! $scan && ($portraitPath === null || ! is_file($portraitPath)
            || filesize($portraitPath) > PublicDocumentStorageService::MAX_BYTES)) {
            throw new RuntimeException('Portrait unavailable.');
        }
        $portraitPath = $scan ? null : realpath($portraitPath);
        $size = @getimagesize($path);
        if ($size === false || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || $size[0] * $size[1] > 16000000) {
            throw new RuntimeException('OCR input requires manual review.');
        }
        if (! function_exists('proc_open') || ! is_executable('/usr/bin/tesseract')
            || ! is_executable('/usr/bin/python3')) {
            throw new RuntimeException('OCR engine unavailable.');
        }

        // One OCR process per application container, without waiting in the request queue.
        $lock = @fopen(WRITEPATH . 'cache/oni-ocr.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('OCR lock unavailable.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('OCR busy.');
        }

        $process = null;
        $pipes = [];
        try {
            // Array command bypasses the shell. No user text is used as an option.
            // Only PATH and thread limit are inherited; no application secrets.
            $process = @proc_open(
                $scan
                    ? ['/usr/bin/python3', ROOTPATH . 'scripts/identity/oni_scan.py', $path]
                    : ['/usr/bin/python3', ROOTPATH . 'scripts/identity/oni_ocr.py', $path, $portraitPath],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                ['PATH' => '/usr/bin:/bin', 'OMP_THREAD_LIMIT' => '1']
            );
            if (! is_resource($process)) {
                throw new RuntimeException('OCR could not start.');
            }
            stream_set_blocking($pipes[1], false);
            $deadline = microtime(true) + 18;
            $output = '';
            do {
                $output .= stream_get_contents($pipes[1]);
                if (strlen($output) > 262144 || microtime(true) > $deadline) {
                    throw new RuntimeException('OCR resource limit reached.');
                }
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $output .= stream_get_contents($pipes[1]);
                    if ($status['exitcode'] !== 0 || strlen($output) > 262144) {
                        throw new RuntimeException('OCR failed.');
                    }
                    return $output;
                }
                usleep(20000);
            } while (true);
        } finally {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
