<?php
session_start();
require_once 'dbconn.php';
// Migración automática: agregar columna tipo_relacion en la tabla `empleados` si no existe
$checkColumn = $conn->query("SHOW COLUMNS FROM empleados LIKE 'tipo_relacion'");
if ($checkColumn && $checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE empleados ADD tipo_relacion VARCHAR(20) DEFAULT 'dependencia'");
}
// Asegurar que exista columna empresa_id para relacionarlo con la empresa (si falta, la agregamos)
$checkEmpresaCol = $conn->query("SHOW COLUMNS FROM empleados LIKE 'empresa_id'");
if ($checkEmpresaCol && $checkEmpresaCol->num_rows == 0) {
    $conn->query("ALTER TABLE empleados ADD empresa_id INT NULL");
}
require_once 'vendor/autoload.php'; // PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\IOFactory;

// Solo permite acceso al rol 'empresa' (ajustar según el sistema de roles)
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'empresa') {
    header("Location: index.php");
    exit();
}

$mensaje = '';
$editando = false;
$empleado_editar = null;

// Obtener empresa_id de forma robusta (debe ir antes de cualquier procesamiento)
if (isset($_POST['empresa_id']) && is_numeric($_POST['empresa_id'])) {
    $empresa_id = intval($_POST['empresa_id']);
} else {
    $empresa_id = $_SESSION['usuario_id'] ?? $_SESSION['id'];
}

// Procesar carga masiva de empleados por Excel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_excel_empleados'])) {
    require_once 'vendor/autoload.php'; // PhpSpreadsheet
    $success_excel = '';
    $error_excel = '';
    $debug_excel = '';
    // Depuración: mostrar info del archivo recibido
    if (isset($_FILES['archivo_excel'])) {
        $debug_excel = 'Archivo recibido: ' . htmlspecialchars($_FILES['archivo_excel']['name']) . ' | Tamaño: ' . $_FILES['archivo_excel']['size'] . ' bytes | Error: ' . $_FILES['archivo_excel']['error'];
    }
    if (isset($_FILES['archivo_excel']) && $_FILES['archivo_excel']['error'] == 0) {
        $fileTmp = $_FILES['archivo_excel']['tmp_name'];
        try {
            $spreadsheet = IOFactory::load($fileTmp);
            $sheet = $spreadsheet->getActiveSheet();
            $rowCount = 0;
            $rowLeidas = 0;
            $empleadosDetectados = 0;
            foreach ($sheet->getRowIterator() as $i => $row) {
                $rowLeidas++;
                // Ignorar la primera fila si es encabezado
                if ($i == 1) continue;
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $data = [];
                foreach ($cellIterator as $cell) {
                    $data[] = $cell->getValue();
                }
                    // Suponiendo columnas: nombre, apellido, cuil, puesto, tipo_relacion (opcional)
                    if (count($data) >= 4 && $data[0] && $data[1] && $data[2] && $data[3]) {
                        $empleadosDetectados++;
                        // Normalizar el valor: quitar espacios y pasar a minúsculas
                        $tipo_relacion_raw = isset($data[4]) ? strtolower(trim($data[4])) : '';
                        // Permitir variantes con espacios y mayúsculas/minúsculas
                        if (preg_match('/^monotributista$/i', $tipo_relacion_raw)) {
                            $tipo_relacion = 'monotributista';
                        } elseif (preg_match('/^dependencia$/i', $tipo_relacion_raw)) {
                            $tipo_relacion = 'dependencia';
                        } else {
                            $tipo_relacion = 'dependencia';
                        }
                    // Verificar CUIL único dentro de la misma empresa
                    $stmt = $conn->prepare("SELECT id FROM empleados WHERE cuil = ? AND empresa_id = ?");
                    $stmt->bind_param("si", $data[2], $empresa_id);
                    $stmt->execute();
                    $stmt->store_result();
                    if ($stmt->num_rows == 0) {
                        $stmt->close();
                        $stmt = $conn->prepare("INSERT INTO empleados (empresa_id, nombre, apellido, cuil, puesto, tipo_relacion, fecha_alta) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->bind_param("isssss", $empresa_id, $data[0], $data[1], $data[2], $data[3], $tipo_relacion);
                        $stmt->execute();
                        $stmt->close();
                        $rowCount++;
                    } else {
                        $stmt->close();
                    }
                }
            }
            // Notificación de archivo cargado
            $mensaje = "Archivo cargado correctamente ($rowLeidas filas leídas, $empleadosDetectados empleados detectados, $rowCount empleados nuevos).";
            if ($rowCount > 0) {
                $success_excel = "Empleados cargados correctamente: $rowCount";
            } else {
                $error_excel = "No se cargó ningún empleado nuevo. Verifica el formato y los CUIL duplicados.";
            }
        } catch (Exception $e) {
            $error_excel = "Error al procesar el archivo: " . $e->getMessage();
        }
    } else {
        $error_excel = 'Error al subir el archivo Excel.';
    }
}

