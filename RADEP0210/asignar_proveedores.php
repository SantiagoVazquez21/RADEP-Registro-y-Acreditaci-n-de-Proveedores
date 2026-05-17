<?php
session_start();
require_once 'dbconn.php';
// Notifications helper (crear_notificacion)
require_once __DIR__ . '/includes/notifications_lib.php';

// Mostrar errores durante la depuración (puedes quitar esto en producción)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar que el usuario esté logueado y sea empresa
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'empresa') {
    header('Location: ingreso.php');
    exit;
}

$evento_id = $_GET['evento'] ?? null;
if (!$evento_id) {
    header('Location: dashboard_empresa.php');
    exit;
}

// Obtener información del evento
$evento_sql = "SELECT nombre, fecha, hora FROM eventos WHERE id = ?";
$evento_stmt = $conn->prepare($evento_sql);
$evento_stmt->bind_param("i", $evento_id);
$evento_stmt->execute();
$evento_result = $evento_stmt->get_result();
$evento = $evento_result->fetch_assoc();

// Obtener proveedores dados de alta
$proveedores_sql = "SELECT id, nombre, email, cuilcuit, foto, servicio FROM proveedores ORDER BY nombre";
$proveedores_result = $conn->query($proveedores_sql);
$proveedores = [];
$db_error = null;
if ($proveedores_result === false) {
    $db_error = $conn->error;
} else {
    while ($row = $proveedores_result->fetch_assoc()) {
        // Si la foto no tiene ruta absoluta, prepéndela
        if (!empty($row['foto']) && strpos($row['foto'], 'uploads/proveedores/') === false) {
            $row['foto'] = 'uploads/proveedores/' . $row['foto'];
        }
        $proveedores[] = $row;
    }
}

// Mensaje de depuración si hay proveedores pero no se muestran
if (count($proveedores) > 0 && empty($proveedores)) {
    echo "<div style='color:orange;background:#fffbe8;padding:10px;border:1px solid #fbbf24;'>Proveedores encontrados en la base, pero no se muestran en la interfaz. Revisa la lógica de renderizado.</div>";
}

// Obtener proveedores ya asignados a este evento
$asignados_sql = "SELECT proveedor_id FROM proveedores_evento WHERE evento_id = ?";
$asignados_stmt = $conn->prepare($asignados_sql);
$asignados_stmt->bind_param("i", $evento_id);
$asignados_stmt->execute();
$asignados_result = $asignados_stmt->get_result();
$proveedores_asignados = [];
while ($row = $asignados_result->fetch_assoc()) {
    $proveedores_asignados[] = $row['proveedor_id'];
}

