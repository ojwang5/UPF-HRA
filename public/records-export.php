<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
$user = require_min_rank('post_commander');
$pdo  = db();
[$scopeW, $scopeP] = scope_where($user, 'e');

$module = $_GET['module'] ?? '';
$today  = date('Y-m-d');

/** Open a CSV download handle with a UTF-8 BOM (Excel-friendly). */
function csv_begin(string $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    return $out;
}

if ($module === 'courses') {
    $stmt = $pdo->prepare("SELECT oc.*, e.service_no, e.rank, e.full_name, e.directorate, e.unit,
                                  rg.name AS region_name, dv.name AS division_name,
                                  st.name AS station_name, pt.name AS post_name
                           FROM on_courses oc
                           JOIN employees e ON e.id=oc.employee_id
                           LEFT JOIN regions   rg ON rg.id=e.region_id
                           LEFT JOIN divisions dv ON dv.id=e.division_id
                           LEFT JOIN stations  st ON st.id=e.station_id
                           LEFT JOIN posts     pt ON pt.id=e.post_id
                           WHERE $scopeW
                           ORDER BY oc.start_date DESC, " . personnel_order_sql('e') . "
                           LIMIT 5000");
    $stmt->execute($scopeP);
    $out = csv_begin('courses-'.$today.'.csv');
    fputcsv($out, ['service_no','rank','full_name','course_name','course_nature','school','place',
                   'start_date','end_date','duration_days','sponsor','status','notes',
                   'directorate','unit','region','division','station','post']);
    foreach ($stmt as $r) {
        fputcsv($out, [$r['service_no'],$r['rank'],$r['full_name'],$r['course_name'],$r['course_nature'],
                       $r['school'],$r['place'],$r['start_date'],$r['end_date'],$r['duration_days'],
                       $r['sponsor'],$r['status'],$r['notes'],$r['directorate'],$r['unit'],
                       $r['region_name'],$r['division_name'],$r['station_name'],$r['post_name']]);
    }
    fclose($out); exit;
}

if ($module === 'assignments') {
    $stmt = $pdo->prepare("SELECT sa.*, e.service_no, e.rank, e.full_name, e.directorate, e.unit,
                                  rg.name AS region_name, dv.name AS division_name,
                                  st.name AS station_name, pt.name AS post_name
                           FROM special_assignments sa
                           JOIN employees e ON e.id=sa.employee_id
                           LEFT JOIN regions   rg ON rg.id=e.region_id
                           LEFT JOIN divisions dv ON dv.id=e.division_id
                           LEFT JOIN stations  st ON st.id=e.station_id
                           LEFT JOIN posts     pt ON pt.id=e.post_id
                           WHERE $scopeW
                           ORDER BY sa.start_date DESC, " . personnel_order_sql('e') . "
                           LIMIT 5000");
    $stmt->execute($scopeP);
    $out = csv_begin('special-assignments-'.$today.'.csv');
    fputcsv($out, ['service_no','rank','full_name','title','nature','place','start_date','end_date',
                   'status','notes','end_remarks','directorate','unit','region','division','station','post']);
    foreach ($stmt as $r) {
        fputcsv($out, [$r['service_no'],$r['rank'],$r['full_name'],$r['title'],$r['nature'],$r['place'],
                       $r['start_date'],$r['end_date'],$r['status'],$r['notes'],$r['end_remarks'],
                       $r['directorate'],$r['unit'],$r['region_name'],$r['division_name'],
                       $r['station_name'],$r['post_name']]);
    }
    fclose($out); exit;
}

if ($module === 'transfers') {
    $stmt = $pdo->prepare("SELECT t.*, e.service_no, e.rank, e.full_name AS emp_name,
                                  fr.name AS from_region, fdv.name AS from_division, fst.name AS from_station, fpt.name AS from_post,
                                  tr.name AS to_region,   tdv.name AS to_division,   tst.name AS to_station,   tpt.name AS to_post,
                                  fd.name AS from_dir, fu.name AS from_unit, td.name AS to_dir, tu.name AS to_unit
                           FROM transfers t
                           JOIN employees e ON e.id=t.employee_id
                           LEFT JOIN regions    fr  ON fr.id=t.from_region_id
                           LEFT JOIN divisions  fdv ON fdv.id=t.from_division_id
                           LEFT JOIN stations   fst ON fst.id=t.from_station_id
                           LEFT JOIN posts      fpt ON fpt.id=t.from_post_id
                           LEFT JOIN regions    tr  ON tr.id=t.to_region_id
                           LEFT JOIN divisions  tdv ON tdv.id=t.to_division_id
                           LEFT JOIN stations   tst ON tst.id=t.to_station_id
                           LEFT JOIN posts      tpt ON tpt.id=t.to_post_id
                           LEFT JOIN directorates fd ON fd.id=t.from_directorate_id
                           LEFT JOIN units      fu  ON fu.id=t.from_unit_id
                           LEFT JOIN directorates td ON td.id=t.to_directorate_id
                           LEFT JOIN units      tu  ON tu.id=t.to_unit_id
                           WHERE $scopeW
                           ORDER BY t.requested_at DESC, " . personnel_order_sql('e') . "
                           LIMIT 5000");
    $stmt->execute($scopeP);
    $out = csv_begin('transfers-'.$today.'.csv');
    fputcsv($out, ['service_no','rank','full_name','transfer_scope','from','to','reason',
                   'transfer_date','effective_date','status','report_status','requested_at']);
    foreach ($stmt as $r) {
        if (($r['transfer_scope'] ?? 'hierarchy') === 'hierarchy') {
            $from = trim(($r['from_region']??'').' › '.($r['from_division']??'').' › '.($r['from_station']??'').' › '.($r['from_post']??''), ' › ');
            $to   = trim(($r['to_region']??'').' › '.($r['to_division']??'').' › '.($r['to_station']??'').' › '.($r['to_post']??''), ' › ');
        } else {
            $from = trim(($r['from_dir']??'—').' › '.($r['from_unit']??'—'), ' ›');
            $to   = trim(($r['to_dir']??'—').' › '.($r['to_unit']??'—'), ' ›');
        }
        fputcsv($out, [$r['service_no'],$r['rank'],$r['emp_name'],$r['transfer_scope'],$from,$to,
                       $r['reason'],$r['transfer_date'],$r['effective_date'],$r['status'],
                       $r['report_status'] ?? 'pending',$r['requested_at']]);
    }
    fclose($out); exit;
}

http_response_code(400);
flash('err', 'Unknown export module.');
header('Location: /index.php'); exit;
