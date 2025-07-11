<?php
require_once 'database.php';

function isLocked($table, $year, $month, $dbh = null)
{
    if (!$dbh) {
        $dbh = getDb();
    }

    $query = "SELECT status FROM {$table} WHERE year = ? AND month = ?";
    $stmt = $dbh->prepare($query);
    $stmt->execute([$year, $month]);
    $status = $stmt->fetchColumn();

    return $status === 'fixed';
}