// Procesar el formulario de alta de empleado
// Procesar el formulario de alta (empresa)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_empleado_empresa']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_empleado_proveedor']))) {
    $nombre = trim($_POST['nombre_empleado_prov']);
    $apellido = trim($_POST['apellido_empleado_prov']);
    $cuil = trim($_POST['cuil_empleado_prov']);
    $puesto = trim($_POST['puesto_empleado_prov']);
    $tipo_relacion = $_POST['tipo_relacion'] ?? 'dependencia';
    
    if ($nombre && $apellido && $cuil && $puesto) {
        // Verifica que el usuario no exista ya
        $stmt = $conn->prepare("SELECT id FROM empleados WHERE cuil = ? AND empresa_id = ?");
        $stmt->bind_param("si", $cuil, $empresa_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $mensaje = "El CUIL ya está en uso.";
        } else {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO empleados (empresa_id, nombre, apellido, cuil, puesto, tipo_relacion, fecha_alta) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("isssss", $empresa_id, $nombre, $apellido, $cuil, $puesto, $tipo_relacion);
            if ($stmt->execute()) {
                $mensaje = "Empleado agregado correctamente.";
            } else {
                $mensaje = "Error al guardar el empleado: " . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $mensaje = "Todos los campos son obligatorios.";
    }
}

// Eliminar empleado
if (isset($_POST['eliminar_empleado']) && isset($_POST['empleado_id'])) {
    $stmt = $conn->prepare("DELETE FROM empleados WHERE id = ? AND empresa_id = ?");
    $stmt->bind_param("ii", $_POST['empleado_id'], $empresa_id);
    if ($stmt->execute()) {
        $mensaje = "Empleado eliminado correctamente.";
    } else {
        $mensaje = "Error al eliminar el empleado.";
    }
    $stmt->close();
}

// Editar empleado (mostrar datos en formulario)
if (isset($_POST['editar_empleado']) && isset($_POST['empleado_id'])) {
    $stmt = $conn->prepare("SELECT id, nombre, apellido, cuil, puesto, tipo_relacion FROM empleados WHERE id = ? AND empresa_id = ?");
    $stmt->bind_param("ii", $_POST['empleado_id'], $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $empleado_editar = $row;
        $editando = true;
    }
    $stmt->close();
}

// Guardar edición de empleado
if (isset($_POST['guardar_edicion']) && isset($_POST['empleado_id'])) {
    $nombre = trim($_POST['nombre_empleado_prov']);
    $apellido = trim($_POST['apellido_empleado_prov']);
    $cuil = trim($_POST['cuil_empleado_prov']);
    $puesto = trim($_POST['puesto_empleado_prov']);
    $tipo_relacion = $_POST['tipo_relacion'] ?? 'dependencia';
    if ($nombre && $apellido && $cuil && $puesto) {
        // Verifica que el CUIL no esté en otro empleado
        $stmt = $conn->prepare("SELECT id FROM empleados WHERE cuil = ? AND id != ? AND empresa_id = ?");
        $stmt->bind_param("sii", $cuil, $_POST['empleado_id'], $empresa_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $mensaje = "El CUIL ya está en uso por otro empleado.";
        } else {
            $stmt->close();
            $stmt = $conn->prepare("UPDATE empleados SET nombre = ?, apellido = ?, cuil = ?, puesto = ?, tipo_relacion = ? WHERE id = ? AND empresa_id = ?");
            $stmt->bind_param("sssssii", $nombre, $apellido, $cuil, $puesto, $tipo_relacion, $_POST['empleado_id'], $empresa_id);
            if ($stmt->execute()) {
                $mensaje = "Empleado editado correctamente.";
                $editando = false;
            } else {
                $mensaje = "Error al editar el empleado: " . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $mensaje = "Todos los campos son obligatorios.";
    }
}

// Obtener lista de empleados del proveedor actual

$empleados = [];
$stmt = $conn->prepare("SELECT id, nombre, apellido, cuil, puesto, tipo_relacion, fecha_alta FROM empleados WHERE empresa_id = ? ORDER BY fecha_alta DESC");
$stmt->bind_param("i", $empresa_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $empleados[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alta de Empleados - Proveedor RADEP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, #a9bec7 0%, #8ba3b0 50%, #6b8a9a 100%);
            min-height: 100vh;
        }
        
        .card {
            background: #d9e3e6;
            border: 2px solid #5092ab;
            border-radius: 8px;
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
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(80, 146, 171, 0.5);
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #5092ab;
            box-shadow: 0 0 0 3px rgba(80, 146, 171, 0.1);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .empleado-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .empleado-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
    </style>
    <script>
        // Limpiar formulario y recargar la página
        function limpiarFormulario() {
            window.location.href = 'alta_empleados.php';
        }
    </script>
</head>
<body class="min-h-screen">
    <!-- Header -->
    <header class="bg-[#5092ab] flex flex-col sm:flex-row items-center px-2 sm:px-0 py-0 h-auto sm:h-20 border-b-2 border-black/20 w-full">
        <div class="flex items-center w-full sm:w-auto justify-center sm:justify-start h-16 sm:h-20 px-1 sm:px-4 mb-2 sm:mb-0">
            <div class="flex items-center h-16 sm:h-20 px-1 sm:px-4">
                <img src="images/logo-radep.svg" alt="Logo RADEP" class="block sm:hidden" style="height:40px; max-width:120px; object-fit:contain;"/>
                <img src="images/logo-radep.svg" alt="Logo RADEP" class="hidden sm:block" style="height:70px; max-width:220px; object-fit:contain;"/>
            </div>
        </div>
        <div class="ml-0 sm:ml-auto flex flex-col sm:flex-row items-center space-y-1 sm:space-y-0 sm:space-x-2 w-full sm:w-auto justify-center sm:justify-end mr-0 sm:mr-2">
            <a href="dashboard_empresa.php" class="flex items-center border border-white/30 h-9 sm:h-10 px-2 space-x-2 min-w-[120px] sm:min-w-[180px] rounded-md bg-white/20 backdrop-blur-sm text-white text-xs sm:text-sm font-semibold transition hover:bg-white/30" style="text-decoration:none;">
                <i class="fas fa-arrow-left mr-1"></i>
                <span>Volver al Dashboard</span>
            </a>
            <a href="logout.php" class="flex items-center border border-white/30 h-9 sm:h-10 px-2 space-x-2 min-w-[120px] sm:min-w-[180px] rounded-md bg-white/20 backdrop-blur-sm text-white text-xs sm:text-sm font-semibold transition hover:bg-white/30 ml-0 sm:ml-2" style="text-decoration:none;">
                <i class="fas fa-sign-out-alt mr-1"></i>
                <span>Cerrar Sesión</span>
            </a>
        </div>
    </header>

    <div class="container mx-auto px-4 py-8">
        <!-- Mensajes de éxito y error -->
        <?php if ($mensaje): ?>
            <div class="bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2 mb-6">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($mensaje) ?></span>
            </div>
        <?php endif; ?>

        <!-- Título principal -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-[#5092ab] mb-4">
                <i class="fas fa-user-plus mr-4"></i>Alta de Empleados
            </h1>
            <p class="text-lg text-gray-600">Gestiona tus empleados en el sistema RADEP</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Bloque para carga masiva de empleados por Excel -->
            <div class="card mb-8">
                <div class="bg-[#5092ab] font-semibold text-white px-4 py-3 border-b border-[#5092ab] rounded-t-lg">
                    <h2 class="text-xl flex items-center">
                        <i class="fas fa-file-upload mr-2"></i>Carga masiva de empleados
                    </h2>
                </div>
                <div class="p-6">
                    <form method="POST" enctype="multipart/form-data" class="space-y-4">
                        <div class="form-group">
                            <label class="form-label">Subir archivo Excel (.xlsx o .csv)</label>
                            <input type="file" name="archivo_excel" accept=".xlsx,.csv" class="form-input" required />
                            <div class="mt-2">
                                <a href="empleados_ej.xlsx" download class="btn-secondary px-4 py-2 text-sm" style="background:#3b82f6; color:white;">
                                    <i class="fas fa-download"></i> Descargar Excel ejemplo
                                </a>
                                <span class="text-xs text-gray-500 ml-2">Descarga, completa y vuelve a subir el archivo.</span>
                            </div>
                        </div>
                        <button type="submit" name="subir_excel_empleados" class="btn-primary">
                            <i class="fas fa-file-upload"></i> Cargar empleados desde Excel
                        </button>
                    </form>
                    <?php if (isset($debug_excel) && $debug_excel): ?>
                        <div class="bg-yellow-300 text-black px-4 py-2 rounded mt-4"> <?= htmlspecialchars($debug_excel) ?> </div>
                    <?php endif; ?>
                    <?php if (isset($success_excel) && $success_excel): ?>
                        <div class="bg-green-500 text-white px-4 py-2 rounded mt-4"> <?= htmlspecialchars($success_excel) ?> </div>
                    <?php endif; ?>
                    <?php if (isset($error_excel) && $error_excel): ?>
                        <div class="bg-red-500 text-white px-4 py-2 rounded mt-4"> <?= htmlspecialchars($error_excel) ?> </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Formulario de alta -->
            <div class="card">
                <div class="bg-[#5092ab] font-semibold text-white px-4 py-3 border-b border-[#5092ab] rounded-t-lg">
                    <h2 class="text-xl flex items-center">
                        <i class="fas fa-user-plus mr-2"></i>Nuevo Empleado
                    </h2>
                </div>
                <div class="p-6">
                    <form method="POST" class="space-y-4" id="form-empleado">
                        <input type="hidden" name="agregar_empleado_empresa" value="1">
                        <?php if ($editando && $empleado_editar): ?>
                            <input type="hidden" name="guardar_edicion" value="1">
                            <input type="hidden" name="empleado_id" value="<?= $empleado_editar['id'] ?>">
                        <?php endif; ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="nombre_empleado_prov" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-user mr-1"></i>Nombre *
                                </label>
                                <input type="text" id="nombre_empleado_prov" name="nombre_empleado_prov" value="<?= htmlspecialchars($empleado_editar['nombre'] ?? $_POST['nombre_empleado_prov'] ?? '') ?>" 
                                       class="form-input" required>
                            </div>
                            <div>
                                <label for="apellido_empleado_prov" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-user mr-1"></i>Apellido *
                                </label>
                                <input type="text" id="apellido_empleado_prov" name="apellido_empleado_prov" value="<?= htmlspecialchars($empleado_editar['apellido'] ?? $_POST['apellido_empleado_prov'] ?? '') ?>" 
                                       class="form-input" required>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="cuil_empleado_prov" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-id-card mr-1"></i>CUIL *
                                </label>
                                <input type="text" id="cuil_empleado_prov" name="cuil_empleado_prov" value="<?= htmlspecialchars($empleado_editar['cuil'] ?? $_POST['cuil_empleado_prov'] ?? '') ?>" 
                                       class="form-input" placeholder="12345678901" required>
                            </div>
                            <div>
                                <label for="puesto_empleado_prov" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-briefcase mr-1"></i>Puesto *
                                </label>
                                <input type="text" id="puesto_empleado_prov" name="puesto_empleado_prov" value="<?= htmlspecialchars($empleado_editar['puesto'] ?? $_POST['puesto_empleado_prov'] ?? '') ?>" 
                                       class="form-input" required>
                        <div>
                            <label for="tipo_relacion" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-user-tag mr-1"></i>Tipo de relación *
                            </label>
                            <select id="tipo_relacion" name="tipo_relacion" class="form-input" required>
                                <option value="dependencia" <?= ($empleado_editar['tipo_relacion'] ?? $_POST['tipo_relacion'] ?? '') === 'dependencia' ? 'selected' : '' ?>>Relación de dependencia</option>
                                <option value="monotributista" <?= ($empleado_editar['tipo_relacion'] ?? $_POST['tipo_relacion'] ?? '') === 'monotributista' ? 'selected' : '' ?>>Monotributista</option>
                            </select>
                        </div>
                            </div>
                        </div>
                        <div class="flex space-x-4 pt-4">
                            <button type="submit" class="btn-primary flex-1">
                                <i class="fas fa-save mr-2"></i><?= $editando ? 'Guardar Cambios' : 'Guardar Empleado' ?>
                            </button>
                            <button type="button" class="btn-secondary flex-1" onclick="limpiarFormulario()">
                                <i class="fas fa-undo mr-2"></i>Limpiar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista de empleados -->
            <div class="card lg:col-span-2">
                <div class="bg-[#5092ab] font-semibold text-white px-4 py-3 border-b border-[#5092ab] rounded-t-lg">
                    <h2 class="text-xl flex items-center">
                        <i class="fas fa-users mr-2"></i>Empleados Registrados
                    </h2>
                </div>
                <div class="p-6">
                    <?php
                        // Obtener lista de puestos disponibles para el filtro
                        $puestos = [];
                        foreach ($empleados as $em) { if (!empty($em['puesto'])) $puestos[] = $em['puesto']; }
                        $puestos = array_values(array_unique($puestos));
                    ?>
                    <!-- Sticky filter bar for employees to remain visible while scrolling -->
                    <div id="emp-filter-bar" style="position:sticky; top:96px; z-index:998; display:flex; gap:0.5rem; align-items:center; margin-bottom:1rem; background: rgba(255,255,255,0.9); padding:8px 10px; border-radius:10px; width:100%;">
                        <input id="emp-search" type="search" placeholder="Buscar por nombre, cuil o puesto" class="form-input" style="flex:1; min-width:320px;" />
                        <select id="emp-filter" class="form-input" style="width:140px;">
                            <option value="">Puesto</option>
                            <?php foreach($puestos as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="emp-relacion" class="form-input" style="width:140px;">
                            <option value="">Relación</option>
                            <option value="dependencia">Dependencia</option>
                            <option value="monotributista">Monotributista</option>
                        </select>
                        <button type="button" id="emp-clear" class="btn-secondary" style="padding:8px 10px; font-size:0.95rem;">Limpiar</button>
                    </div>
                    <div id="emp-noresults" class="text-center py-4" style="display:none;">
                        <i class="fas fa-search text-3xl text-gray-400 mb-2"></i>
                        <p class="text-gray-500">No se encontraron empleados con esos criterios.</p>
                    </div>
                    <?php if (count($empleados) > 0): ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-4 max-h-96 overflow-y-auto lg:col-span-2" id="empleados-list">
                            <?php foreach($empleados as $empleado): ?>
                                <div class="empleado-card p-4" data-nombre="<?= htmlspecialchars($empleado['nombre'].' '.$empleado['apellido']) ?>" data-cuil="<?= htmlspecialchars($empleado['cuil']) ?>" data-puesto="<?= htmlspecialchars($empleado['puesto']) ?>" data-tipo-relacion="<?= htmlspecialchars(strtolower($empleado['tipo_relacion'])) ?>">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <h3 class="font-semibold text-gray-800">
                                                <?= htmlspecialchars($empleado['nombre'] . ' ' . $empleado['apellido']) ?>
                                            </h3>
                                            <div class="text-sm text-gray-600 space-y-1 mt-2">
                                                <p><i class="fas fa-id-card mr-1"></i>CUIL: <?= htmlspecialchars($empleado['cuil']) ?></p>
                                                <p><i class="fas fa-briefcase mr-1"></i>Puesto: <?= htmlspecialchars($empleado['puesto']) ?></p>
                                                <p><i class="fas fa-user-tag mr-1"></i>Tipo: <?= (isset($empleado['tipo_relacion']) && $empleado['tipo_relacion'] === 'monotributista') ? 'Monotributista' : 'Relación de dependencia' ?></p>
                                                <p><i class="fas fa-calendar mr-1"></i>Alta: <?= date('d/m/Y', strtotime($empleado['fecha_alta'])) ?></p>
                                            </div>
                                        </div>
                                        <div class="flex flex-col space-y-2 ml-4">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="empleado_id" value="<?= $empleado['id'] ?>">
                                                <input type="hidden" name="empresa_id" value="<?= htmlspecialchars($empresa_id) ?>">
                                                <button type="submit" name="editar_empleado" class="btn-primary px-3 py-1 text-xs" style="background:#3b82f6;">
                                                    <i class="fas fa-edit"></i> Editar
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Seguro que desea eliminar este empleado?');">
                                                <input type="hidden" name="empleado_id" value="<?= $empleado['id'] ?>">
                                                <input type="hidden" name="empresa_id" value="<?= htmlspecialchars($empresa_id) ?>">
                                                <button type="submit" name="eliminar_empleado" class="btn-secondary px-3 py-1 text-xs" style="background:#ef4444; color:white;">
                                                    <i class="fas fa-trash"></i> Eliminar
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-users text-4xl text-gray-400 mb-4"></i>
                            <p class="text-gray-500">No hay empleados registrados aún.</p>
                            <p class="text-gray-400 text-sm mt-2">Comienza agregando el primer empleado usando el formulario.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Información adicional -->
        <div class="mt-8">
            <div class="card">
                <div class="bg-blue-50 p-6 rounded-lg">
                    <h3 class="font-semibold text-blue-800 mb-3 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>Información Importante
                    </h3>
                    <div class="text-blue-700 text-sm space-y-2">
                        <p><strong>•</strong> Todos los campos marcados con * son obligatorios.</p>
                        <p><strong>•</strong> El CUIL debe ser único en el sistema.</p>
                        <p><strong>•</strong> Como proveedor, puedes gestionar el personal que trabajará en los eventos.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-hide mensajes después de 5 segundos
        setTimeout(function() {
            const mensajes = document.querySelectorAll('.bg-green-500, .bg-red-500');
            mensajes.forEach(function(mensaje) {
                mensaje.style.display = 'none';
            });
        }, 5000);

        // Filtrado cliente para empleados (buscar por nombre, cuil, puesto)
        document.addEventListener('DOMContentLoaded', function(){
            var empCards = Array.from(document.querySelectorAll('#empleados-list .empleado-card'));
            var empSearch = document.getElementById('emp-search');
            var empFilter = document.getElementById('emp-filter');
            var empClear = document.getElementById('emp-clear');
            var empNoResults = document.getElementById('emp-noresults');
            var empFilterTimeout = null;
            var empRelacion = document.getElementById('emp-relacion');
            // emp-filter-bar is sticky and should remain visible; hide-on-scroll logic removed
            function applyEmpFilter(){
                var q = (empSearch && empSearch.value || '').trim().toLowerCase();
                var cat = (empFilter && empFilter.value || '').trim().toLowerCase();
                var rel = (empRelacion && empRelacion.value || '').trim().toLowerCase();
                var cards = Array.from(document.querySelectorAll('#empleados-list .empleado-card'));
                cards.forEach(function(c){
                    var n = (c.dataset.nombre||'').toLowerCase();
                    var cu = (c.dataset.cuil||'').toLowerCase();
                    var p = (c.dataset.puesto||'').toLowerCase();
                    var tr = (c.dataset.tipoRelacion || c.dataset.tipo_relacion || '').toLowerCase();
                    var matchText = q === '' || n.indexOf(q) !== -1 || cu.indexOf(q) !== -1 || p.indexOf(q) !== -1;
                    var matchCat = !cat || p === cat;
                    var matchRel = !rel || tr === rel;
                    c.style.display = (matchText && matchCat && matchRel) ? '' : 'none';
                });
                // show/hide no-results message
                if (empNoResults) {
                    var anyVisible = Array.from(document.querySelectorAll('#empleados-list .empleado-card')).some(function(c){ return c.style.display !== 'none'; });
                    empNoResults.style.display = anyVisible ? 'none' : '';
                }
            }
            if (empSearch) empSearch.addEventListener('input', function(){ clearTimeout(empFilterTimeout); empFilterTimeout = setTimeout(applyEmpFilter, 200); });
            if (empFilter) empFilter.addEventListener('change', applyEmpFilter);
            if (empRelacion) empRelacion.addEventListener('change', applyEmpFilter);
            if (empClear) empClear.addEventListener('click', function(){ if (empSearch) empSearch.value=''; if (empFilter) empFilter.value=''; if (empRelacion) empRelacion.value=''; applyEmpFilter(); });
        });
    </script>
</body>
</html>