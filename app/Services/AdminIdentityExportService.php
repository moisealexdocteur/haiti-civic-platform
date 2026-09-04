<?php

namespace App\Services;

use InvalidArgumentException;

final class AdminIdentityExportService
{
    /**
     * Build an Excel-compatible SpreadsheetML document without sensitive data.
     */
    public function xls(array $rows, array $labels, array $departments): string
    {
        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<?mso-application progid="Excel.Sheet"?>',
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
                . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">',
            '<Worksheet ss:Name="' . $this->xml((string) $labels['sheet']) . '"><Table>',
            '<Row>' . $this->xlsCells([
                $labels['reference'],
                $labels['status'],
                $labels['department'],
                $labels['documents'],
                $labels['submitted'],
            ], 'String') . '</Row>',
        ];

        foreach ($rows as $row) {
            $xml[] = '<Row>' . $this->xlsCells([
                (string) $row['public_reference'],
                (string) ($labels['statuses'][(string) $row['verification_status']] ?? $row['verification_status']),
                (string) ($departments[(string) ($row['department_code'] ?? '')] ?? $labels['notProvided']),
                (string) $row['document_count'],
                (string) $row['created_at'] . ' UTC',
            ], 'String') . '</Row>';
        }

        $xml[] = '</Table></Worksheet></Workbook>';

        return implode("\n", $xml);
    }

    /**
     * Build a compact landscape PDF with one repeated header per page.
     */
    public function pdf(
        array $rows,
        array $labels,
        array $departments,
        string $tenantName
    ): string {
        $pageRows = array_chunk($rows, 34);

        if ($pageRows === []) {
            $pageRows = [[]];
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $kids = [];
        $nextObject = 4;

        foreach ($pageRows as $pageIndex => $page) {
            $pageObject = $nextObject++;
            $contentObject = $nextObject++;
            $kids[] = $pageObject . ' 0 R';
            $content = $this->pdfPage(
                $page,
                $labels,
                $departments,
                $tenantName,
                $pageIndex + 1,
                count($pageRows)
            );
            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595]'
                . ' /Resources << /Font << /F1 3 0 R >> >>'
                . ' /Contents ' . $contentObject . ' 0 R >>';
            $objects[$contentObject] = '<< /Length ' . strlen($content) . " >>\nstream\n"
                . $content . "\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Count ' . count($kids)
            . ' /Kids [' . implode(' ', $kids) . '] >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($number = 1; $number <= count($objects); $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }

        $pdf .= 'trailer << /Size ' . (count($objects) + 1)
            . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    private function pdfPage(
        array $rows,
        array $labels,
        array $departments,
        string $tenantName,
        int $page,
        int $pages
    ): string {
        $lines = [
            [$labels['title'], 16, 552],
            [$tenantName, 10, 534],
            [sprintf((string) $labels['page'], $page, $pages), 8, 518],
            [
                $this->fixed((string) $labels['reference'], 23)
                . $this->fixed((string) $labels['status'], 14)
                . $this->fixed((string) $labels['department'], 20)
                . $this->fixed((string) $labels['documents'], 10)
                . (string) $labels['submitted'],
                8,
                494,
            ],
        ];
        $y = 478;

        foreach ($rows as $row) {
            $lines[] = [
                $this->fixed((string) $row['public_reference'], 23)
                . $this->fixed(
                    (string) ($labels['statuses'][(string) $row['verification_status']] ?? $row['verification_status']),
                    14
                )
                . $this->fixed(
                    (string) ($departments[(string) ($row['department_code'] ?? '')] ?? $labels['notProvided']),
                    20
                )
                . $this->fixed((string) $row['document_count'], 10)
                . (string) $row['created_at'] . ' UTC',
                8,
                $y,
            ];
            $y -= 13;
        }

        $commands = ['BT'];

        foreach ($lines as [$line, $size, $lineY]) {
            $commands[] = '/F1 ' . $size . ' Tf';
            $commands[] = '1 0 0 1 40 ' . $lineY . ' Tm';
            $commands[] = '(' . $this->pdfText((string) $line) . ') Tj';
        }

        $commands[] = 'ET';

        return implode("\n", $commands);
    }

    private function xlsCells(array $values, string $type): string
    {
        $cells = [];

        foreach ($values as $value) {
            $cells[] = '<Cell><Data ss:Type="' . $type . '">'
                . $this->xml((string) $value) . '</Data></Cell>';
        }

        return implode('', $cells);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function fixed(string $value, int $length): string
    {
        if ($length < 2) {
            throw new InvalidArgumentException('Column length is too small.');
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        if (mb_strlen($value) >= $length) {
            return mb_substr($value, 0, $length - 1) . ' ';
        }

        return $value . str_repeat(' ', $length - mb_strlen($value));
    }

    private function pdfText(string $value): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        $encoded = is_string($encoded) ? $encoded : $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }
}
