<?php
require_once 'dbconn.php';
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$info = null;

// Determine which event-employee junction table exists (historical variants)
$eventEmployeeTable = 'empleados_evento';
$tbl = $conn->query("SHOW TABLES LIKE 'empleados_evento'");
if (!($tbl && $tbl->num_rows > 0)) {
  $tbl2 = $conn->query("SHOW TABLES LIKE 'evento_empleado'");
  if ($tbl2 && $tbl2->num_rows > 0) $eventEmployeeTable = 'evento_empleado';
}

// Ensure qr_token column exists on the detected table
$colRes = $conn->query("SHOW COLUMNS FROM {$eventEmployeeTable} LIKE 'qr_token'");
if ($colRes && $colRes->num_rows == 0) {
  // Try to add the column (silent on failure)
  @$conn->query("ALTER TABLE {$eventEmployeeTable} ADD COLUMN qr_token VARCHAR(64) NULL");
}

if ($token) {
  // Also fetch employee CUIL and the provider (company) name via proveedores table
  $sql = "SELECT ee.evento_id, ee.empleado_id, e.nombre, e.apellido, e.cuil, p.nombre AS proveedor_nombre, ev.nombre as evento_nombre, ev.fecha as evento_fecha FROM {$eventEmployeeTable} ee JOIN empleados_proveedor e ON ee.empleado_id = e.id LEFT JOIN proveedores p ON e.proveedor_id = p.id JOIN eventos ev ON ee.evento_id = ev.id WHERE ee.qr_token = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if ($stmt) {
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $info = $row;
    }
    $stmt->close();
  }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title>Validar QR</title>
<style>body{font-family:Arial,Helvetica,sans-serif;background:#f7fafc;padding:24px} .box{background:#fff;padding:20px;border-radius:8px;max-width:600px;margin:0 auto;border:1px solid #e3e8ee}</style>
</head>
<body>
  <div class="box">
    <?php if (!$info): ?>
      <h3>Token inválido o no encontrado</h3>
      <p>Contacte al organizador.</p>
    <?php else: ?>
      <h3>Acceso válido</h3>
      <p>Empleado: <strong><?= htmlspecialchars($info['nombre'] . ' ' . $info['apellido']) ?></strong></p>
      <?php if (!empty($info['cuil'])): ?>
        <p>CUIL: <strong><?= htmlspecialchars($info['cuil']) ?></strong></p>
      <?php endif; ?>
      <?php if (!empty($info['proveedor_nombre'])): ?>
        <p>Empresa / Proveedor: <strong><?= htmlspecialchars($info['proveedor_nombre']) ?></strong></p>
      <?php endif; ?>
      <p>Evento: <strong><?= htmlspecialchars($info['evento_nombre']) ?></strong> (<?= htmlspecialchars($info['evento_fecha']) ?>)</p>
      <p>Puede ingresar al evento</p>
    <?php endif; ?>
  </div>
</body>
</html>
