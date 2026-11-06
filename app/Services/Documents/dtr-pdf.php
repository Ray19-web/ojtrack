<?php
function coordinator_dtr_pdf(array $student, array $attendance, bool $pagesOnly = false): string|array
{
    $pdfText = static function ($value): string {
        $text = (string)$value;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) $text = $converted;
        }
        $text = preg_replace('/[^\\x20-\\x7E\\x80-\\xFF]/', '', $text) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    };
    $pages = [];
    $useTwoColumns = count($attendance) > 24;
    $rowsPerPage = $useTwoColumns ? 48 : 24;
    $chunks = array_chunk($attendance, $rowsPerPage);
    if (!$chunks) $chunks = [[]];
    $totalHours = array_sum(array_map(static fn(array $row): float => (float)($row['hours_rendered'] ?? 0), $attendance));
    $studentName = $pdfText($student['name'] ?? 'Student');

    foreach ($chunks as $pageIndex => $rows) {
        $content = "0.12 0.25 0.43 rg\n";
        $drawText = static function (string &$stream, float $x, float $y, string $text, int $size = 10, bool $bold = false): void {
            $font = $bold ? 'F2' : 'F1';
            $stream .= "BT /$font $size Tf $x $y Td ($text) Tj ET\n";
        };
        $drawCenteredText = static function (string &$stream, float $cellX, float $cellWidth, float $y, string $text, int $size = 8, bool $bold = false) use ($drawText): void {
            $estimatedTextWidth = strlen($text) * $size * 0.52;
            $drawText($stream, $cellX + max(0, ($cellWidth - $estimatedTextWidth) / 2), $y, $text, $size, $bold);
        };
        $line = static function (string &$stream, float $x1, float $y1, float $x2, float $y2, float $width = 0.6): void {
            $stream .= "0.65 G $width w $x1 $y1 m $x2 $y2 l S 0 G\n";
        };
        $fill = static function (string &$stream, float $x, float $y, float $w, float $h): void {
            $stream .= "0.92 0.95 0.98 rg $x $y $w $h re f 0 0 0 rg\n";
        };

        $drawText($content, 42, 798, 'DAILY TIME RECORD', 9, true);
        $drawText($content, 42, 778, 'ON-THE-JOB TRAINING ATTENDANCE', 16, true);
        $drawText($content, 42, 761, 'Student time-in and time-out record', 9);
        $line($content, 42, 750, 553, 750, 0.8);
        $drawText($content, 42, 731, 'STUDENT', 8, true);
        $drawText($content, 42, 716, $studentName, 11, true);
        $drawText($content, 320, 731, 'STUDENT ID', 8, true);
        $drawText($content, 320, 716, $pdfText($student['student_id_no'] ?? '—'), 10, true);
        $drawText($content, 42, 695, 'PROGRAM', 8, true);
        $drawText($content, 42, 680, $pdfText($student['program'] ?? '—'), 10);
        $drawText($content, 320, 695, 'COMPANY', 8, true);
        $drawText($content, 320, 680, $pdfText($student['company_name'] ?? '—'), 10);
        if ($pageIndex === 0) {
            $drawText($content, 42, 657, count($attendance) . ' DTR day(s)', 9);
            $drawText($content, 430, 657, number_format($totalHours, 2) . ' total hours', 9, true);
        } else {
            $drawText($content, 42, 657, 'Continued - ' . $studentName, 9);
        }

        $top = 638;
        $tableWidth = 242;
        $rowHeight = 20;
        $tableStarts = $useTwoColumns ? [48.5, 304.5] : [(595 - $tableWidth) / 2];
        if ($useTwoColumns) {
            $splitAt = (int)ceil(count($rows) / 2);
            $columnRows = [array_slice($rows, 0, $splitAt), array_slice($rows, $splitAt)];
        } else {
            $columnRows = [$rows];
        }
        if (!$attendance && $pageIndex === 0) $columnRows[0] = [[]];

        foreach ($columnRows as $columnIndex => $column) {
            $startX = $tableStarts[$columnIndex];
            $edge = array_map(static fn(float $offset): float => $startX + $offset, [0, 54, 90, 126, 162, 198, 242]);
            $fill($content, $startX, $top - 40, $tableWidth, 40);
            $line($content, $startX, $top, $startX + $tableWidth, $top);
            $line($content, $startX + 54, $top - 20, $startX + 198, $top - 20);
            $line($content, $startX, $top - 40, $startX + $tableWidth, $top - 40);
            foreach ([$edge[0], $edge[1], $edge[3], $edge[5], $edge[6]] as $xLine) $line($content, $xLine, $top, $xLine, $top - 40);
            foreach ([$edge[2], $edge[4]] as $xLine) $line($content, $xLine, $top - 20, $xLine, $top - 40);
            $drawCenteredText($content, $startX, 54, $top - 24, 'Day', 7, true);
            $drawCenteredText($content, $startX + 54, 72, $top - 12, 'A.M.', 7, true);
            $drawCenteredText($content, $startX + 126, 72, $top - 12, 'P.M.', 7, true);
            $drawCenteredText($content, $startX + 198, 44, $top - 24, 'Hours', 7, true);
            $drawCenteredText($content, $startX + 54, 36, $top - 31, 'IN', 6, true);
            $drawCenteredText($content, $startX + 90, 36, $top - 31, 'OUT', 6, true);
            $drawCenteredText($content, $startX + 126, 36, $top - 31, 'IN', 6, true);
            $drawCenteredText($content, $startX + 162, 36, $top - 31, 'OUT', 6, true);

            $rowY = $top - 40;
            $visibleRows = $useTwoColumns ? max(count($column), count($columnRows[0]), count($columnRows[1])) : count($column);
            foreach ($column as $rowIndex => $row) {
                $rowBottom = $rowY - $rowHeight;
                $line($content, $startX, $rowBottom, $startX + $tableWidth, $rowBottom);
                foreach ($edge as $xLine) $line($content, $xLine, $rowY, $xLine, $rowBottom);
                if (isset($row['date'])) {
                    $date = date('m-d-y', strtotime((string)$row['date']));
                    $drawCenteredText($content, $startX, 54, $rowY - 13, $pdfText($date), 6);
                    $drawCenteredText($content, $startX + 54, 36, $rowY - 13, $pdfText($row['morning_in'] ?? '—'), 5);
                    $drawCenteredText($content, $startX + 90, 36, $rowY - 13, $pdfText($row['morning_out'] ?? '—'), 5);
                    $drawCenteredText($content, $startX + 126, 36, $rowY - 13, $pdfText($row['afternoon_in'] ?? '—'), 5);
                    $drawCenteredText($content, $startX + 162, 36, $rowY - 13, $pdfText($row['afternoon_out'] ?? '—'), 5);
                    $hours = (float)($row['hours_rendered'] ?? 0);
                    $drawCenteredText($content, $startX + 198, 44, $rowY - 13, $hours > 0 ? number_format($hours, 2) : '—', 6);
                } elseif (!$attendance) {
                    $drawCenteredText($content, $startX, $tableWidth, $rowY - 13, 'No attendance records', 6);
                }
                $rowY = $rowBottom;
            }
            for ($blankIndex = count($column); $blankIndex < $visibleRows; $blankIndex++) {
                $rowBottom = $rowY - $rowHeight;
                $line($content, $startX, $rowBottom, $startX + $tableWidth, $rowBottom);
                foreach ($edge as $xLine) $line($content, $xLine, $rowY, $xLine, $rowBottom);
                $rowY = $rowBottom;
            }
        }
        if ($pageIndex === count($chunks) - 1) {
            $summaryStart = $tableStarts[0];
            $summaryEnd = $useTwoColumns ? $tableStarts[1] + $tableWidth : $tableStarts[0] + $tableWidth;
            $summaryY = $top - 40 - (max(array_map('count', $columnRows)) * $rowHeight);
            $rowBottom = $summaryY - 24;
            $fill($content, $summaryStart, $rowBottom, $summaryEnd - $summaryStart, 24);
            $line($content, $summaryStart, $summaryY, $summaryEnd, $summaryY);
            $line($content, $summaryStart, $rowBottom, $summaryEnd, $rowBottom);
            $line($content, $summaryStart, $summaryY, $summaryStart, $rowBottom);
            $line($content, $summaryEnd, $summaryY, $summaryEnd, $rowBottom);
            $line($content, $summaryEnd - 108, $summaryY, $summaryEnd - 108, $rowBottom);
            $drawCenteredText($content, $summaryStart, $summaryEnd - $summaryStart - 108, $rowBottom + 8, 'Total Recorded Hours', 8, true);
            $drawCenteredText($content, $summaryEnd - 108, 108, $rowBottom + 8, number_format($totalHours, 2) . ' hrs', 8, true);
        }
        if (!$pagesOnly) $drawText($content, 42, 28, 'Generated from OJTRACK attendance records - Page ' . ($pageIndex + 1) . ' of ' . count($chunks), 7);
        $pages[] = $content;
    }

    if ($pagesOnly) return $pages;
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $pageRefs = [];
    $nextObjectId = 5;
    foreach ($pages as $content) {
        $contentId = $nextObjectId;
        $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $pageId = $contentId + 1;
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $pageRefs[] = $pageId . ' 0 R';
        $nextObjectId = $pageId + 1;
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n%OJTRACK\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= count($objects); $id++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
    return $pdf;
}
