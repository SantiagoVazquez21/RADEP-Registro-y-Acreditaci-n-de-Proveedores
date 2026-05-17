<?php
require_once 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de proveedor inválido']);
    exit;
}

$proveedor_id = intval($_GET['id']);

try {
    $stmt = $conn->prepare("SELECT id, nombre, apellido, cuilcuit, email, usuario, rol, fechaalta, foto, servicio FROM proveedores WHERE id = ?");
    $stmt->bind_param("i", $proveedor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $proveedor = $result->fetch_assoc();
        echo json_encode(['success' => true, 'proveedor' => $proveedor]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Proveedor no encontrado']);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}

$conn->close();
?> 