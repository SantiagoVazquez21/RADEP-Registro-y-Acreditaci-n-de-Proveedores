<?php
session_start();

// Verificar que el usuario esté logueado y sea proveedor
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'proveedor') {
    header("Location: index.php");
    exit();
}

require_once 'dbconn.php';

// Obtener mes y año actuales o de la URL
$mes = isset($_GET['mes']) ? intval($_GET['mes']) : date('n');
$año = isset($_GET['año']) ? intval($_GET['año']) : date('Y');

// Navegación del calendario
$mes_anterior = $mes == 1 ? 12 : $mes - 1;
$año_anterior = $mes == 1 ? $año - 1 : $año;
$mes_siguiente = $mes == 12 ? 1 : $mes + 1;
$año_siguiente = $mes == 12 ? $año + 1 : $año;

// Obtener eventos del mes

$proveedor_id = $_SESSION['proveedor_id'] ?? $_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
// Debug: registrar en comentario qué id de proveedor se está utilizando
if (empty($proveedor_id)) {
    echo "<!-- Debug: No se encontró proveedor_id en la sesión. Keys: " . json_encode(array_keys($_SESSION)) . " -->";
} else {
    echo "<!-- Debug: proveedor_id usado = " . intval($proveedor_id) . " -->";
}
$eventos_mes = [];

$sql = "SELECT e.* 
        FROM eventos e
        INNER JOIN proveedores_evento pe ON e.id = pe.evento_id
        WHERE pe.proveedor_id = ?
        AND MONTH(e.fecha) = ? 
        AND YEAR(e.fecha) = ?
        ORDER BY e.fecha ASC, e.hora ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $proveedor_id, $mes, $año);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $dia = date('j', strtotime($row['fecha']));
    if (!isset($eventos_mes[$dia])) {
        $eventos_mes[$dia] = [];
    }
    $eventos_mes[$dia][] = $row;
}
$stmt->close();

// Debug: mostrar información de eventos encontrados
if (empty($eventos_mes)) {
    echo "<!-- Debug: No se encontraron eventos para el mes $mes del año $año -->";
} else {
    echo "<!-- Debug: Se encontraron eventos: " . count($eventos_mes) . " días con eventos -->";
    foreach($eventos_mes as $dia => $eventos) {
        foreach($eventos as $evento) {
            echo "<!-- Debug: Día $dia - Evento: " . $evento['nombre'] . " (ID: " . $evento['id'] . ") -->";
        }
    }
}

// Nombres de los meses
$nombres_meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

// Días de la semana
$dias_semana = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

// Obtener el primer día del mes
$primer_dia = mktime(0, 0, 0, $mes, 1, $año);
$primer_dia_semana = date('N', $primer_dia); // 1=Lunes, 7=Domingo
$dias_mes = date('t', $primer_dia);

