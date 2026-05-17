<?php
require_once 'dbconn.php';
// validar_empleado_qr.php - robust QR validator page
// Accepts ?token=... and shows employee, provider/company, event and authorization state.

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$info = null; $message = '';
if ($token !== '') {
    // Lookup token and join to employee, proveedor and evento
    $sql = "SELECT ee.evento_id, ee.empleado_id, ee.qr_generated_at, ee.qr_revoked, 
                   ep.nombre as emp_nombre, ep.apellido as emp_apellido, ep.cuil as emp_cuil, 
                   p.id as prov_id, p.nombre as prov_nombre, p.email as prov_email,
                   ev.nombre as evento_nombre, ev.fecha as evento_fecha
            FROM empleados_evento ee
            JOIN empleados_proveedor ep ON ee.empleado_id = ep.id
            LEFT JOIN proveedores p ON ep.proveedor_id = p.id
            JOIN eventos ev ON ee.evento_id = ev.id
            WHERE ee.qr_token = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $info = $row;
        // Basic validation rules
        $now = new DateTimeImmutable('now');
        $eventoFecha = null;
        if (!empty($info['evento_fecha'])) {
            try { $eventoFecha = new DateTimeImmutable($info['evento_fecha']); } catch (Exception $e) { $eventoFecha = null; }
        }
        $authorized = true; $reasons = [];
        if ($info['qr_revoked']) { $authorized = false; $reasons[] = 'QR revocado por la empresa'; }
        // If event date exists, allow only if same day (configurable)
        if ($eventoFecha) {
            if ($eventoFecha->format('Y-m-d') !== $now->format('Y-m-d')) {
                // Not the same day; you may relax this rule if you want
                $authorized = false; $reasons[] = 'Evento no corresponde a la fecha actual';
            }
        }
        // Prepare message
        if ($authorized) {
            $message = 'Autorizado';
        } else {
            $message = 'No autorizado: ' . implode('; ', $reasons);
        }
        // Log validation attempt if table exists
        $logStmt = null;
        $colCheck = $conn->query("SHOW TABLES LIKE 'qr_validation_log'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $resLog = $conn->prepare("INSERT INTO qr_validation_log (evento_id, empleado_id, token, result, scanner_ip, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $resultText = $authorized ? 'authorized' : 'denied';
            $notes = implode('; ', $reasons);
            $resLog->bind_param('iissss', $info['evento_id'], $info['empleado_id'], $token, $resultText, $ip, $notes);
            $resLog->execute();
            $resLog->close();
        }
    }
    $stmt->close();
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Validar acceso - QR</title>
  <style>
    body{font-family:Arial,Helvetica,sans-serif;background:#f3f6f9;padding:18px}
    .card{max-width:760px;margin:0 auto;background:#fff;padding:20px;border-radius:8px;border:1px solid #e6eef6}
    .row{display:flex;gap:16px;flex-wrap:wrap}
    .col{flex:1 1 220px}
    .status{padding:12px;border-radius:6px;font-weight:700}
    .ok{background:#e6ffed;color:#1a7f36;border:1px solid #c6f0d1}
    .nok{background:#ffecec;color:#9b1d1d;border:1px solid #f1c6c6}
    .meta{color:#334155;font-size:14px}
    .label{color:#64748b;font-size:13px}
  </style>
</head>
<body>
  <div class="card">
    <?php if (!$token): ?>
      <h2>Validar acceso mediante QR</h2>
      <p>Indique el token en la URL: <code>?token=...</code> o escanee el QR generado en el mail.</p>
    <?php elseif (!$info): ?>
      <h2 class="nok status">Token inválido o no encontrado</h2>
      <p class="meta">El token no coincide con ningún acceso asignado. Contacte al organizador.</p>
    <?php else: ?>
      <div class="row">
        <div class="col">
          <h3>Empleado</h3>
          <div class="label">Nombre</div>
          <div class="meta"><?= htmlspecialchars($info['emp_nombre'] . ' ' . $info['emp_apellido']) ?></div>
          <div class="label">CUIL</div>
          <div class="meta"><?= htmlspecialchars($info['emp_cuil']) ?></div>
        </div>
        <div class="col">
          <h3>Proveedor / Empresa</h3>
          <div class="label">Nombre</div>
          <div class="meta"><?= htmlspecialchars($info['prov_nombre'] ?? 'N/A') ?></div>
          <div class="label">Email</div>
          <div class="meta"><?= htmlspecialchars($info['prov_email'] ?? 'N/A') ?></div>
        </div>
        <div class="col">
          <h3>Evento</h3>
          <div class="label">Nombre</div>
          <div class="meta"><?= htmlspecialchars($info['evento_nombre']) ?></div>
          <div class="label">Fecha</div>
          <div class="meta"><?= htmlspecialchars($info['evento_fecha']) ?></div>
        </div>
      </div>
      <hr/>
      <?php if (strpos($message, 'Autorizado') !== false): ?>
        <div class="status ok">Autorizado: puede ingresar</div>
      <?php else: ?>
        <div class="status nok">No autorizado: <?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <p style="margin-top:12px;color:#55606a;font-size:14px">Generado: <?= htmlspecialchars($info['qr_generated_at']) ?> | Revocado: <?= $info['qr_revoked'] ? 'Sí' : 'No' ?></p>
    <?php endif; ?>
  </div>
</body>
</html>
