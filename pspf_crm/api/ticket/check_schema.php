<?php
require_once '../db.php';

// Check if last_updated_by column exists
$result = $conn->query("SHOW COLUMNS FROM tickets LIKE 'last_updated_by'");
if ($result->num_rows == 0) {
    echo "Column last_updated_by missing. Adding it...\n";
    $conn->query("ALTER TABLE tickets ADD COLUMN last_updated_by VARCHAR(255) NULL");
    echo "Column added.\n";
} else {
    echo "Column last_updated_by exists.\n";
}

// Check if updated_at column exists
$result = $conn->query("SHOW COLUMNS FROM tickets LIKE 'updated_at'");
if ($result->num_rows == 0) {
    echo "Column updated_at missing. Adding it...\n";
    $conn->query("ALTER TABLE tickets ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    echo "Column added.\n";
} else {
    echo "Column updated_at exists.\n";
}

echo "Schema check complete.\n";
?>