// Calcular días del mes anterior para completar la primera semana
$dias_mes_anterior = $primer_dia_semana - 1;
$ultimo_dia_mes_anterior = date('t', mktime(0, 0, 0, $mes - 1, 1, $año));
$inicio_calendario = $ultimo_dia_mes_anterior - $dias_mes_anterior + 1;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario de Eventos - RADEP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet" />
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, #a9bec7 0%, #8ba3b0 50%, #6b8a9a 100%);
            min-height: 100vh;
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
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(80, 146, 171, 0.5);
        }
        
        .card {
            background: #d9e3e6;
            border: 2px solid #5092ab;
            border-radius: 8px;
        }
        
        .nav-btn {
            background: #5092ab;
            color: white;
            border: 2px solid #5092ab;
            border-radius: 6px;
            padding: 8px 16px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .nav-btn:hover {
            background: #3b82f6;
            border-color: #3b82f6;
            transform: translateY(-1px);
        }
        
        .calendar-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .calendar-header {
            background: #5092ab;
            color: white;
            font-weight: bold;
            padding: 16px 8px;
            text-align: center;
            font-size: 0.9rem;
        }
        
        .calendar-day {
            min-height: 120px;
            border: 1px solid #e5e7eb;
            background: white;
            transition: all 0.2s ease;
            position: relative;
            padding: 8px;
        }
        
        .calendar-day:hover {
            background-color: #f8fafc;
        }
        
        .calendar-day.today {
            background-color: #eff6ff;
            border-color: #3b82f6;
        }
        
        .calendar-day.other-month {
            background-color: #f9fafb;
            color: #9ca3af;
        }
        
        .evento-item {
            background: #5092ab;
            color: white;
            border-radius: 4px;
            padding: 4px 6px;
            margin: 1px 0;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s ease;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: normal;
            line-height: 1.2;
        }
        
        .evento-item:hover {
            background: #3b82f6;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0;
            background: #e5e7eb;
        }
        
        .day-number {
            font-weight: bold;
            font-size: 1.1rem;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .today-indicator {
            display: inline-block;
            width: 6px;
            height: 6px;
            background: #3b82f6;
            border-radius: 50%;
            margin-left: 4px;
        }
        
        @media (max-width: 768px) {
            .calendar-header {
                padding: 12px 6px;
                font-size: 0.8rem;
            }
            
            .calendar-day {
                min-height: 100px;
                padding: 6px;
            }
            
            .evento-item {
                font-size: 0.75rem;
                padding: 4px 6px;
            }
            
            .day-number {
                font-size: 1rem;
            }
        }
        
        @media (max-width: 480px) {
            .calendar-day {
                min-height: 80px;
                padding: 4px;
            }
            
            .evento-item {
                font-size: 0.7rem;
                padding: 3px 4px;
            }
            
            .day-number {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body class="bg-[#a9bec7] min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-[#5092ab] flex flex-col sm:flex-row items-center px-2 sm:px-0 py-0 h-auto sm:h-20 border-b-2 border-black/20 w-full">
        <div class="flex items-center w-full sm:w-auto justify-center sm:justify-start h-16 sm:h-20 px-1 sm:px-4 mb-2 sm:mb-0">
            <div class="flex items-center h-16 sm:h-20 px-1 sm:px-4">
                <img src="images/logo-radep.svg" alt="Logo RADEP" class="block sm:hidden" style="height:40px; max-width:120px; object-fit:contain;"/>
                <img src="images/logo-radep.svg" alt="Logo RADEP" class="hidden sm:block" style="height:70px; max-width:220px; object-fit:contain;"/>
            </div>
        </div>
        <div class="ml-0 sm:ml-auto flex flex-col sm:flex-row items-center space-y-1 sm:space-y-0 sm:space-x-2 w-full sm:w-auto justify-center sm:justify-end mr-0 sm:mr-2">
            <div class="flex items-center border border-white/30 h-9 sm:h-10 px-2 space-x-2 min-w-[120px] sm:min-w-[180px] relative rounded-md bg-white/20 backdrop-blur-sm transition text-xs sm:text-[11px]">
                <img alt="User icon" class="object-contain rounded-full border border-white/50 shadow h-5 w-5 sm:h-6 sm:w-6" src="https://storage.googleapis.com/a1aa/image/0a14249b-d6d3-4ba9-919c-8fdc2f46616f.jpg"/>
                <div class="flex flex-col justify-center min-w-0">
                    <span class="font-semibold text-white leading-tight truncate">
                        <?= isset($_SESSION['nombre']) ? htmlspecialchars($_SESSION['nombre']) : 'Proveedor' ?>
                    </span>
                    <span class="text-white/80">Proveedor</span>
                </div>
            </div>
            <a href="logout.php" class="flex items-center border border-white/30 h-9 sm:h-10 px-2 space-x-2 min-w-[120px] sm:min-w-[180px] rounded-md bg-white/20 backdrop-blur-sm text-white text-xs sm:text-sm font-semibold transition hover:bg-white/30 ml-0 sm:ml-2" style="text-decoration:none;">
                <i class="fas fa-sign-out-alt mr-1"></i>
                <span class="font-semibold text-white leading-tight truncate">Cerrar Sesión</span>
            </a>
        </div>
    </header>
    
    <!-- Navigation bar -->
    <nav class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2 border-b-2 border-[#5092ab]/30 px-2 py-2 bg-[#d9e3e6] shadow-sm select-none relative z-10 w-full">
        <a href="dashboard_proveedor.php" class="nav-btn flex items-center space-x-1 text-sm px-3 py-1.5">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        <a href="calendario_eventos_proveedor.php" class="nav-btn flex items-center space-x-1 text-sm px-3 py-1.5 bg-[#3b82f6] border-[#3b82f6]">
            <i class="fas fa-calendar-alt"></i>
            <span>Eventos</span>
        </a>
        <a href="completar_requisitos.php" class="nav-btn flex items-center space-x-1 text-sm px-3 py-1.5">
            <i class="fas fa-file-upload"></i>
            <span>Completar Requisitos</span>
        </a>
    </nav>

    <!-- Main content -->
    <main class="flex-1 px-2 sm:px-4 py-4 sm:py-6">
        <div class="max-w-6xl mx-auto">
            <!-- Header del calendario -->
            <div class="flex flex-col sm:flex-row items-center justify-between mb-6 gap-4">
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 text-center sm:text-left">
                    <i class="fas fa-calendar-alt text-[#5092ab] mr-3"></i>
                    Calendario de Eventos
                </h1>
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <a href="?mes=<?= $mes_anterior ?>&año=<?= $año_anterior ?>" 
                       class="btn-primary text-xs sm:text-sm px-3 sm:px-4 py-2">
                        <i class="fas fa-chevron-left mr-1 sm:mr-2"></i>
                        <span class="hidden sm:inline">Mes anterior</span>
                        <span class="sm:hidden">Anterior</span>
                    </a>
                    <span class="text-lg sm:text-xl font-semibold text-gray-900 px-2">
                        <?= $nombres_meses[$mes] ?> <?= $año ?>
                    </span>
                    <a href="?mes=<?= $mes_siguiente ?>&año=<?= $año_siguiente ?>" 
                       class="btn-primary text-xs sm:text-sm px-3 sm:px-4 py-2">
                        <span class="hidden sm:inline">Mes siguiente</span>
                        <span class="sm:hidden">Siguiente</span>
                        <i class="fas fa-chevron-right ml-1 sm:ml-2"></i>
                    </a>
                </div>
            </div>

            <!-- Calendario -->
            <div class="calendar-container">
                <div class="calendar-grid">
                    <!-- Días de la semana -->
                    <?php foreach($dias_semana as $dia): ?>
                        <div class="calendar-header">
                            <?= $dia ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Días del mes anterior -->
                    <?php for($i = 0; $i < $dias_mes_anterior; $i++): ?>
                        <div class="calendar-day other-month">
                            <div class="text-sm text-gray-400">
                                <?= $inicio_calendario + $i ?>
                            </div>
                        </div>
                    <?php endfor; ?>
                    
                    <!-- Días del mes actual -->
                    <?php for($dia = 1; $dia <= $dias_mes; $dia++): ?>
                        <?php 
                        $es_hoy = ($dia == date('j') && $mes == date('n') && $año == date('Y'));
                        $clase_dia = $es_hoy ? 'today' : '';
                        ?>
                        <div class="calendar-day <?= $clase_dia ?>">
                            <div class="day-number">
                                <?= $dia ?>
                                <?php if($es_hoy): ?>
                                    <span class="today-indicator"></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if(isset($eventos_mes[$dia])): ?>
                                <div class="space-y-1">
                                    <?php foreach($eventos_mes[$dia] as $evento): ?>
                                        <div class="evento-item" 
                                             onclick="window.location.href='dashboard_proveedor.php?evento=<?= $evento['id'] ?>'"
                                             title="<?= htmlspecialchars($evento['nombre']) ?>">
                                            <?= htmlspecialchars($evento['nombre']) ?>
                                            <!-- Debug: ID=<?= $evento['id'] ?> -->
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                    
                    <!-- Días del mes siguiente para completar la última semana -->
                    <?php 
                    $dias_mostrados = $dias_mes_anterior + $dias_mes;
                    $dias_faltantes = 7 - ($dias_mostrados % 7);
                    if($dias_faltantes < 7):
                        for($i = 1; $i <= $dias_faltantes; $i++):
                    ?>
                        <div class="calendar-day other-month">
                            <div class="text-sm text-gray-400">
                                <?= $i ?>
                            </div>
                        </div>
                    <?php 
                        endfor;
                    endif;
                    ?>
                </div>
            </div>

            <!-- Leyenda -->
            <div class="mt-6 card p-4">
                <h3 class="font-semibold text-[#5092ab] mb-3">
                    <i class="fas fa-info-circle mr-2"></i>Leyenda
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div class="flex items-center space-x-2">
                        <div class="w-4 h-4 bg-blue-500 rounded-full"></div>
                        <span>Hoy</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div class="w-4 h-4 bg-[#5092ab] rounded"></div>
                        <span>Evento (click para gestionar)</span>
                    </div>
                </div>
            </div>
        </div>
    </main>


</body>
</html> 