<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class PublicDocumentStorageService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const ALLOWED_MIME = [
        VerificationDocumentWriteService::CIN_FRONT => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        ],
        VerificationDocumentWriteService::CIN_BACK => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        ],
        VerificationDocumentWriteService::PORTRAIT => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ],
    ];

    public function store(
        string $temporaryPath,
        string $tenantUuid,
        string $citizenUuid,
        string $documentType
    ): array {
        if (! isset(self::ALLOWED_MIME[$documentType])) {
            throw new InvalidArgumentException(
                'Unsupported verification document type.'
            );
        }

        $this->assertUuid($tenantUuid, 'Tenant UUID');
        $this->assertUuid($citizenUuid, 'Citizen UUID');

        if ($temporaryPath === '' || ! is_file($temporaryPath)) {
            throw new InvalidArgumentException(
                'Uploaded verification document is missing.'
            );
        }

        $size = filesize($temporaryPath);

        if (
            $size === false
            || $size <= 0
            || $size > self::MAX_BYTES
        ) {
            throw new InvalidArgumentException(
                'Verification document size is invalid.'
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporaryPath);

        if (! is_string($mime)) {
            throw new InvalidArgumentException(
                'Verification document MIME type cannot be determined.'
            );
        }

        $extension = self::ALLOWED_MIME[$documentType][$mime] ?? null;

        if ($extension === null) {
            throw new InvalidArgumentException(
                'Verification document MIME type is not allowed.'
            );
        }

        $directory = WRITEPATH
            . 'uploads/identity/'
            . strtolower($tenantUuid)
            . '/'
            . strtolower($citizenUuid);

        if (
            ! is_dir($directory)
            && ! mkdir($directory, 0700, true)
            && ! is_dir($directory)
        ) {
            throw new RuntimeException(
                'Verification document directory creation failed.'
            );
        }

        @chmod($directory, 0700);

        $objectId = bin2hex(random_bytes(32));
        $destination = $directory
            . '/'
            . $objectId
            . '.'
            . $extension;

        if (! copy($temporaryPath, $destination)) {
            throw new RuntimeException(
                'Verification document storage failed.'
            );
        }

        @chmod($destination, 0600);

        $sha256 = hash_file('sha256', $destination);

        if (! is_string($sha256)) {
            @unlink($destination);

            throw new RuntimeException(
                'Verification document hash failed.'
            );
        }

        return [
            'storage_ref' => 'local://' . $objectId,
            'absolute_path' => $destination,
            'content_type' => $mime,
            'size_bytes' => (int) $size,
            'sha256' => $sha256,
            'captured_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function deleteStoredPath(string $absolutePath): void
    {
        if (
            $absolutePath !== ''
            && is_file($absolutePath)
        ) {
            @unlink($absolutePath);
        }
    }

    private function assertUuid(string $value, string $field): void
    {
        if (! preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-'
            . '[0-9a-f]{4}-[0-9a-f]{4}-'
            . '[0-9a-f]{12}$/i',
            $value
        )) {
            throw new InvalidArgumentException(
                $field . ' is invalid.'
            );
        }
    }
}