// Procesar formulario de asignación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proveedores_seleccionados = $_POST['proveedores'] ?? [];
    // Eliminar asignaciones anteriores
    $delete_sql = "DELETE FROM proveedores_evento WHERE evento_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $evento_id);
    $delete_stmt->execute();
    // También eliminar empleados de proveedores de este evento
    $delete_empleados_sql = "DELETE FROM evento_empleado WHERE evento_id = ?";
    $delete_empleados_stmt = $conn->prepare($delete_empleados_sql);
    $delete_empleados_stmt->bind_param("i", $evento_id);
    $delete_empleados_stmt->execute();
    $delete_empleados_stmt->close();
    // Insertar nuevas asignaciones
    if (!empty($proveedores_seleccionados)) {
        $insert_sql = "INSERT INTO proveedores_evento (evento_id, proveedor_id, fecha_asignacion) VALUES (?, ?, NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        foreach ($proveedores_seleccionados as $proveedor_id) {
                $insert_stmt->bind_param("ii", $evento_id, $proveedor_id);
                $insert_stmt->execute();
                // Crear notificación para el proveedor asignado
                // Intentar obtener nombre del evento para la notificación
                $evt_name = $evento['nombre'] ?? ('Evento #' . $evento_id);
                // Título y descripción claros
                $titulo = 'Asignación a evento';
                $desc = "Has sido asignado al evento: " . $evt_name . " (ID: $evento_id)";
                // crear_notificacion espera (usuario_id, tipo_usuario, icono, titulo, descripcion)
                // proveedores esperan tipo 'proveedor'
                if (function_exists('crear_notificacion')) {
                    @crear_notificacion(intval($proveedor_id), 'proveedor', 'fa-user-plus', $titulo, $desc);
                }
            // --- AUTOMÁTICO: Asignar empleados del proveedor al evento ---
            $empleados_sql = "SELECT id FROM empleados_proveedor WHERE proveedor_id = ?";
            $empleados_stmt = $conn->prepare($empleados_sql);
            $empleados_stmt->bind_param("i", $proveedor_id);
            $empleados_stmt->execute();
            $empleados_result = $empleados_stmt->get_result();
            while ($emp = $empleados_result->fetch_assoc()) {
                // Verificar si ya está asignado
                $check_sql = "SELECT id FROM evento_empleado WHERE evento_id = ? AND empleado_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("ii", $evento_id, $emp['id']);
                $check_stmt->execute();
                $check_stmt->store_result();
                if ($check_stmt->num_rows == 0) {
                    $insert_emp_sql = "INSERT INTO evento_empleado (evento_id, empleado_id) VALUES (?, ?)";
                    $insert_emp_stmt = $conn->prepare($insert_emp_sql);
                    $insert_emp_stmt->bind_param("ii", $evento_id, $emp['id']);
                    $insert_emp_stmt->execute();
                    $insert_emp_stmt->close();
                }
                $check_stmt->close();
            }
            $empleados_stmt->close();
        }
        $insert_stmt->close();
    }
    $delete_stmt->close();
    header('Location: dashboard_empresa.php?success=proveedores_asignados');
    exit;
}

