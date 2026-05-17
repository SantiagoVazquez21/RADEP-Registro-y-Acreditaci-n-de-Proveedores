<?php
require_once 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de proveedor inválido']);
    exit;
}

$proveedor_id = intval($_GET['id']);

try {
    $response = ['success' => true, 'eventos' => [], 'cambios_estado' => [], 'requisitos' => []];
    
    // Obtener eventos asignados
    $stmt = $conn->prepare("
        SELECT e.nombre, e.fecha, e.hora 
        FROM eventos e 
        JOIN evento_proveedor ee ON e.id = ee.evento_id 
        WHERE ee.proveedor_id = ? 
        ORDER BY e.fecha DESC, e.hora DESC
    ");
    $stmt->bind_param("i", $proveedor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['fecha'] = date('d/m/Y H:i', strtotime($row['fecha'] . ' ' . $row['hora']));
        $response['eventos'][] = $row;
    }
    $stmt->close();
    
    // Obtener cambios de estado (si existe la tabla)
    $check_table = $conn->query("SHOW TABLES LIKE 'historial_proveedores'");
    if ($check_table->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT estado_anterior, estado_nuevo, motivo, fecha_cambio 
            FROM historial_proveedores 
            WHERE proveedor_id = ? 
            ORDER BY fecha_cambio DESC
        ");
        $stmt->bind_param("i", $proveedor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['fecha'] = date('d/m/Y H:i', strtotime($row['fecha_cambio']));
            $response['cambios_estado'][] = $row;
        }
        $stmt->close();
    }
    
    // Obtener requisitos completados (inferir por fecha_actualizacion o por registros en proveedor_requisito_evento con comentario/archivo)
    $stmt = $conn->prepare("\
        SELECT r.nombre, er.fecha_actualizacion
        FROM requisitos r 
        JOIN evento_requisito er ON r.id = er.requisito_id
        JOIN evento_proveedor ee ON er.evento_id = ee.evento_id
        WHERE ee.proveedor_id = ? AND er.fecha_actualizacion IS NOT NULL
        ORDER BY er.fecha_actualizacion DESC
    ");
    $stmt->bind_param("i", $proveedor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['fecha'] = $row['fecha_actualizacion'] ? date('d/m/Y H:i', strtotime($row['fecha_actualizacion'])) : 'Sin fecha';
        $row['estado'] = 'completado';
        $response['requisitos'][] = $row;
    }
    $stmt->close();
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}

$conn->close();
?> 