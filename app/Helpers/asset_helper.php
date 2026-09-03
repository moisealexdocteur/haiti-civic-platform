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
