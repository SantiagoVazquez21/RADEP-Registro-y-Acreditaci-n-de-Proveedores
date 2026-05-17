<?php
require_once 'dbconn.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);
$res = $conn->query("SELECT id, nombre, cuilcuit, email, usuario, fechaalta, foto, servicio FROM proveedores");
echo "<pre>";
if ($res) {
    $rows = 0;
    while ($row = $res->fetch_assoc()) {
        print_r($row);
        $rows++;
    }
    echo "\nTotal proveedores: $rows\n";
    $res->free();
} else {
    echo "Error SQL: " . $conn->error;
}
echo "</pre>";
?>
