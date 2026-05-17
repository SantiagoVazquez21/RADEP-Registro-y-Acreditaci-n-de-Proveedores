<?php
require_once 'dbconn.php';

echo "<h2>Debug: Problema con Agregar Requisitos</h2>";

// 1. Verificar si hay requisitos en la tabla
$result = $conn->query("SELECT COUNT(*) as total FROM requisitos");
if ($result) {
    $row = $result->fetch_assoc();
    echo "<p><strong>Total de requisitos:</strong> " . $row['total'] . "</p>";
    
    if ($row['total'] == 0) {
        echo "<p style='color: red;'>❌ No hay requisitos en la tabla. Insertando datos de prueba...</p>";
        
        $requisitos_prueba = [
            'Documento de identidad',
            'Certificado médico',
            'Formulario de inscripción',
            'Comprobante de pago',
            'Foto carnet',
            'Certificado de antecedentes',
            'Carnet de vacunación',
            'Certificado de capacitación',
            'Equipo de protección personal',
            'Licencia de conducir',
            'Certificado de aptitud física',
            'Formulario de emergencias',
            'Seguro médico',
            'Certificado de formación',
            'Documentación de vehículo',
            'Certificado de seguridad',
            'Formulario de contacto',
            'Certificado de experiencia',
            'Documentación de herramientas',
            'Certificado de especialización'
        ];
        
        foreach ($requisitos_prueba as $requisito) {
            $stmt = $conn->prepare("INSERT INTO requisitos (nombre) VALUES (?)");
            $stmt->bind_param("s", $requisito);
            $stmt->execute();
            $stmt->close();
        }
        
        echo "<p style='color: green;'>✅ Datos de prueba insertados</p>";
    }
}

// 2. Verificar eventos disponibles
$result = $conn->query("SELECT id, nombre FROM eventos ORDER BY fecha DESC");
echo "<p><strong>Eventos disponibles:</strong></p>";
if ($result && $result->num_rows > 0) {
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>ID: " . $row['id'] . " - " . $row['nombre'] . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: orange;'>⚠️ No hay eventos disponibles</p>";
}

// 3. Verificar tabla evento_requisito
$result = $conn->query("SHOW TABLES LIKE 'evento_requisito'");
if ($result->num_rows == 0) {
    echo "<p style='color: red;'>❌ La tabla 'evento_requisito' no existe. Creándola...</p>";
    
    $sql = "CREATE TABLE `evento_requisito` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `evento_id` int(11) NOT NULL,
        `requisito_id` int(11) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `evento_id` (`evento_id`),
        KEY `requisito_id` (`requisito_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if ($conn->query($sql)) {
        echo "<p style='color: green;'>✅ Tabla evento_requisito creada</p>";
    } else {
        echo "<p style='color: red;'>❌ Error al crear tabla: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: green;'>✅ La tabla 'evento_requisito' existe</p>";
}

// 4. Probar la consulta de requisitos disponibles
$evento_id = 1; // Probar con el primer evento
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

echo "<p><strong>Requisitos disponibles para evento ID $evento_id:</strong></p>";
if ($result && $result->num_rows > 0) {
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>ID: " . $row['id'] . " - " . $row['nombre'] . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: orange;'>⚠️ No hay requisitos disponibles para este evento</p>";
}
$stmt->close();

echo "<p><a href='dashboard_empresa.php'>← Volver al Dashboard</a></p>";
?> 