// DEPURACION TEMPORAL: mostrar info clave antes del HTML
if (!headers_sent()) {
    echo "<pre style='background:#fffbe8; color:#222; font-size:1rem; padding:1rem; border:2px solid #fbbf24;'>";
    echo "Archivo: " . __FILE__ . "\n";
    echo "Proveedores encontrados: " . count($proveedores) . "\n";
    echo "Proveedores array:\n";
    var_export($proveedores);
    echo "\n\nSESSION:\n";
    var_export($_SESSION);
    echo "\n\nDB error: ";
    echo $db_error ? $db_error : 'ninguno';
    echo "</pre>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignar Proveedores al Evento - RADEP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #a9bec7 0%, #8ba3b0 50%, #6b8a9a 100%); min-height: 100vh; }
        /* Modal más ancho para aprovechar espacio */
        .modal-asignar {
            max-width: 900px;
            margin: 4rem auto;
            background: #f8fafc;
            border-radius: 28px;
            box-shadow: 0 20px 60px rgba(80,146,171,0.25);
            padding: 2.5rem 2.5rem;
            position: relative;
        }
        .modal-asignar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
        }
        .modal-asignar-title {
            font-size: 2rem;
            font-weight: 800;
            color: #3b82f6;
            display: flex;
            align-items: center;
            gap: 1rem;
            letter-spacing: 0.02em;
        }
        .proveedores-list {
            display: grid;
            /* columnas más compactas para permitir más tarjetas en pantalla */
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }
        .proveedor-card-asignar {
            background: linear-gradient(135deg, #eaf1f6, #d6e3e8);
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(80,146,171,0.12);
            padding: 1rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: box-shadow 0.3s, border 0.3s, transform 0.15s;
            border: 2px solid transparent;
            cursor: pointer;
            justify-content: space-between;
        }
        .proveedor-card-asignar:hover { transform: translateY(-4px); }
        /* ajustar efecto de selección para el contenedor */
        .proveedor-card-asignar.selected {
            box-shadow: 0 18px 44px rgba(59,130,246,0.15);
            border: 2px solid #3b82f6;
        }
        .proveedor-foto-asignar {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #5092ab;
            background: #fff;
            box-shadow: 0 6px 18px rgba(80,146,171,0.12);
            flex-shrink: 0;
        }
        .proveedor-foto-placeholder-asignar { width:84px; height:84px; border-radius:50%; }
        .proveedor-info-asignar { flex: 1; padding-left: 0.5rem; }
        .proveedor-nombre-asignar { font-weight:700; color:#1e3a8a; font-size:1.1rem; }
        .proveedor-email-asignar { font-size:0.95rem; color:#374151; }
        .proveedor-cuil-asignar { font-size:0.9rem; color:#6b7280; }
        .proveedor-checkbox { accent-color:#3b82f6; width:1.25rem; height:1.25rem; }
        .asignar-actions { display:flex; justify-content:flex-end; gap:1.25rem; margin-top:1.25rem; }
        .btn-asignar { background: linear-gradient(135deg,#5092ab,#3b82f6); color:white; font-weight:bold; border:none; padding:0.9rem 1.8rem; border-radius:12px; }
        .btn-cancelar { background:none; color:#5092ab; border:none; padding:0.9rem 1.8rem; }
        .no-proveedores { text-align:center; color:#6b7280; font-size:1.05rem; margin:2rem 0; }
        .db-error { background:#ffe8e8; color:#9b1c1c; padding:0.9rem 1rem; border-radius:8px; margin-bottom:1rem; }
        @media (max-width: 700px) {
            .modal-asignar { padding: 1rem; margin: 2rem 1rem; }
            .proveedores-list { grid-template-columns: 1fr; }
        }
    </style>
    <script>
        // JS combinado: toggle selection + búsqueda y filtro por servicio
        document.addEventListener('DOMContentLoaded', function(){
            var cards = Array.from(document.querySelectorAll('.proveedor-card-asignar'));
            cards.forEach(function(card){
                card.addEventListener('click', function(e){
                    if (e.target.tagName.toLowerCase() === 'input') return;
                    var checkbox = card.querySelector('input[type="checkbox"]');
                    if (!checkbox) return;
                    checkbox.checked = !checkbox.checked;
                    card.classList.toggle('selected', checkbox.checked);
                });
                var cb = card.querySelector('input[type="checkbox"]');
                if (cb && cb.checked) card.classList.add('selected');
            });

            // Filtrado
            var search = document.getElementById('assign-prov-search');
            var filter = document.getElementById('assign-prov-filter');
            var clear = document.getElementById('assign-prov-clear');
            function applyFilterAssign() {
                var q = (search && search.value || '').trim().toLowerCase();
                var cat = (filter && filter.value) || '';
                cards.forEach(function(c){
                    var n = (c.dataset.nombre||'').toLowerCase();
                    var e = (c.dataset.email||'').toLowerCase();
                    var s = (c.dataset.servicio||'');
                    var matchText = q === '' || n.indexOf(q) !== -1 || e.indexOf(q) !== -1 || s.toLowerCase().indexOf(q) !== -1;
                    var matchCat = !cat || s === cat;
                    c.style.display = (matchText && matchCat) ? '' : 'none';
                });
            }
            if (search) search.addEventListener('input', applyFilterAssign);
            if (filter) filter.addEventListener('change', applyFilterAssign);
            if (clear) clear.addEventListener('click', function(){ if (search) search.value=''; if (filter) filter.value=''; applyFilterAssign(); });
        });
    </script>
</head>
<body>
    <div class="modal-asignar">
        <div class="modal-asignar-header">
            <span class="modal-asignar-title"><i class="fas fa-user-plus"></i> Asignar proveedores al evento</span>
            <button class="modal-asignar-close" onclick="window.location.href='dashboard_empresa.php'">&times;</button>
        </div>
        <form method="POST">
            <?php if ($db_error): ?>
                <div class="db-error">Error al consultar proveedores: <?= htmlspecialchars($db_error) ?></div>
            <?php endif; ?>

            <?php if (empty($proveedores)): ?>
                <div class="no-proveedores">
                    <i class="fas fa-users text-3xl mb-2"></i><br>
                    No hay proveedores disponibles para asignar.
                    <div style="margin-top:0.75rem; font-size:0.95rem;">
                        Verifique que existan proveedores dados de alta o <a href="../alta_proveedores.php" style="color:#1d4ed8; text-decoration:underline;">agregue uno aquí</a>.
                    </div>
                </div>
            <?php else: ?>
                <div style="display:flex; gap:0.75rem; align-items:center; margin-bottom:1rem;">
                    <input id="assign-prov-search" type="search" placeholder="Buscar proveedor por nombre, email o servicio..." style="flex:1; padding:10px; border:1px solid #e3e8ee; border-radius:8px;" />
                    <select id="assign-prov-filter" style="margin-left:8px; padding:10px; border:1px solid #e3e8ee; border-radius:8px;">
                        <option value="">Filtrar por servicio (todos)</option>
                        <?php
                        // construir lista de servicios desde $proveedores
                        $srvSeen = [];
                        foreach ($proveedores as $p) { if (!empty($p['servicio']) && !in_array($p['servicio'], $srvSeen)) $srvSeen[] = $p['servicio']; }
                        sort($srvSeen);
                        foreach ($srvSeen as $s) { echo '<option value="' . htmlspecialchars($s) . '">' . htmlspecialchars($s) . '</option>'; }
                        ?>
                    </select>
                    <button id="assign-prov-clear" type="button" style="margin-left:8px; padding:10px 12px; background:#fff; border:1px solid #cbd5e1; border-radius:8px;">Limpiar</button>
                </div>
                <div class="proveedores-list">
                    <?php foreach ($proveedores as $proveedor): ?>
                        <label class="proveedor-card-asignar" data-nombre="<?= htmlspecialchars($proveedor['nombre']) ?>" data-email="<?= htmlspecialchars($proveedor['email']) ?>" data-servicio="<?= htmlspecialchars($proveedor['servicio'] ?? '') ?>">
                            <input type="checkbox" name="proveedores[]" value="<?= $proveedor['id'] ?>" <?= in_array($proveedor['id'], $proveedores_asignados) ? 'checked' : '' ?> class="proveedor-checkbox">
                            <?php if (!empty($proveedor['foto'])): ?>
                                <img src="<?= htmlspecialchars($proveedor['foto']) ?>" alt="Foto de <?= htmlspecialchars($proveedor['nombre']) ?>" class="proveedor-foto-asignar"/>
                            <?php else: ?>
                                <div class="proveedor-foto-placeholder-asignar">
                                    <i class="fas fa-user" style="font-size:1.4rem;color:white"></i>
                                </div>
                            <?php endif; ?>
                            <div class="proveedor-info-asignar">
                                <div class="proveedor-nombre-asignar"><?= htmlspecialchars($proveedor['nombre']) ?></div>
                                <div class="proveedor-email-asignar"><i class="fas fa-envelope"></i> <?= htmlspecialchars($proveedor['email']) ?></div>
                                <div class="proveedor-cuil-asignar"><i class="fas fa-id-card"></i> <?= htmlspecialchars($proveedor['cuilcuit']) ?></div>
                                <div class="proveedor-cuil-asignar">Servicio: <?= htmlspecialchars($proveedor['servicio'] ?? '-') ?></div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="asignar-actions">
                <button type="submit" class="btn-asignar"><i class="fas fa-save"></i> Asignar seleccionados</button>
                <button type="button" class="btn-cancelar" onclick="window.location.href='dashboard_empresa.php'">Cancelar</button>
            </div>
        </form>
    </div>
</body>
</html>