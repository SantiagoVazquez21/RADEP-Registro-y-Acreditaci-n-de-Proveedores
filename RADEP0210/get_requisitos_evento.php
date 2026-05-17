<?php
session_start();
header('Content-Type: application/json');
include("dbconn.php");

// Verificar que se proporcione el ID del evento
if (!isset($_GET['evento_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID del evento no proporcionado']);
    exit();
}

$evento_id = intval($_GET['evento_id']);

// Obtener requisitos del evento
$requisitos = [];
$sql = "SELECT r.id, r.nombre FROM evento_requisito er JOIN requisitos r ON er.requisito_id = r.id WHERE er.evento_id = ? ORDER BY r.nombre";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $evento_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $requisitos[] = [
        'id' => $row['id'],
        'nombre' => $row['nombre'],
        'estado' => 'pendiente', // default; UI may request proveedor-specific status separately
        'archivo' => null,
        'fecha_subida' => null
    ];
}

$stmt->close();

// Devolver los requisitos como JSON
echo json_encode($requisitos);
?> 