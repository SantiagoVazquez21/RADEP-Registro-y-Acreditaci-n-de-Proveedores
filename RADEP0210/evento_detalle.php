<?php
include("dbconn.php");
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Procesar agregar requisito al evento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_requisito'])) {
    $requisito_id = intval($_POST['requisito_id']);
    
    // Verificar que el requisito no esté ya asignado al evento
    $stmt = $conn->prepare("SELECT id FROM evento_requisito WHERE evento_id = ? AND requisito_id = ?");
    $stmt->bind_param("ii", $id, $requisito_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    
    if ($result->num_rows == 0) {
        // Insertar nuevo requisito al evento (no usamos columna 'estado' fuera de eventos)
        $stmt = $conn->prepare("INSERT INTO evento_requisito (evento_id, requisito_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $id, $requisito_id);
        
        if ($stmt->execute()) {
            header("Location: evento_detalle.php?id=" . $id . "&success=requisito_agregado");
            exit();
        } else {
            $error = "Error al agregar el requisito: " . $stmt->error;
        }
    } else {
        $error = "Este requisito ya está asignado al evento.";
    }
    $stmt->close();
}

// Obtener datos del evento
$stmt = $conn->prepare("SELECT * FROM eventos WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$evento = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Obtener proveedores/empleados asignados correctamente
$empleados = [];
$res = $conn->query("SELECT e.nombre FROM empleados e JOIN evento_empleado ee ON e.id = ee.empleado_id WHERE ee.evento_id = $id");
while ($row = $res->fetch_assoc()) {
    $empleados[] = $row['nombre'];
}

// Obtener requisitos asociados correctamente
$requisitos = [];
 $res = $conn->query("SELECT r.id, r.nombre FROM evento_requisito er JOIN requisitos r ON er.requisito_id = r.id WHERE er.evento_id = $id");
while ($row = $res->fetch_assoc()) {
    $requisitos[] = $row;
}

// Obtener todos los requisitos disponibles para agregar
$requisitos_disponibles = [];
$res = $conn->query("SELECT r.id, r.nombre FROM requisitos r WHERE r.id NOT IN (SELECT er.requisito_id FROM evento_requisito er WHERE er.evento_id = $id)");
while ($row = $res->fetch_assoc()) {
    $requisitos_disponibles[] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>Detalle del Evento - RADEP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet" />
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, #a9bec7 0%, #8ba3b0 50%, #6b8a9a 100%);
            min-height: 100vh;
        }
        
        .detail-card {
            background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
            border: 2px solid #5092ab;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(80, 146, 171, 0.2);
            backdrop-filter: blur(15px);
            animation: slideInUp 0.8s ease-out;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
        
        .back-link {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
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
        
        .back-link:hover {
            transform: translateX(-5px);
        }
        
        .info-item {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 12px;
            border-radius: 12px;
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border: 1px solid rgba(80, 146, 171, 0.2);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }
        
        .info-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(80, 146, 171, 0.15);
            border-color: #5092ab;
        }
        
        .section-card {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border-radius: 16px;
            border: 2px solid rgba(80, 146, 171, 0.2);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: fadeInUp 0.6s ease-out;
            animation-fill-mode: both;
        }
        
        .section-card:nth-child(1) { animation-delay: 0.1s; }
        .section-card:nth-child(2) { animation-delay: 0.2s; }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .section-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(80, 146, 171, 0.2);
            border-color: #5092ab;
        }
        
        .status-badge {
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            box-shadow: 0 4px 15px rgba(80, 146, 171, 0.3);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 4px 15px rgba(80, 146, 171, 0.3); }
            50% { box-shadow: 0 4px 15px rgba(80, 146, 171, 0.5), 0 0 0 5px rgba(80, 146, 171, 0.1); }
            100% { box-shadow: 0 4px 15px rgba(80, 146, 171, 0.3); }
        }
        
        .icon-container {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            box-shadow: 0 6px 20px rgba(80, 146, 171, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .icon-container:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 8px 25px rgba(80, 146, 171, 0.4);
        }
        
        .empty-state {
            background: linear-gradient(145deg, #f8fafc, #e2e8f0);
            border: 2px dashed rgba(80, 146, 171, 0.3);
            border-radius: 16px;
            transition: all 0.3s ease;
        }
        
        .empty-state:hover {
            border-color: #5092ab;
            background: linear-gradient(145deg, #f1f5f9, #e2e8f0);
        }
        
        .list-item {
            transition: all 0.3s ease;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 4px;
        }
        
        .list-item:hover {
            background: rgba(80, 146, 171, 0.1);
            transform: translateX(5px);
        }
        
        .requirement-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .requirement-status.approved {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 2px 10px rgba(16, 185, 129, 0.3);
        }
        
        .requirement-status.pending {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 2px 10px rgba(245, 158, 11, 0.3);
        }
        
        .requirement-status:hover {
            transform: scale(1.05);
        }
        
        @media (max-width: 768px) {
            .detail-card {
                margin: 1rem;
                padding: 1.5rem;
            }
            
            .section-card:hover {
                transform: translateY(-3px);
            }
            
            .icon-container:hover {
                transform: scale(1.05);
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
        <div class="max-w-4xl mx-auto">
            <!-- Navegación -->
            <nav class="mb-6">
                <a href="eventos.php" class="inline-flex items-center space-x-2 text-[#5092ab] hover:text-[#3b82f6] transition font-semibold">
                    <i class="fas fa-arrow-left"></i>
                    <span>Volver a eventos</span>
                </a>
            </nav>

            <!-- Mensajes de éxito/error -->
            <?php if (isset($_GET['success']) && $_GET['success'] === 'evento_actualizado'): ?>
                <div class="bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2 mb-6">
                    <i class="fas fa-check-circle"></i>
                    <span>Evento actualizado correctamente</span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success']) && $_GET['success'] === 'requisito_agregado'): ?>
                <div class="bg-blue-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2 mb-6">
                    <i class="fas fa-check-circle"></i>
                    <span>Requisito agregado correctamente al evento</span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2 mb-6">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
            <div class="text-center mb-8">
                <h1 class="text-[#5092ab] font-semibold text-3xl mb-2 flex items-center justify-center gap-3">
                    <i class="fas fa-calendar-alt"></i>
                    Detalle del Evento
                </h1>
            </div>

            <!-- Contenido del evento -->
            <div class="detail-card p-8">
                <h2 class="text-2xl font-bold text-[#5092ab] mb-6 flex items-center gap-3">
                    <i class="fas fa-calendar-alt"></i>
                    <?= htmlspecialchars($evento['nombre']) ?>
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div class="space-y-4">
                        <div class="flex items-center text-gray-700">
                            <i class="fas fa-map-marker-alt w-6 text-[#5092ab]"></i>
                            <div class="ml-3">
                                <span class="font-semibold text-[#5092ab]">Lugar:</span>
                                <span class="ml-2"><?= htmlspecialchars($evento['lugar']) ?></span>
                            </div>
                        </div>
                        
                        <div class="flex items-center text-gray-700">
                            <i class="fas fa-calendar w-6 text-[#5092ab]"></i>
                            <div class="ml-3">
                                <span class="font-semibold text-[#5092ab]">Fecha de inicio:</span>
                                <span class="ml-2"><?= date('d/m/Y', strtotime($evento['fecha'])) ?></span>
                                <?php if (!empty($evento['fecha_fin'])): ?>
                                    <span class="ml-4 font-semibold text-[#5092ab]">Fecha de finalización:</span>
                                    <span class="ml-2"><?= date('d/m/Y', strtotime($evento['fecha_fin'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="flex items-center text-gray-700">
                            <i class="fas fa-clock w-6 text-[#5092ab]"></i>
                            <div class="ml-3">
                                <span class="font-semibold text-[#5092ab]">Hora de inicio:</span>
                                <span class="ml-2"><?= date('H:i', strtotime($evento['hora'])) ?></span>
                                <?php if (!empty($evento['hora_fin'])): ?>
                                    <span class="ml-4 font-semibold text-[#5092ab]">Hora de finalización:</span>
                                    <span class="ml-2"><?= date('H:i', strtotime($evento['hora_fin'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($evento['descripcion'])): ?>
                            <div class="flex items-start text-gray-700">
                                <i class="fas fa-align-left w-6 text-[#5092ab] mt-1"></i>
                                <div class="ml-3">
                                    <span class="font-semibold text-[#5092ab]">Descripción:</span>
                                    <p class="ml-2 mt-1 text-sm"><?= htmlspecialchars($evento['descripcion']) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($evento['estado'])): ?>
                            <div class="flex items-center text-gray-700">
                                <i class="fas fa-info-circle w-6 text-[#5092ab]"></i>
                                <div class="ml-3">
                                    <span class="font-semibold text-[#5092ab]">Estado:</span>
                                    <span class="ml-2 px-3 py-1 bg-[#5092ab] text-white rounded-full text-sm">
                                        <?= htmlspecialchars($evento['estado']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="space-y-6">
                        <!-- Empleados asignados -->
                        <div>
                            <h3 class="text-lg font-semibold text-[#5092ab] mb-3 flex items-center gap-2">
                                <i class="fas fa-users"></i>
                                Empleados Asignados
                            </h3>
                            <?php if (count($empleados) > 0): ?>
                                <div class="bg-white rounded-lg p-4 border border-[#5092ab]/20">
                                    <ul class="space-y-2">
                                        <?php foreach($empleados as $emp): ?>
                                            <li class="flex items-center text-gray-700">
                                                <i class="fas fa-user text-[#5092ab] mr-2"></i>
                                                <?= htmlspecialchars($emp) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <div class="bg-white rounded-lg p-4 border border-[#5092ab]/20 text-center text-gray-500">
                                    <i class="fas fa-users text-2xl mb-2"></i>
                                    <p>No hay empleados asignados</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Requisitos -->
                        <div>
                            <h3 class="text-lg font-semibold text-[#5092ab] mb-3 flex items-center gap-2">
                                <i class="fas fa-clipboard-list"></i>
                                Requisitos
                            </h3>
                            
                            <!-- Lista de requisitos actuales -->
                            <?php if (count($requisitos) > 0): ?>
                                <div class="bg-white rounded-lg p-4 border border-[#5092ab]/20 mb-4">
                                    <h4 class="font-semibold text-gray-700 mb-3">Requisitos Asignados:</h4>
                                    <ul class="space-y-2">
                                        <?php foreach($requisitos as $req): ?>
                                            <?php $estado = isset($req['estado']) && $req['estado'] !== '' ? $req['estado'] : 'pendiente'; ?>
                                            <li class="flex items-center justify-between text-gray-700 p-2 bg-gray-50 rounded">
                                                <span><?= htmlspecialchars($req['nombre']) ?></span>
                                                <span class="px-2 py-1 rounded text-xs font-semibold <?= $estado === 'aprobado' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                                    <?= ucfirst(htmlspecialchars($estado)) ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Formulario para agregar requisitos -->
                            <?php if (count($requisitos_disponibles) > 0): ?>
                                <div class="bg-white rounded-lg p-6 border border-[#5092ab]/20 flex flex-col">
                                    <h4 class="font-semibold text-gray-700 mb-3">Agregar Nuevo Requisito:</h4>
                                    <form method="post" class="flex items-center gap-3 w-full">
                                        <input type="hidden" name="agregar_requisito" value="1">
                                        <select name="requisito_id" required class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-[#5092ab] w-2/3 max-w-xs">
                                            <option value="">Seleccionar requisito...</option>
                                            <?php foreach($requisitos_disponibles as $req): ?>
                                                <option value="<?= $req['id'] ?>"><?= htmlspecialchars($req['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="px-4 py-2 bg-[#5092ab] text-white rounded-lg hover:bg-[#3b82f6] transition flex items-center gap-2 w-1/3 max-w-[120px]">
                                            <i class="fas fa-plus"></i>
                                            Agregar
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="bg-white rounded-lg p-4 border border-[#5092ab]/20 text-center text-gray-500">
                                    <i class="fas fa-clipboard-list text-2xl mb-2"></i>
                                    <p>No hay requisitos disponibles para agregar</p>
                                    <p class="text-sm mt-2">Todos los requisitos ya están asignados a este evento</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Acciones -->
                <div class="flex justify-center space-x-4 pt-6 border-t border-[#5092ab]/20">
                    <a href="eventos.php" class="btn-primary flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Volver a Eventos
                    </a>
                    <a href="editar_evento.php?id=<?= $id ?>" class="btn-primary flex items-center gap-2">
                        <i class="fas fa-edit"></i>
                        Editar Evento
                    </a>
                    <a href="requisitos.php" class="btn-primary flex items-center gap-2">
                        <i class="fas fa-cog"></i>
                        Gestionar Requisitos
                    </a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>