<?php
include("dbconn.php");

// --- VERIFICAR Y ACTUALIZAR ESTRUCTURA DE LA TABLA ---
$checkTable = $conn->query("SHOW TABLES LIKE 'eventos'");
if ($checkTable->num_rows == 0) {
    // La tabla no existe, la creamos
    $conn->query("CREATE TABLE `eventos` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `nombre` varchar(100) NOT NULL,
        `lugar` varchar(100) NOT NULL,
        `fecha` date NOT NULL,
        `fecha_fin` date DEFAULT NULL,
    `hora` time NOT NULL,
    `hora_fin` time DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

// Verificar si las columnas descripcion y estado existen
$checkColumns = $conn->query("SHOW COLUMNS FROM `eventos` LIKE 'hora_fin'");
if ($checkColumns->num_rows == 0) {
    $conn->query("ALTER TABLE `eventos` ADD `hora_fin` time DEFAULT NULL");
}
$checkColumns = $conn->query("SHOW COLUMNS FROM `eventos` LIKE 'fecha_fin'");
if ($checkColumns->num_rows == 0) {
    $conn->query("ALTER TABLE `eventos` ADD `fecha_fin` date DEFAULT NULL");
}
$checkColumns = $conn->query("SHOW COLUMNS FROM `eventos` LIKE 'descripcion'");
if ($checkColumns->num_rows == 0) {
    $conn->query("ALTER TABLE `eventos` ADD `descripcion` text DEFAULT NULL");
}

$checkColumns = $conn->query("SHOW COLUMNS FROM `eventos` LIKE 'estado'");
if ($checkColumns->num_rows == 0) {
    $conn->query("ALTER TABLE `eventos` ADD `estado` varchar(20) DEFAULT 'Activo'");
}

// --- BORRAR EVENTO ---
if (isset($_GET['borrar']) && is_numeric($_GET['borrar'])) {
    $id = intval($_GET['borrar']);
    // -- Limpieza segura de dependencias para evitar errores de FK --
    // 1) Protocolo/clausulas: eliminar archivos físicos y registros
    $stmt = $conn->prepare("SELECT archivo FROM protocolo_clausulas WHERE evento_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($archivo_nombre);
    $files_to_unlink = [];
    while ($stmt->fetch()) {
        if ($archivo_nombre) $files_to_unlink[] = $archivo_nombre;
    }
    $stmt->close();
    foreach ($files_to_unlink as $f) {
        $path = __DIR__ . '/uploads/protocolo_clausulas/' . $f;
        if (file_exists($path)) @unlink($path);
    }
    $stmt = $conn->prepare("DELETE FROM protocolo_clausulas WHERE evento_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 2) Archivos subidos por proveedores relacionados (proveedor_requisito_evento)
    $stmt = $conn->prepare("SELECT archivo FROM proveedor_requisito_evento WHERE evento_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($prov_archivo);
    $prov_files = [];
    while ($stmt->fetch()) {
        if ($prov_archivo) $prov_files[] = $prov_archivo;
    }
    $stmt->close();
    foreach ($prov_files as $f) {
        $path = __DIR__ . '/uploads/proveedor_requisitos/' . $f;
        if (file_exists($path)) @unlink($path);
    }
    $stmt = $conn->prepare("DELETE FROM proveedor_requisito_evento WHERE evento_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 3) Empleados asociados al evento (tabla intermedia evento_empleado)
    $stmt = $conn->prepare("DELETE FROM empleados_evento WHERE evento_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 4) Proveedores asignados al evento
    $stmt = $conn->prepare("DELETE FROM proveedores_evento WHERE evento_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 5) Requisitos asignados al evento
    $stmt = $conn->prepare("DELETE FROM evento_requisito WHERE evento_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 6) Empleados_proveedor asociados (si existiera columna evento_id)
    // Mantener compatibilidad: si la columna no existe, la consulta no afecta filas.
    $stmt = $conn->prepare("DELETE FROM empleados_proveedor WHERE evento_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // Finalmente, eliminar el evento
    $stmt = $conn->prepare("DELETE FROM eventos WHERE id = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        // Algo falló al intentar borrar el evento. Mostrar error útil.
        $err = htmlspecialchars($conn->error);
        echo "<div style='color:red;padding:10px;'>No se pudo eliminar el evento (error de base de datos): $err</div>";
        $stmt->close();
        exit;
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- AGREGAR EVENTO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_evento'])) {
    $nombre = trim($_POST['nombre_evento']);
    $lugar = trim($_POST['lugar_evento']);
    $fecha = trim($_POST['fecha_evento']);
    $fecha_fin = trim($_POST['fecha_fin_evento'] ?? '');
    $hora = trim($_POST['hora_evento']);
    $hora_fin = trim($_POST['hora_fin_evento'] ?? '');
    $descripcion = trim($_POST['descripcion_evento'] ?? '');
    $estado = trim($_POST['estado_evento'] ?? 'Activo');
    $requisitos_evento = $_POST['requisitos_evento'] ?? [];
    
    if ($nombre !== "" && $lugar !== "" && $fecha !== "" && $hora !== "") {
    $stmt = $conn->prepare("INSERT INTO eventos (nombre, lugar, fecha, fecha_fin, hora, hora_fin, descripcion, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $nombre, $lugar, $fecha, $fecha_fin, $hora, $hora_fin, $descripcion, $estado);
        $stmt->execute();
        $evento_id = $conn->insert_id;
        $stmt->close();
        // Guardar requisitos seleccionados
        if (!empty($requisitos_evento)) {
            $stmt = $conn->prepare("INSERT INTO evento_requisito (evento_id, requisito_id, estado) VALUES (?, ?, 'pendiente')");
            foreach ($requisitos_evento as $req_id) {
                $stmt->bind_param("ii", $evento_id, $req_id);
                $stmt->execute();
            }
            $stmt->close();
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// --- OBTENER EVENTOS ---
$eventos = [];
$res = $conn->query("SELECT id, nombre, lugar, fecha, fecha_fin, hora, hora_fin, descripcion, estado FROM eventos ORDER BY fecha DESC, hora DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $eventos[] = $row;
    }
    $res->free();
} else {
    echo "<div style='color:red;padding:10px;'>Error en la consulta de eventos: " . $conn->error . "</div>";
}

// Obtener requisitos base para mostrar en el formulario de evento
$requisitos_base = [];
$res = $conn->query("SELECT id, nombre FROM requisitos ORDER BY nombre");
while ($row = $res->fetch_assoc()) {
    $requisitos_base[] = $row;
}

// Crear requisitos base si no existen y obtener sus IDs
$requisitos_def = [
  'Seguro de Vida Obligatorio (SVO)',
  'Certificado de ART de todos los empleados con nómina',
  'Comprobante de pago del F931',
  'Cláusulas de no repetición (dependencia) a favor de las empresas listadas',
  'Seguro de Accidentes Personales con cláusulas de no repetición',
  'Constancia del último pago del seguro de AP',
  'Montos mínimos y asistencia médica/farmacéutica',
  'Cláusulas de no repetición (independientes) a favor de las empresas listadas'
];
$requisitos_ids = [];
foreach ($requisitos_def as $nombre) {
  $stmt = $conn->prepare("SELECT id FROM requisitos WHERE nombre = ?");
  $stmt->bind_param("s", $nombre);
  $stmt->execute();
  $stmt->bind_result($id);
  if ($stmt->fetch()) {
    $requisitos_ids[$nombre] = $id;
  } else {
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO requisitos (nombre) VALUES (?)");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $requisitos_ids[$nombre] = $conn->insert_id;
  }
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>Gestión de Eventos - RADEP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet" />
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, #a9bec7 0%, #8ba3b0 50%, #6b8a9a 100%);
            min-height: 100vh;
        }
        
        .evento-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
            border: 2px solid #5092ab;
            box-shadow: 0 8px 32px rgba(80, 146, 171, 0.15);
            backdrop-filter: blur(10px);
        }
        .evento-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(80, 146, 171, 0.3);
            border-color: #3b82f6;
        }
        
        .estado-activo {
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        .estado-pendiente {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }
        .estado-completado {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            box-shadow: 0 4px 15px rgba(107, 114, 128, 0.4);
        }
        .estado-cancelado {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }
        
        .delete-btn {
            color: #dc2626;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 8px;
            border-radius: 8px;
        }
        .delete-btn:hover {
            color: #991b1b;
            transform: scale(1.15);
            background: rgba(220, 38, 38, 0.1);
        }
        
        .edit-btn {
            color: #5092ab;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 8px;
            border-radius: 8px;
        }
        .edit-btn:hover {
            color: #3b82f6;
            transform: scale(1.15);
            background: rgba(59, 130, 246, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            color: white;
            font-weight: bold;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 25px rgba(80, 146, 171, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(80, 146, 171, 0.5);
        }
        
        .btn-primary:active {
            transform: translateY(-1px);
        }
        
        .stat-card {
            background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
            border: 2px solid #5092ab;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(80, 146, 171, 0.15);
            backdrop-filter: blur(10px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(80, 146, 171, 0.1), transparent);
            transform: rotate(45deg);
            transition: transform 0.8s;
        }
        
        .stat-card:hover::before {
            transform: rotate(45deg) translate(50%, 50%);
        }
        
        .stat-card:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 15px 40px rgba(80, 146, 171, 0.3);
            border-color: #3b82f6;
        }
        
        .modal-content {
            background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
            border: 2px solid #5092ab;
            backdrop-filter: blur(20px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        }
        
        .form-input {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border: 2px solid #5092ab;
            border-radius: 12px;
            padding: 12px 16px;
            color: #374151;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1), inset 0 2px 4px rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
        }
        
        .header-title {
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: shimmer 2s ease-in-out infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }
        
        .floating-add-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            border: none;
            color: white;
            font-size: 1.8rem;
            cursor: pointer;
            box-shadow: 0 10px 40px rgba(80, 146, 171, 0.4);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 10px 40px rgba(80, 146, 171, 0.4); }
            50% { box-shadow: 0 10px 40px rgba(80, 146, 171, 0.6), 0 0 0 10px rgba(80, 146, 171, 0.1); }
            100% { box-shadow: 0 10px 40px rgba(80, 146, 171, 0.4); }
        }
        
        .floating-add-btn:hover {
            transform: translateY(-8px) scale(1.1);
            box-shadow: 0 15px 50px rgba(80, 146, 171, 0.6);
            animation: none;
        }
        
        .floating-add-btn:active {
            transform: translateY(-4px) scale(1.05);
        }
        
        .empty-state {
            background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
            border-radius: 24px;
            border: 2px solid #5092ab;
            backdrop-filter: blur(10px);
            box-shadow: 0 15px 40px rgba(80, 146, 171, 0.2);
            animation: fadeInUp 0.8s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stat-icon {
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
        
        .evento-card {
            animation: slideInUp 0.6s ease-out;
            animation-fill-mode: both;
        }
        
        .evento-card:nth-child(1) { animation-delay: 0.1s; }
        .evento-card:nth-child(2) { animation-delay: 0.2s; }
        .evento-card:nth-child(3) { animation-delay: 0.3s; }
        .evento-card:nth-child(4) { animation-delay: 0.4s; }
        .evento-card:nth-child(5) { animation-delay: 0.5s; }
        .evento-card:nth-child(6) { animation-delay: 0.6s; }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal {
            backdrop-filter: blur(10px);
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            animation: slideInDown 0.4s ease-out;
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .back-link {
            position: relative;
            overflow: hidden;
        }
        
        .back-link::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #5092ab, #3b82f6);
            transition: width 0.3s ease;
        }
        
        .back-link:hover::before {
            width: 100%;
        }
        
        .rol-badge {
            position: relative;
            overflow: hidden;
        }
        
        .rol-badge::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        .rol-badge:hover::before {
            left: 100%;
        }
        
        @media (max-width: 768px) {
            .floating-add-btn {
                bottom: 1rem;
                right: 1rem;
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .stat-card:hover {
                transform: translateY(-3px) scale(1.02);
            }
            
            .evento-card:hover {
                transform: translateY(-5px) scale(1.01);
            }
        }
    </style>
</head>
<body class="bg-[#a9bec7] min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-[#5092ab] flex items-center px-0 py-0 h-28 border-b-2 border-black/20">
        <img src="https://postimage.me/images/2025/06/25/Diseno-sin-titulo.png" alt="LOGO RADEP" class="h-full object-contain">
    </header>

    <main class="flex-grow px-4 py-6">
        <div class="max-w-7xl mx-auto">
            <!-- Navegación -->
            <nav class="mb-6">
                <a href="dashboard_empresa.php" class="inline-flex items-center space-x-2 text-[#5092ab] hover:text-[#3b82f6] transition font-semibold">
                    <i class="fas fa-arrow-left"></i>
                    <span>Volver al Dashboard</span>
                </a>
            </nav>

            <!-- Título -->
            <div class="text-center mb-8">
                <h1 class="text-[#5092ab] font-semibold text-3xl mb-2 flex items-center justify-center gap-3">
                    <i class="fas fa-calendar-alt"></i>
                    Gestión de Eventos
                </h1>
            </div>

            <!-- Estadísticas -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="stat-card p-4">
                    <div class="flex items-center">
                        <i class="fas fa-calendar-check text-2xl text-[#5092ab] mr-3"></i>
                        <div>
                            <p class="text-sm text-gray-600">Total Eventos</p>
                            <p class="text-2xl font-bold text-[#5092ab]"><?= count($eventos) ?></p>
                        </div>
                    </div>
                </div>
                <div class="stat-card p-4">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-2xl text-green-600 mr-3"></i>
                        <div>
                            <p class="text-sm text-gray-600">Activos</p>
                            <p class="text-2xl font-bold text-[#5092ab]"><?= count(array_filter($eventos, function($e) { return ($e['estado'] ?? 'Activo') === 'Activo'; })) ?></p>
                        </div>
                    </div>
                </div>
                <div class="stat-card p-4">
                    <div class="flex items-center">
                        <i class="fas fa-clock text-2xl text-yellow-600 mr-3"></i>
                        <div>
                            <p class="text-sm text-gray-600">Pendientes</p>
                            <p class="text-2xl font-bold text-[#5092ab]"><?= count(array_filter($eventos, function($e) { return ($e['estado'] ?? 'Activo') === 'Pendiente'; })) ?></p>
                        </div>
                    </div>
                </div>
                <div class="stat-card p-4">
                    <div class="flex items-center">
                        <i class="fas fa-flag-checkered text-2xl text-gray-600 mr-3"></i>
                        <div>
                            <p class="text-sm text-gray-600">Completados</p>
                            <p class="text-2xl font-bold text-[#5092ab]"><?= count(array_filter($eventos, function($e) { return ($e['estado'] ?? 'Activo') === 'Completado'; })) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Listado de eventos -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-[#5092ab]">Eventos Registrados</h2>
                    <div class="flex items-center gap-3">
                        <button onclick="document.getElementById('modalEvento').style.display='flex'" 
                                class="btn-primary flex items-center space-x-2">
                            <i class="fas fa-plus"></i>
                            <span>Nuevo Evento</span>
                        </button>
                        <a href="requisitos.php" class="btn-primary flex items-center space-x-2" style="background:#3b82f6;">
                            <i class="fas fa-cog"></i>
                            <span>Gestionar Requisitos</span>
                        </a>
                    </div>
                </div>
                
                <?php if (count($eventos) > 0): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach($eventos as $evento): ?>
                            <?php 
                            $estado = $evento['estado'] ?? 'Activo';
                            $estadoClass = '';
                            $estadoIcon = '';
                            switch($estado) {
                                case 'Activo':
                                    $estadoClass = 'estado-activo';
                                    $estadoIcon = 'fas fa-check-circle';
                                    break;
                                case 'Pendiente':
                                    $estadoClass = 'estado-pendiente';
                                    $estadoIcon = 'fas fa-clock';
                                    break;
                                case 'Completado':
                                    $estadoClass = 'estado-completado';
                                    $estadoIcon = 'fas fa-flag-checkered';
                                    break;
                                case 'Cancelado':
                                    $estadoClass = 'estado-cancelado';
                                    $estadoIcon = 'fas fa-times-circle';
                                    break;
                                default:
                                    $estadoClass = 'estado-activo';
                                    $estadoIcon = 'fas fa-check-circle';
                            }
                            ?>
                            <div class="evento-card rounded-xl p-6">
                                <div class="flex items-start justify-between mb-4">
                                    <h3 class="text-lg font-bold text-[#5092ab]">
                                        <?= htmlspecialchars($evento['nombre']) ?>
                                    </h3>
                                    <div class="flex space-x-2">
                                        <a href="evento_detalle.php?id=<?= $evento['id'] ?>" 
                                           class="edit-btn" 
                                           title="Ver detalles">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="?borrar=<?= $evento['id'] ?>" 
                                           onclick="return confirm('¿Seguro que deseas borrar este evento?');"
                                           class="delete-btn" 
                                           title="Borrar evento">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="space-y-3">
                                    <div class="flex items-center text-gray-700">
                                        <i class="fas fa-map-marker-alt w-5 text-[#5092ab]"></i>
                                        <span class="ml-2"><?= htmlspecialchars($evento['lugar']) ?></span>
                                    </div>
                                    
                                    <div class="flex items-center text-gray-700">
                                        <i class="fas fa-calendar w-5 text-[#5092ab]"></i>
                                        <span class="ml-2">Inicio: <?= date('d/m/Y', strtotime($evento['fecha'])) ?></span>
                                        <?php if (!empty($evento['fecha_fin'])): ?>
                                            <span class="ml-2">Fin: <?= date('d/m/Y', strtotime($evento['fecha_fin'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex items-center text-gray-700">
                                        <i class="fas fa-clock w-5 text-[#5092ab]"></i>
                                        <span class="ml-2">Inicio: <?= date('H:i', strtotime($evento['hora'])) ?></span>
                                        <?php if (!empty($evento['hora_fin'])): ?>
                                            <span class="ml-2">Fin: <?= date('H:i', strtotime($evento['hora_fin'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($evento['descripcion'])): ?>
                                        <div class="flex items-start text-gray-700">
                                            <i class="fas fa-align-left w-5 text-[#5092ab] mt-1"></i>
                                            <span class="ml-2 text-sm"><?= htmlspecialchars($evento['descripcion']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mt-4 pt-4 border-t border-[#5092ab]/20">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium text-white <?= $estadoClass ?>">
                                        <i class="<?= $estadoIcon ?> mr-1"></i>
                                        <?= htmlspecialchars($estado) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-calendar-times text-6xl text-[#5092ab]/50 mb-4"></i>
                        <h3 class="text-xl font-semibold text-[#5092ab] mb-2">No hay eventos registrados</h3>
                        <p class="text-gray-600 mb-4">Comienza agregando tu primer evento</p>
                        <button onclick="document.getElementById('modalEvento').style.display='flex'" 
                                class="btn-primary">
                            Agregar Evento
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Botón flotante para agregar evento -->
    <button class="floating-add-btn" onclick="document.getElementById('modalEvento').style.display='flex'" 
            title="Agregar nuevo evento">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Modal para agregar evento -->
    <div id="modalEvento" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display:none;">
        <div class="modal-content rounded-xl shadow-2xl p-6 w-full max-w-md mx-4 relative" style="max-height:90vh; overflow-y:auto;">
            <button onclick="document.getElementById('modalEvento').style.display='none'" 
                    class="absolute top-4 right-4 text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
            
            <h2 class="text-xl font-bold text-[#5092ab] mb-6 flex items-center">
                <i class="fas fa-plus-circle text-[#5092ab] mr-2"></i>
                Nuevo Evento
            </h2>
            
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-[#5092ab] mb-1">Nombre del evento</label>
                    <input type="text" name="nombre_evento" placeholder="Ej: Reunión de equipo" required 
                           class="form-input w-full"/>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-[#5092ab] mb-1">Lugar</label>
                    <input type="text" name="lugar_evento" placeholder="Ej: Sala de conferencias" required 
                           class="form-input w-full"/>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-[#5092ab] mb-1">Fecha de inicio</label>
                        <input type="date" name="fecha_evento" required 
                               class="form-input w-full"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#5092ab] mb-1">Fecha de finalización</label>
                        <input type="date" name="fecha_fin_evento" 
                               class="form-input w-full"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#5092ab] mb-1">Hora</label>
                        <input type="time" name="hora_evento" required 
                               class="form-input w-full"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#5092ab] mb-1">Hora de finalización</label>
                        <input type="time" name="hora_fin_evento" 
                               class="form-input w-full"/>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-[#5092ab] mb-1">Descripción (opcional)</label>
                    <textarea name="descripcion_evento" placeholder="Descripción del evento..." rows="3"
                              class="form-input w-full"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-[#5092ab] mb-1">Estado</label>
                    <select name="estado_evento" class="form-input w-full">
                        <option value="Activo">Activo</option>
                        <option value="Pendiente">Pendiente</option>
                        <option value="Completado">Completado</option>
                        <option value="Cancelado">Cancelado</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-[#5092ab] mb-1">Requisitos necesarios para este evento</label>
                    <div class="max-h-48 overflow-y-auto grid grid-cols-1 gap-2 p-2 bg-white/60 rounded border border-[#5092ab]/30">
                        <div class="font-semibold text-[#5092ab] mt-2 mb-1">Trabajadores en Relación de Dependencia</div>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="requisitos_evento[]" value="<?= $requisitos_ids['Seguro de Vida Obligatorio (SVO)'] ?>" checked>
                            <span>Seguro de Vida Obligatorio (SVO)</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="requisitos_evento[]" value="<?= $requisitos_ids['Certificado de ART de todos los empleados con nómina'] ?>" checked>
                            <span>Certificado de ART de todos los empleados con nómina</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="requisitos_evento[]" value="<?= $requisitos_ids['Comprobante de pago del F931'] ?>" checked>
                            <span>Comprobante de pago del F931</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="requisitos_evento[]" value="<?= $requisitos_ids['Cláusulas de no repetición (dependencia) a favor de las empresas listadas'] ?>" checked>
                            <span>Cláusulas de no repetición (dependencia) a favor de las empresas listadas</span>
                        </label>
                        <div class="font-semibold text-[#5092ab] mt-4 mb-1">Trabajadores Independientes</div>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="requisitos_evento[]" value="<?= $requisitos_ids['Seguro de Accidentes Personales con cláusulas de no repetición'] ?>" checked>
                            <span>Seguro de Accidentes Personales con cláusulas de no repetición</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="requisitos_evento[]" value="<?= $requisitos_ids['Constancia del último pago del seguro de AP'] ?>" checked>
                            <span>Constancia del último pago del seguro de AP</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="requisitos_evento[]" value="<?= $requisitos_ids['Montos mínimos y asistencia médica/farmacéutica'] ?>" checked>
                            <span>Montos mínimos y asistencia médica/farmacéutica</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="requisitos_evento[]" value="<?= $requisitos_ids['Cláusulas de no repetición (independientes) a favor de las empresas listadas'] ?>" checked>
                            <span>Cláusulas de no repetición (independientes) a favor de las empresas listadas</span>
                        </label>
                    </div>
                </div>
                
                <div class="flex space-x-3 pt-4">
                    <button type="submit" name="nuevo_evento" 
                            class="flex-1 btn-primary">
                        <i class="fas fa-save mr-2"></i>Guardar Evento
                    </button>
                    <button type="button" onclick="document.getElementById('modalEvento').style.display='none'"
                            class="px-4 py-2 border border-[#5092ab] text-[#5092ab] rounded-lg hover:bg-[#5092ab] hover:text-white transition">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Cerrar modal al hacer clic fuera
        document.getElementById('modalEvento').addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
        
        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('modalEvento').style.display = 'none';
            }
        });
    </script>
</body>
</html> 