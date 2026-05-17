<?php
session_start();
require_once 'dbconn.php';

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario'])) {
    header("Location: index.php");
    exit();
}

// Obtener el ID del evento
$evento_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$evento_id) {
    header("Location: eventos.php");
    exit();
}

// Obtener información del evento
$stmt = $conn->prepare("SELECT * FROM eventos WHERE id = ?");
$stmt->bind_param("i", $evento_id);
$stmt->execute();
$result = $stmt->get_result();
$evento = $result->fetch_assoc();
$stmt->close();

if (!$evento) {
    header("Location: eventos.php");
    exit();
}

// Procesar el formulario de edición
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_evento'])) {
    $nombre = trim($_POST['nombre']);
    $lugar = trim($_POST['lugar']);
    $fecha = $_POST['fecha'];
    $fecha_fin = $_POST['fecha_fin'] ?? '';
    $hora = $_POST['hora'];
    $hora_fin = $_POST['hora_fin'] ?? '';
    $descripcion = trim($_POST['descripcion']);
    $estado = $_POST['estado'];
    
    // Validar datos
    if (empty($nombre) || empty($lugar) || empty($fecha) || empty($hora)) {
        $error = "Todos los campos obligatorios deben estar completos.";
    } else {
        // Actualizar evento
        $stmt = $conn->prepare("UPDATE eventos SET nombre = ?, lugar = ?, fecha = ?, fecha_fin = ?, hora = ?, hora_fin = ?, descripcion = ?, estado = ? WHERE id = ?");
        $stmt->bind_param("sssssssii", $nombre, $lugar, $fecha, $fecha_fin, $hora, $hora_fin, $descripcion, $estado, $evento_id);
        
        if ($stmt->execute()) {
            header("Location: evento_detalle.php?id=" . $evento_id . "&success=evento_actualizado");
            exit();
        } else {
            $error = "Error al actualizar el evento: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Evento - RADEP</title>
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
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: border-color 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #5092ab;
            box-shadow: 0 0 0 3px rgba(80, 146, 171, 0.1);
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
    </style>
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
            <a href="eventos.php" class="flex items-center border border-white/30 h-9 sm:h-10 px-2 space-x-2 min-w-[120px] sm:min-w-[180px] rounded-md bg-white/20 backdrop-blur-sm text-white text-xs sm:text-sm font-semibold transition hover:bg-white/30" style="text-decoration:none;">
                <i class="fas fa-arrow-left mr-1"></i>
                <span>Volver a Eventos</span>
            </a>
            <a href="logout.php" class="flex items-center border border-white/30 h-9 sm:h-10 px-2 space-x-2 min-w-[120px] sm:min-w-[180px] rounded-md bg-white/20 backdrop-blur-sm text-white text-xs sm:text-sm font-semibold transition hover:bg-white/30 ml-0 sm:ml-2" style="text-decoration:none;">
                <i class="fas fa-sign-out-alt mr-1"></i>
                <span>Cerrar Sesión</span>
            </a>
        </div>
    </header>

    <div class="container mx-auto px-4 py-8">
        <?php if (isset($error)): ?>
            <div class="bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2 mb-6">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Formulario de Edición -->
        <div class="card max-w-4xl mx-auto">
            <div class="bg-[#5092ab] font-semibold text-white px-4 py-3 border-b border-[#5092ab] rounded-t-lg">
                <h1 class="text-xl flex items-center gap-2">
                    <i class="fas fa-edit"></i>
                    Editar Evento
                </h1>
            </div>
            <div class="p-6">
                <form method="post" class="space-y-6">
                    <input type="hidden" name="actualizar_evento" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Nombre del Evento -->
                        <div>
                            <label class="form-label">Nombre del Evento *</label>
                            <input type="text" 
                                   name="nombre" 
                                   value="<?= htmlspecialchars($evento['nombre']) ?>" 
                                   required 
                                   class="form-input">
                        </div>
                        
                        <!-- Lugar -->
                        <div>
                            <label class="form-label">Lugar *</label>
                            <input type="text" 
                                   name="lugar" 
                                   value="<?= htmlspecialchars($evento['lugar']) ?>" 
                                   required 
                                   class="form-input">
                        </div>
                        
                        <!-- Fecha de inicio -->
                        <div>
                            <label class="form-label">Fecha de inicio *</label>
                            <input type="date" 
                                   name="fecha" 
                                   value="<?= $evento['fecha'] ?>" 
                                   required 
                                   class="form-input">
                        </div>
                        <!-- Fecha de finalización -->
                        <div>
                            <label class="form-label">Fecha de finalización</label>
                            <input type="date" 
                                   name="fecha_fin" 
                                   value="<?= $evento['fecha_fin'] ?? '' ?>" 
                                   class="form-input">
                        </div>
                    </div>
                        <div>
                            <label class="form-label">Hora de inicio *</label>
                            <input type="time" 
                                   name="hora" 
                                   value="<?= $evento['hora'] ?>" 
                                   required 
                                   class="form-input">
                        </div>
                        <div>
                            <label class="form-label">Hora de finalización</label>
                            <input type="time" 
                                   name="hora_fin" 
                                   value="<?= $evento['hora_fin'] ?? '' ?>" 
                                   class="form-input">
                        </div>
                            <div>
                                <label class="form-label">Estado</label>
                                <select name="estado" class="form-input">
                                    <option value="Activo" <?= $evento['estado'] === 'Activo' ? 'selected' : '' ?>>Activo</option>
                                    <option value="Pendiente" <?= $evento['estado'] === 'Pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                    <option value="Completado" <?= $evento['estado'] === 'Completado' ? 'selected' : '' ?>>Completado</option>
                                    <option value="Cancelado" <?= $evento['estado'] === 'Cancelado' ? 'selected' : '' ?>>Cancelado</option>
                                </select>
                            </div>
                    </div>
                    
                    <!-- Botones -->
                    <div class="flex justify-center space-x-4 pt-6 border-t border-[#5092ab]/20">
                        <a href="evento_detalle.php?id=<?= $evento_id ?>" class="px-6 py-3 border border-[#5092ab] text-[#5092ab] rounded-lg hover:bg-[#5092ab] hover:text-white transition">
                            <i class="fas fa-times mr-2"></i>
                            Cancelar
                        </a>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save mr-2"></i>
                            Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html> 