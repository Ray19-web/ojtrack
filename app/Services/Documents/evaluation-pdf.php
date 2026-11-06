<?php
/** Dependency-free, paginated A4 evaluation export. Call with authorized records only. */
function student_evaluations_pdf(array $student, array $evaluations): string
{
    $pages = []; $stream = ''; $y = 0;
    $activeEvaluation = null; $pageOwners = []; $owner = -1;
    $paperDefinition = json_decode(file_get_contents(__DIR__.'/../../../database/bsit-ojt-evaluation.json'), true, 512, JSON_THROW_ON_ERROR);
    $encode = static function ($text): string {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$text) ?? '';
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return $encoded !== false ? $encoded : '';
    };
    // Maximum of Helvetica regular/bold advances, in thousandths of an em.
    $advances = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,1015,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
    $measure = static function (string $value, float $size) use ($advances): float {
        $units = 0;
        foreach (str_split($value) as $char) $units += $advances[ord($char) - 32] ?? 1000;
        return $units * $size / 1000;
    };
    $wrap = static function ($text, float $width, float $size = 9) use ($encode, $measure): array {
        $encoded = $encode(str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], (string)$text));
        $lines = [];
        foreach (explode("\n", $encoded) as $paragraph) {
            $line = '';
            foreach (explode(' ', $paragraph) as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($measure($candidate, $size) <= $width) { $line = $candidate; continue; }
                if ($line !== '') { $lines[] = $line; $line = ''; }
                foreach (str_split($word) as $char) {
                    if ($line !== '' && $measure($line.$char, $size) > $width) { $lines[] = $line; $line = ''; }
                    $line .= $char;
                }
            }
            $lines[] = rtrim($line);
        }
        return array_map(static fn($line) => iconv('Windows-1252', 'UTF-8', $line), $lines);
    };
    $text = static function (float $x, float $atY, $value, float $size = 9, bool $bold = false, string $color = '0 0 0') use (&$stream, $encode): void {
        $value = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encode($value));
        $font = $bold ? 'F2' : 'F1';
        $stream .= "$color rg BT /$font $size Tf 1 0 0 1 $x $atY Tm ($value) Tj ET\n";
    };
    $line = static function ($x1, $y1, $x2, $y2) use (&$stream): void { $stream .= "0 G 0.5 w $x1 $y1 m $x2 $y2 l S\n"; };
    $box = static function ($x, $top, $width, $height) use (&$stream): void { $bottom = $top - $height; $stream .= "0 G 0.5 w $x $bottom $width $height re S\n"; };
    $newPage = static function () use (&$stream, &$pages, &$y, $text, $box, $line, &$activeEvaluation, &$pageOwners, &$owner, $paperDefinition): void {
        if ($stream !== '') $pages[] = $stream;
        $stream = ''; $pageOwners[] = $owner;
        // A typographic university mark; do not substitute the OJTRACK app logo for its seal.
        $text(36, 789, 'USTP', 21, true, '0.67 0.49 0.08');
        $text(37, 778, 'JASAAN', 7);
        $text(119, 803, 'UNIVERSITY OF SCIENCE AND TECHNOLOGY', 9, true);
        $text(177, 790, 'OF SOUTHERN PHILIPPINES', 9, true);
        $text(122, 776, 'Alubijid | Balubal | Cagayan de Oro | Claveria | Jasaan', 6.5);
        $text(185, 766, 'Oroquieta | Panaon | Villanueva', 6.5);
        $official = $activeEvaluation && (strcasecmp($activeEvaluation['form_title'], $paperDefinition['title']) === 0 || str_contains($activeEvaluation['description'] ?? '', $paperDefinition['document_code']));
        $box(402, 808, 157, 42); $line(402, 796, 559, 796); $line(402, 782, 559, 782);
        $text(445, 800, $official ? 'Document Code No.' : 'Evaluation Record', 6.5);
        $text(424, 786, $official ? $paperDefinition['document_code'] : 'Student copy', 8, true);
        $line(443, 782, 443, 766); $line(506, 782, 506, 766);
        $text(408, 776, 'Rev. No.', 5.5); $text(450, 776, 'Effective Date', 5.5); $text(516, 776, 'Page No.', 5.5);
        $text(417, 769, $official ? '00' : '-', 6); $text(455, 769, $official ? '10.01.21' : '-', 6); $text(515, 769, '__FORMPAGE__', 6);
        $text(204, 742, 'OJT EVALUATION SHEET', 12, true);
        $y = 716;
    };
    $flow = static function ($value, float $size = 10, bool $bold = false) use (&$y, $wrap, $newPage, $text): void {
        foreach ($wrap($value, 505, $size) as $line) {
            if ($y < 50) $newPage();
            $text(40, $y, $line, $size, $bold); $y -= $size + 6;
        }
    };
    $section = static function ($title, int $reserve = 64) use (&$stream, &$y, $wrap, $newPage, $text, $box): void {
        $lines = $wrap($title, 495, 10);
        $height = count($lines) * 15 + 14;
        if ($y - $height < $reserve) $newPage();
        // Extremely long titles flow normally across pages rather than clipping.
        foreach ($lines as $line) {
            if ($y < 70) $newPage();
            $box(36, $y + 12, 523, 24);
            $text(46, $y, $line, 10, true); $y -= 24;
        }
        $y -= 6;
    };
    $tableHeader = static function (array $labels, array $widths) use (&$y, $text, $box, $line): void {
        $binary = count($labels) === 4 && $labels[2] === 'Passed';
        $height = $binary ? 32 : 24;
        $top = $y + 12; $box(36, $top, 523, $height);
        $x = 36;
        foreach ($labels as $index => $label) {
            if ($index) $line($x, $binary && $index === 3 ? $top - 14 : $top, $x, $top - $height);
            if ($binary && $index === 1) {
                $text($x + 7, $top - 12, 'Perfect Score', 7.5, true);
                $text($x + 7, $top - 24, 'Earned / Max', 7);
            } elseif (!$binary || $index < 2) $text($x + 7, $y - 3, $label, 8, true);
            $x += $widths[$index];
        }
        if ($binary) {
            $ratingX = 36 + $widths[0] + $widths[1];
            $text($ratingX + 25, $top - 10, 'His/her training', 7.5, true);
            $line($ratingX, $top - 14, 559, $top - 14);
            $text($ratingX + 12, $top - 25, 'Passed', 7, true);
            $text($ratingX + $widths[2] + 8, $top - 22, 'Needs', 6.5, true);
            $text($ratingX + $widths[2] + 3, $top - 29, 'Improvement', 6.5, true);
        }
        $y -= $height;
    };
    $rowNumber = 0;
    $tableRow = static function (array $cells, array $widths, array $labels, bool $bold = false) use (&$stream, &$y, &$rowNumber, $wrap, $newPage, $tableHeader, $text, $box, $line): void {
        $columns = []; $lineCount = 1;
        foreach ($cells as $index => $cell) {
            $columns[] = $wrap($cell, $widths[$index] - 14, 8.5);
            $lineCount = max($lineCount, count($columns[$index]));
        }
        if ($y - ($lineCount * 9.5 + 5.5) < 42 && $lineCount * 9.5 + 5.5 < 570) { $newPage(); $tableHeader($labels, $widths); }
        $offset = 0;
        while ($offset < $lineCount) {
            $available = (int)floor(($y - 48) / 9.5);
            if ($available < 1) { $newPage(); $tableHeader($labels, $widths); $available = (int)floor(($y - 48) / 9.5); }
            $count = min($available, $lineCount - $offset); $height = $count * 9.5 + 5.5;
            $box(36, $y + 12, 523, $height);
            $x = 36;
            foreach ($columns as $index => $lines) {
                if ($index) $line($x, $y + 12, $x, $y + 12 - $height);
                foreach (array_slice($lines, $offset, $count) as $lineIndex => $rowLine) $text($x + 7, $y - $lineIndex * 9.5, $rowLine, 8.5, $bold);
                $x += $widths[$index];
            }
            $y -= $height;
            $offset += $count;
        }
        $rowNumber++;
    };
    $identity = static function () use ($student, $flow, &$y): void {
        $flow($student['name'] ?? 'Student', 14, true);
        $flow('Student ID: '.($student['student_id_no'] ?? '-').' | Program: '.($student['program'] ?? '-'), 9);
        $y -= 10;
    };
    $scoreLabel = static function (array $evaluation): string {
        if (($evaluation['status'] ?? '') !== 'completed') return '-';
        if ($evaluation['overall_score'] === null) return 'Not recorded';
        return number_format((float)$evaluation['overall_score'], 2).(($evaluation['score_mode'] ?? '') === 'points' ? ' / 100' : '%');
    };
    $number = static fn($value) => rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    if (count($evaluations) !== 1 || ($evaluations[0]['status'] ?? '') !== 'completed') {
        $newPage(); $identity(); $section('Evaluation overview');
        $widths = [235, 90, 88, 110]; $labels = ['EVALUATION', 'STATUS', 'SCORE', 'COMPLETED'];
        $tableHeader($labels, $widths);
        foreach ($evaluations as $evaluation) {
            $tableRow([$evaluation['form_title'], ucfirst($evaluation['status']), $scoreLabel($evaluation), $evaluation['status'] === 'completed' && !empty($evaluation['evaluated_at']) ? date('M d, Y', strtotime($evaluation['evaluated_at'])) : '-'], $widths, $labels);
        }
        if (!$evaluations) $flow('No evaluations assigned.');
    }
    foreach ($evaluations as $evaluationIndex => $evaluation) {
        if ($evaluation['status'] !== 'completed') continue;
        $activeEvaluation = $evaluation; $owner = $evaluationIndex;
        $newPage();
        $leftLines = array_merge($wrap('Name of Trainee: '.($student['name'] ?? '-'), 310, 9), $wrap('Training Agency: '.($evaluation['company_name'] ?? '-'), 310, 9));
        $rightLines = array_merge($wrap('Course: '.($student['program'] ?? '-'), 180, 9), $wrap('Training Period: '.(!empty($student['required_hours']) ? $student['required_hours'].' hours' : 'Not recorded'), 180, 9));
        $identityHeight = max(count($leftLines), count($rightLines)) * 13;
        // Ordinary identities use the original two-column paper layout.
        if ($identityHeight < 180) {
            foreach ($leftLines as $index=>$identityLine) $text(36, $y - $index*13, $identityLine);
            foreach ($rightLines as $index=>$identityLine) $text(375, $y - $index*13, $identityLine);
            $y -= $identityHeight + 12;
        } else { $identity(); $flow('Training Agency: '.($evaluation['company_name'] ?? '-'), 9); }
        $flow('Form: '.$evaluation['form_title'].' | Completed: '.(!empty($evaluation['evaluated_at']) ? date('M d, Y', strtotime($evaluation['evaluated_at'])) : 'Not recorded'), 8);
        $y -= 5;
        $groups = [];
        foreach ($evaluation['answers'] ?? [] as $answer) $groups[trim((string)($answer['section_title'] ?? '')) ?: 'Evaluation Questions'][] = $answer;
        $ruleDescriptions = array_map(static fn($rule) => strtoupper(trim((string)($rule['description'] ?? ''))), $evaluation['rules'] ?? []);
        $binary = in_array('PASSED', $ruleDescriptions, true) && in_array('NEEDS IMPROVEMENT', $ruleDescriptions, true);
        $widths = $binary ? [318, 75, 65, 65] : [318, 75, 130];
        $labels = $binary ? ['Criteria', 'Earned / Max', 'Passed', 'Needs Improvement'] : ['Criteria', 'Earned / Max', 'Rating'];
        $tableHeader($labels, $widths);
        foreach ($groups as $title => $answers) {
            $tableRow($binary ? [strtoupper($title), '', '', ''] : [strtoupper($title), '', ''], $widths, $labels, true);
            foreach ($answers as $answer) {
                $hasScore = $answer['score'] !== null;
                $maximum = $evaluation['score_mode'] === 'points' ? max(1, (float)$answer['maximum_points']) : 100;
                $percent = $hasScore ? (float)$answer['score'] / $maximum * 100 : null;
                $rating = '';
                foreach ($evaluation['rules'] ?? [] as $rule) {
                    if ($percent !== null && $percent >= (float)$rule['score_min'] && $percent <= (float)$rule['score_max']) {
                        $rating = trim((string)($rule['description'] ?? ''));
                        if ($rating === '' && $rule['equivalent'] !== null) $rating = (string)$rule['equivalent'];
                        break;
                    }
                }
                if ($rating === '' && $hasScore && ($answer['equivalent'] ?? null) !== null) $rating = (string)$answer['equivalent'];
                $cells = [$answer['criterion_label'], $hasScore ? $number($answer['score']).' / '.$number($maximum) : '-'];
                if ($binary) { $cells[] = strtoupper($rating) === 'PASSED' ? 'X' : ''; $cells[] = strtoupper($rating) === 'NEEDS IMPROVEMENT' ? 'X' : ''; }
                else $cells[] = $rating !== '' ? $rating : '-';
                $tableRow($cells, $widths, $labels);
            }
        }
        if (!$groups) $flow('No saved question scores are available for this evaluation.');
        $y -= 6;
        $rule = $evaluation['overall_score'] !== null ? normalized_eval_score_rule((float)$evaluation['overall_score'], $evaluation['rules'] ?? [], $evaluation['score_mode']) : null;
        if ($binary) {
            if ($y < 110) $newPage();
            $box(36, $y+12, 523, 44);
            $text(43, $y, "Trainee's performance was:", 9);
            $text(365, $y, 'Overall score: '.$scoreLabel($evaluation), 9, true);
            $box(43, $y-10, 8, 8); $box(267, $y-10, 8, 8);
            $description = strtoupper(trim((string)($rule['description'] ?? '')));
            if ($description === 'PASSED') $text(44, $y-17, 'X', 8, true);
            if ($description === 'NEEDS IMPROVEMENT') $text(268, $y-17, 'X', 8, true);
            $text(58, $y-17, 'PASSED', 9, true); $text(282, $y-17, 'NEEDS IMPROVEMENT', 9, true);
            $y -= 44;
        } else {
            $tableRow(["Trainee's performance: ".($rule['description'] ?? 'Not recorded'), 'Overall score: '.$scoreLabel($evaluation)], [338,185], ['Performance','Score'], true);
        }
        if (($evaluation['overall_equivalent'] ?? null) !== null) $flow('Overall equivalent: '.$evaluation['overall_equivalent']);
        $y -= 6;
        $feedback = $wrap(trim((string)($evaluation['comments'] ?? '')) ?: 'No feedback recorded.', 507, 9);
        $offset = 0;
        while ($offset < count($feedback)) {
            if ($y < 100) $newPage();
            $available = max(1, (int)floor(($y - 75) / 12));
            $chunk = array_slice($feedback, $offset, $available);
            $height = max(48, count($chunk)*12 + 27);
            $box(36, $y + 12, 523, $height);
            $text(43, $y, $offset ? 'Feedback to the trainee (continued)' : 'Feedback to the trainee (general comments - strengths and improvements needed)', 8, true);
            foreach ($chunk as $index=>$feedbackLine) $text(43, $y - 17 - $index*12, $feedbackLine, 9);
            $y -= $height; $offset += count($chunk);
        }
        if ($y < 132) $newPage();
        $y -= 6;
        foreach (["Trainee's Signature"=>($student['name'] ?? ''), "Trainer's Signature"=>($evaluation['evaluator_name'] ?? '')] as $signature=>$printedName) {
            $nameLines = $wrap('Printed name: '.$printedName, 380, 7);
            $height = 36 + count($nameLines)*9;
            if ($y - $height < 40) $newPage();
            $box(36, $y + 12, 523, $height); $line(447, $y+12, 447, $y+12-$height);
            $text(43, $y, $signature, 8); $text(455, $y, 'Date:', 8);
            $line(145, $y-9, 430, $y-9); $line(478, $y-9, 548, $y-9);
            foreach ($nameLines as $index=>$nameLine) $text(43, $y-23-$index*9, $nameLine, 7);
            $y -= $height;
        }
    }
    if ($stream !== '') $pages[] = $stream;
    $objects = [1=>'<< /Type /Catalog /Pages 2 0 R >>', 3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>', 4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>'];
    $refs = []; $id = 5;
    $totals = array_count_values($pageOwners); $numbers = [];
    foreach ($pages as $pageIndex=>$content) {
        $pageOwner = $pageOwners[$pageIndex]; $numbers[$pageOwner] = ($numbers[$pageOwner] ?? 0) + 1;
        $content = str_replace('__FORMPAGE__', $numbers[$pageOwner].' of '.$totals[$pageOwner], $content);
        $objects[$id] = '<< /Length '.strlen($content).">>\nstream\n".$content.'endstream';
        $objects[$id+1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$id.' 0 R >>';
        $refs[] = ($id+1).' 0 R'; $id += 2;
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $refs).'] /Count '.count($refs).' >>'; ksort($objects);
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $key=>$object) { $offsets[$key] = strlen($pdf); $pdf .= "$key 0 obj\n$object\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
    for ($key=1; $key<=count($objects); $key++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$key]);
    return $pdf.'trailer << /Size '.(count($objects)+1).' /Root 1 0 R >>'."\nstartxref\n$xref\n%%EOF";
}
