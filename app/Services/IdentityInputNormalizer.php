<?php

namespace App\Services;

use InvalidArgumentException;

final class IdentityInputNormalizer
{
    public const NINU_DIGITS = 10;

    public function normalizeNinu(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                'NINU cannot be empty.'
            );
        }

        /*
         * Normalisation volontairement conservative.
         *
         * Nous retirons uniquement les séparateurs de
         * présentation usuels.
         *
         * La carte CINU et le vérificateur DELIDOC présentent
         * le NINU sous la forme de dix chiffres.
         */
        $normalized = preg_replace(
            '/[\s\-\x{2010}\x{2011}\x{2012}'
            . '\x{2013}\x{2014}\x{2212}]+/u',
            '',
            $value
        );

        if ($normalized === null) {
            throw new InvalidArgumentException(
                'NINU normalization failed.'
            );
        }

        if (
            $normalized === ''
            || preg_match(
                '/^[0-9]+$/D',
                $normalized
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'NINU must contain digits only '
                . 'after removing presentation separators.'
            );
        }

        if (strlen($normalized) !== self::NINU_DIGITS) {
            throw new InvalidArgumentException(
                'NINU must contain exactly 10 digits.'
            );
        }

        return $normalized;
    }

    public function normalizeHaitiPhone(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        /*
         * Seuls les caractères de présentation sont retirés.
         * Les lettres et autres caractères sont refusés.
         */
        $normalized = preg_replace(
            '/[\s().\-]+/u',
            '',
            $value
        );

        if ($normalized === null) {
            throw new InvalidArgumentException(
                'Phone normalization failed.'
            );
        }

        if (
            str_starts_with(
                $normalized,
                '00509'
            )
        ) {
            $normalized =
                '+509'
                . substr(
                    $normalized,
                    5
                );
        } elseif (
            str_starts_with(
                $normalized,
                '509'
            )
            && strlen($normalized) === 11
        ) {
            $normalized =
                '+'
                . $normalized;
        } elseif (
            preg_match(
                '/^[0-9]{8}$/D',
                $normalized
            ) === 1
        ) {
            $normalized =
                '+509'
                . $normalized;
        }

        if (
            preg_match(
                '/^\+509[0-9]{8}$/D',
                $normalized
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Haiti phone number must resolve '
                . 'to +509 followed by 8 digits.'
            );
        }

        return $normalized;
    }

    public function normalizePersonName(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(
                'Person name cannot be empty.'
            );
        }

        if (
            mb_strlen($value) > 100
            || preg_match(
                "/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'’\\-]*$/uD",
                $value
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Person name contains unsupported characters.'
            );
        }

        return $value;
    }

    public function normalizeEmail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if (
            strlen($value) > 254
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                'Email address is invalid.'
            );
        }

        return $value;
    }
}
