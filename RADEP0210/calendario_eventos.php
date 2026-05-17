<?php
// Obtener eventos
$eventos = [];
$res = $conn->query("SELECT id, nombre, fecha FROM eventos ORDER BY fecha ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $eventos[] = $row;
    }
}

// Mes y año actual
$mes = isset($_GET['mes']) ? intval($_GET['mes']) : date('n');
$ano = isset($_GET['ano']) ? intval($_GET['ano']) : date('Y');

// Nombres de meses
$nombres_meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

// Función para obtener eventos de un día
function getEventosDelDia($fecha, $eventos) {
    $eventos_dia = [];
    foreach ($eventos as $evento) {
        if ($evento['fecha'] == $fecha) {
            $eventos_dia[] = $evento;
        }
    }
    return $eventos_dia;
}
?>

<style>
.calendario-container {
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

.calendario-header {
    background: linear-gradient(135deg, #5092ab 0%, #3b82f6 100%);
    color: white;
    padding: 24px;
    text-align: center;
    position: relative;
}

.calendario-header h2 {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.5px;
}

.calendario-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.nav-button {
    background: linear-gradient(135deg, #5092ab 0%, #3b82f6 100%);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 12px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.nav-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
}

.nav-button:active {
    transform: translateY(0);
}

.nav-center {
    font-size: 16px;
    font-weight: 600;
    color: #64748b;
}

.calendario-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: #f8fafc;
}

.dia-header {
    background: #f1f5f9;
    padding: 16px 8px;
    text-align: center;
    font-weight: 700;
    font-size: 14px;
    color: #475569;
    border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.dia-celda {
    background: white;
    border: 1px solid #e2e8f0;
    min-height: 120px;
    padding: 12px;
    position: relative;
    transition: all 0.2s ease;
}

.dia-celda:hover {
    background: #f8fafc;
    transform: scale(1.02);
    z-index: 1;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.dia-numero {
    font-weight: 700;
    font-size: 16px;
    color: #1e293b;
    margin-bottom: 8px;
    display: block;
}

.dia-otro-mes {
    background: #f8fafc;
    color: #94a3b8;
}

.dia-otro-mes .dia-numero {
    color: #cbd5e1;
}

.dia-hoy {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border: 2px solid #3b82f6;
}

.dia-hoy .dia-numero {
    color: #1d4ed8;
    font-weight: 800;
}

.evento-item {
    background: linear-gradient(135deg, #5092ab 0%, #3b82f6 100%);
    color: white;
    padding: 6px 10px;
    margin: 4px 0;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
    border: none;
    position: relative;
}

.evento-item:hover {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.4);
}

.evento-item:active {
    transform: translateY(0);
}

.evento-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(45deg, transparent 30%, rgba(255,255,255,0.1) 50%, transparent 70%);
    transform: translateX(-100%);
    transition: transform 0.6s;
}

.evento-item:hover::before {
    transform: translateX(100%);
}

.requisito-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    margin: 8px 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
}

.requisito-card:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

.requisito-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.requisito-nombre {
    font-weight: 600;
    color: #1e293b;
    font-size: 14px;
}

.estado-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
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

.requisito-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.action-button {
    background: linear-gradient(135deg, #5092ab 0%, #3b82f6 100%);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.action-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

.action-button.empleados {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.action-button.empleados:hover {
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
}

@media (max-width: 768px) {
    .calendario-header h2 {
        font-size: 24px;
    }
    
    .dia-celda {
        min-height: 100px;
        padding: 8px;
    }
    
    .evento-item {
        font-size: 10px;
        padding: 4px 8px;
    }
}
</style>

<div class="calendario-container">
    <div class="calendario-header">
        <h2><?= $nombres_meses[$mes] ?> <?= $ano ?></h2>
    </div>
    
    <div class="calendario-nav">
        <button class="nav-button" onclick="cambiarMes(<?= $mes-1 ?>, <?= $ano ?>)">
            ← Mes Anterior
        </button>
        <span class="nav-center">Navegación del Calendario</span>
        <button class="nav-button" onclick="cambiarMes(<?= $mes+1 ?>, <?= $ano ?>)">
            Mes Siguiente →
        </button>
    </div>
    
    <div class="calendario-grid">
        <div class="dia-header">Domingo</div>
        <div class="dia-header">Lunes</div>
        <div class="dia-header">Martes</div>
        <div class="dia-header">Miércoles</div>
        <div class="dia-header">Jueves</div>
        <div class="dia-header">Viernes</div>
        <div class="dia-header">Sábado</div>
        
        <?php
        // Calcular primer día del mes
        $primer_dia = mktime(0, 0, 0, $mes, 1, $ano);
        $dia_semana_inicio = date('w', $primer_dia);
        $dias_en_mes = date('t', $primer_dia);
        
        // Días del mes anterior
        $mes_anterior = $mes - 1;
        $ano_anterior = $ano;
        if ($mes_anterior < 1) {
            $mes_anterior = 12;
            $ano_anterior = $ano - 1;
        }
        $ultimo_dia_anterior = mktime(0, 0, 0, $mes, 0, $ano);
        $dias_mes_anterior = date('t', $ultimo_dia_anterior);
        
        $fila = 0;
        $dia_actual = 1;
        
        // Generar semanas
        for ($semana = 0; $semana < 6; $semana++) {
            for ($columna = 0; $columna < 7; $columna++) {
                if ($semana == 0 && $columna < $dia_semana_inicio) {
                    // Días del mes anterior
                    $dia = $dias_mes_anterior - ($dia_semana_inicio - $columna - 1);
                    echo "<div class='dia-celda dia-otro-mes'>";
                    echo "<span class='dia-numero'>$dia</span>";
                    echo "</div>";
                } elseif ($dia_actual > $dias_en_mes) {
                    // Días del mes siguiente
                    $dia = $dia_actual - $dias_en_mes;
                    echo "<div class='dia-celda dia-otro-mes'>";
                    echo "<span class='dia-numero'>$dia</span>";
                    echo "</div>";
                    $dia_actual++;
                } else {
                    // Día del mes actual
                    $fecha_actual = sprintf('%04d-%02d-%02d', $ano, $mes, $dia_actual);
                    $eventos_dia = getEventosDelDia($fecha_actual, $eventos);
                    $es_hoy = ($fecha_actual == date('Y-m-d'));
                    
                    $clase = 'dia-celda';
                    if ($es_hoy) $clase .= ' dia-hoy';
                    
                    echo "<div class='$clase'>";
                    echo "<span class='dia-numero'>$dia_actual</span>";
                    
                    foreach ($eventos_dia as $evento) {
                        echo "<button class='evento-item' onclick='mostrarRequisitos(" . $evento['id'] . ", \"" . htmlspecialchars($evento['nombre']) . "\")'>";
                        echo htmlspecialchars($evento['nombre']);
                        echo "</button>";
                    }
                    
                    echo "</div>";
                    $dia_actual++;
                }
            }
        }
        ?>
    </div>
</div>

<script>
function cambiarMes(mes, ano) {
    // Ajustar mes y año
    if (mes < 1) {
        mes = 12;
        ano--;
    } else if (mes > 12) {
        mes = 1;
        ano++;
    }
    
    // Redirigir
    window.location.href = 'dashboard_proveedor.php?mes=' + mes + '&ano=' + ano;
}

function mostrarRequisitos(eventoId, nombreEvento) {
    console.log('Mostrando requisitos para:', eventoId, nombreEvento);
    
    // Obtener el contenedor del sidebar
    const container = document.getElementById('requisitosSidebarContainer');
    if (!container) {
        alert('Error: No se encontró el contenedor de requisitos');
        return;
    }
    
    const sidebarContent = container.querySelector('.flex-1.overflow-y-auto');
    if (!sidebarContent) {
        alert('Error: No se encontró el contenido del sidebar');
        return;
    }
    
    sidebarContent.innerHTML = '<p style="text-align: center; color: #64748b; padding: 20px;">⏳ Cargando requisitos...</p>';
    
    // Hacer petición AJAX
    fetch('get_requisitos_evento.php?evento_id=' + eventoId)
        .then(response => response.json())
        .then(data => {
            console.log('Requisitos recibidos:', data);
            
            if (data && data.length > 0) {
                let html = '<div style="padding: 20px;">';
                html += '<h3 style="margin: 0 0 20px 0; color: #1e293b; font-size: 20px; font-weight: 700;">' + nombreEvento + '</h3>';
                
                data.forEach(requisito => {
                    let estadoClase = 'estado-pendiente';
                    let estadoTexto = 'Pendiente';
                    
                    if (requisito.estado === 'aprobado') {
                        estadoClase = 'estado-aprobado';
                        estadoTexto = 'Aprobado';
                    } else if (requisito.estado === 'rechazado') {
                        estadoClase = 'estado-rechazado';
                        estadoTexto = 'Rechazado';
                    }
                    
                    html += '<div class="requisito-card">';
                    html += '<div class="requisito-header">';
                    html += '<span class="requisito-nombre">' + requisito.nombre + '</span>';
                    html += '<span class="estado-badge ' + estadoClase + '">' + estadoTexto + '</span>';
                    html += '</div>';
                    html += '<div class="requisito-actions">';
                    html += '<button class="action-button" onclick="subirPDF(' + eventoId + ', ' + requisito.id + ')">📄 Subir PDF</button>';
                    html += '<button class="action-button empleados" onclick="asignarEmpleados(' + eventoId + ', ' + requisito.id + ')">👥 Empleados</button>';
                    html += '</div>';
                    html += '</div>';
                });
                
                html += '</div>';
                sidebarContent.innerHTML = html;
            } else {
                sidebarContent.innerHTML = '<div style="text-align: center; color: #64748b; padding: 40px;"><p>📋 No hay requisitos asignados a este evento</p></div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            sidebarContent.innerHTML = '<div style="text-align: center; color: #ef4444; padding: 40px;"><p>⚠️ Error al cargar requisitos</p></div>';
        });
}

function subirPDF(eventoId, requisitoId) {
    alert('Función de subir PDF para evento ' + eventoId + ', requisito ' + requisitoId);
}

function asignarEmpleados(eventoId, requisitoId) {
    alert('Función de asignar empleados para evento ' + eventoId + ', requisito ' + requisitoId);
}

console.log('Calendario moderno cargado');
</script> 