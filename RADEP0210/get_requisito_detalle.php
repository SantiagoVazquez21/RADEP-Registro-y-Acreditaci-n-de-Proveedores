<?php
require_once 'dbconn.php';

header('Content-Type: application/json');

if (!isset($_GET['empleado_id']) || !isset($_GET['requisito_id']) || 
    !is_numeric($_GET['empleado_id']) || !is_numeric($_GET['requisito_id'])) {
    echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
    exit;
}

$empleado_id = intval($_GET['empleado_id']);
$requisito_id = intval($_GET['requisito_id']);

try {
    // Obtener datos del requisito
    $stmt = $conn->prepare("
        SELECT r.nombre, er.estado, er.fecha_creacion, er.fecha_actualizacion
        FROM requisitos r 
        JOIN evento_requisito er ON r.id = er.requisito_id
        JOIN evento_empleado ee ON er.evento_id = ee.evento_id
        WHERE ee.empleado_id = ? AND r.id = ?
    ");
        $stmt->bind_param("ii", $empleado_id, $requisito_id);
        $stmt = $conn->prepare("
            SELECT r.nombre
            FROM requisitos r 
            JOIN evento_requisito er ON r.id = er.requisito_id
            JOIN evento_empleado ee ON er.evento_id = ee.evento_id
            WHERE ee.empleado_id = ? AND r.id = ?
        ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Requisito no encontrado para este proveedor']);
        exit;
    }
    
    $requisito = $result->fetch_assoc();
    $stmt->close();
    
    // Obtener datos del proveedor
    $stmt = $conn->prepare("SELECT nombre, apellido, email FROM empleados WHERE id = ?");
    $stmt->bind_param("i", $empleado_id);
    $stmt->execute();
    $proveedor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Obtener datos del evento
    $stmt = $conn->prepare("
        SELECT e.nombre 
        FROM eventos e 
        JOIN evento_empleado ee ON e.id = ee.evento_id
        JOIN evento_requisito er ON e.id = er.evento_id
        WHERE ee.empleado_id = ? AND er.requisito_id = ?
    ");
    $stmt->bind_param("ii", $empleado_id, $requisito_id);
    $stmt->execute();
    $evento = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Obtener archivos subidos (si existe la tabla)
    $archivos = [];
    $check_table = $conn->query("SHOW TABLES LIKE 'archivos_requisitos'");
    if ($check_table->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT nombre_archivo, fecha_subida, tipo_archivo
            FROM archivos_requisitos
            WHERE empleado_id = ? AND requisito_id = ?
            ORDER BY fecha_subida DESC
        ");
        $stmt->bind_param("ii", $empleado_id, $requisito_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['fecha_subida'] = date('d/m/Y H:i', strtotime($row['fecha_subida']));
            $archivos[] = $row;
        }
        $stmt->close();
    }
    
    $response = [
        'success' => true,
        'requisito' => [
            'nombre' => $requisito['nombre'],
            'estado' => $requisito['estado'],
                    'estado' => 'pendiente', // default when estado column may be absent
                    'fecha_asignacion' => 'N/D',
                    'ultima_actualizacion' => 'N/D'
        ],
        'proveedor' => $proveedor,
        'evento' => $evento,
        'archivos' => $archivos
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}

$conn->close();
?> 