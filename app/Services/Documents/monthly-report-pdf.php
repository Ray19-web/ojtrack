<?php
/** Paginated A4 compilation; all data must belong to the authorized student. */
function monthly_report_pdf(array $student, string $month, array $attendance, array $journals): string {
    $pages=[]; $lines=[];
    $flush=function() use (&$pages,&$lines) { if ($lines) $pages[]=$lines; $lines=[]; };
    $add=function(string $text) use (&$lines,$flush) {
        $text=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text) ?: '';
        foreach (explode("\n",wordwrap(str_replace(["\r","\t"],['',' '],$text),90,"\n",true)) as $line) {
            if (count($lines)>=24) $flush();
            $lines[]=$line;
        }
    };
    $add('MONTHLY OJT REPORT');
    $add(date('F Y',strtotime($month.'-01')));
    $add('Student: '.($student['name'] ?? ''));
    $add('Student ID: '.($student['student_id_no'] ?? ''));
    $add('Program: '.($student['program'] ?? ''));
    $add('Company: '.($student['company_name'] ?? 'Not assigned'));
    $add(''); $add('MONTH SUMMARY');
    $add('Attendance records: '.count($attendance));
    $add('Recorded hours: '.number_format(array_sum(array_column($attendance,'hours_rendered')),2));
    $add('Journal entries: '.count($journals));
    $add(''); $add('This compilation reflects saved records at the time of download.');
    $add('Journal hours are claimed hours; attendance hours are recorded credited hours.');
    $flush();
    require_once __DIR__ . '/dtr-pdf.php';
    $dtrRows=$attendance;
    foreach ($dtrRows as &$dtrRow) {
        foreach (['morning_in','morning_out','afternoon_in','afternoon_out'] as $field) {
            $dtrRow[$field]=!empty($dtrRow[$field]) ? date('h:i A',strtotime($dtrRow[$field])) : null;
        }
    }
    unset($dtrRow);
    foreach (coordinator_dtr_pdf($student,$dtrRows,true) as $dtrPage) $pages[]=$dtrPage;
    foreach ($journals as $entry) {
        $add('DAILY JOURNAL - '.$entry['entry_date']);
        $add('Status: '.$entry['status'].' | Claimed hours: '.number_format((float)$entry['hours_rendered'],2));
        foreach (['activities'=>'ACTIVITIES','learnings'=>'LEARNINGS','challenges'=>'CHALLENGES','coordinator_remarks'=>'COORDINATOR FEEDBACK'] as $key=>$label) {
            $add(''); $add($label); $add((string)($entry[$key] ?: 'None recorded.'));
        }
        $flush();
    }
    if (!$journals) { $add('DAILY JOURNALS'); $add('No journal entries for this month.'); $flush(); }
    $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>']; $refs=[]; $id=5;
    foreach ($pages as $index=>$page) {
        if (is_string($page)) { $stream=$page; } else {
        $stream="0.95 0.97 0.99 rg 0 752 595 90 re f\n0.08 0.23 0.43 rg 36 795 30 3 re f\n";
        $draw=function($x,$y,$value,$size=10,$bold=false,$color='0.17 0.24 0.35') use (&$stream) {
            $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],(string)$value);
            $font=$bold?'F2':'F1'; $stream.="$color rg BT /$font $size Tf 1 0 0 1 $x $y Tm ($escaped) Tj ET\n";
        };
        $draw(36,774,'MONTHLY OJT REPORT',12,true,'0.08 0.23 0.43');
        $draw(380,774,date('F Y',strtotime($month.'-01')),11);
        $y=724;
        foreach ($page as $line) {
            if (str_starts_with($line,'Date         ') || preg_match('/^\d{4}-\d{2}-\d{2}\s/', $line)) {
                $header=str_starts_with($line,'Date         ');
                $values=$header ? ['Date','AM In','AM Out','PM In','PM Out','Hours'] : preg_split('/\s{2,}/',trim($line));
                $stream.=($header?'0.08 0.23 0.43':'0.96 0.98 0.99')." rg 36 ".($y-10)." 523 26 re f\n";
                $positions=[44,148,228,308,388,481];
                foreach ($values as $column=>$value) $draw($positions[$column] ?? 481,$y,$value,9,$header,$header?'1 1 1':'0.17 0.24 0.35');
                $stream.="0.85 0.89 0.94 RG 0.5 w 36 ".($y-10)." m 559 ".($y-10)." l S\n";
                $y-=27;
            } else {
                $heading=in_array($line,['ACTIVITIES','LEARNINGS','CHALLENGES','COORDINATOR FEEDBACK','MONTH SUMMARY','ATTENDANCE / DAILY TIME RECORD']) || str_starts_with($line,'DAILY JOURNAL');
                if ($heading) { $stream.="0.94 0.96 0.98 rg 36 ".($y-6)." 523 23 re f\n"; }
                $draw(44,$y,$line,$heading?10:9,$heading); $y-= $heading?27:(str_starts_with($line,'Status:')?24:14);
            }
        }
        }
        $objects[$id]='<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream';
        $objects[$id+1]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$id.' 0 R >>';
        $refs[]=($id+1).' 0 R'; $id+=2;
    }
    $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$refs).'] /Count '.count($refs).' >>'; ksort($objects);
    $pdf="%PDF-1.4\n"; $offsets=[0];
    foreach ($objects as $key=>$object) { $offsets[$key]=strlen($pdf); $pdf.="$key 0 obj\n$object\nendobj\n"; }
    $xref=strlen($pdf); $pdf.='xref'."\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
    for ($key=1;$key<=count($objects);$key++) $pdf.=sprintf("%010d 00000 n \n",$offsets[$key]);
    return $pdf.'trailer << /Size '.(count($objects)+1).' /Root 1 0 R >>'."\nstartxref\n$xref\n%%EOF";
}
