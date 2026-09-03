<?php

namespace App\Services;

use InvalidArgumentException;

final class PublicReferenceGenerator
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const GROUP_LENGTH = 4;
    private const GROUP_COUNT = 3;
    private const PREFIX = 'DOS';

    public function generate(): string
    {
        $characters = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::GROUP_LENGTH * self::GROUP_COUNT; $i++) {
            $characters .= self::ALPHABET[random_int(0, $last)];
        }

        return self::PREFIX . '-'
            . implode('-', str_split($characters, self::GROUP_LENGTH));
    }

    public function normalize(string $reference): string
    {
        $reference = strtoupper(trim($reference));
        $reference = preg_replace('/[^A-Z0-9]/', '', $reference) ?? '';

        if (str_starts_with($reference, self::PREFIX)) {
            $reference = substr($reference, strlen(self::PREFIX));
        }

        if (strlen($reference) !== self::GROUP_LENGTH * self::GROUP_COUNT) {
            throw new InvalidArgumentException('Public reference is invalid.');
        }

        $normalized = self::PREFIX . '-'
            . implode('-', str_split($reference, self::GROUP_LENGTH));

        if (! $this->isValid($normalized)) {
            throw new InvalidArgumentException('Public reference is invalid.');
        }

        return $normalized;
    }

    public function isValid(string $reference): bool
    {
        return preg_match(
            '/^DOS-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}'
            . '-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}'
            . '-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/D',
            $reference
        ) === 1;
    }
}
