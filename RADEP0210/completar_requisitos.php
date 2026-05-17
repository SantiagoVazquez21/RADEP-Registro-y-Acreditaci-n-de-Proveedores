<?php
session_start();
require_once 'dbconn.php';

// Verificar que el usuario esté logueado y sea proveedor
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'proveedor') {
    header("Location: index.php");
    exit();
}

// Obtener el ID del evento
$evento_id = isset($_GET['evento']) ? intval($_GET['evento']) : 0;

if (!$evento_id) {
    header("Location: dashboard_proveedor.php");
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
    header("Location: dashboard_proveedor.php");
    exit();
}

// Obtener requisitos del evento
$requisitos_evento = [];
$sql = "SELECT r.id, r.nombre, er.estado
        FROM evento_requisito er
        JOIN requisitos r ON er.requisito_id = r.id
        WHERE er.evento_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $evento_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requisitos_evento[] = $row;
}
$stmt->close();

// Obtener el nombre de usuario del proveedor actual
$proveedor_usuario = $_SESSION['usuario_id'] ?? '';

// Procesar subida de archivos de requisitos de proveedor
if (isset($_POST['subir_requisito_proveedor']) && isset($_POST['proveedor_usuario']) && isset($_POST['requisito_id']) && $evento_id) {
    $proveedor_usuario = $_POST['proveedor_usuario'];
    $requisito_id = intval($_POST['requisito_id']);
    $estado = 'pendiente';
    $archivo_nombre = null;
    if (isset($_FILES['archivo_pdf']) && $_FILES['archivo_pdf']['error'] === UPLOAD_ERR_OK) {
        $dir = 'uploads/proveedor_requisitos/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ext = pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION);
        $archivo_nombre = 'evento'.$evento_id.'_prov'.$proveedor_usuario.'_req'.$requisito_id.'_'.time().'.'.$ext;
        move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $dir.$archivo_nombre);
    }
    // Insertar o actualizar registro
    $stmt = $conn->prepare("SELECT id FROM proveedor_requisito_evento WHERE proveedor_usuario=? AND evento_id=? AND requisito_id=?");
    $stmt->bind_param("sii", $proveedor_usuario, $evento_id, $requisito_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $id = $row['id'];
        $sql = "UPDATE proveedor_requisito_evento SET archivo=?, fecha_subida=NOW() WHERE id=?";
        $stmt2 = $conn->prepare($sql);
        $stmt2->bind_param("si", $archivo_nombre, $id);
        $stmt2->execute();
        $stmt2->close();
    } else {
        $sql = "INSERT INTO proveedor_requisito_evento (proveedor_usuario, evento_id, requisito_id, archivo, fecha_subida) VALUES (?, ?, ?, ?, NOW())";
        $stmt2 = $conn->prepare($sql);
        $stmt2->bind_param("siis", $proveedor_usuario, $evento_id, $requisito_id, $archivo_nombre);
        $stmt2->execute();
        $stmt2->close();
    }
    $stmt->close();
    header("Location: completar_requisitos.php?evento=".$evento_id."&success=requisito_completado");
    exit;
}

