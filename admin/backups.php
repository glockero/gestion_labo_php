<?php
// admin/backups.php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

requireRole('admin');

// Manual backup implementation without exec/mysqldump
// Warning: This is a basic implementation for shared hosting.
// It dumps table structures and data.

$pdo = getDbConnection();

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="backup_laboratorio_' . date('Ymd_His') . '.sql"');

echo "-- Backup Laboratorio DB\n";
echo "-- Date: " . date('Y-m-d H:i:s') . "\n\n";

$tables = [];
$stmt = $pdo->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

foreach ($tables as $table) {
    echo "-- Table structure for `$table`\n";
    echo "DROP TABLE IF EXISTS `$table`;\n";
    $createStmt = $pdo->query("SHOW CREATE TABLE `$table`");
    $createRow = $createStmt->fetch(PDO::FETCH_NUM);
    echo $createRow[1] . ";\n\n";

    echo "-- Dumping data for `$table`\n";
    $dataStmt = $pdo->query("SELECT * FROM `$table`");
    $rowCount = $dataStmt->rowCount();
    if ($rowCount > 0) {
        $columns = [];
        $colStmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        while ($colRow = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $colRow['Field'];
        }
        
        $insertPrefix = "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES ";
        echo $insertPrefix . "\n";
        
        $currentRow = 0;
        while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            $currentRow++;
            $values = [];
            foreach ($row as $value) {
                if (is_null($value)) {
                    $values[] = "NULL";
                } else {
                    // Escape string to avoid breaking SQL
                    $escaped = str_replace(["\\", "'", "\n", "\r"], ["\\\\", "''", "\\n", "\\r"], $value);
                    $values[] = "'$escaped'";
                }
            }
            echo "(" . implode(", ", $values) . ")";
            if ($currentRow < $rowCount) {
                echo ",\n";
            } else {
                echo ";\n\n";
            }
        }
    } else {
        echo "-- No data for `$table`\n\n";
    }
}
exit;
