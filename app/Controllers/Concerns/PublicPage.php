<?php

namespace App\Controllers\Concerns;

/**
 * Réglages communs aux pages publiques : langue, thème, données de gabarit.
 *
 * Le kreyòl est la langue par défaut. Le français reste à un bouton.
 */
trait PublicPage
{
    private const LOCALES = ['ht', 'fr'];
    private const LOCALE_COOKIE = 'lang';
    private const THEME_COOKIE = 'theme';
    private const COOKIE_LIFETIME = 31536000;

    /**
     * Ordre : paramètre d'URL, cookie, défaut du tenant, langue du
     * téléphone, puis kreyòl.
     */
    protected function resolveLocale(?string $tenantDefault = null): string
    {
        $requested = $this->normalizeLocale(
            (string) $this->request->getGet('lang')
        );

        if ($requested !== null) {
            return $requested;
        }

        $stored = $this->normalizeLocale(
            (string) $this->request->getCookie(self::LOCALE_COOKIE)
        );

        if ($stored !== null) {
            return $stored;
        }

        $fromTenant = $this->normalizeLocale((string) $tenantDefault);

        if ($fromTenant !== null) {
            return $fromTenant;
        }

        $header = strtolower(
            (string) $this->request->getHeaderLine('Accept-Language')
        );

        if ($header !== '' && ! str_contains($header, 'ht')) {
            if (str_contains($header, 'fr')) {
                return 'fr';
            }
        }

        return 'ht';
    }

    private function normalizeLocale(string $value): ?string
    {
        $value = strtolower(trim($value));

        return in_array($value, self::LOCALES, true) ? $value : null;
    }

    /**
     * Mémorise la langue : un lien qui oublie ?lang= ne doit plus
     * renvoyer le citoyen dans l'autre langue.
     */
    protected function rememberLocale(string $locale): void
    {
        if ($this->request->getCookie(self::LOCALE_COOKIE) === $locale) {
            return;
        }

        $this->response->setCookie([
            'name' => self::LOCALE_COOKIE,
            'value' => $locale,
            'expire' => self::COOKIE_LIFETIME,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->request->isSecure(),
        ]);
    }

    /**
     * Thème explicite choisi par le visiteur ; sinon celui du téléphone.
     */
    protected function resolveTheme(): ?string
    {
        $requested = strtolower(
            trim((string) $this->request->getGet('theme'))
        );

        if (in_array($requested, ['light', 'dark', 'auto'], true)) {
            $this->response->setCookie([
                'name' => self::THEME_COOKIE,
                'value' => $requested === 'auto' ? '' : $requested,
                'expire' => $requested === 'auto' ? -1 : self::COOKIE_LIFETIME,
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $this->request->isSecure(),
            ]);

            return $requested === 'auto' ? null : $requested;
        }

        $theme = strtolower(
            trim((string) $this->request->getCookie(self::THEME_COOKIE))
        );

        return in_array($theme, ['light', 'dark'], true) ? $theme : null;
    }

    /**
     * Liens du sélecteur de thème, en gardant la page et la langue.
     *
     * @return array<string, string>
     */
    protected function themeUrls(string $locale): array
    {
        $path = '/' . ltrim($this->request->getUri()->getPath(), '/');
        $query = 'lang=' . rawurlencode($locale) . '&theme=';

        return [
            'auto' => $path . '?' . $query . 'auto',
            'light' => $path . '?' . $query . 'light',
            'dark' => $path . '?' . $query . 'dark',
        ];
    }

    /**
     * @param array<string, string> $langUrls
     *
     * @return array<string, mixed>
     */
    protected function pageData(
        string $locale,
        string $pageTitle,
        array $langUrls,
        array $extra = []
    ): array {
        return array_merge(
            [
                'locale' => $locale,
                'pageTitle' => $pageTitle,
                'theme' => $this->resolveTheme(),
                'themeUrls' => $this->themeUrls($locale),
                'langUrls' => $langUrls,
                'brandName' => null,
                'brandInitials' => null,
                'wide' => false,
            ],
            $extra
        );
    }

    /**
     * Initiales affichées dans le sceau de l'organisation.
     */
    protected function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $letters .= mb_strtoupper(mb_substr($word, 0, 1));

            if (mb_strlen($letters) === 2) {
                break;
            }
        }

        return $letters === '' ? 'PS' : $letters;
    }
}
