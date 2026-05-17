<link rel="stylesheet" type="text/css" href="./estilos.css">
<?php
session_start();
// Normalize session keys: some login flows set user_id, otros usan usuario_id or id
if (isset($_SESSION['user_id']) && !isset($_SESSION['usuario_id'])) {
  $_SESSION['usuario_id'] = $_SESSION['user_id'];
}
if (isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
  $_SESSION['id'] = $_SESSION['user_id'];
}
// Legacy fallbacks may set other names; ensure 'usuario' and 'rol' exist for the auth check
if (!isset($_SESSION['usuario']) || !isset($_SESSION['rol'])) {
  // allow other variants
  if (isset($_SESSION['username']) && !isset($_SESSION['usuario'])) $_SESSION['usuario'] = $_SESSION['username'];
  if (isset($_SESSION['role']) && !isset($_SESSION['rol'])) $_SESSION['rol'] = $_SESSION['role'];
}
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'proveedor') {
  header("Location: index.php");
  exit();
}
?>

<?php

require_once 'dbconn.php';


function compute_req_estado($row) {
    if (!is_array($row)) return 'pendiente';
    if (isset($row['estado'])) return $row['estado'];
    $archivo = trim($row['archivo'] ?? '');
    $comentario = trim($row['comentario'] ?? '');
    if ($archivo !== '' && $comentario === '') return 'autorizado';
    if ($archivo !== '' && $comentario !== '') return 'revision';
    return 'pendiente';
}

// Verificar que el usuario esté logueado y sea proveedor
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'proveedor') {
   header("Location: index.php");
    exit();
}

// Canonical provider id: require explicit 'proveedor_id' in session to avoid confusion
$used_key = null;
$proveedor_id = 0;
if (isset($_SESSION['proveedor_id']) && strlen(trim((string)$_SESSION['proveedor_id'])) > 0) {
  $used_key = 'proveedor_id';
  $proveedor_id = intval($_SESSION['proveedor_id']);
} elseif (isset($_SESSION['usuario_id']) && strlen(trim((string)$_SESSION['usuario_id'])) > 0) {
  // fallback to legacy session key (users.id stored in session)
  $used_key = 'usuario_id';
  $proveedor_id = intval($_SESSION['usuario_id']);
} elseif (isset($_SESSION['id']) && strlen(trim((string)$_SESSION['id'])) > 0) {
  $used_key = 'id';
  $proveedor_id = intval($_SESSION['id']);
} else {
  die('Error: No se encontró el ID de proveedor en la sesión. Se requiere $_SESSION["proveedor_id"] o un fallback (usuario_id).');
}

// Detectar correctamente la tabla que relaciona empleados con eventos.
// Preferir 'empleados_evento' (usa empleados_proveedor) cuando existe, si no usar 'evento_empleado'.
$eventEmployeeTable = 'empleados_evento';
$tbl = $conn->query("SHOW TABLES LIKE 'empleados_evento'");
if (!($tbl && $tbl->num_rows > 0)) {
  $tbl2 = $conn->query("SHOW TABLES LIKE 'evento_empleado'");
  if ($tbl2 && $tbl2->num_rows > 0) $eventEmployeeTable = 'evento_empleado';
}

// Debug helper: muestra la sesión y el proveedor_id canónico cuando se añade ?debug_sess=1
if (isset($_GET['debug_sess']) && $_GET['debug_sess'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "-- SESSION DEBUG --\n";
    var_export($_SESSION);
    echo "\n\ncanonical_proveedor_id: ";
    var_export($proveedor_id);
    echo "\nused_session_key: ";
    var_export($used_key);
    echo "\n-- END DEBUG --\n";
    exit;
}

// Obtener evento seleccionado
$evento_id = isset($_GET['evento']) ? intval($_GET['evento']) : 0;

// Manejar acciones de requisitos para proveedores
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Subir archivo de requisito
    if (isset($_POST['subir_requisito_proveedor']) && isset($_POST['requisito_id']) && $evento_id) {
  $requisito_id = intval($_POST['requisito_id']);
  // use canonical provider id
  $proveedor_id = $proveedor_id;
    $estado = 'pendiente';
    $archivo_nombre = null;
    if (isset($_FILES['archivo_pdf']) && $_FILES['archivo_pdf']['error'] === UPLOAD_ERR_OK) {
      $dir = 'uploads/proveedor_requisitos/';
      if (!is_dir($dir)) mkdir($dir, 0777, true);
      $ext = pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION);
      $archivo_nombre = 'evento'.$evento_id.'_prov'.$proveedor_id.'_req'.$requisito_id.'_'.time().'.'.$ext;
      move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $dir.$archivo_nombre);
    }
    // Insertar o actualizar registro
    $stmt = $conn->prepare("SELECT id FROM proveedor_requisito_evento WHERE proveedor_id=? AND evento_id=? AND requisito_id=?");
    $stmt->bind_param("sii", $proveedor_id, $evento_id, $requisito_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $es_reemplazo = false;
      if ($res->num_rows > 0) {
      $row = $res->fetch_assoc();
      $id = $row['id'];
      // Al reemplazar el archivo, se borra el comentario
      $sql = "UPDATE proveedor_requisito_evento SET archivo=?, fecha_subida=NOW(), comentario=NULL WHERE id=?";
      $stmt2 = $conn->prepare($sql);
      $stmt2->bind_param("si", $archivo_nombre, $id);
      $stmt2->execute();
      $stmt2->close();
      $es_reemplazo = true;
    } else {
      // Insert without relying on an 'estado' column
      $sql = "INSERT INTO proveedor_requisito_evento (proveedor_id, evento_id, requisito_id, archivo, comentario, fecha_subida) VALUES (?, ?, ?, ?, NULL, NOW())";
      $stmt2 = $conn->prepare($sql);
      $stmt2->bind_param("iiis", $proveedor_id, $evento_id, $requisito_id, $archivo_nombre);
      $stmt2->execute();
      $stmt2->close();
    }
    $stmt->close();

  // Notificación a empresa (use new include)
  require_once __DIR__ . '/includes/notifications_lib.php';
    // Obtener empresa_id asociada al evento
    $empresa_id = null;
    $evento_q = $conn->query("SELECT empresa_id FROM eventos WHERE id = $evento_id");
    if ($evento_q && $evento_q->num_rows > 0) {
      $empresa_id = $evento_q->fetch_assoc()['empresa_id'];
    }
    if ($empresa_id) {
      // Obtener nombre del proveedor y requisito
      $proveedor_nombre = '';
      $req_nombre = '';
      $prov_q = $conn->query("SELECT nombre FROM proveedores WHERE id = $proveedor_id");
      if ($prov_q && $prov_q->num_rows > 0) $proveedor_nombre = $prov_q->fetch_assoc()['nombre'];
      $req_q = $conn->query("SELECT nombre FROM requisitos WHERE id = $requisito_id");
      if ($req_q && $req_q->num_rows > 0) $req_nombre = $req_q->fetch_assoc()['nombre'];
      crear_notificacion(
        $empresa_id,
        'empresa',
        $es_reemplazo ? 'fa-file-pen' : 'fa-file-arrow-up',
        $es_reemplazo ? 'Archivo reemplazado' : 'Archivo subido',
        "El proveedor $proveedor_nombre " . ($es_reemplazo ? 'reemplazó' : 'subió') . " el archivo '$req_nombre' para el evento #$evento_id."
      );
    }
    header("Location: dashboard_proveedor.php?evento=" . $evento_id . "&success=archivo_subido");
    exit;
    }
    
    // Agregar empleado del proveedor
    if (isset($_POST['agregar_empleado_proveedor']) && $evento_id) {
  // use canonical provider id
  // $proveedor_id already initialized above
        $nombre = trim($_POST['nombre_empleado_prov']);
        $apellido = trim($_POST['apellido_empleado_prov']);
        $cuil = trim($_POST['cuil_empleado_prov']);
        $puesto = trim($_POST['puesto_empleado_prov']);
        
        if ($nombre && $apellido && $cuil && $puesto) {
            // Insert new employee without writing 'estado' column (may have been removed)
            $stmt = $conn->prepare("INSERT INTO empleados_proveedor (proveedor_id, nombre, apellido, cuil, puesto, fecha_alta) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("issss", $proveedor_id, $nombre, $apellido, $cuil, $puesto);
            $stmt->execute();
            $stmt->close();
            header("Location: dashboard_proveedor.php?evento=" . $evento_id . "&success=empleado_agregado");
            exit;
        } else {
            $error = "Todos los campos del empleado son obligatorios.";
        }
    }

  // Asignar empleado existente al evento
  if (isset($_POST['asignar_empleado_existente']) && isset($_POST['empleado_existente']) && $evento_id) {
    $empleado_id = intval($_POST['empleado_existente']);
    // Si es array (multi-day checkboxes), convertir a string
    if (isset($_POST['fechas_asistencia'])) {
      if (is_array($_POST['fechas_asistencia'])) {
        $fechas_asistencia = implode(',', array_map('trim', $_POST['fechas_asistencia']));
      } else {
        $fechas_asistencia = trim($_POST['fechas_asistencia']);
      }
    } else {
      $fechas_asistencia = '';
    }
  // Verifica si ya está asignado
  $stmt = $conn->prepare("SELECT id FROM {$eventEmployeeTable} WHERE empleado_id = ? AND evento_id = ?");
        $stmt->bind_param("ii", $empleado_id, $evento_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows == 0) {
            // Asigna el empleado al evento con fechas
            $stmt_insert = $conn->prepare("INSERT INTO {$eventEmployeeTable} (empleado_id, evento_id, fechas_asistencia) VALUES (?, ?, ?)");
            $stmt_insert->bind_param("iis", $empleado_id, $evento_id, $fechas_asistencia);
            $stmt_insert->execute();
            $stmt_insert->close();
        } else {
            // Si ya está asignado, actualiza las fechas
            $stmt_update = $conn->prepare("UPDATE {$eventEmployeeTable} SET fechas_asistencia=? WHERE empleado_id=? AND evento_id=?");
            $stmt_update->bind_param("sii", $fechas_asistencia, $empleado_id, $evento_id);
            $stmt_update->execute();
            $stmt_update->close();
        }
        $stmt->close();
        header("Location: dashboard_proveedor.php?evento=" . $evento_id . "&success=empleado_asignado");
        exit;
    }

  // Quitar empleado asignado del evento
  if (isset($_POST['quitar_empleado_evento']) && isset($_POST['empleado_id']) && $evento_id) {
    $empleado_id_quitar = intval($_POST['empleado_id']);
  $stmt_del = $conn->prepare("DELETE FROM {$eventEmployeeTable} WHERE empleado_id = ? AND evento_id = ?");
  $stmt_del->bind_param("ii", $empleado_id_quitar, $evento_id);
    if ($stmt_del->execute()) {
      $stmt_del->close();
      header("Location: dashboard_proveedor.php?evento=" . $evento_id . "&success=empleado_quitado");
      exit;
    } else {
      $stmt_del->close();
      $error = 'Error al quitar el empleado del evento';
    }
  }
}

