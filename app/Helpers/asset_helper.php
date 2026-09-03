<?php

if (! function_exists('versioned_asset')) {
    /**
     * Return a public asset URL with a content fingerprint.
     *
     * The fingerprint prevents a browser or reverse proxy from reusing an
     * incompatible stylesheet after a deployment.
     */
    function versioned_asset(string $path): string
    {
        static $urls = [];

        $urlPath = '/' . ltrim($path, '/');

        if (isset($urls[$urlPath])) {
            return $urls[$urlPath];
        }

        $fullPath = FCPATH . ltrim($urlPath, '/');

        if (! is_file($fullPath)) {
            return $urls[$urlPath] = $urlPath;
        }

        $hash = hash_file('sha256', $fullPath);

        if (! is_string($hash) || $hash === '') {
            return $urls[$urlPath] = $urlPath;
        }

        return $urls[$urlPath] = $urlPath . '?v=' . substr($hash, 0, 12);
    }
}

if (! function_exists('inline_stylesheet')) {
    /**
     * Read a repository-owned stylesheet for critical inline delivery.
     *
     * The admin interface uses this as a deterministic fallback when an
     * intermediary serves stale or incomplete static assets.
     */
    function inline_stylesheet(string $path): string
    {
        $urlPath = '/' . ltrim($path, '/');

        if (str_contains($urlPath, '..') || ! str_ends_with($urlPath, '.css')) {
            throw new InvalidArgumentException('Invalid stylesheet path.');
        }

        $fullPath = FCPATH . ltrim($urlPath, '/');

        if (! is_file($fullPath)) {
            throw new RuntimeException('Stylesheet not found: ' . $urlPath);
        }

        $contents = file_get_contents($fullPath);

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Stylesheet could not be read: ' . $urlPath);
        }

        return $contents;
    }
}
