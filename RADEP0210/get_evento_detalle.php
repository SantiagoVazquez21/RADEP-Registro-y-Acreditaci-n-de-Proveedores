<?php
include('dbconn.php');

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de evento no proporcionado']);
    exit();
}

$evento_id = intval($_GET['id']);

// Obtener detalles del evento
$stmt = $conn->prepare("SELECT id, nombre, fecha, hora, descripcion FROM eventos WHERE id = ?");
$stmt->bind_param("i", $evento_id);
$stmt->execute();
$result = $stmt->get_result();
$evento = $result->fetch_assoc();
$stmt->close();

if (!$evento) {
    echo json_encode(['success' => false, 'message' => 'Evento no encontrado']);
    exit();
}

// Obtener requisitos del evento
$requisitos = [];
$stmt = $conn->prepare("SELECT r.id, r.nombre 
                       FROM evento_requisito er 
                       JOIN requisitos r ON er.requisito_id = r.id 
                       WHERE er.evento_id = ?");
$stmt->bind_param("i", $evento_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requisitos[] = $row;
}
$stmt->close();

// Formatear fecha y hora
$evento['fecha'] = date('d/m/Y', strtotime($evento['fecha']));
$evento['hora'] = date('H:i', strtotime($evento['hora']));

echo json_encode([
    'success' => true,
    'evento' => $evento,
    'requisitos' => $requisitos
]);
?> 