// Obtener requisitos del evento seleccionado
$requisitos_evento = [];
if ($evento_id) {
    $sql = "SELECT r.id, r.nombre, er.estado
            FROM evento_requisito er
            JOIN requisitos r ON er.requisito_id = r.id
            WHERE er.evento_id = $evento_id";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $requisitos_evento[] = $row;
    }
}

// Re-obtener con aplica_a si la columna existe
$requisitos_evento = [];
if ($evento_id) {
  $sql = "SELECT r.id, r.nombre, r.aplica_a, er.estado
      FROM evento_requisito er
      JOIN requisitos r ON er.requisito_id = r.id
      WHERE er.evento_id = $evento_id";
  $result = $conn->query($sql);
  while ($row = $result->fetch_assoc()) {
    $requisitos_evento[] = $row;
  }
}

// Obtener todos los eventos para el select

$eventos = [];
// use canonical $proveedor_id initialized earlier
$stmt = $conn->prepare("SELECT e.id, e.nombre FROM eventos e INNER JOIN proveedores_evento pe ON e.id = pe.evento_id WHERE pe.proveedor_id = ? ORDER BY e.fecha DESC, e.hora DESC");
$stmt->bind_param("i", $proveedor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
  $eventos[] = $row;
}
$stmt->close();

// Obtener empleados del proveedor para el evento
$empleados_proveedor_evento = [];
if ($evento_id) {
  // use canonical provider id
  // $proveedor_id already initialized above
  $stmt = $conn->prepare("
    SELECT ep.*, ee.fechas_asistencia
    FROM empleados_proveedor ep
    INNER JOIN {$eventEmployeeTable} ee ON ep.id = ee.empleado_id
    WHERE ep.proveedor_id = ? AND ee.evento_id = ?
  ");
  $stmt = $conn->prepare("
    SELECT ep.*, ee.fechas_asistencia
    FROM empleados_proveedor ep
    INNER JOIN {$eventEmployeeTable} ee ON ep.id = ee.empleado_id
    WHERE ep.proveedor_id = ? AND ee.evento_id = ?
  ");
  if (!$stmt) {
      die("Error en la preparación de la consulta: " . $conn->error);
  }
  $stmt->bind_param("ii", $proveedor_id, $evento_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
      $empleados_proveedor_evento[] = $row;
  }
  $stmt->close();
}

// Obtener requisitos subidos por el proveedor para el evento
$requisitos_proveedor_evento = [];
if ($evento_id) {
  // use canonical provider id
  // $proveedor_id already initialized above
    $sql = "SELECT pre.*, r.nombre as requisito_nombre FROM proveedor_requisito_evento pre JOIN requisitos r ON pre.requisito_id = r.id WHERE pre.proveedor_id=? AND pre.evento_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $proveedor_id, $evento_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $requisitos_proveedor_evento[$row['requisito_id']] = $row;
    }
    $stmt->close();
}

// Obtener todos los empleados del proveedor (sin filtrar por evento)
// use canonical $proveedor_id initialized earlier
$empleados_proveedor = [];
$stmt = $conn->prepare("SELECT id, nombre, apellido FROM empleados_proveedor WHERE proveedor_id = ?");
if (!$stmt) {
    die("Error en la preparación de la consulta: " . $conn->error);
}
$stmt->bind_param("i", $proveedor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $empleados_proveedor[] = $row;
}
$stmt->close();

// Determinar tipos de empleados; preferir los empleados asignados al evento si existen
$tiene_dependencia = false;
$tiene_monotributo = false;
$empleados_fuente = [];
if (!empty($empleados_proveedor_evento)) {
    // usar empleados asignados al evento
    $empleados_fuente = $empleados_proveedor_evento;
} else {
    // fallback: todos los empleados del proveedor
    $empleados_fuente = $empleados_proveedor;
}
foreach ($empleados_fuente as $ep) {
  $tipo = isset($ep['tipo_relacion']) ? strtolower($ep['tipo_relacion']) : 'dependencia';
  if ($tipo === 'monotributista') $tiene_monotributo = true;
  if ($tipo === 'dependencia') $tiene_dependencia = true;
}


function icono_estado($estado) {
    if ($estado === 'aprobado') return '<i class="fas fa-check-circle text-green-600 text-lg" title="Aprobado"></i>';
    if ($estado === 'rechazado') return '<i class="fas fa-times-circle text-red-600 text-lg" title="Rechazado"></i>';
    return '<i class="fas fa-clock text-yellow-500 text-lg" title="Pendiente"></i>';
}
?>
<html lang="en">
 <head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>
   RADEP - Dashboard Proveedor
  </title>
  <script src="https://cdn.tailwindcss.com">
  </script>
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
   
   .floating-action {
     position: fixed;
     bottom: 2rem;
     right: 2rem;
     width: 38px;
     height: 38px;
     border-radius: 50%;
     background: linear-gradient(135deg, #5092ab, #3b82f6);
     border: none;
     color: white;
     font-size: 1.15rem;
     cursor: pointer;
     box-shadow: 0 6px 24px rgba(80, 146, 171, 0.35);
     transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
   
   .floating-action:hover {
     transform: translateY(-4px) scale(1.08);
     box-shadow: 0 10px 32px rgba(80, 146, 171, 0.5);
     animation: none;
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
   
   .pdf-modal-bg {
     position: fixed;
     z-index: 1000;
     left: 0; top: 0; right: 0; bottom: 0;
     background: rgba(30,40,60,0.45);
     display: flex;
     align-items: center;
     justify-content: center;
     transition: background 0.2s;
   }
   
   .pdf-modal {
     background: #fff;
     border-radius: 18px;
     box-shadow: 0 8px 40px rgba(80,146,171,0.18);
     padding: 0;
     max-width: 90vw;
     max-height: 90vh;
     width: 700px;
     display: flex;
     flex-direction: column;
     overflow: hidden;
     animation: fadeInModal 0.3s;
   }
   
   @keyframes fadeInModal {
     from { opacity: 0; transform: scale(0.97); }
     to { opacity: 1; transform: scale(1); }
   }
   
   .pdf-modal-header {
     display: flex;
     justify-content: flex-end;
     background: #f3f6fa;
     padding: 0.5rem 1rem;
   }
   
   .pdf-modal-close {
     background: none;
     border: none;
     color: #ef4444;
     font-size: 1.5rem;
     cursor: pointer;
     transition: color 0.2s;
   }
   
   .pdf-modal-close:hover {
     color: #b91c1c;
   }
   
   .pdf-modal-iframe {
     width: 100%;
     height: 80vh;
     border: none;
     background: #f9fafb;
   }
   
   .btn-ver-pdf {
     background: #e63946;
     color: #fff;
     border: none;
     border-radius: 6px;
     padding: 0.2rem 0.7rem;
     font-size: 0.93rem;
     margin-left: 0.5em;
     cursor: pointer;
     transition: background 0.2s;
     display: flex;
     align-items: center;
     gap: 0.3em;
   }
   
   .btn-ver-pdf:hover {
     background: #b91c1c;
   }
   
   .archivo-nombre {
     color: #e63946;
     font-weight: 500;
     display: flex;
     align-items: center;
     gap: 0.3em;
   }
   
   .pdf-icon {
     color: #e63946;
     font-size: 1.1em;
     margin-right: 0.2em;
   }

   .asignar-empleado-card {
     margin: 20px auto;
     max-width: 360px;
     background: transparent;
     border-radius: 14px;
     box-shadow: 0 2px 8px rgba(0,0,0,0.04);
     padding: 18px 16px;
     border: 1px solid rgba(0,0,0,0.04);
   }

   /* Upload box used under each requisito - make transparent and remove heavy white background */
   .req-upload-box {
     background: transparent !important;
     border-radius: 0 !important;
     box-shadow: none !important;
     border: none !important;
     padding: 0 !important;
   }

  /* Ensure no child inside the upload box creates a white container */
  .req-upload-box, .req-upload-box * {
    background: transparent !important;
    box-shadow: none !important;
    border: none !important;
    border-radius: 0 !important;
  }

  /* Neutralize inner white boxes inside requisitos section specifically */
  .requisitos-section .bg-white,
  .requisitos-section .bg-gray-50,
  .requisitos-section .shadow-sm,
  .requisitos-section .shadow-lg,
  .requisitos-section .rounded-lg {
    background: transparent !important;
    box-shadow: none !important;
    border-radius: 0 !important;
  }

  /* Visible minimal buttons inside the upload area */
  .req-upload-box .btn-upload-pdf,
  .req-upload-box .btn-ver-pdf,
  .req-upload-box button[type="submit"] {
    background: transparent !important;
    color: #2563eb !important;
    border: 1px solid rgba(37,99,235,0.12) !important;
    padding: 6px 10px !important;
    border-radius: 6px !important;
    box-shadow: none !important;
  }

  /* Improve separation and readability for requisitos: tighter spacing + visible thin divider lines */
  /* Use the existing divide-y lines: make that single dividing line more opaque and avoid double borders */
  .requisitos-section ul > div, .requisitos-section ul > li {
    background: transparent !important;
    border-radius: 0 !important;
  margin: 4px 0 !important; /* remove horizontal inset so dividers reach edges */
    padding: 8px 12px !important;
    box-shadow: none !important;
    border: none !important; /* remove any explicit borders to avoid duplicates */
  }

  /* Keep items compact */
  .requisitos-section li {
    padding: 6px 12px !important;
    margin: 0 !important;
  width: 100% !important;
  box-sizing: border-box !important;
  }

  /* Strengthen the single dividing line provided by the Tailwind-like `divide-y` utility */
  /* Make the dividing line more visible and ensure it stretches full width of its container */
  .requisitos-section .divide-y > * + * {
    border-top: 2px solid rgba(0,0,0,0.28) !important; /* stronger single line */
    margin: 0 !important; /* ensure divider sits at full width (no inset margins) */
  }

  /* Ensure ULs do not inset the divider — remove outer padding/margins so lines run edge-to-edge */
  .requisitos-section ul {
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin: 0 !important;
  }

  /* Small vertical gap between block groups (keeps layout compact) */
  .requisitos-section ul > div + div,
  .requisitos-section ul > li + li {
    margin-top: 6px !important;
    padding-top: 6px !important;
  }

  /* Add a vertical separator between the two columns on medium+ screens */
  @media (min-width: 768px) {
    /* target the md:flex-row container: the class has a colon so escape it */
    .requisitos-section .flex.flex-col.md\:flex-row > .flex-1 + .flex-1 {
      border-left: 2px solid rgba(0,0,0,0.14) !important;
      padding-left: 16px !important; /* breathing room from the vertical line */
      margin-left: 8px !important;
    }
    /* Make both column wrappers use column layout so title and UL align top */
    .requisitos-section .flex.flex-col.md\:flex-row > .flex-1 {
      display: flex !important;
      flex-direction: column !important;
    }
    /* Let the inner UL expand so first LI items align across both columns */
    .requisitos-section .flex.flex-col.md\:flex-row > .flex-1 > ul {
      flex: 1 1 auto !important;
    }
    /* Ensure the title area has consistent height */
    .requisitos-section .flex.flex-col.md\:flex-row > .flex-1 h4 {
      min-height: 42px; /* ensures both titles occupy same vertical space */
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 6px !important;
    }
  }

  /* Layout tweak: ensure upload row items align nicely and are compact */
  .requisitos-section .req-upload-box {
    display: flex;
    gap: 8px;
    align-items: center;
    padding: 6px 0 !important;
  }

  /* Keep the archivo name compact and slightly separated from buttons */
  .requisitos-section .archivo-nombre {
    margin-left: 6px;
    margin-top: 6px !important;
    font-size: 0.95rem;
    color: rgba(0,0,0,0.75);
  }

   /* Neutralizar fondos blancos en botones secundarios para un diseño más limpio */
   .asignar-empleado-card select,
   .asignar-empleado-card label,
   .asignar-empleado-card .btn-upload-pdf,
   .asignar-empleado-card .btn-ver-pdf {
     background: transparent;
     box-shadow: none;
     border: 0;
   }
   button:not(.btn-primary) {
     background: transparent !important;
     border: none !important;
     box-shadow: none !important;
     color: inherit !important;
   }

   /* Specific: keep .btn-primary intact; style ver/upload/save buttons to sit visually on the card */
   .btn-ver-pdf, .btn-upload-pdf, .asignar-empleado-card .btn-ver-pdf, .mt-3 p button, .mt-3 form button {
     background: transparent !important;
     color: #2563eb !important;
     border: none !important;
     padding: 0.25rem 0.5rem !important;
     border-radius: 6px !important;
     box-shadow: none !important;
   }

   .asignar-empleado-card label {
     font-weight: 500;
     font-size: 1.1em;
     text-align: center;
     margin-bottom: 8px;
   }

   .asignar-empleado-card select {
     padding: 8px;
     border-radius: 6px;
     border: 1px solid #bcd;
   }

   .asignar-empleado-card button {
     background: linear-gradient(90deg, #3b82f6 60%, #2563eb 100%);
     color: #fff;
     border: none;
     border-radius: 6px;
     padding: 10px 0;
     font-weight: 600;
     font-size: 1em;
     cursor: pointer;
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
   <a href="calendario_eventos_proveedor.php" class="nav-btn flex items-center space-x-1 text-sm px-3 py-1.5">
    <i class="fas fa-calendar-alt"></i>
    <span>Eventos</span>
   </a>
   <a href="alta_empleados_proveedor.php" class="nav-btn flex items-center space-x-1 text-sm px-3 py-1.5">
    <i class="fas fa-user-plus"></i>
    <span>Alta de Empleados</span>
   </a>
   <!-- Recuadro horizontal de advertencia y archivos de protocolo/cláusulas -->
   <div class="flex items-center ml-0 sm:ml-4">
     <div class="bg-red-100 border border-red-400 text-red-800 rounded-lg px-3 py-2 flex flex-row items-center gap-2 shadow-sm" style="white-space:nowrap; min-width:220px; max-width:100vw;">
       <span class="font-bold text-xs mr-2"><i class="fas fa-exclamation-triangle mr-1"></i>IMPORTANTE!</span>
       <span class="text-xs font-semibold mr-2">Antes de cargar sus requisitos busque las cláusulas de no repetición incluidas en el protocolo de aquí:</span>
       <?php
       $archivos_empresa = [];
       if ($evento_id) {
         $stmt = $conn->prepare("SELECT archivo, nombre_original FROM protocolo_clausulas WHERE evento_id = ? ORDER BY id DESC");
         $stmt->bind_param('i', $evento_id);
         $stmt->execute();
         $stmt->bind_result($archivo, $nombre_original);
         while ($stmt->fetch()) {
           $archivos_empresa[] = ['archivo' => $archivo, 'nombre' => $nombre_original];
         }
         $stmt->close();
       }
       if (count($archivos_empresa) > 0):
         foreach ($archivos_empresa as $archivo): ?>
           <a href="uploads/protocolo_clausulas/<?= htmlspecialchars($archivo['archivo']) ?>" target="_blank" class="bg-white border border-red-300 text-red-700 px-2 py-1 rounded text-xs font-semibold mx-1 hover:bg-red-200 transition flex items-center gap-1" style="display:inline-flex;">
             <i class="fas fa-file-pdf"></i> <?= htmlspecialchars($archivo['nombre']) ?>
           </a>
         <?php endforeach; else: ?>
           <span class="text-xs text-gray-500 ml-2">No hay archivos cargados por la empresa aún.</span>
       <?php endif; ?>
     </div>
   </div>
  </nav>

<!-- Main content -->
<main class="flex flex-col md:flex-row px-2 md:px-4 py-4 md:py-6 space-y-4 md:space-y-0 md:space-x-4 flex-grow min-h-0 w-full">
  <?php if (isset($_GET['success'])): ?>
    <div class="fixed top-4 right-4 z-50">
      <?php if ($_GET['success'] === 'archivo_subido'): ?>
        <div class="bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2">
          <i class="fas fa-check-circle"></i>
          <span>Archivo subido correctamente</span>
        </div>
      <?php elseif ($_GET['success'] === 'empleado_agregado'): ?>
        <div class="bg-blue-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2">
          <i class="fas fa-user-plus"></i>
          <span>Empleado agregado correctamente</span>
        </div>
      <?php elseif ($_GET['success'] === 'empleado_asignado'): ?>
        <div class="bg-yellow-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2">
          <i class="fas fa-user-check"></i>
          <span>Empleado asignado al evento</span>
        </div>
      <?php endif; ?>
    </div>
    <script>
      setTimeout(function() {
        document.querySelector('.fixed.top-4.right-4').style.display = 'none';
      }, 3000);
    </script>
  <?php endif; ?>
  
  <?php if (isset($error)): ?>
    <div class="fixed top-4 right-4 z-50">
      <div class="bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2">
        <i class="fas fa-exclamation-triangle"></i>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
    </div>
    <script>
      setTimeout(function() {
        document.querySelector('.fixed.top-4.right-4').style.display = 'none';
      }, 5000);
    </script>
  <?php endif; ?>

 <!-- Left sidebar -->
 <aside aria-label="Requisitos navigation" class="w-full md:w-[380px] card select-none flex flex-col flex-shrink-0 mb-4 md:mb-0">
  <div class="bg-[#5092ab] font-semibold text-white px-4 py-3 border-b border-[#5092ab] flex items-center justify-between rounded-t-lg">
   <div class="flex items-center space-x-3">
     <i class="fas fa-clipboard-list"></i>
     <span>Asignación de Empleados</span>
   </div>
  </div>
  <div class="flex-1 overflow-y-auto">
    <?php if ($evento_id): ?>
      <!-- Formulario para asignar empleado movido al sidebar -->
  <div class="asignar-empleado-card sidebar" style="margin:12px; padding:16px; background:transparent; border-radius:12px;">
        <form method="post" action="" style="display: flex; flex-direction: column; gap: 12px;">
            <label for="empleado_existente" style="font-weight: 600; font-size: 1em; text-align: left; margin-bottom: 6px; color:#2563eb;">
                Asignar empleado existente al evento
            </label>
            <select name="empleado_existente" id="empleado_existente" required style="padding: 8px; border-radius: 6px; border: 1px solid #bcd;">
              <option value="">Seleccionar empleado...</option>
              <?php foreach ($empleados_proveedor as $empleado): ?>
                <option value="<?= $empleado['id'] ?>">
                  <?= htmlspecialchars($empleado['nombre'] . ' ' . $empleado['apellido']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php
            // Obtener fechas del evento (mismo comportamiento que antes)
            $fecha_inicio = null;
            $fecha_fin = null;
            if ($evento_id) {
              $stmt = $conn->prepare("SELECT fecha, fecha_fin FROM eventos WHERE id = ?");
              $stmt->bind_param("i", $evento_id);
              $stmt->execute();
              $stmt->bind_result($fecha_inicio, $fecha_fin);
              $stmt->fetch();
              $stmt->close();
            }
            if ($fecha_inicio) {
              $fecha_inicio_dt = new DateTime($fecha_inicio);
              $fecha_fin_dt = $fecha_fin ? new DateTime($fecha_fin) : $fecha_inicio_dt;
              $interval = $fecha_inicio_dt->diff($fecha_fin_dt);
              $days = $interval->days;
              if ($days == 0) {
                echo '<div><label class="block text-sm font-medium text-[#5092ab] mb-2">Fechas de asistencia</label>';
                echo '<input type="hidden" name="fechas_asistencia" value="'.htmlspecialchars($fecha_inicio).'" />';
                echo '<span class="block px-3 py-2 bg-gray-100 rounded text-gray-700">'.date('d/m/Y', strtotime($fecha_inicio)).'</span>';
                echo '</div>';
              } else {
                echo '<div><label class="block text-sm font-medium text-[#5092ab] mb-2">Fechas de asistencia</label>';
                echo '<div class="flex flex-wrap gap-2">';
                for ($i = 0; $i <= $days; $i++) {
                  $d = clone $fecha_inicio_dt;
                  $d->modify("+{$i} day");
                  $date_str = $d->format('Y-m-d');
                  echo '<label class="flex items-center gap-1 text-sm bg-gray-100 px-2 py-1 rounded">';
                  echo '<input type="checkbox" name="fechas_asistencia[]" value="'.$date_str.'"> '.date('d/m/Y', strtotime($date_str));
                  echo '</label>';
                }
                echo '</div>';
                echo '<span class="text-xs text-gray-500 block mt-1">Selecciona los días que asistirá el empleado</span>';
                echo '</div>';
              }
            }
            ?>
            <button type="submit" name="asignar_empleado_existente" style="background: linear-gradient(90deg, #3b82f6 60%, #2563eb 100%); color: #fff; border: none; border-radius: 6px; padding: 8px 0; font-weight: 600; font-size: 0.95em; cursor: pointer;">
                Asignar
            </button>
        </form>
      </div>
    <?php else: ?>
      <div class="text-center text-gray-500 py-8">
        <i class="fas fa-calendar-alt text-2xl mb-2"></i>
        <p>Selecciona un evento para ver sus requisitos</p>
      </div>
    <?php endif; ?>
  </div>
 </aside>
 
 <!-- Main content -->
 <section class="flex-1 space-y-6 min-w-0 w-full">
  <!-- Estado del Evento -->
  <section class="card">
   <header class="text-[13px] font-bold text-[#5092ab] px-4 py-2 border-b border-[#5092ab] select-none rounded-t-lg">
    Estado del evento:
   </header>
   <div class="flex items-center gap-3 px-4 py-3 select-text text-[14px] text-gray-900">
    <span class="whitespace-nowrap">ELEGIR EVENTO</span>
    <select class="border border-[#5092ab] rounded-md px-3 py-2 text-[14px] text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#5092ab] transition bg-white flex-1 min-w-0"
            onchange="(function(v){ if(v === '__home__' || v === '') { window.location.href = 'dashboard_proveedor.php'; } else { window.location.href = 'dashboard_proveedor.php?evento=' + encodeURIComponent(v); } })(this.value);">
     <?php if ($evento_id): ?>
       <option value="__home__">Volver al inicio</option>
     <?php else: ?>
       <option value="">Selecciona evento</option>
     <?php endif; ?>
     <?php foreach($eventos as $ev): ?>
       <option value="<?= $ev['id'] ?>" <?= ($evento_id == $ev['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ev['nombre']) ?></option>
     <?php endforeach; ?>
    </select>
   </div>
  </section>
  
  <!-- Requisitos section -->
  <section class="card requisitos-section">
   <header class="text-[13px] font-bold text-[#5092ab] px-4 py-2 border-b border-[#5092ab] select-none rounded-t-lg flex items-center justify-between">
    <div class="flex items-center space-x-3">
      <span>Requisitos del Evento</span>
      <?php if ($evento_id && count($requisitos_evento) > 0): ?>
        <span class="text-xs bg-[#5092ab] text-white px-2 py-1 rounded-full">
          <?= count($requisitos_evento) ?> requisito<?= count($requisitos_evento) > 1 ? 's' : '' ?>
        </span>
      <?php endif; ?>
    </div>
   </header>
   <ul class="divide-y divide-[#5092ab]/30 select-text text-[14px] text-gray-900">
  <?php if (!empty($empleados_proveedor_evento) && ($tiene_dependencia xor $tiene_monotributo) && ($tiene_dependencia || $tiene_monotributo)): ?>
      <div style="padding:12px; background:#fff8; margin:8px; border-radius:8px;">
        <?php if ($tiene_dependencia && !$tiene_monotributo): ?>
          <strong>Atención:</strong> Los empleados asignados a este evento son todos en relación de dependencia. No es necesario subir requisitos específicos para monotributistas.
        <?php elseif ($tiene_monotributo && !$tiene_dependencia): ?>
          <strong>Atención:</strong> Los empleados asignados a este evento son todos monotributistas. No es necesario subir requisitos específicos para empleados en relación de dependencia.
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="flex flex-col md:flex-row gap-4">
  <div class="flex-1 rounded-lg overflow-hidden p-2">
  <h4 class="text-[#5092ab] text-center font-bold mb-2 text-sm">Relación de Dependencia</h4>
  <ul class="divide-y divide-[#5092ab]/20" style="padding-bottom:8px;">
          <?php
          $dependencia_keywords = ['vida', 'art', 'f931'];
      foreach($requisitos_evento as $req):
            $nombre = mb_strtolower($req['nombre']);
            foreach ($dependencia_keywords as $kw) {
              if (strpos($nombre, $kw) !== false) {
        // si aplica_a está presente y es monotributista, omitir/dim
        $aplica = $req['aplica_a'] ?? 'ambos';
        $reqProv = $requisitos_proveedor_evento[$req['id']] ?? null;
        $should_show = !($aplica === 'monotributista');
        $dim_class = $should_show ? '' : 'opacity-40';
          ?>
      <li class="px-4 py-3 hover:bg-[#5092ab]/10 transition relative <?= $dim_class ?>">
              <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                  <i class="fas fa-file-alt text-[#5092ab] text-lg"></i>
                  <div class="flex flex-col">
                    <span class="font-medium text-gray-900"><?= htmlspecialchars($req['nombre']) ?></span>
                    <span class="text-xs text-gray-500">
                      <?php if ($reqProv && $reqProv['archivo']): ?>
                        <i class="fas fa-file-pdf mr-1"></i>Archivo subido: <?= htmlspecialchars($reqProv['archivo']) ?>
                      <?php else: ?>
                        <i class="fas fa-exclamation-triangle mr-1"></i>Archivo pendiente
                      <?php endif; ?>
                    </span>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <?php
                    $estado = compute_req_estado($reqProv);
                    $comentario = $reqProv['comentario'] ?? '';
                    $colorEstado = 'bg-yellow-100 text-yellow-800 border-yellow-400';
                    if ($estado === 'autorizado') $colorEstado = 'bg-green-100 text-green-700 border-green-400';
                    elseif ($estado === 'revision') $colorEstado = 'bg-red-100 text-red-700 border-red-400';
                  ?>
                  <span class="text-xs px-2 py-1 rounded-full font-semibold border <?= $colorEstado ?>">
                    <?= ucfirst($estado) ?>
                  </span>
                  <?php if ($comentario): ?>
                    <span class="ml-2 px-2 py-1 rounded bg-gray-100 text-gray-800 text-xs border border-gray-300">Comentario: <?= htmlspecialchars($comentario) ?></span>
                  <?php endif; ?>
                  <?php if ($reqProv && $reqProv['archivo']): ?>
                    <button onclick="verPdfModal('uploads/proveedor_requisitos/<?= htmlspecialchars($reqProv['archivo']) ?>')" class="btn-ver-pdf" style="min-width:unset; width:auto; padding:0.18rem 0.7rem; font-size:0.95em;">
                      <i class="fas fa-eye"></i>
                      <span style="display:inline-block; min-width:40px; text-align:center;">Ver</span>
                    </button>
                  <?php endif; ?>
                </div>
              </div>
              <?php if (!$should_show): ?>
                <div class="mt-2 text-sm text-gray-600">Este requisito no aplica para monotributistas.</div>
              <?php endif; ?>
              <div class="mt-3 p-3 req-upload-box">
                <?php if ($estado === 'revision'): ?>
                  <form method="post" enctype="multipart/form-data" class="flex items-center gap-2">
                    <input type="hidden" name="subir_requisito_proveedor" value="1">
                    <input type="hidden" name="requisito_id" value="<?= $req['id'] ?>">
                    <label class="btn-upload-pdf cursor-pointer flex items-center" style="font-size:0.85em; padding:0.18rem 0.6rem; background:#f7fafc; color:#2563eb; border:1px solid #bcd;">
                      <i class="fas fa-file-upload mr-1" style="font-size:1em;"></i>
                      <?= $reqProv && $reqProv['archivo'] ? 'Reemplazar PDF' : 'Subir PDF' ?>
                      <input type="file" name="archivo_pdf" accept="application/pdf" class="form-input-file" required style="display:none;" onchange="mostrarNombreArchivo(this, <?= $req['id'] ?>)">
                    </label>
                    <button type="submit" class="px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition text-xs flex items-center border border-green-300" style="font-size:0.85em;">
                      <i class="fas fa-save mr-1" style="font-size:1em;"></i>Guardar
                    </button>
                  </form>
                  <div id="archivo-nombre-<?= $req['id'] ?>" class="archivo-nombre mt-2"></div>
                <?php elseif (!$reqProv || !$reqProv['archivo']): ?>
                  <form method="post" enctype="multipart/form-data" class="flex items-center gap-2">
                    <input type="hidden" name="subir_requisito_proveedor" value="1">
                    <input type="hidden" name="requisito_id" value="<?= $req['id'] ?>">
                    <label class="btn-upload-pdf cursor-pointer flex items-center" style="font-size:0.85em; padding:0.18rem 0.6rem; background:#f7fafc; color:#2563eb; border:1px solid #bcd;">
                      <i class="fas fa-file-upload mr-1" style="font-size:1em;"></i>
                      Subir PDF
                      <input type="file" name="archivo_pdf" accept="application/pdf" class="form-input-file" required style="display:none;" onchange="mostrarNombreArchivo(this, <?= $req['id'] ?>)">
                    </label>
                    <button type="submit" class="px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition text-xs flex items-center border border-green-300" style="font-size:0.85em;">
                      <i class="fas fa-save mr-1" style="font-size:1em;"></i>Guardar
                    </button>
                  </form>
                  <div id="archivo-nombre-<?= $req['id'] ?>" class="archivo-nombre mt-2"></div>
                <?php elseif ($estado !== 'autorizado'): ?>
                  <button type="button" class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded hover:bg-yellow-200 transition text-xs flex items-center border border-yellow-300" style="font-size:0.85em;" onclick="document.getElementById('form-reemplazar-<?= $req['id'] ?>').style.display='flex'">
                    <i class="fas fa-sync-alt mr-1" style="font-size:1em;"></i>Cambiar archivo
                  </button>
                  <form id="form-reemplazar-<?= $req['id'] ?>" method="post" enctype="multipart/form-data" class="flex items-center gap-2 mt-2" style="display:none;">
                    <input type="hidden" name="subir_requisito_proveedor" value="1">
                    <input type="hidden" name="requisito_id" value="<?= $req['id'] ?>">
                    <label class="btn-upload-pdf cursor-pointer flex items-center" style="font-size:0.85em; padding:0.18rem 0.6rem; background:#f7fafc; color:#2563eb; border:1px solid #bcd;">
                      <i class="fas fa-file-upload mr-1" style="font-size:1em;"></i>
                      Reemplazar PDF
                      <input type="file" name="archivo_pdf" accept="application/pdf" class="form-input-file" required style="display:none;" onchange="mostrarNombreArchivo(this, <?= $req['id'] ?>)">
                    </label>
                    <button type="submit" class="px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition text-xs flex items-center border border-green-300" style="font-size:0.85em;">
                      <i class="fas fa-save mr-1" style="font-size:1em;"></i>Guardar
                    </button>
                  </form>
                  <div id="archivo-nombre-<?= $req['id'] ?>" class="archivo-nombre mt-2"></div>
                <?php endif; ?>
              </div>
            </li>
          <?php break; } }
          endforeach; ?>
        </ul>
      </div>
  <div class="flex-1 rounded-lg overflow-hidden p-2">
  <h4 class="text-[#5092ab] text-center font-bold mb-2 text-sm">Monotributistas</h4>
  <ul class="divide-y divide-[#5092ab]/20" style="padding-bottom:8px;">
          <?php
          $mono_keywords = ['accidente', 'ap', 'muerte', 'invalidez', 'asistencia médica', 'farmacéutica'];
      foreach($requisitos_evento as $req):
            $nombre = mb_strtolower($req['nombre']);
            foreach ($mono_keywords as $kw) {
              if (strpos($nombre, $kw) !== false) {
        $aplica = $req['aplica_a'] ?? 'ambos';
        $reqProv = $requisitos_proveedor_evento[$req['id']] ?? null;
        $should_show = !($aplica === 'dependencia');
        $dim_class = $should_show ? '' : 'opacity-40';
  $estado = compute_req_estado($reqProv);
    $comentario = $reqProv['comentario'] ?? '';
    $colorEstado = 'bg-yellow-100 text-yellow-800 border-yellow-400';
    if ($estado === 'autorizado') $colorEstado = 'bg-green-100 text-green-700 border-green-400';
    elseif ($estado === 'revision') $colorEstado = 'bg-red-100 text-red-700 border-red-400';
          ?>
      <li class="px-4 py-3 hover:bg-[#5092ab]/10 transition relative <?= $dim_class ?>">
              <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                  <i class="fas fa-file-alt text-[#5092ab] text-lg"></i>
                  <div class="flex flex-col">
                    <span class="font-medium text-gray-900"><?= htmlspecialchars($req['nombre']) ?></span>
                    <span class="text-xs text-gray-500">
                      <?php if ($reqProv && $reqProv['archivo']): ?>
                        <i class="fas fa-file-pdf mr-1"></i>Archivo subido: <?= htmlspecialchars($reqProv['archivo']) ?>
                      <?php else: ?>
                        <i class="fas fa-exclamation-triangle mr-1"></i>Archivo pendiente
                      <?php endif; ?>
                    </span>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <span class="text-xs px-2 py-1 rounded-full font-semibold border <?= $colorEstado ?>">
                    <?= ucfirst($estado) ?>
                  </span>
                  <?php if ($comentario): ?>
                    <span class="ml-2 px-2 py-1 rounded bg-gray-100 text-gray-800 text-xs border border-gray-300">Comentario: <?= htmlspecialchars($comentario) ?></span>
                  <?php endif; ?>
                  <?php if ($reqProv && $reqProv['archivo']): ?>
                    <button onclick="verPdfModal('uploads/proveedor_requisitos/<?= htmlspecialchars($reqProv['archivo']) ?>')" class="btn-ver-pdf" style="min-width:unset; width:auto; padding:0.18rem 0.7rem; font-size:0.95em;">
                      <i class="fas fa-eye"></i>
                      <span style="display:inline-block; min-width:40px; text-align:center;">Ver</span>
                    </button>
                  <?php endif; ?>
                </div>
              </div>
              <?php if (!$should_show): ?>
                <div class="mt-2 text-sm text-gray-600">Este requisito no aplica para empleados en relación de dependencia.</div>
              <?php endif; ?>
              <div class="mt-3 p-3 req-upload-box">
                <?php if ($estado === 'revision'): ?>
                  <form method="post" enctype="multipart/form-data" class="flex items-center gap-2">
                    <input type="hidden" name="subir_requisito_proveedor" value="1">
                    <input type="hidden" name="requisito_id" value="<?= $req['id'] ?>">
                    <label class="btn-upload-pdf cursor-pointer flex items-center" style="font-size:0.85em; padding:0.18rem 0.6rem; background:#f7fafc; color:#2563eb; border:1px solid #bcd;">
                      <i class="fas fa-file-upload mr-1" style="font-size:1em;"></i>
                      <?= $reqProv && $reqProv['archivo'] ? 'Reemplazar PDF' : 'Subir PDF' ?>
                      <input type="file" name="archivo_pdf" accept="application/pdf" class="form-input-file" required style="display:none;" onchange="mostrarNombreArchivo(this, <?= $req['id'] ?>)">
                    </label>
                    <button type="submit" class="px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition text-xs flex items-center border border-green-300" style="font-size:0.85em;">
                      <i class="fas fa-save mr-1" style="font-size:1em;"></i>Guardar
                    </button>
                  </form>
                  <div id="archivo-nombre-<?= $req['id'] ?>" class="archivo-nombre mt-2"></div>
                <?php elseif (!$reqProv || !$reqProv['archivo']): ?>
                  <form method="post" enctype="multipart/form-data" class="flex items-center gap-2">
                    <input type="hidden" name="subir_requisito_proveedor" value="1">
                    <input type="hidden" name="requisito_id" value="<?= $req['id'] ?>">
                    <label class="btn-upload-pdf cursor-pointer flex items-center" style="font-size:0.85em; padding:0.18rem 0.6rem; background:#f7fafc; color:#2563eb; border:1px solid #bcd;">
                      <i class="fas fa-file-upload mr-1" style="font-size:1em;"></i>
                      Subir PDF
                      <input type="file" name="archivo_pdf" accept="application/pdf" class="form-input-file" required style="display:none;" onchange="mostrarNombreArchivo(this, <?= $req['id'] ?>)">
                    </label>
                    <button type="submit" class="px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition text-xs flex items-center border border-green-300" style="font-size:0.85em;">
                      <i class="fas fa-save mr-1" style="font-size:1em;"></i>Guardar
                    </button>
                  </form>
                  <div id="archivo-nombre-<?= $req['id'] ?>" class="archivo-nombre mt-2"></div>
                <?php elseif ($estado !== 'autorizado'): ?>
                  <button type="button" class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded hover:bg-yellow-200 transition text-xs flex items-center border border-yellow-300" style="font-size:0.85em;" onclick="document.getElementById('form-reemplazar-<?= $req['id'] ?>').style.display='flex'">
                    <i class="fas fa-sync-alt mr-1" style="font-size:1em;"></i>Cambiar archivo
                  </button>
                  <form id="form-reemplazar-<?= $req['id'] ?>" method="post" enctype="multipart/form-data" class="flex items-center gap-2 mt-2" style="display:none;">
                    <input type="hidden" name="subir_requisito_proveedor" value="1">
                    <input type="hidden" name="requisito_id" value="<?= $req['id'] ?>">
                    <label class="btn-upload-pdf cursor-pointer flex items-center" style="font-size:0.85em; padding:0.18rem 0.6rem; background:#f7fafc; color:#2563eb; border:1px solid #bcd;">
                      <i class="fas fa-file-upload mr-1" style="font-size:1em;"></i>
                      Reemplazar PDF
                      <input type="file" name="archivo_pdf" accept="application/pdf" class="form-input-file" required style="display:none;" onchange="mostrarNombreArchivo(this, <?= $req['id'] ?>)">
                    </label>
                    <button type="submit" class="px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition text-xs flex items-center border border-green-300" style="font-size:0.85em;">
                      <i class="fas fa-save mr-1" style="font-size:1em;"></i>Guardar
                    </button>
                  </form>
                  <div id="archivo-nombre-<?= $req['id'] ?>" class="archivo-nombre mt-2"></div>
                <?php endif; ?>
              </div>
            </li>
          <?php break; } }
          endforeach; ?>
        </ul>
      </div>
    </div>
   </ul>
  </section>
  
  <!-- Empleados del Proveedor section -->
  <section class="card">
   <header class="text-[13px] font-bold text-[#5092ab] px-4 py-2 border-b border-[#5092ab] select-none rounded-t-lg flex items-center">
    <span>Empleados del Proveedor</span>
    <?php if ($evento_id && count($empleados_proveedor_evento) > 0): ?>
        <span class="text-xs bg-[#5092ab] text-white px-2 py-1 rounded-full ml-2">
          <?= count($empleados_proveedor_evento) ?> empleado<?= count($empleados_proveedor_evento) > 1 ? 's' : '' ?>
        </span>
    <?php endif; ?>
</header>

<!-- Formulario de asignar empleado movido al sidebar para un mejor diseño y accesibilidad -->

<?php if (count($empleados_proveedor_evento) > 0): ?>
  <div style="margin: 28px auto; max-width: 760px;">
        <h3 style="color: #2563eb; text-align: center; margin-bottom: 24px; letter-spacing: 1px;">
            Empleados asignados a este evento
        </h3>
        <table style="border-radius: 10px; overflow: hidden; box-shadow: 0 2px 12px #0002; width: 100%; background: #fff; font-size: 1.05em;">
            <thead>
        <tr style="background: #2563eb; color: #fff; border-bottom: 2px solid #5092ab; text-align: center;">
          <th style="padding: 12px; border-right: 1px solid #e5e7eb;">Nombre</th>
          <th style="padding: 12px; border-right: 1px solid #e5e7eb;">Apellido</th>
          <th style="padding: 12px; border-right: 1px solid #e5e7eb;">CUIL</th>
          <th style="padding: 12px; border-right: 1px solid #e5e7eb;">Puesto</th>
          <th style="padding: 12px; border-right: 1px solid #e5e7eb;">Tipo de Relación</th>
          <th style="padding: 12px; border-right: 1px solid #e5e7eb;">Fechas de asistencia</th>
          <th style="padding: 12px;">Acciones</th>
        </tr>
            </thead>
            <tbody>
                <?php $i = 0; foreach ($empleados_proveedor_evento as $empleado): ?>
          <tr style="background: <?= $i++ % 2 == 0 ? '#f4f8fb' : '#fff' ?>; color: #222; text-align: center; border-bottom: 1px solid #e5e7eb;">
            <td style="padding: 10px; border-right: 1px solid #e5e7eb; vertical-align: middle;"><?= htmlspecialchars($empleado['nombre'] ?? '-') ?></td>
            <td style="padding: 10px; border-right: 1px solid #e5e7eb; vertical-align: middle;"><?= htmlspecialchars($empleado['apellido'] ?? '-') ?></td>
            <td style="padding: 10px; border-right: 1px solid #e5e7eb; vertical-align: middle;"><?= htmlspecialchars($empleado['cuil'] ?? '-') ?></td>
            <td style="padding: 10px; border-right: 1px solid #e5e7eb; vertical-align: middle;"><?= htmlspecialchars($empleado['puesto'] ?? '-') ?></td>
            <td style="padding: 10px; border-right: 1px solid #e5e7eb; vertical-align: middle;">
              <?php $rel = $empleado['tipo_relacion'] ?? '-'; echo ucfirst(mb_strtolower($rel)); ?>
            </td>
            <td style="padding: 10px; vertical-align: middle;">
              <?php if (!empty($empleado['fechas_asistencia'])): ?>
                <?php
                  $fechas = explode(',', $empleado['fechas_asistencia']);
                  foreach ($fechas as $fecha) {
                    echo '<span class="inline-block bg-blue-50 text-blue-700 rounded px-2 py-1 mx-1">'.date('d/m/Y', strtotime($fecha)).'</span>';
                  }
                ?>
              <?php else: ?>
                <span class="text-gray-400">No asignadas</span>
              <?php endif; ?>
            </td>
            <td style="padding: 10px; vertical-align: middle; width:64px;">
              <form method="post" onsubmit="return confirm('Quitar este empleado del evento?');" style="margin:0;">
                <input type="hidden" name="empleado_id" value="<?= intval($empleado['id']) ?>" />
                <button type="submit" name="quitar_empleado_evento" title="Quitar empleado" style="background:transparent; border:0; color:#ef4444; padding:6px; border-radius:8px; cursor:pointer; width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center; transition:background .15s;">
                  <i class="fas fa-trash-alt" style="font-size:14px;"></i>
                </button>
              </form>
            </td>
          </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div style="margin: 32px 0; color: #888; font-size: 1.1em; text-align: center;">
        No hay empleados asignados a este evento.
    </div>
<?php endif; ?>
  </section>
 </section>
</main>

<!-- Modal para agregar empleado -->
<?php if ($evento_id): ?>
<div id="modalAgregarEmpleado" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display:none;">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4 relative">
    <button onclick="document.getElementById('modalAgregarEmpleado').style.display='none'" class="absolute top-4 right-4 text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
    <h2 class="text-xl font-bold text-[#5092ab] mb-6 flex items-center">
      <i class="fas fa-user-plus text-[#5092ab] mr-2"></i>Agregar empleado
    </h2>
    <form method="post" class="space-y-4">
      <input type="hidden" name="agregar_empleado_proveedor" value="1"/>
      <?php
      // Obtener fechas del evento para el modal también
      $fecha_inicio = null;
      $fecha_fin = null;
      if ($evento_id) {
        $stmt = $conn->prepare("SELECT fecha, fecha_fin FROM eventos WHERE id = ?");
        $stmt->bind_param("i", $evento_id);
        $stmt->execute();
        $stmt->bind_result($fecha_inicio, $fecha_fin);
        $stmt->fetch();
        $stmt->close();
      }
      if ($fecha_inicio) {
        $fecha_inicio_dt = new DateTime($fecha_inicio);
        $fecha_fin_dt = $fecha_fin ? new DateTime($fecha_fin) : $fecha_inicio_dt;
        $interval = $fecha_inicio_dt->diff($fecha_fin_dt);
        $days = $interval->days;
        if ($days == 0) {
          // Evento de un solo día
          echo '<div><label class="block text-sm font-medium text-[#5092ab] mb-2">Fechas de asistencia</label>';
          echo '<input type="hidden" name="fechas_asistencia" value="'.htmlspecialchars($fecha_inicio).'" />';
          echo '<span class="block px-3 py-2 bg-gray-100 rounded text-gray-700">'.date('d/m/Y', strtotime($fecha_inicio)).'</span>';
          echo '</div>';
        } else {
          // Evento de varios días
          echo '<div><label class="block text-sm font-medium text-[#5092ab] mb-2">Fechas de asistencia</label>';
          echo '<div class="flex flex-wrap gap-2">';
          $dates = [];
          for ($i = 0; $i <= $days; $i++) {
            $d = clone $fecha_inicio_dt;

            $d->modify("+{$i} day");
            $date_str = $d->format('Y-m-d');
            $dates[] = $date_str;
            echo '<label class="flex items-center gap-1 text-sm bg-gray-100 px-2 py-1 rounded">';
            echo '<input type="checkbox" name="fechas_asistencia[]" value="'.$date_str.'"> '.date('d/m/Y', strtotime($date_str));
            echo '</label>';
          }
          echo '</div>';
          echo '<span class="text-xs text-gray-500 block mt-1">Selecciona los días que asistirá el empleado</span>';
          echo '</div>';
        }
      }
      ?>
      <div>
        <label class="block text-sm font-medium text-[#5092ab] mb-2">Nombre</label>
        <input type="text" name="nombre_empleado_prov" required class="w-full border border-[#5092ab] rounded-md px-3 py-2 text-[14px] text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#5092ab] transition bg-white"/>
      </div>
      <div>
        <label class="block text-sm font-medium text-[#5092ab] mb-2">Apellido</label>
        <input type="text" name="apellido_empleado_prov" required class="w-full border border-[#5092ab] rounded-md px-3 py-2 text-[14px] text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#5092ab] transition bg-white"/>
      </div>
      <div>
        <label class="block text-sm font-medium text-[#5092ab] mb-2">CUIL</label>
        <input type="text" name="cuil_empleado_prov" required class="w-full border border-[#5092ab] rounded-md px-3 py-2 text-[14px] text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#5092ab] transition bg-white"/>
      </div>
      <div>
        <label class="block text-sm font-medium text-[#5092ab] mb-2">Puesto</label>
        <input type="text" name="puesto_empleado_prov" required class="w-full border border-[#5092ab] rounded-md px-3 py-2 text-[14px] text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#5092ab] transition bg-white"/>
      </div>
      <div class="flex space-x-3 pt-4">
        <button type="submit" class="flex-1 btn-primary">
          <i class="fas fa-save mr-2"></i>Guardar
        </button>
        <button type="button" onclick="document.getElementById('modalAgregarEmpleado').style.display='none'" class="px-4 py-2 border border-[#5092ab] text-[#5092ab] rounded-lg hover:bg-[#5092ab] hover:text-white transition">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Manejo de fallbacks para el logo
  function handleLogoFallback() {
    const logoContainer = document.querySelector('.flex.items-center.h-20.px-4');
    if (!logoContainer) return;
    
    const images = logoContainer.querySelectorAll('img');
    const textFallback = logoContainer.querySelector('div');
    
    function showNextFallback(currentIndex) {
      if (currentIndex < images.length - 1) {
        images[currentIndex + 1].style.display = 'block';
      } else if (textFallback) {
        textFallback.style.display = 'flex';
      }
    }
    
    images.forEach((img, index) => {
      img.addEventListener('error', () => {
        img.style.display = 'none';
        showNextFallback(index);
      });
      
      img.addEventListener('load', () => {
        images.forEach((otherImg, otherIndex) => {
          if (otherIndex !== index) {
            otherImg.style.display = 'none';
          }
        });
        if (textFallback) textFallback.style.display = 'none';
      });
    });
  }
  
  handleLogoFallback();
});

let pdfObjectUrls = {};

function mostrarNombreArchivo(input, reqId) {
  const archivoNombre = document.getElementById('archivo-nombre-' + reqId);
  if (input.files && input.files[0]) {
    // Limpiar URL anterior si existe
    if (pdfObjectUrls[reqId]) {
      URL.revokeObjectURL(pdfObjectUrls[reqId]);
    }
    const url = URL.createObjectURL(input.files[0]);
    pdfObjectUrls[reqId] = url;
    archivoNombre.innerHTML = `
      <i class='fas fa-file-pdf pdf-icon'></i> ${input.files[0].name}
      <button type='button' class='btn-ver-pdf' onclick='verPdfModal("${url}")'><i class="fas fa-eye"></i>Ver</button>
    `;
  } else {
    archivoNombre.innerHTML = '';
  }
}

function verPdfModal(url) {
  // Crear modal si no existe
  let modal = document.getElementById('pdf-modal-bg');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'pdf-modal-bg';
    modal.className = 'pdf-modal-bg';
    modal.innerHTML = `
      <div class='pdf-modal'>
        <div class='pdf-modal-header'>
          <button class='pdf-modal-close' onclick='cerrarPdfModal()' title='Cerrar'>&times;</button>
        </div>
        <iframe class='pdf-modal-iframe' id='pdf-modal-iframe'></iframe>
      </div>
    `;
    document.body.appendChild(modal);
  } else {
    modal.style.display = 'flex';
  }
  document.getElementById('pdf-modal-iframe').src = url;
}

function cerrarPdfModal() {
  let modal = document.getElementById('pdf-modal-bg');
  if (modal) {
    modal.style.display = 'none';
    document.getElementById('pdf-modal-iframe').src = '';
  }
}

// Cerrar modales al hacer clic fuera
document.addEventListener('click', function(e) {
  if (e.target.id === 'modalAgregarEmpleado') {
    document.getElementById('modalAgregarEmpleado').style.display = 'none';
  }
});

// Cerrar modales con Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.getElementById('modalAgregarEmpleado').style.display = 'none';
  }
});
</script>
</body>
</html>