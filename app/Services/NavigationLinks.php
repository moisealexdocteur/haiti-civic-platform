<?php

namespace App\Services;

/** Internal destinations only; never trust Referer, return URLs or browser history. */
final class NavigationLinks
{
    public static function query(array $input): string
    {
        $safe = [];
        foreach (['status', 'department', 'sort', 'direction', 'page', 'per_page', 'audience', 'channel', 'lang'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key]) && strlen((string) $input[$key]) <= 80) {
                $safe[$key] = (string) $input[$key];
            }
        }
        return http_build_query($safe, '', '&', PHP_QUERY_RFC3986);
    }

    public static function listPath(string $path): ?string
    {
        foreach (['/admin/identites', '/admin/notifications'] as $list) {
            if ($path === $list || str_starts_with($path, $list . '/')) {
                return $list;
            }
        }
        return null;
    }

    public static function listUrl(string $path, array $saved, string $locale): string
    {
        $list = self::listPath($path);
        if ($list === null) {
            return '/admin?lang=' . ($locale === 'fr' ? 'fr' : 'ht');
        }
        $query = self::query(array_merge($saved, ['lang' => $locale === 'fr' ? 'fr' : 'ht']));
        return $list . '?' . $query;
    }
}