// Obtener archivos subidos por el proveedor para este evento
$archivos_subidos = [];
$sql = "SELECT pre.*, r.nombre as requisito_nombre FROM proveedor_requisito_evento pre 
        JOIN requisitos r ON pre.requisito_id = r.id 
        WHERE pre.proveedor_usuario=? AND pre.evento_id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $proveedor_usuario, $evento_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $archivos_subidos[$row['requisito_id']] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completar Requisitos - RADEP</title>
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
        
        .requisito-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .requisito-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .estado-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .estado-pendiente {
            background: #fef3c7;
            color: #92400e;
        }
        
        .estado-aprobado {
            background: #d1fae5;
            color: #065f46;
        }
        
        .estado-rechazado {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .btn-upload-pdf {
            background: #5092ab;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 0.35rem 0.9rem 0.35rem 0.7rem;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
            box-shadow: 0 1px 4px rgba(80,146,171,0.10);
        }
        
        .btn-upload-pdf:hover {
            background: #407a8c;
            box-shadow: 0 2px 8px rgba(80,146,171,0.18);
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
            <a href="dashboard_proveedor.php" class="flex items-center border border-white/30 h-9 sm:h-10 px-2 space-x-2 min-w-[120px] sm:min-w-[180px] rounded-md bg-white/20 backdrop-blur-sm text-white text-xs sm:text-sm font-semibold transition hover:bg-white/30" style="text-decoration:none;">
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
        <?php if (isset($_GET['success']) && $_GET['success'] === 'archivo_subido'): ?>
            <div class="bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2 mb-6">
                <i class="fas fa-check-circle"></i>
                <span>Archivo subido correctamente</span>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success']) && $_GET['success'] === 'requisito_completado'): ?>
            <div class="bg-blue-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2 mb-6">
                <i class="fas fa-check-circle"></i>
                <span>Requisito completado correctamente</span>
            </div>
        <?php endif; ?>

        <!-- Información del Evento -->
        <div class="card mb-8">
            <div class="bg-[#5092ab] font-semibold text-white px-4 py-3 border-b border-[#5092ab] rounded-t-lg">
                <h1 class="text-xl">Información del Evento</h1>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h2 class="text-2xl font-bold text-[#5092ab] mb-4"><?= htmlspecialchars($evento['nombre']) ?></h2>
                        <div class="space-y-2">
                            <p><strong>Fecha:</strong> <?= date('d/m/Y', strtotime($evento['fecha'])) ?></p>
                            <p><strong>Hora:</strong> <?= $evento['hora'] ?></p>
                            <p><strong>Descripción:</strong> <?= htmlspecialchars($evento['descripcion'] ?? 'Sin descripción') ?></p>
                        </div>
                    </div>
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <h3 class="font-semibold text-blue-800 mb-2">Instrucciones</h3>
                        <p class="text-blue-700 text-sm">
                            Completa los requisitos del evento subiendo los archivos correspondientes. 
                            Solo se aceptan archivos PDF. Una vez subidos, los archivos serán revisados 
                            por la empresa organizadora.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Requisitos del Evento -->
        <div class="card">
            <div class="bg-[#5092ab] font-semibold text-white px-4 py-3 border-b border-[#5092ab] rounded-t-lg">
                <h2 class="text-xl">Requisitos del Evento</h2>
            </div>
            <div class="p-6">
                <?php if (count($requisitos_evento) > 0): ?>
                    <ul class="space-y-3">
                        <?php foreach($requisitos_evento as $req): ?>
                            <?php $reqProv = $archivos_subidos[$req['id']] ?? null; ?>
                            <li class="bg-white rounded-lg p-4 border border-gray-200 flex flex-col md:flex-row md:items-center md:justify-between gap-3 shadow-sm hover:shadow-md transition-all">
                                <div class="flex items-center gap-3">
                                    <span class="font-medium text-gray-800 text-base"><i class="fas fa-file-alt mr-1 text-[#5092ab]"></i><?= htmlspecialchars($req['nombre']) ?></span>
                                    <?php if ($reqProv && $reqProv['archivo']): ?>
                                        <a href="uploads/proveedor_requisitos/<?= htmlspecialchars($reqProv['archivo']) ?>" target="_blank" class="ml-2 text-blue-600 hover:underline text-xs flex items-center"><i class="fas fa-file-pdf mr-1"></i>Ver PDF</a>
                                    <?php endif; ?>
                                    <?php
                                        $estadoComputed = 'Pendiente';
                                        if ($reqProv) {
                                            if (!empty($reqProv['archivo']) && empty($reqProv['comentario'])) $estadoComputed = 'Autorizado';
                                            elseif (!empty($reqProv['archivo']) && !empty($reqProv['comentario'])) $estadoComputed = 'Revision';
                                            else $estadoComputed = 'Pendiente';
                                        }
                                        $badgeClass = $estadoComputed === 'Autorizado' ? 'bg-green-100 text-green-800 border-green-300' : ($estadoComputed === 'Revision' ? 'bg-red-100 text-red-800 border-red-300' : 'bg-yellow-100 text-yellow-800 border-yellow-300');
                                    ?>
                                    <span class="text-xs px-2 py-1 rounded-full font-semibold border <?= $badgeClass ?>">
                                        <?= $estadoComputed ?>
                                    </span>
                                </div>
                                <form method="post" enctype="multipart/form-data" class="flex items-center gap-2">
                                    <input type="hidden" name="subir_requisito_proveedor" value="1">
                                    <input type="hidden" name="proveedor_usuario" value="<?= $proveedor_usuario ?>">
                                    <input type="hidden" name="requisito_id" value="<?= $req['id'] ?>">
                                    <label class="btn-upload-pdf cursor-pointer flex items-center px-2 py-1 bg-[#5092ab] text-white rounded hover:bg-[#3b82f6] transition shadow-sm" style="margin-bottom:0;">
                                        <i class="fas fa-file-upload mr-1"></i>Subir PDF
                                        <input type="file" name="archivo_pdf" accept="application/pdf" class="form-input-file" required style="display:none;">
                                    </label>
                                    <button type="submit" class="px-3 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition text-xs flex items-center"><i class="fas fa-save mr-1"></i>Guardar</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-clipboard-list text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-500">No hay requisitos asignados a este evento.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html> 