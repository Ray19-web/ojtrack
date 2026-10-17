<?php
// Connection-scoped synthetic tables only; application records are never modified.
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../config/db.php';
require __DIR__.'/../config/coordinator-filters.php';
db()->query("CREATE TEMPORARY TABLE coordinator_filter_users (id INT, name VARCHAR(100), email VARCHAR(150), status VARCHAR(20))");
db()->query("CREATE TEMPORARY TABLE coordinator_filter_coords (user_id INT, coordinator_id_no VARCHAR(20), department VARCHAR(150), contact_number VARCHAR(30))");
db()->query("CREATE TEMPORARY TABLE programs (code VARCHAR(20), name VARCHAR(150))");
db()->query("INSERT INTO programs VALUES ('BSIT','Information Technology'),('BSTCM','Technology Communication Management')");
db()->query("INSERT INTO coordinator_filter_users VALUES (1,'Alice Example',NULL,'active'),(2,'Bob Example','bob@example.invalid','inactive'),(3,'Archived Example',NULL,'archived'),(4,'100%_Exact!',NULL,'active')");
db()->query("INSERT INTO coordinator_filter_coords VALUES (1,'C20990001','BSIT',NULL),(2,'C20990002','Information Technology','09123456789'),(3,'C20990003','BSTCM',NULL),(4,'C20990004','BSTCM',NULL)");
$checks=0;
function filter_check($input,$expected) {
    global $checks;
    $f=coordinator_filters($input);
    $rows=query("SELECT u.id FROM coordinator_filter_coords c JOIN coordinator_filter_users u ON u.id=c.user_id WHERE {$f['where']} ORDER BY u.id",$f['params'],$f['types']);
    if (array_map('intval',array_column($rows,'id'))!==$expected) throw new RuntimeException('Filter failed: '.json_encode($input));
    $checks++;
}
filter_check([], [1,2,4]);
filter_check(['q'=>'alice'], [1]);
filter_check(['q'=>'C20990002'], [2]);
filter_check(['q'=>'bob@example.invalid'], [2]);
filter_check(['q'=>'091234'], [2]);
filter_check(['q'=>'Information Technology'], [1,2]);
filter_check(['q'=>'missing'], []);
filter_check(['q'=>"' OR 1=1 --"], []);
filter_check(['q'=>'%_'], [4]);
filter_check(['q'=>'!'], [4]);
filter_check(['program'=>'BSIT'], [1,2]);
filter_check(['program'=>'BSTCM'], [4]);
filter_check(['program'=>'unknown'], []);
filter_check(['status'=>'active'], [1,4]);
filter_check(['status'=>'inactive'], [2]);
filter_check(['status'=>'archived'], [1,2,4]);
filter_check(['q'=>'Bob','program'=>'BSIT','status'=>'inactive'], [2]);
filter_check(['q'=>'Bob','program'=>'BSTCM','status'=>'inactive'], []);
filter_check(['q'=>['malformed'],'program'=>['malformed'],'status'=>['malformed']], [1,2,4]);
filter_check(['q'=>' Alice ','program'=>' BSIT ','status'=>' active '], [1]);
foreach (['coordinator_filter_users','coordinator_filter_coords','programs'] as $table) db()->query("DROP TEMPORARY TABLE $table");
echo json_encode(['coordinator_filter_checks_passed'=>$checks,'application_records_changed'=>false,'result'=>'PASS']).PHP_EOL;
