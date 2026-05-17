<?php
require_once 'dbconn.php';

echo "<h2>Debug Dashboard - Estado Actual</h2>";

// Simular el estado del dashboard
$evento_id = isset($_GET['evento']) ? intval($_GET['evento']) : 0;
echo "<p><strong>Evento seleccionado:</strong> " . ($evento_id ? $evento_id : 'Ninguno') . "</p>";

// Verificar eventos disponibles
$eventos = [];
$res = $conn->query("SELECT id, nombre FROM eventos ORDER BY fecha DESC, hora DESC");
while ($row = $res->fetch_assoc()) {
    $eventos[] = $row;
}

echo "<p><strong>Eventos disponibles:</strong></p>";
echo "<ul>";
foreach($eventos as $ev) {
    $selected = ($evento_id == $ev['id']) ? ' (SELECCIONADO)' : '';
    echo "<li>ID: " . $ev['id'] . " - " . $ev['nombre'] . $selected . "</li>";
}
echo "</ul>";

// Verificar requisitos del evento
if ($evento_id) {
    $requisitos_evento = [];
    $sql = "SELECT r.id, r.nombre
            FROM evento_requisito er
            JOIN requisitos r ON er.requisito_id = r.id
            WHERE er.evento_id = $evento_id";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $requisitos_evento[] = $row;
    }
    
    echo "<p><strong>Requisitos del evento $evento_id:</strong></p>";
    if (count($requisitos_evento) > 0) {
        echo "<ul>";
        foreach($requisitos_evento as $req) {
            echo "<li>ID: " . $req['id'] . " - " . $req['nombre'] . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color: orange;'>No hay requisitos asignados a este evento</p>";
    }
    
    // Verificar requisitos disponibles
    $requisitos_disponibles = [];
    $sql = "SELECT r.id, r.nombre 
            FROM requisitos r 
            WHERE r.id NOT IN (
                SELECT er.requisito_id 
                FROM evento_requisito er 
                WHERE er.evento_id = ?
            )";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $evento_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $requisitos_disponibles[] = $row;
    }
    $stmt->close();
    
    echo "<p><strong>Requisitos disponibles para agregar:</strong></p>";
    if (count($requisitos_disponibles) > 0) {
        echo "<ul>";
        foreach($requisitos_disponibles as $req) {
            echo "<li>ID: " . $req['id'] . " - " . $req['nombre'] . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color: orange;'>No hay requisitos disponibles para agregar</p>";
    }
}

// Verificar todos los requisitos
$requisitos_todos = [];
$res = $conn->query("SELECT id, nombre FROM requisitos");
while ($row = $res->fetch_assoc()) {
    $requisitos_todos[] = $row;
}

echo "<p><strong>Total de requisitos en la base de datos:</strong> " . count($requisitos_todos) . "</p>";

// Probar agregar un requisito
if (isset($_GET['test_agregar']) && $evento_id) {
    $requisito_id = intval($_GET['test_agregar']);
    echo "<h3>Probando agregar requisito ID $requisito_id al evento $evento_id</h3>";
    
    // Verificar si ya existe
    $check = $conn->prepare("SELECT id FROM evento_requisito WHERE evento_id = ? AND requisito_id = ?");
    $check->bind_param("ii", $evento_id, $requisito_id);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO evento_requisito (evento_id, requisito_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $evento_id, $requisito_id);
        if ($stmt->execute()) {
            echo "<p style='color: green;'>✅ Requisito agregado exitosamente</p>";
        } else {
            echo "<p style='color: red;'>❌ Error al agregar: " . $stmt->error . "</p>";
        }
        $stmt->close();
    } else {
        echo "<p style='color: orange;'>⚠️ El requisito ya está asignado a este evento</p>";
    }
    $check->close();
}

echo "<hr>";
echo "<h3>Enlaces de prueba:</h3>";
if ($evento_id) {
    echo "<p><a href='?evento=$evento_id&test_agregar=1'>Probar agregar requisito ID 1</a></p>";
    echo "<p><a href='?evento=$evento_id&test_agregar=2'>Probar agregar requisito ID 2</a></p>";
    echo "<p><a href='?evento=$evento_id&test_agregar=3'>Probar agregar requisito ID 3</a></p>";
}

echo "<p><a href='dashboard_empresa.php'>← Volver al Dashboard</a></p>";
?> 