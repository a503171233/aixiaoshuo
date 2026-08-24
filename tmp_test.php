<?php
require __DIR__.'/lib/bootstrap.php';
try {
    $pdo = kl_db();
    $stmt = $pdo->query('SELECT 1');
    $result = $stmt->fetchColumn();
    echo json_encode(['ok'=>true,'result'=>$result]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
?>