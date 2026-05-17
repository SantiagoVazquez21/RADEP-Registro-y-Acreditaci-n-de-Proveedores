<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Buffer output so we can return JSON for AJAX POSTs without early HTML (the file prints a <link> tag before the PHP POST handler)
if (!ob_get_level()) ob_start();
?>
<link rel="stylesheet" type="text/css" href="./estilos.css">
<?php

require_once 'dbconn.php';
// Notifications helper
require_once __DIR__ . '/includes/notifications_lib.php';
// Optional: helper to send mail via SMTP using PHPMailer if config_smtp.php is available
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}
function send_via_smtp_if_configured($toEmail, $toName, $subject, $htmlBody, $embeddedImages = []) {
  // Return true if sent, false otherwise. If SMTP config missing, return false to let fallback create preview.
  if (!file_exists(__DIR__ . '/config_smtp.php')) return false;
  try {
    require_once __DIR__ . '/config_smtp.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = isset($mail->SMTPSecure) ? $mail->SMTPSecure : 'tls';
    $mail->Port = SMTP_PORT;
    // Enable debug output to file for diagnostics
    $dir = __DIR__ . '/tmp_mail_previews'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $mail->SMTPDebug = 2; // verbose
    $mail->Debugoutput = function($str, $level) use ($dir) {
      $line = date('c') . " | level=" . $level . " | " . trim($str) . "\n";
      @file_put_contents($dir . '/smtp_debug.log', $line, FILE_APPEND | LOCK_EX);
    };
    // For better deliverability with Gmail SMTP, use the authenticated user as the envelope sender
    $fromEmail = SMTP_USER;
    $fromName = defined('FROM_NAME') ? FROM_NAME : '';
    $mail->setFrom($fromEmail, $fromName);
    // If a different FROM_EMAIL was configured, set it as Reply-To so replies go there
    if (defined('FROM_EMAIL') && FROM_EMAIL && strtolower(FROM_EMAIL) !== strtolower(SMTP_USER)) {
      $mail->addReplyTo(FROM_EMAIL, defined('FROM_NAME') ? FROM_NAME : '');
    }
    // Ensure envelope sender is the authenticated user
    $mail->Sender = SMTP_USER;
    $mail->addAddress($toEmail, $toName ?: '');
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = strip_tags(str_replace(['<br/>','<br>','</p>','</div>'], "\n", $htmlBody));
    // Attach embedded images (cid) if provided: array of ['cid'=>..., 'data'=>binary, 'type'=>'image/png', 'name'=>...]
    if (is_array($embeddedImages) && count($embeddedImages) > 0) {
      foreach ($embeddedImages as $emb) {
        if (isset($emb['cid']) && isset($emb['data'])) {
          $type = $emb['type'] ?? 'image/png';
          $name = $emb['name'] ?? ($emb['cid'] . '.png');
          // addStringEmbeddedImage($string, $cid, $name = '', $encoding = 'base64', $type = '')
          $mail->addStringEmbeddedImage($emb['data'], $emb['cid'], $name, 'base64', $type);
        }
      }
    }
    $mail->send();
    // Log success with Message-ID for diagnostics
    try {
      $msgId = method_exists($mail, 'getLastMessageID') ? $mail->getLastMessageID() : '';
      $log = date('c') . " | to:" . $toEmail . " | subject:" . substr($subject,0,120) . " | message_id:" . $msgId . "\n";
      @file_put_contents($dir . '/smtp_send_log.txt', $log, FILE_APPEND | LOCK_EX);
    } catch (Throwable $t) { }
    return true;
  } catch (Exception $e) {
    // log error
    try { @file_put_contents(__DIR__ . '/tmp_mail_previews/mail_error.log', date('c') . " | smtp_error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX); } catch (Throwable $t) {}
    return false;
  }
}

// --- Temporary query timing / debug helper (safe, non-invasive)
if (!function_exists('qlog_ensure_dir')) {
  function qlog_ensure_dir(): string {
    $d = __DIR__ . '/tmp_mail_previews';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
  }
  function qlog_start(string $label = '') {
    return ['t' => microtime(true), 'label' => $label];
  }
  function qlog_write($meta, $sql = '') {
    try {
      $dir = qlog_ensure_dir();
      $now = microtime(true);
      $start = is_array($meta) && isset($meta['t']) ? $meta['t'] : null;
      $label = is_array($meta) && isset($meta['label']) ? $meta['label'] : (is_string($meta) ? $meta : 'query');
      $dur = $start ? number_format($now - $start, 6) : '0';
      $line = date('c') . " | {$label} | dur={$dur}s | sql=" . str_replace("\n", ' ', trim((string)$sql)) . "\n";
      @file_put_contents($dir . '/query_debug.log', $line, FILE_APPEND | LOCK_EX);
    } catch (
      Throwable $e) {
      // noop
    }
  }
}

// Migration: asegurarse de que la tabla evento_empleado tenga columna fecha_asistencia
// Determine canonical event-employee junction table name (historical variants)
// Prefer 'empleados_evento' (links to empleados_proveedor) when present
$eventEmployeeTable = 'empleados_evento';
$tbl = $conn->query("SHOW TABLES LIKE 'empleados_evento'");
if (!($tbl && $tbl->num_rows > 0)) {
  $tbl2 = $conn->query("SHOW TABLES LIKE 'evento_empleado'");
  if ($tbl2 && $tbl2->num_rows > 0) $eventEmployeeTable = 'evento_empleado';
}

// Migration: ensure columns exist on whichever table is present
$res = $conn->query("SHOW COLUMNS FROM {$eventEmployeeTable} LIKE 'fecha_asistencia'");
if ($res && $res->num_rows == 0) {
  // Try to add silently
  $conn->query("ALTER TABLE {$eventEmployeeTable} ADD COLUMN fecha_asistencia DATE NULL");
}
$res2 = $conn->query("SHOW COLUMNS FROM {$eventEmployeeTable} LIKE 'fechas_asistencia'");
if ($res2 && $res2->num_rows == 0) {
  $conn->query("ALTER TABLE {$eventEmployeeTable} ADD COLUMN fechas_asistencia TEXT NULL");
}

// Obtener evento seleccionado
$evento_id = isset($_GET['evento']) ? intval($_GET['evento']) : 0;

// Manejar acciones de requisitos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Clear any buffered output (like the <link> tag) so we can send JSON or redirects cleanly
  if (ob_get_length()) {
    ob_end_clean();
  }
  // Notifications AJAX endpoints: acción=list|leida|eliminar via POST
  if (isset($_POST['noti_accion'])) {
    header('Content-Type: application/json');
    // Support multiple session key names (legacy differences)
    $u_id = $_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? null;
    $u_tipo = $_SESSION['tipo_usuario'] ?? $_SESSION['rol'] ?? null;
    if (!$u_id || !$u_tipo) {
      echo json_encode(['success'=>false, 'error'=>'No autenticado']);
      exit;
    }
    $accion = $_POST['noti_accion'];
    if ($accion === 'list') {
      // debug: log that list was requested
      @file_put_contents(__DIR__ . '/tmp_mail_previews/noti_debug.log', date('c') . " list requested by {$u_id} / {$u_tipo}\n", FILE_APPEND | LOCK_EX);
      $list = obtener_notificaciones($u_id, $u_tipo, 200);
      echo json_encode(['success'=>true, 'data'=>$list]);
      exit;
    } elseif ($accion === 'leida' && isset($_POST['id'])) {
      $id = intval($_POST['id']);
      $ok = marcar_notificacion_leida($id, $u_id);
      echo json_encode(['success'=>$ok]);
      exit;
    } elseif ($accion === 'eliminar' && isset($_POST['id'])) {
      $id = intval($_POST['id']);
      $ok = eliminar_notificacion($id, $u_id);
      echo json_encode(['success'=>$ok]);
      exit;
    }
    echo json_encode(['success'=>false, 'error'=>'Acción inválida']);
    exit;
  }
  // Autorizar proveedor y enviar mail con QR a sus empleados
    if (isset($_POST['autorizar_proveedor_enviar_mail']) && isset($_POST['proveedor_id']) && $evento_id) {
    // Debug: log incoming AJAX/POST details to help diagnose HTML responses
    try {
      $dbg = [];
      $dbg[] = "time=" . date('c');
      $dbg[] = "remote_addr=" . ($_SERVER['REMOTE_ADDR'] ?? '');
      $dbg[] = "user_agent=" . ($_SERVER['HTTP_USER_AGENT'] ?? '');
      $dbg[] = "x_requested_with=" . ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
      $dbg[] = "accept=" . ($_SERVER['HTTP_ACCEPT'] ?? '');
      $dbg[] = "post_keys=" . implode(',', array_keys($_POST));
      $dbg[] = "session_usuario=" . ($_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? 'null');
      @file_put_contents(__DIR__ . '/tmp_mail_previews/ajax_debug.log', implode(' | ', $dbg) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) { /* noop */ }
    $proveedor_id = intval($_POST['proveedor_id']);
    // Determine AJAX early so we can return JSON on error paths
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
      || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
      || (isset($_POST['ajax']) && $_POST['ajax'] == '1');
    // Verificar que todos los requisitos para este proveedor y evento estén cargados y con archivo (inferencia de autorización)
    $stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN archivo IS NOT NULL AND archivo <> '' THEN 1 ELSE 0 END) as with_file FROM proveedor_requisito_evento WHERE proveedor_id = ? AND evento_id = ?");
    $stmt->bind_param("ii", $proveedor_id, $evento_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (($res['total'] ?? 0) == 0) {
      $error = 'No hay requisitos cargados para este proveedor en el evento.';
    } elseif (($res['with_file'] ?? 0) < ($res['total'] ?? 0)) {
      $error = 'No todos los requisitos tienen archivo cargado. No se puede autorizar al proveedor.';
    } else {
      // Obtener datos del proveedor
      $stmt = $conn->prepare("SELECT nombre, email FROM proveedores WHERE id = ?");
      $stmt->bind_param("i", $proveedor_id);
      $stmt->execute();
      $stmt->bind_result($prov_nombre, $prov_email);
      $stmt->fetch();
      $stmt->close();

  // Obtener empleados asignados de este proveedor al evento (tabla empleados_evento)
  $emps = [];
  $stmt = $conn->prepare("SELECT e.id, e.nombre, e.apellido, e.cuil FROM {$eventEmployeeTable} ee JOIN empleados_proveedor e ON ee.empleado_id = e.id WHERE ee.evento_id = ? AND e.proveedor_id = ?");
  $stmt->bind_param("ii", $evento_id, $proveedor_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($r = $result->fetch_assoc()) $emps[] = $r;
  $stmt->close();

      if (count($emps) == 0) {
        $error = 'No hay empleados asignados para este proveedor en el evento.';
      } else {
        // Cargar plantilla
        $tpl = file_exists(__DIR__ . '/mail_qr_template.html') ? file_get_contents(__DIR__ . '/mail_qr_template.html') : '';
        // Generar tokens y QR por empleado
        $empBlocks = [];
  $embeddedImages = [];
  foreach ($emps as $emp) {
          // token simple: evento-empid-time hash (no claims)
          $token = substr(hash('sha256', $evento_id . '|' . $emp['id'] . '|' . time()), 0, 32);
          // Guardar token en DB tabla empleados_evento (columna qr_token) - verificar existencia antes de intentar ALTER
          $colRes = $conn->query("SHOW COLUMNS FROM {$eventEmployeeTable} LIKE 'qr_token'");
          if ($colRes && $colRes->num_rows == 0) {
            $conn->query("ALTER TABLE {$eventEmployeeTable} ADD COLUMN qr_token VARCHAR(64) NULL");
          }
          $stmt = $conn->prepare("UPDATE {$eventEmployeeTable} SET qr_token = ? WHERE evento_id = ? AND empleado_id = ?");
          $stmt->bind_param("sii", $token, $evento_id, $emp['id']);
          $stmt->execute();
          $stmt->close();

          // QR content: a small URL that can be scanned to validate (point to validar_qr.php?token=...)
            // If the deploy defines SITE_BASE_URL in config_app.php, prefer it (useful for ngrok / public URLs)
            if (file_exists(__DIR__ . '/config_app.php')) require_once __DIR__ . '/config_app.php';
            if (defined('SITE_BASE_URL') && trim(SITE_BASE_URL) !== '') {
                $qrUrl = rtrim(SITE_BASE_URL, '/') . '/validar_qr.php?token=' . urlencode($token);
            } else {
                $qrUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/validar_qr.php?token=' . urlencode($token);
            }
          // generate QR image URL using qrserver by default (more reliable); keep Google Chart as fallback
          $qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrUrl);
          $qrImg_google = 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . urlencode($qrUrl);

          // try to generate PNG server-side using chillerlan if available and prepare embedded image
          $cid = 'qr_emp_' . $emp['id'];
          $embedded = null;
          $imgTag = '';
          try {
            if (class_exists('\\chillerlan\\qrcode\\QRCode') || class_exists('chillerlan\\QRCode\\QRCode')) {
              // Try common namespaces; create options minimal
              if (class_exists('chillerlan\\QRCode\\QROptions')) {
                $optsClass = 'chillerlan\\QRCode\\QROptions';
                $qrClass = 'chillerlan\\QRCode\\QRCode';
              } elseif (class_exists('chillerlan\\qrcode\\QROptions')) {
                $optsClass = 'chillerlan\\qrcode\\QROptions';
                $qrClass = 'chillerlan\\qrcode\\QRCode';
              } else {
                $optsClass = null; $qrClass = null;
              }
              if ($qrClass && $optsClass) {
                $opts = new $optsClass();
                // prefer PNG output
                if (property_exists($opts, 'outputType')) {
                  $opts->outputType = defined('QR_OUTPUT_PNG') ? QR_OUTPUT_PNG : 0;
                }
                $q = new $qrClass($opts);
                $pngData = $q->render($qrUrl);
                if (!empty($pngData)) {
                  $embedded = ['cid' => $cid, 'data' => $pngData, 'type' => 'image/png', 'name' => 'qr_' . $emp['id'] . '.png'];
                  // prefer data URI in HTML so clients show image even if remote images are blocked
                  $dataUri = 'data:image/png;base64,' . base64_encode($pngData);
                  $imgTag = '<img src="' . $dataUri . '" alt="QR" style="width:120px;height:120px;"/>';
                }
              }
            }
            // if we reach here but didn't generate png, try fetching Google Chart PNG and embed
            if (empty($embedded)) {
              // try to fetch PNG via cURL (safer if allow_url_fopen is disabled)
              try {
                if (function_exists('curl_init')) {
                    $ch = curl_init($qrImg);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                    // some hosts require a user agent
                    curl_setopt($ch, CURLOPT_USERAGENT, 'SVazquezMailer/1.0');
                    $maybe = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                    curl_close($ch);
                    if ($maybe !== false && $httpCode >= 200 && $httpCode < 300 && is_string($maybe) && strlen($maybe) > 100 && strpos($contentType, 'image') !== false) {
                      $embedded = ['cid' => $cid, 'data' => $maybe, 'type' => $contentType ?: 'image/png', 'name' => 'qr_' . $emp['id'] . '.png'];
                      $dataUri = 'data:' . ($contentType ?: 'image/png') . ';base64,' . base64_encode($maybe);
                      $imgTag = '<img src="' . $dataUri . '" alt="QR" style="width:120px;height:120px;"/>';
                    } else {
                      // try alternate QR provider (qrserver) as fallback
                      $qrImg2 = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrUrl);
                      $ch2 = curl_init($qrImg2);
                      curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                      curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
                      curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
                      curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 3);
                      curl_setopt($ch2, CURLOPT_USERAGENT, 'SVazquezMailer/1.0');
                      $maybe2 = curl_exec($ch2);
                      $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                      $contentType2 = curl_getinfo($ch2, CURLINFO_CONTENT_TYPE);
                      curl_close($ch2);
                      if ($maybe2 !== false && $httpCode2 >= 200 && $httpCode2 < 300 && is_string($maybe2) && strlen($maybe2) > 100 && strpos($contentType2, 'image') !== false) {
                        $embedded = ['cid' => $cid, 'data' => $maybe2, 'type' => $contentType2 ?: 'image/png', 'name' => 'qr_' . $emp['id'] . '.png'];
                        $dataUri = 'data:' . ($contentType2 ?: 'image/png') . ';base64,' . base64_encode($maybe2);
                        $imgTag = '<img src="' . $dataUri . '" alt="QR" style="width:120px;height:120px;"/>';
                        // update qrImg to qrImg2 so the external src fallback also points to the working provider
                        $qrImg = $qrImg2;
                      }
                    }
                  }
              } catch (Throwable $_) { }
            }
          } catch (Throwable $e) {
            // ignore and fallback
            $embedded = null;
            $imgTag = '';
          }
          // Fallbacks: if no embedded image created, use Google Chart URL; if sending inline (non-smtp) prefer data URI
          if (empty($imgTag)) {
            $imgTag = '<img src="' . $qrImg . '" alt="QR" style="width:120px;height:120px;"/>';
          }
          $empBlocks[] = '<div style="margin-bottom:8px"><strong>' . htmlspecialchars($emp['nombre'] . ' ' . $emp['apellido']) . '</strong> - CUIL: ' . htmlspecialchars($emp['cuil']) . '<br/>' . $imgTag . '</div>';
          if ($embedded) $embeddedImages[] = $embedded;
        }

  // if any embedded images were created above, they were appended to $embeddedImages
  $body = str_replace(['{PROVEEDOR_NOMBRE}','{EVENTO_NOMBRE}','{EVENTO_FECHA}','{EMPLEADOS_QR}'], [htmlspecialchars($prov_nombre), htmlspecialchars($evento['nombre'] ?? ''), htmlspecialchars($evento['fecha'] ?? ''), implode('\n', $empBlocks)], $tpl);

        // Enviar correo
        $subject = 'Accesos para evento ' . ($evento['nombre'] ?? '');
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n";
        $sent = false;
        if (filter_var($prov_email, FILTER_VALIDATE_EMAIL)) {
          // intentar enviar vía SMTP (PHPMailer) si está configurado
          $sent = send_via_smtp_if_configured($prov_email, $prov_nombre ?? '', $subject, $body, $embeddedImages ?? []);
          // si no se envió por SMTP, intentar mail() como último recurso
          if (!$sent) {
            $sent = @mail($prov_email, $subject, $body, $headers);
          }
        }

        // Si no se pudo enviar, crear archivo preview en /tmp para que admin lo revise
        $previewFile = '';
        $send_method = null;
        if (!$sent) {
          $previewDir = __DIR__ . '/tmp_mail_previews';
          if (!is_dir($previewDir)) mkdir($previewDir, 0777, true);
          $fname = $previewDir . '/mail_prov_' . $proveedor_id . '_' . time() . '.html';
          file_put_contents($fname, $body);
          $success = 'No se pudo enviar por mail con mail(). Se generó una vista previa en: ' . $fname;
          $logLine = date('Y-m-d H:i:s') . " | prov_id:" . $proveedor_id . " | email:" . $prov_email . " | sent:0 | method:unknown | preview:" . $fname . "\n";
          $previewFile = $fname;
        } else {
          $success = 'Correo enviado a ' . htmlspecialchars($prov_email);
          // determine method: check if smtp_send_log has a recent entry for this recipient
          $method = 'mail';
          $dir = __DIR__ . '/tmp_mail_previews';
          $smtpLog = @file_get_contents($dir . '/smtp_send_log.txt');
          if ($smtpLog && strpos($smtpLog, $prov_email) !== false) $method = 'smtp';
          $logLine = date('Y-m-d H:i:s') . " | prov_id:" . $proveedor_id . " | email:" . $prov_email . " | sent:1 | method:" . $method . " | preview:" . "" . "\n";
        }
        // Append log
        if (isset($logLine)) {
          $logFile = __DIR__ . '/tmp_mail_previews/mail_log.txt';
          file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        }
        // If request came via AJAX, return JSON and create a dashboard notification instead of redirecting
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
               || (isset($_POST['ajax']) && $_POST['ajax'] == '1');
        if ($isAjax) {
          // create a notification for the current admin user
          $admin_id = $_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? null;
          $admin_tipo = $_SESSION['tipo_usuario'] ?? $_SESSION['rol'] ?? null;
          $titulo = $sent ? 'Correo enviado' : 'Correo no enviado - vista previa generada';
          $descripcion = $sent ? ('Se envió el correo a ' . ($prov_email ?? '')) : ('Se generó una vista previa: ' . (isset($previewFile) ? basename($previewFile) : '')); 
          if ($admin_id && $admin_tipo) {
            crear_notificacion($admin_id, $admin_tipo, 'fa-envelope', $titulo, $descripcion);
          }
          // Debug: log that we're returning JSON and the mail result
          try {
            $dbg2 = 'AJAX_RESPONSE | time=' . date('c') . ' | isAjax=1 | sent=' . ($sent ? '1' : '0') . ' | preview=' . (isset($previewFile) ? basename($previewFile) : '') . ' | admin=' . ($admin_id ?? '') . '\n';
            @file_put_contents(__DIR__ . '/tmp_mail_previews/ajax_debug.log', $dbg2, FILE_APPEND | LOCK_EX);
          } catch (Throwable $e) { }
          header('Content-Type: application/json');
          echo json_encode(['success'=>true, 'sent'=> (bool)$sent, 'preview'=> isset($previewFile) ? basename($previewFile) : null, 'message' => $success ?? '']);
          exit;
        }
        // Non-AJAX fallback: redirect as before (Post-Redirect-Get)
        // Debug: log that we're taking the non-AJAX redirect path
        try { @file_put_contents(__DIR__ . '/tmp_mail_previews/ajax_debug.log', 'AJAX_RESPONSE | time=' . date('c') . ' | isAjax=0 | redirecting\n', FILE_APPEND | LOCK_EX); } catch (Throwable $e) {}
        $redirect = 'dashboard_empresa.php?evento=' . $evento_id;
        if (!empty($previewFile)) {
          $redirect .= '&mail_status=preview&preview=' . urlencode(basename($previewFile));
        } else {
          $redirect .= '&mail_status=sent';
        }
        header('Location: ' . $redirect);
        exit;
      }
    }
    // If an error was set in the checks above, return JSON for AJAX or redirect for normal requests
    if (isset($error) && $error) {
      if (!empty($isAjax)) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false, 'error' => $error]);
        // Debug log
        try { @file_put_contents(__DIR__ . '/tmp_mail_previews/ajax_debug.log', 'AJAX_RESPONSE | time=' . date('c') . ' | isAjax=1 | early_error=' . substr($error,0,200) . "\n", FILE_APPEND | LOCK_EX); } catch (Throwable $e) {}
        exit;
      } else {
        header('Location: dashboard_empresa.php?evento=' . $evento_id . '&error=' . urlencode($error));
        exit;
      }
    }
  }
  if (isset($_POST['agregar_requisito']) && $evento_id) {
    $requisito_id = intval($_POST['requisito_id']);
  if ($requisito_id > 0) {
      // Verificar si ya existe
      $check = $conn->prepare("SELECT id FROM evento_requisito WHERE evento_id = ? AND requisito_id = ?");
      $check->bind_param("ii", $evento_id, $requisito_id);
      $check->execute();
      if ($check->get_result()->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO evento_requisito (evento_id, requisito_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $evento_id, $requisito_id);
        $stmt->execute();
        $stmt->close();
      }
      $check->close();
    }
    header("Location: dashboard_empresa.php?evento=" . $evento_id);
    exit;
  }

  if (isset($_POST['eliminar_requisito']) && $evento_id) {
    $requisito_id = intval($_POST['requisito_id']);
  if ($requisito_id > 0) {
      $stmt = $conn->prepare("DELETE FROM evento_requisito WHERE evento_id = ? AND requisito_id = ?");
      $stmt->bind_param("ii", $evento_id, $requisito_id);
      $stmt->execute();
      $stmt->close();
    }
    header("Location: dashboard_empresa.php?evento=" . $evento_id);
    exit;
  }

  // Actualizar estado y comentario de requisito
  if (isset($_POST['actualizar_estado_requisito']) && $evento_id) {
    $requisito_id = intval($_POST['requisito_id']);
    $proveedor_id = intval($_POST['proveedor_id']);
    $comentario = isset($_POST['comentario']) ? trim($_POST['comentario']) : '';
    $estado = isset($_POST['estado']) ? trim($_POST['estado']) : 'pendiente';
    if ($requisito_id > 0 && $proveedor_id > 0) {
      // Verifica si ya existe el registro
      $check = $conn->prepare("SELECT id FROM proveedor_requisito_evento WHERE evento_id = ? AND proveedor_id = ? AND requisito_id = ?");
      $check->bind_param("iii", $evento_id, $proveedor_id, $requisito_id);
      $check->execute();
      $result = $check->get_result();
      if ($result->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE proveedor_requisito_evento SET comentario = ?, estado = ? WHERE evento_id = ? AND proveedor_id = ? AND requisito_id = ?");
        $stmt->bind_param("ssiii", $comentario, $estado, $evento_id, $proveedor_id, $requisito_id);
        $stmt->execute();
        $stmt->close();
      } else {
        $stmt = $conn->prepare("INSERT INTO proveedor_requisito_evento (evento_id, proveedor_id, requisito_id, comentario, estado) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $evento_id, $proveedor_id, $requisito_id, $comentario, $estado);
        $stmt->execute();
        $stmt->close();
      }
      $check->close();
    }
 header("Location: dashboard_empresa.php?evento=" . $evento_id);
 exit;
  }
    

    
    // Asignar empleados al evento
    if (isset($_POST['asignar_empleados']) && $evento_id && isset($_POST['empleados']) && is_array($_POST['empleados'])) {
        foreach ($_POST['empleados'] as $emp_id) {
            $emp_id = intval($emp_id);
            // Verificar si ya está asignado
            $check = $conn->prepare("SELECT id FROM {$eventEmployeeTable} WHERE evento_id = ? AND empleado_id = ?");
            $check->bind_param("ii", $evento_id, $emp_id);
            $check->execute();
            if ($check->get_result()->num_rows == 0) {
                $stmt = $conn->prepare("INSERT INTO {$eventEmployeeTable} (evento_id, empleado_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $evento_id, $emp_id);
                $stmt->execute();
                $stmt->close();
            }
            $check->close();
        }
        header("Location: dashboard_empresa.php?evento=" . $evento_id);
        exit;
    }

  // --- ASIGNAR EMPLEADOS DE LA EMPRESA AL EVENTO (con fecha de asistencia por empleado) ---
  if (isset($_POST['asignar_empleados_empresa']) && $evento_id && isset($_POST['empleados_empresa']) && is_array($_POST['empleados_empresa'])) {
    $fecha_map = isset($_POST['fecha_asistencia_empresa']) && is_array($_POST['fecha_asistencia_empresa']) ? $_POST['fecha_asistencia_empresa'] : [];
    foreach ($_POST['empleados_empresa'] as $emp_id) {
      $emp_id = intval($emp_id);
      $fechas_selected = [];
      // fecha_map may have scalar (single date) or array of dates per employee
      if (isset($fecha_map[$emp_id])) {
        if (is_array($fecha_map[$emp_id])) {
          foreach ($fecha_map[$emp_id] as $d) {
            $d = trim($d);
            if ($d !== '') $fechas_selected[] = $d;
          }
        } else {
          $d = trim($fecha_map[$emp_id]);
          if ($d !== '') $fechas_selected[] = $d;
        }
      }
      $fechas_csv = count($fechas_selected) ? implode(',', $fechas_selected) : null;
      $primera_fecha = count($fechas_selected) ? $fechas_selected[0] : null;

      // Verificar si ya está asignado
  $check = $conn->prepare("SELECT id FROM {$eventEmployeeTable} WHERE evento_id = ? AND empleado_id = ?");
      $check->bind_param("ii", $evento_id, $emp_id);
      $check->execute();
      if ($check->get_result()->num_rows == 0) {
  $stmt = $conn->prepare("INSERT INTO {$eventEmployeeTable} (evento_id, empleado_id, fecha_asistencia, fechas_asistencia) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $evento_id, $emp_id, $primera_fecha, $fechas_csv);
        $stmt->execute();
        $stmt->close();
      } else {
        // Actualizar fechas
  $stmt = $conn->prepare("UPDATE {$eventEmployeeTable} SET fecha_asistencia = ?, fechas_asistencia = ? WHERE evento_id = ? AND empleado_id = ?");
        $stmt->bind_param("ssii", $primera_fecha, $fechas_csv, $evento_id, $emp_id);
        $stmt->execute();
        $stmt->close();
      }
      $check->close();
    }
    header("Location: dashboard_empresa.php?evento=" . $evento_id . "&success=empleados_empresa_asignados");
    exit;
  }

    // Quitar empleado del evento
    if (isset($_POST['quitar_empleado']) && $evento_id && isset($_POST['empleado_id'])) {
        $emp_id = intval($_POST['empleado_id']);
  $stmt = $conn->prepare("DELETE FROM {$eventEmployeeTable} WHERE evento_id = ? AND empleado_id = ?");
        $stmt->bind_param("ii", $evento_id, $emp_id);
        $stmt->execute();
        $stmt->close();
        header("Location: dashboard_empresa.php?evento=" . $evento_id);
        exit;
    }
    
    // Quitar proveedor asignado del evento
    if (isset($_POST['quitar_proveedor']) && $evento_id && isset($_POST['proveedor_id'])) {
        $prov_id = intval($_POST['proveedor_id']);
        $stmt = $conn->prepare("DELETE FROM proveedores_evento WHERE evento_id = ? AND proveedor_id = ?");
        $stmt->bind_param("ii", $evento_id, $prov_id);
        $stmt->execute();
        $stmt->close();
        header("Location: dashboard_empresa.php?evento=" . $evento_id);
        exit;
    }
    
    // Actualizar proveedor
    if (isset($_POST['actualizar_empleado']) && isset($_POST['empleado_id_editar'])) {
        $emp_id = intval($_POST['empleado_id_editar']);
        $nombre = trim($_POST['edit_nombre']);
        $apellido = trim($_POST['edit_apellido']);
        $email = trim($_POST['edit_email']);
        $cuilcuit = trim($_POST['edit_cuilcuit']);
        $usuario = trim($_POST['edit_usuario']);
        
        $sql = "UPDATE empleados SET nombre=?, apellido=?, email=?, cuilcuit=?, usuario=?";
        $params = [$nombre, $apellido, $email, $cuilcuit, $usuario];
        $types = "sssss";
        
        // Si se proporcionó una nueva contraseña, actualizarla
        if (!empty($_POST['edit_password'])) {
            $password = password_hash(trim($_POST['edit_password']), PASSWORD_DEFAULT);
            $sql .= ", password=?";
            $params[] = $password;
            $types .= "s";
        }
        
        $sql .= " WHERE id=?";
        $params[] = $emp_id;
        $types .= "i";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            header("Location: dashboard_empresa.php?evento=" . $evento_id . "&success=empleado_actualizado");
            exit;
        } else {
            $error = "Error al actualizar proveedor: " . $stmt->error;
        }
        $stmt->close();
    }
    
    // Procesar asignación de proveedores al evento
    if (isset($_POST['asignar_proveedores']) && $evento_id && isset($_POST['proveedores']) && is_array($_POST['proveedores'])) {
        // Obtener proveedores ya asignados
        $asignados = [];
        $stmt = $conn->prepare("SELECT proveedor_id FROM proveedores_evento WHERE evento_id = ?");
        $stmt->bind_param("i", $evento_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $asignados[] = $row['proveedor_id'];
        }
        $stmt->close();
        // Insertar solo los nuevos
    $stmt = $conn->prepare("INSERT INTO proveedores_evento (evento_id, proveedor_id, fecha_asignacion) VALUES (?, ?, NOW())");
    foreach ($_POST['proveedores'] as $pid) {
      $pid = intval($pid);
      if (!in_array($pid, $asignados)) {
        $stmt->bind_param("ii", $evento_id, $pid);
        $stmt->execute();
        // Crear notificación para el proveedor asignado
        if (function_exists('crear_notificacion')) {
          // intentar obtener nombre del evento
          $evt_name = '';
          $s = $conn->prepare("SELECT nombre FROM eventos WHERE id = ? LIMIT 1");
          if ($s) { $s->bind_param('i', $evento_id); $s->execute(); $s->bind_result($evt_name); $s->fetch(); $s->close(); }
          $titulo = 'Asignación a evento';
          $desc = "Has sido asignado al evento: " . ($evt_name ?: ('ID ' . $evento_id));
          @crear_notificacion($pid, 'proveedor', 'fa-user-plus', $titulo, $desc);
        }
      }
    }
        $stmt->close();
        header("Location: dashboard_empresa.php?evento=" . $evento_id . "&success=proveedores_asignados");
        exit;
    }

    // --- ASIGNAR EMPLEADOS DE PROVEEDOR AL EVENTO ---
    if (isset($_POST['asignar_empleados_proveedor']) && $evento_id && isset($_POST['empleados_proveedor']) && is_array($_POST['empleados_proveedor']) && isset($_POST['proveedor_id'])) {
        $proveedor_id = intval($_POST['proveedor_id']);
        foreach ($_POST['empleados_proveedor'] as $emp_id) {
            $emp_id = intval($emp_id);
            // Verificar si ya está asignado
            $check = $conn->prepare("SELECT id FROM {$eventEmployeeTable} WHERE evento_id = ? AND empleado_id = ?");
            $check->bind_param("ii", $evento_id, $emp_id);
            $check->execute();
            if ($check->get_result()->num_rows == 0) {
                $stmt = $conn->prepare("INSERT INTO {$eventEmployeeTable} (evento_id, empleado_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $evento_id, $emp_id);
                $stmt->execute();
                $stmt->close();
            }
            $check->close();
        }
        header("Location: dashboard_empresa.php?evento=" . $evento_id . "&success=empleados_proveedor_asignados");
        exit;
    }
}

// Obtener requisitos del evento seleccionado
$requisitos_evento = [];
if ($evento_id) {
  $meta = qlog_start('requisitos_evento');
  $sql = "SELECT r.id, r.nombre, er.estado
      FROM evento_requisito er
      JOIN requisitos r ON er.requisito_id = r.id
      WHERE er.evento_id = $evento_id";
  $result = $conn->query($sql);
  qlog_write($meta, $sql);
  while ($row = $result->fetch_assoc()) {
    $requisitos_evento[] = $row;
  }
}

// Obtener todos los requisitos para mostrar los no asignados
$requisitos_todos = [];
$res = $conn->query("SELECT id, nombre FROM requisitos");
qlog_write(qlog_start('requisitos_todos'), "SELECT id, nombre FROM requisitos");
while ($row = $res->fetch_assoc()) {
    $requisitos_todos[] = $row;
}

// Obtener todos los eventos para el select
$eventos = [];
$res = $conn->query("SELECT id, nombre FROM eventos ORDER BY fecha DESC, hora DESC");
while ($row = $res->fetch_assoc()) {
    $eventos[] = $row;
}

// Obtener requisitos disponibles para agregar (los que no están asignados al evento)
$requisitos_disponibles = [];
if ($evento_id) {
    $sql = "SELECT r.id, r.nombre 
            FROM requisitos r 
            WHERE r.id NOT IN (
                SELECT er.requisito_id 
                FROM evento_requisito er 
                WHERE er.evento_id = ?
            )";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $evento_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $requisitos_disponibles[] = $row;
    }
    $stmt->close();
}


// Obtener proveedores  
$empleados_disponibles = [];
$res = $conn->query("SELECT id, nombre, apellido, cuil, fecha_alta, puesto FROM empleados ORDER BY nombre");
while ($row = $res->fetch_assoc()) {
    $empleados_disponibles[] = $row;
}

// Obtener fechas del evento para prellenar opciones de asistencia
$fecha_inicio = null;
$fecha_fin = null;
$event_dates = [];
if ($evento_id) {
  $stmt = $conn->prepare("SELECT fecha, fecha_fin FROM eventos WHERE id = ?");
  $stmt->bind_param("i", $evento_id);
  $stmt->execute();
  $stmt->bind_result($fecha_inicio, $fecha_fin);
  $stmt->fetch();
  $stmt->close();
  if ($fecha_inicio) {
    $fecha_inicio_dt = new DateTime($fecha_inicio);
    $fecha_fin_dt = $fecha_fin ? new DateTime($fecha_fin) : $fecha_inicio_dt;
    $interval = $fecha_inicio_dt->diff($fecha_fin_dt);
    $days = $interval->days;
    for ($i = 0; $i <= $days; $i++) {
      $d = clone $fecha_inicio_dt;
      $d->modify("+{$i} day");
      $event_dates[] = $d->format('Y-m-d');
    }
  }
}

// Obtener empleados asignados al evento
$empleados_asignados = [];
if ($evento_id) {
  // Obtener empleados de la empresa asignados al evento (si existen en tabla empleados)
  $sql = "SELECT e.*, ee.fecha_asistencia, ee.fechas_asistencia FROM {$eventEmployeeTable} ee JOIN empleados e ON ee.empleado_id = e.id WHERE ee.evento_id = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $evento_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $empleados_asignados[] = $row;
  }
  $stmt->close();
}


// Obtener empleados de proveedor por cada proveedor asignado al evento
$proveedores_asignados = [];
if ($evento_id) {
  // Proveedores asignados al evento
  $sql = "SELECT p.* FROM proveedores_evento pe JOIN proveedores p ON pe.proveedor_id = p.id WHERE pe.evento_id = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $evento_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $proveedores_asignados[] = $row;
  }
  $stmt->close();

 
}
if (!is_array($proveedores_asignados)) $proveedores_asignados = [];
if (!isset($proveedores_asignados) || !is_array($proveedores_asignados)) {
  $proveedores_asignados = [];
}

// Obtener empleados de proveedor por cada proveedor asignado al evento
$empleados_proveedor = [];
if ($evento_id && count($proveedores_asignados) > 0) {
  foreach ($proveedores_asignados as $prov) {
    $pid = $prov['id'];
    $stmt = $conn->prepare("SELECT * FROM empleados_proveedor WHERE proveedor_id = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $result = $stmt->get_result();
    $empleados_proveedor[$pid] = [];
    while ($row = $result->fetch_assoc()) {
      $empleados_proveedor[$pid][] = $row;
    }
    $stmt->close();
  }
}


// --- PROVEEDORES DISPONIBLES Y ASIGNADOS ---
$proveedores_disponibles = [];
$proveedores_asignados = [];
if ($evento_id) {
    // Proveedores asignados al evento
    $sql = "SELECT p.* FROM proveedores_evento pe JOIN proveedores p ON pe.proveedor_id = p.id WHERE pe.evento_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $evento_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $proveedores_asignados[] = $row;
    }
    $stmt->close();

  // Proveedores disponibles (NO asignados al evento)
  $sql = "SELECT p.* FROM proveedores p WHERE p.id NOT IN (SELECT proveedor_id FROM proveedores_evento WHERE evento_id = ?) ORDER BY p.nombre";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $evento_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $proveedores_disponibles[] = $row;
  }
  $stmt->close();
}
if (!is_array($proveedores_disponibles)) $proveedores_disponibles = [];
if (!is_array($proveedores_asignados)) $proveedores_asignados = [];
if (!isset($proveedores_asignados) || !is_array($proveedores_asignados)) {
    $proveedores_asignados = [];
}
// Obtener requisitos subidos por proveedor para el evento
$requisitos_proveedor_evento = [];
if ($evento_id && count($proveedores_asignados) > 0) {
    foreach ($proveedores_asignados as $prov) {
        $pid = $prov['id'];
        $sql = "SELECT pre.*, r.nombre as requisito_nombre FROM proveedor_requisito_evento pre JOIN requisitos r ON pre.requisito_id = r.id WHERE pre.proveedor_id=? AND pre.evento_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $pid, $evento_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $requisitos_proveedor_evento[$pid] = [];
        while ($row = $result->fetch_assoc()) {
            $requisitos_proveedor_evento[$pid][$row['requisito_id']] = $row;
        }
        $stmt->close();
    }
}

?>
<html lang="en">
 <head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>
   RADEP
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
   
   /* Dropdown container styling */
   .dropdown-menu {
     position: absolute;
     background-color: #d9e3e6;
     min-width: 180px;
     box-shadow: 0 4px 6px rgba(0,0,0,0.1);
     border: 2px solid #5092ab;
     border-radius: 0.375rem;
     z-index: 50;
     top: 100%;
     right: 0;
     margin-top: 4px;
   }
   .dropdown-menu.hidden { display: none; }
   .dropdown-menu button, .dropdown-menu a {
     display: block;
     width: 100%;
     padding: 0.5rem 1rem;
     text-align: left;
     font-size: 14px;
     color: #374151;
     background-color: #d9e3e6;
     border: none;
     cursor: pointer;
   }
   .dropdown-menu button:hover, .dropdown-menu a:hover {
     background-color: #5092ab;
     color: white;
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
   
   .dashboard-card {
     background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
     border: 2px solid #5092ab;
     border-radius: 20px;
     box-shadow: 0 15px 40px rgba(80, 146, 171, 0.2);
     backdrop-filter: blur(15px);
     transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
     animation: slideInUp 0.8s ease-out;
     animation-fill-mode: both;
   }
   
   .dashboard-card:nth-child(1) { animation-delay: 0.1s; }
   .dashboard-card:nth-child(2) { animation-delay: 0.2s; }
   .dashboard-card:nth-child(3) { animation-delay: 0.3s; }
   .dashboard-card:nth-child(4) { animation-delay: 0.4s; }
   
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
   
   .dashboard-card:hover {
     transform: translateY(-8px) scale(1.02);
     box-shadow: 0 25px 50px rgba(80, 146, 171, 0.3);
     border-color: #3b82f6;
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
   
   .logout-link {
     position: relative;
     overflow: hidden;
     transition: all 0.3s ease;
   }
   
   .logout-link::before {
     content: '';
     position: absolute;
     bottom: 0;
     left: 0;
     width: 0;
     height: 2px;
     background: linear-gradient(90deg, #5092ab, #3b82f6);
     transition: width 0.3s ease;
   }
   
   .logout-link:hover::before {
     width: 100%;
   }
   
   .logout-link:hover {
     transform: translateX(5px);
   }
   
   .stat-number {
     font-size: 2.5rem;
     font-weight: bold;
     background: linear-gradient(135deg, #5092ab, #3b82f6);
     -webkit-background-clip: text;
     -webkit-text-fill-color: transparent;
     background-clip: text;
     animation: countUp 2s ease-out;
   }
   
   @keyframes countUp {
     from {
       opacity: 0;
       transform: scale(0.5);
     }
     to {
       opacity: 1;
       transform: scale(1);
     }
   }
   
   .stat-icon {
     width: 60px;
     height: 60px;
     border-radius: 16px;
     background: linear-gradient(135deg, #5092ab, #3b82f6);
     display: flex;
     align-items: center;
     justify-content: center;
     color: white;
     font-size: 1.5rem;
     box-shadow: 0 8px 25px rgba(80, 146, 171, 0.3);
     transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
     animation: bounce 2s infinite;
   }
   
   @keyframes bounce {
     0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
     40% { transform: translateY(-10px); }
     60% { transform: translateY(-5px); }
   }
   
   .stat-icon:hover {
     transform: scale(1.1) rotate(5deg);
     box-shadow: 0 12px 35px rgba(80, 146, 171, 0.4);
     animation: none;
   }
   
   .quick-action {
     background: linear-gradient(145deg, #ffffff, #f8fafc);
     border: 2px solid rgba(80, 146, 171, 0.2);
     border-radius: 16px;
     padding: 1.5rem;
     text-align: center;
     transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
     box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
     position: relative;
     overflow: hidden;
   }
   
   .quick-action::before {
     content: '';
     position: absolute;
     top: -50%;
     left: -50%;
     width: 200%;
     height: 200%;
     background: linear-gradient(45deg, transparent, rgba(80, 146, 171, 0.1), transparent);
     transform: rotate(45deg);
     transition: transform 0.8s;
   }
   
   .quick-action:hover::before {
     transform: rotate(45deg) translate(50%, 50%);
   }
   
   .quick-action:hover {
     transform: translateY(-8px) scale(1.05);
     box-shadow: 0 15px 40px rgba(80, 146, 171, 0.2);
     border-color: #5092ab;
   }
   
   .quick-action-icon {
     width: 50px;
     height: 50px;
     border-radius: 12px;
     background: linear-gradient(135deg, #5092ab, #3b82f6);
     display: flex;
     align-items: center;
     justify-content: center;
     color: white;
     font-size: 1.3rem;
     margin: 0 auto 1rem;
     box-shadow: 0 6px 20px rgba(80, 146, 171, 0.3);
     transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
   }
   
   .quick-action:hover .quick-action-icon {
     transform: scale(1.15) rotate(10deg);
     box-shadow: 0 8px 25px rgba(80, 146, 171, 0.4);
   }
   
   .welcome-section {
     background: linear-gradient(145deg, #ffffff, #f8fafc);
     border-radius: 24px;
     border: 2px solid rgba(80, 146, 171, 0.2);
     box-shadow: 0 15px 40px rgba(80, 146, 171, 0.15);
     backdrop-filter: blur(10px);
     animation: fadeInUp 1s ease-out;
   }
   
   @keyframes fadeInUp {
     from {
       opacity: 0;
       transform: translateY(30px);
     }
     to {
       opacity: 1;
       transform: translateY(0);
     }
   }
   
   .welcome-section:hover {
     transform: translateY(-5px);
     box-shadow: 0 20px 50px rgba(80, 146, 171, 0.25);
     border-color: #5092ab;
   }
   
   .recent-activity {
     background: linear-gradient(145deg, #ffffff, #f8fafc);
     border-radius: 16px;
     border: 2px solid rgba(80, 146, 171, 0.2);
     box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
     transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
   }
   
   .recent-activity:hover {
     transform: translateY(-5px);
     box-shadow: 0 15px 35px rgba(80, 146, 171, 0.2);
     border-color: #5092ab;
   }
   
   .activity-item {
     transition: all 0.3s ease;
     padding: 12px;
     border-radius: 12px;
     margin-bottom: 8px;
     border-left: 4px solid transparent;
   }
   
   .activity-item:hover {
     background: rgba(80, 146, 171, 0.1);
     border-left-color: #5092ab;
     transform: translateX(5px);
   }
   
   .activity-icon {
     width: 40px;
     height: 40px;
     border-radius: 10px;
     background: linear-gradient(135deg, #5092ab, #3b82f6);
     display: flex;
     align-items: center;
     justify-content: center;
     color: white;
     font-size: 1rem;
     box-shadow: 0 4px 15px rgba(80, 146, 171, 0.3);
     transition: all 0.3s ease;
   }
   
   .activity-item:hover .activity-icon {
     transform: scale(1.1);
     box-shadow: 0 6px 20px rgba(80, 146, 171, 0.4);
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
   
   .floating-action:active {
     transform: scale(0.98);
   }
   
   @media (max-width: 768px) {
     .dashboard-card:hover {
       transform: translateY(-5px) scale(1.01);
     }
     
     .quick-action:hover {
       transform: translateY(-5px) scale(1.02);
     }
     
     .floating-action {
       bottom: 1rem;
       right: 1rem;
       width: 32px;
       height: 32px;
       font-size: 1rem;
     }
     
     .stat-icon:hover {
       transform: scale(1.05);
     }
   }
   
   /* Optimizaciones para evitar scroll horizontal */
   @media (max-width: 1200px) {
     main {
       flex-direction: column;
     }
     
     aside {
       width: 100% !important;
       margin-bottom: 1rem;
     }
     
     .flex-1 {
       min-width: 0;
     }
   }
   
   @media (max-width: 768px) {
     header {
       padding: 0 0.5rem;
     }
     
     .ml-auto {
       flex-wrap: wrap;
       gap: 0.5rem;
     }
     
     .min-w-[160px], .min-w-[180px] {
       min-width: auto;
       flex: 1;
     }
     
     nav {
       flex-wrap: wrap;
       gap: 0.5rem;
     }
     
     .nav-btn {
       flex: 1;
       justify-content: center;
     }
   }
   
   /* Asegurar que el texto largo se trunque correctamente */
   .truncate {
     overflow: hidden;
     text-overflow: ellipsis;
     white-space: nowrap;
   }
   
   /* Optimizar el layout principal */
   .min-h-0 {
     min-height: 0;
   }
   
   .flex-shrink-0 {
     flex-shrink: 0;
   }
   
   .empleado-acordeon {
     /* background: #fff; */
     border-radius: 12px;
     box-shadow: 0 4px 24px rgba(80,146,171,0.10);
     border: 1.5px solid #5092ab33;
     margin-top: 0.5rem;
     padding: 1.2rem 1.5rem 1.2rem 1.5rem;
     transition: all 0.3s cubic-bezier(.4,0,.2,1);
     animation: fadeInAcordeon 0.4s;
   }
   @keyframes fadeInAcordeon {
     from { opacity: 0; transform: translateY(-10px); }
     to { opacity: 1; transform: translateY(0); }
   }
   .empleado-acordeon-label {
     color: #5092ab;
     font-weight: 600;
     margin-right: 0.5rem;
     font-size: 0.98rem;
   }
   .empleado-acordeon-value {
     color: #374151;
     font-weight: 500;
     font-size: 0.98rem;
   }
  /* Estilos para lista de empleados de proveedor (mejora estética) */
  .prov-emp-item {
    padding: 12px 8px;
    border-bottom: 1px solid #e6eef3;
    display: flex;
    gap: 12px;
    align-items: flex-start;
  }
  .prov-emp-item:last-child { border-bottom: none; }
  .prov-emp-avatar {
    width:44px; height:44px; border-radius:50%; background:#b6c7d6; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:14px; flex-shrink:0;
  }
  .prov-emp-name { font-size: 0.98rem; font-weight:600; color:#0f1724; }
  .prov-emp-cuil { font-size:0.85rem; color:#64748b; }
  .prov-emp-meta { margin-top:6px; display:flex; gap:12px; font-size:0.88rem; color:#475569; align-items:center; }
  .prov-emp-badge { background:#eef2f7; color:#0b3a57; padding:4px 8px; border-radius:999px; font-weight:600; font-size:0.78rem; }
   .btn-quitar-empleado {
     background: transparent;
     border: none;
     color: #ef4444;
     border-radius: 50%;
     width: 22px;
     height: 22px;
     display: flex;
     align-items: center;
     justify-content: center;
     font-size: 1.1rem;
     transition: color 0.2s, transform 0.2s;
     margin-left: 0.5rem;
     padding: 0;
   }
   .btn-quitar-empleado:hover {
     color: #fff;
     background: #ef4444;
     transform: scale(1.1) rotate(-10deg);
   }
   .requisito-upload-row {
     background: rgba(255,255,255,0.45);
     border-radius: 14px;
     box-shadow: 0 2px 12px rgba(80,146,171,0.10);
     backdrop-filter: blur(6px);
     border: 1.5px solid #5092ab22;
     padding: 0.7rem 1.1rem;
     margin-bottom: 0.7rem;
     display: flex;
     align-items: center;
     gap: 1rem;
     transition: box-shadow 0.2s, transform 0.2s;
     animation: fadeInRequisito 0.5s;
   }
   .requisito-upload-row:hover {
     box-shadow: 0 4px 24px rgba(80,146,171,0.18);
     transform: translateY(-2px) scale(1.02);
   }
   @keyframes fadeInRequisito {
     from { opacity: 0; transform: translateY(10px); }
     to { opacity: 1; transform: translateY(0); }
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
   .pdf-icon {
     color: #e63946;
     font-size: 1.1em;
     margin-right: 0.2em;
   }
   .archivo-nombre {
     color: #e63946;
     font-weight: 500;
     display: flex;
     align-items: center;
     gap: 0.3em;
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
   .btn-validar {
     border: none;
     border-radius: 50%;
     width: 28px;
     height: 28px;
     display: flex;
     align-items: center;
     justify-content: center;
     font-size: 1.1rem;
     margin-left: 0.3em;
     cursor: pointer;
     transition: background 0.18s, color 0.18s, box-shadow 0.18s;
     box-shadow: 0 1px 4px rgba(80,146,171,0.10);
   }
   .btn-validar.aprobar {
     background: #22c55e22;
     color: #22c55e;
   }
   .btn-validar.aprobar.selected, .btn-validar.aprobar:active {
     background: #22c55e;
     color: #fff;
   }
   .btn-validar.aprobar:disabled {
     opacity: 0.5;
     cursor: not-allowed;
   }
   .btn-validar.desaprobar {
     background: #ef444422;
     color: #ef4444;
   }
   .btn-validar.desaprobar.selected, .btn-validar.desaprobar:active {
     background: #ef4444;
     color: #fff;
   }
   .btn-validar.desaprobar:disabled {
     opacity: 0.5;
     cursor: not-allowed;
   }
   
   /* Estilos para el desplegable de empleados */
   .empleado-acordeon {
     transition: all 0.3s ease;
     max-height: 0;
     overflow: hidden;
   }
   
   .empleado-acordeon:not(.hidden) {
     max-height: 1000px;
   }
   
   .requisito-upload-row {
     transition: all 0.2s ease;
   }
   
   .requisito-upload-row:hover {
     box-shadow: 0 2px 8px rgba(0,0,0,0.1);
   }
   
   /* Animación para el chevron */
   .fa-chevron-down {
     transition: transform 0.3s ease;
   }
   /* Separación visual y minimalismo */
  .prov-card {
    border: 2px solid #e3e8ee;
    border-radius: 16px;
    margin-bottom: 24px;
    background: #f7fafc;
    box-shadow: 0 2px 8px rgba(80,146,171,0.04);
    transition: box-shadow 0.2s;
  }
  .prov-card:hover {
    box-shadow: 0 4px 16px rgba(80,146,171,0.10);
    border-color: #5092ab;
  }
  .prov-header {
    border-bottom: 1.5px dashed #b6c7d6;
    padding-bottom: 8px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .prov-detalles {
    border-top: 1.5px dashed #b6c7d6;
    margin-top: 12px;
    padding-top: 12px;
  }
  .empleados-col {
    border-right: 1.5px solid #e3e8ee;
    padding-right: 18px;
    margin-right: 18px;
  }
  .requisitos-col {
    padding-left: 18px;
  }
  .requisito-upload-row {
    border: 1.5px solid #e3e8ee;
    background: #fff;
    box-shadow: 0 1px 4px rgba(80,146,171,0.04);
    margin-bottom: 8px;
  }
  .requisito-upload-row.selected {
    border-color: #5092ab;
    box-shadow: 0 2px 8px rgba(80,146,171,0.10);
  }
  </style>
 </head>
 <body class="bg-[#a9bec7] min-h-screen flex flex-col">
  <!-- Header -->
  <header class="bg-[#5092ab] flex flex-col sm:flex-row items-center px-2 sm:px-0 py-0 h-auto sm:h-20 border-b-2 border-black/20 w-full">
    <div class="flex items-center w-full sm:w-auto justify-center sm:justify-start h-16 sm:h-20 px-1 sm:px-4 mb-2 sm:mb-0">
      <!-- Logo y fallback -->
      <div class="flex items-center h-16 sm:h-20 px-1 sm:px-4">
        <img src="images/logo-radep.svg" alt="Logo RADEP" class="block sm:hidden" style="height:40px; max-width:120px; object-fit:contain;"/>
        <img src="images/logo-radep.svg" alt="Logo RADEP" class="hidden sm:block" style="height:70px; max-width:220px; object-fit:contain;"/>
        <!-- Fallbacks igual, pero con height reducido en móvil -->
      </div>
    </div>
    <div class="ml-0 sm:ml-auto flex flex-col sm:flex-row items-center space-y-1 sm:space-y-0 sm:space-x-2 w-full sm:w-auto justify-center sm:justify-end mr-0 sm:mr-2">
      <div class="relative">
  <button id="empresaDropdownTrigger" type="button"
    class="flex items-center border border-white/30 h-9 sm:h-10 px-2 space-x-2 min-w-[120px] sm:min-w-[180px] relative rounded-md bg-white/20 backdrop-blur-sm transition text-xs sm:text-[11px] cursor-pointer focus:outline-none focus:ring-2 focus:ring-white/60">
    <img alt="User icon"
         class="object-contain rounded-full border border-white/50 shadow h-5 w-5 sm:h-6 sm:w-6"
         src="https://storage.googleapis.com/a1aa/image/0a14249b-d6d3-4ba9-919c-8fdc2f46616f.jpg"/>
    <div class="flex flex-col justify-center min-w-0 text-left">
      <span class="font-semibold text-white leading-tight truncate">
        <?= !empty($_SESSION['empresa_nombre']) ? htmlspecialchars($_SESSION['empresa_nombre']) : 'Empresa' ?>
      </span>
    </div>
    <i class="fas fa-chevron-down text-white transition-transform" id="empresaChevron"></i>
  </button>
  <div id="empresaMenu" class="dropdown-menu hidden">
    <div class="px-4 py-2 text-sm flex items-center justify-between">
      <div style="display:flex;align-items:center;gap:8px">
        <button id="notiToggle" style="background:none;border:none;cursor:pointer;color:#0f172a;display:flex;align-items:center;gap:6px">
          <i class="fas fa-bell"></i>
          <span id="notiCount" style="background:#e11d48;color:#fff;padding:2px 6px;border-radius:999px;font-size:12px;display:none">0</span>
        </button>
        <strong>Notificaciones</strong>
      </div>
      <a href="logout.php" class="text-sm hover:text-white">Cerrar Sesión</a>
    </div>
    <div id="notiPanel" style="display:none; max-height:360px; overflow:auto; border-top:1px solid rgba(0,0,0,0.05); background:#fff;">
      <div id="notiList" style="padding:8px">
        <div style="color:#666; padding:12px; text-align:center">Cargando notificaciones...</div>
      </div>
    </div>
  </div>
</div>
<script>
(function() {
  const trigger = document.getElementById('empresaDropdownTrigger');
  const menu = document.getElementById('empresaMenu');
  const chevron = document.getElementById('empresaChevron');
  if (!trigger || !menu) return;

  function closeMenu() {
    if (!menu.classList.contains('hidden')) {
      menu.classList.add('hidden');
      if (chevron) chevron.style.transform = 'rotate(0deg)';
    }
  }

  trigger.addEventListener('click', function(e) {
    e.stopPropagation();
    menu.classList.toggle('hidden');
    if (chevron) {
      chevron.style.transform = menu.classList.contains('hidden') ? 'rotate(0deg)' : 'rotate(180deg)';
    }
  });

  document.addEventListener('click', function(e) {
    if (!menu.contains(e.target) && !trigger.contains(e.target)) {
      closeMenu();
    }
  });

  // Cerrar con Escape
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeMenu();
  });
})();
</script>
<script>
// AJAX handler for sending QR mail without redirect
document.addEventListener('DOMContentLoaded', function(){
  function showToast(msg, ok){
    var existing = document.getElementById('ajax-toast');
    if(existing) existing.remove();
    var d = document.createElement('div');
    d.id = 'ajax-toast';
    d.style.position = 'fixed'; d.style.right = '20px'; d.style.bottom = '20px'; d.style.padding = '12px 16px'; d.style.borderRadius = '8px'; d.style.zIndex = '9999';
    d.style.background = ok ? '#10b981' : '#ef4444'; d.style.color = '#fff'; d.style.boxShadow = '0 6px 18px rgba(0,0,0,0.12)';
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(function(){ if(d) d.remove(); }, 5000);
  }

  Array.from(document.querySelectorAll('.send-qr-btn')).forEach(function(btn){
    btn.addEventListener('click', function(e){
      var provId = this.dataset.provId;
      var eventoId = this.dataset.eventoId;
      if (!provId || !eventoId) return showToast('Faltan datos del proveedor o evento', false);
      this.disabled = true;
      this.textContent = 'Enviando...';
      var form = new URLSearchParams();
      form.append('autorizar_proveedor_enviar_mail', '1');
      form.append('proveedor_id', provId);
      form.append('evento_id', eventoId);
      form.append('ajax', '1');
      fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json,text/plain,text/html'},
        body: form.toString()
      }).then(function(r){
        // Prefer JSON but fall back to text for debugging
        if (r.ok) {
          var ctype = r.headers.get('Content-Type') || '';
          if (ctype.indexOf('application/json') !== -1) return r.json().then(function(json){ return {ok:true, json:json}; });
          return r.text().then(function(text){ return {ok:true, text:text}; });
        }
        return r.text().then(function(text){ return {ok:false, status: r.status, text: text}; });
      }).then(function(res){
        if (!res.ok) {
          console.error('Server error', res.status, res.text);
          showToast('Error del servidor: ' + (res.status || '') + ' - ' + (res.text ? res.text.substring(0,200) : ''), false);
          return;
        }
        if (res.json) {
          var json = res.json;
          if (json && json.success) {
            showToast(json.sent ? 'Correo enviado correctamente' : ('Vista previa generada: '+(json.preview||'')), true);
            if(typeof fetchList === 'function'){ try{ fetchList(); }catch(e){} }
          } else {
            showToast((json && json.message) ? json.message : 'Error al autorizar/enviar', false);
            console.warn('send-qr response JSON', json);
          }
        } else if (res.text) {
          // Non-JSON successful response: show up to first 300 chars for debugging
          console.log('send-qr response text:', res.text);
          showToast('Respuesta servidor: ' + (res.text ? res.text.substring(0,200) : ''), false);
        } else {
          showToast('Respuesta inesperada del servidor', false);
        }
      }).catch(function(err){ console.error(err); showToast('Error de red: '+(err.message||err), false); })
      .finally(()=>{ btn.disabled = false; btn.textContent = 'Autorizar proveedor: Enviar QR por mail'; });
    });
  });
});
</script>

    </div>
  </header>
  <!-- Barra de navegación superior -->
  <nav class="flex flex-row gap-2 items-center border-b-2 border-[#5092ab]/30 px-2 py-2 bg-[#d9e3e6] shadow-sm select-none relative z-10 w-full">
    <a href="eventos.php" class="nav-btn flex items-center space-x-1 text-sm px-3 py-1.5 bg-[#5092ab] text-white rounded hover:bg-[#3b82f6] transition">
      <i class="fas fa-calendar-alt"></i>
      <span>Eventos</span>
    </a>
    <a href="alta_proveedores.php" class="nav-btn flex items-center space-x-1 text-sm px-3 py-1.5 bg-[#5092ab] text-white rounded hover:bg-[#3b82f6] transition">
      <i class="fas fa-users"></i>
      <span>Proveedores</span>
    </a>
    <a href="alta_empleados.php" class="nav-btn flex items-center space-x-1 text-sm px-3 py-1.5 bg-[#5092ab] text-white rounded hover:bg-[#3b82f6] transition">
      <i class="fas fa-user-plus"></i>
      <span>Alta de empleados</span>
    </a>
  </nav>
  
  <?php
    // Mostrar mensajes de resultado de envío de mail vía GET (Post-Redirect-Get)
    if (isset($_GET['mail_status']) && $_GET['mail_status'] === 'sent') {
      $msg = 'Correo enviado correctamente.';
      $msgClass = 'bg-green-500';
      $icon = 'fa-check-circle';
    } elseif (isset($_GET['mail_status']) && $_GET['mail_status'] === 'preview' && isset($_GET['preview'])) {
      $preview = basename($_GET['preview']);
      $msg = 'No se pudo enviar por SMTP/mail(). Se generó una vista previa en: tmp_mail_previews/' . $preview;
      $msgClass = 'bg-yellow-500';
      $icon = 'fa-exclamation-triangle';
    } elseif (isset($error)) {
      $msg = $error;
      $msgClass = 'bg-red-500';
      $icon = 'fa-exclamation-triangle';
    } else {
      $msg = null;
    }
  ?>
  <?php if ($msg): ?>
    <div class="fixed top-4 right-4 z-50">
      <div class="<?= $msgClass ?> text-white px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2">
        <i class="fas <?= $icon ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
      </div>
    </div>
    <script>
      setTimeout(function() {
        var el = document.querySelector('.fixed.top-4.right-4'); if (el) el.style.display = 'none';
      }, 7000);
      // Remove mail_status/preview from URL so reload won't re-show the notification
      (function(){
        try {
          var url = new URL(window.location.href);
          if (url.searchParams.has('mail_status') || url.searchParams.has('preview')) {
            url.searchParams.delete('mail_status');
            url.searchParams.delete('preview');
            // Replace state without reloading
            window.history.replaceState({}, document.title, url.pathname + url.search);
          }
        } catch(e) { /* noop */ }
      })();
    </script>
  <?php endif; ?>
<!-- Layout principal: requisitos a la izquierda, contenido principal a la derecha -->
<main class="flex flex-row gap-6 w-full px-2 py-4">
  <!-- Requisitos del evento a la izquierda -->
  <aside aria-label="Requisitos navigation" class="w-[300px] card select-none flex flex-col flex-shrink-0 mb-0">
    <div class="bg-[#5092ab] font-semibold text-white px-4 py-3 border-b border-[#5092ab] flex items-center justify-between rounded-t-lg">
      <div class="flex items-center space-x-3">
        <i class="fas fa-clipboard-list"></i>
        <span>Requisitos del Evento</span>
      </div>
    </div>
    <div class="flex-1 overflow-y-auto">
      <?php if ($evento_id): ?>
        <?php if (count($requisitos_evento) > 0): ?>
          <ul class="divide-y divide-[#5092ab]/30">
            <?php foreach($requisitos_evento as $req): ?>
              <li class="group relative">
                <div class="px-4 py-3 hover:bg-[#5092ab]/10 cursor-pointer transition bg-white">
                  <span class="text-[15px] text-gray-800 select-text" style="white-space:normal;word-break:break-word;display:block;">
                    <?= htmlspecialchars($req['nombre']) ?>
                  </span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-center text-gray-500 py-8">
            <i class="fas fa-clipboard-list text-2xl mb-2"></i>
            <p>No hay requisitos asignados a este evento</p>
          </div>
        <?php endif; ?>
        <script>
        function mostrarComentario(select, id) {
          var div = document.getElementById(id);
          if (select.value === 'revision') {
            div.style.display = 'block';
          } else {
            div.style.display = 'none';
          }
        }
        </script>
      <?php endif; ?>
    </div>
  </aside>
  <!-- Contenido principal a la derecha -->
  <section class="flex-1 flex flex-col space-y-6 min-w-0 w-full">
    <section class="card">
   <header class="text-[13px] font-bold text-[#5092ab] px-4 py-2 border-b border-[#5092ab] select-none rounded-t-lg">
    Seleccione un evento:
   </header>
   <div class="flex items-center gap-3 px-4 py-3 select-text text-[14px] text-gray-900">
    <span class="whitespace-nowrap">ELEGIR EVENTO</span>
  <select class="border border-[#5092ab] rounded-md px-3 py-2 text-[14px] text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#5092ab] transition bg-white" style="max-width:420px;flex:0 0 420px;"
      onchange="(function(v){ if(v === '__home__' || v === '') { window.location.href = 'dashboard_empresa.php'; } else { window.location.href = 'dashboard_empresa.php?evento=' + encodeURIComponent(v); } })(this.value);">
     <?php if ($evento_id): ?>
       <option value="__home__">Volver al inicio</option>
     <?php else: ?>
       <option value="">Selecciona evento</option>
     <?php endif; ?>
     <?php foreach($eventos as $ev): ?>
       <option value="<?= $ev['id'] ?>" <?= ($evento_id == $ev['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ev['nombre']) ?></option>
     <?php endforeach; ?>
    </select>

    <!-- Protocolo/Cláusulas: se muestra sólo si hay evento seleccionado -->
    <?php if ($evento_id): ?>
      <button type="button" onclick="document.getElementById('modalProtocoloClausulas').style.display='flex'" class="ml-2 text-white bg-[#5092ab] hover:bg-[#3b82f6] px-3 py-1.5 rounded-md flex items-center gap-2" title="Protocolo/Cláusulas del evento">
        <i class="fas fa-file-upload"></i>
        <span class="text-sm">Protocolo/Cláusulas</span>
      </button>
      <!-- Export proveedores+empleados -->
      <a href="export_event_proveedores.php?evento=<?= $evento_id ?>" class="ml-2 text-white bg-[#0f766e] hover:bg-[#065f52] px-3 py-1.5 rounded-md flex items-center gap-2 text-sm" title="Descargar listado de proveedores y empleados para este evento">
        <i class="fas fa-download"></i>
        <span>Descargar lista</span>
      </a>
    <?php endif; ?>
   </div>
  </section>
  
  <!-- Proveedores section -->
  <section class="card">
    <?php if ($evento_id): ?>
    <?php endif; ?>
    <?php if ($evento_id): ?>
    <div id="modalAsignarProveedores" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display:none;">
      <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-lg mx-4 relative">
        <button onclick="document.getElementById('modalAsignarProveedores').style.display='none'" class="absolute top-4 right-4 text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
        <h2 class="text-xl font-bold text-[#5092ab] mb-6 flex items-center"><i class="fas fa-user-plus text-[#5092ab] mr-2"></i>Asignar proveedores al evento</h2>
        <form method="post">
          <input type="hidden" name="asignar_proveedores" value="1"/>
          <div class="mb-4">
            <div class="flex items-center gap-2 mb-3">
              <input id="assign-prov-search" type="search" placeholder="Buscar por nombre, email o servicio" class="border rounded px-3 py-2 flex-1" />
              <?php
                // construir lista de servicios disponibles para el filtro
                $servicios = [];
                foreach ($proveedores_disponibles as $p) {
                  if (!empty($p['servicio'])) $servicios[] = $p['servicio'];
                }
                $servicios = array_values(array_unique($servicios));
              ?>
              <select id="assign-prov-filter" class="border rounded px-3 py-2">
                <option value="">Todos</option>
                <?php foreach($servicios as $s): ?>
                  <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" id="assign-prov-clear" class="border rounded px-3 py-2 bg-white">Limpiar</button>
            </div>

            <div class="max-h-64 overflow-y-auto">
            <?php if (count($proveedores_disponibles) > 0): ?>
              <?php foreach($proveedores_disponibles as $prov): ?>
                <label class="proveedor-card-asignar flex items-center space-x-3 mb-3 bg-[#f8fafc] rounded-lg p-3 shadow-sm hover:bg-[#e3f2fd] transition cursor-pointer border border-[#5092ab]/20" data-nombre="<?= htmlspecialchars($prov['nombre']) ?>" data-email="<?= htmlspecialchars($prov['email']) ?>" data-servicio="<?= htmlspecialchars($prov['servicio'] ?? '') ?>">
                  <input type="checkbox" name="proveedores[]" value="<?= $prov['id'] ?>" class="form-checkbox h-5 w-5 text-[#5092ab]">
                  <?php if ($prov['foto'] && file_exists($prov['foto'])): ?>
                    <img src="<?= htmlspecialchars($prov['foto']) ?>" alt="Foto de <?= htmlspecialchars($prov['nombre']) ?>" class="w-8 h-8 rounded-full object-cover border-2 border-[#5092ab]/30">
                  <?php else: ?>
                    <div class="w-8 h-8 rounded-full bg-[#5092ab] flex items-center justify-center text-white text-sm font-semibold">
                      <?= strtoupper(substr($prov['nombre'], 0, 2)) ?>
                    </div>
                  <?php endif; ?>
                  <div class="flex flex-col">
                    <span class="font-medium text-gray-900 text-base"><?= htmlspecialchars($prov['nombre']) ?></span>
                    <span class="text-xs text-gray-500"><?= htmlspecialchars($prov['email']) ?></span>
                    <?php if (!empty($prov['servicio'])): ?><span class="text-xs text-gray-600 mt-1">Servicio: <?= htmlspecialchars($prov['servicio']) ?></span><?php endif; ?>
                  </div>
                </label>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-gray-500 text-center py-8">No hay proveedores disponibles para asignar.</div>
            <?php endif; ?>
            </div>
          </div>
          <div class="flex space-x-3 pt-4">
            <button type="submit" class="flex-1 btn-primary"><i class="fas fa-save mr-2"></i>Asignar seleccionados</button>
            <button type="button" onclick="document.getElementById('modalAsignarProveedores').style.display='none'" class="px-4 py-2 border border-[#5092ab] text-[#5092ab] rounded-lg hover:bg-[#5092ab] hover:text-white transition">Cancelar</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
 <header class="text-[15px] font-bold text-[#5092ab] px-4 py-2 border-b border-[#5092ab] select-none rounded-t-lg flex items-center justify-between">
  <div class="flex items-center gap-2">
    <span>Proveedores asignados</span>
    <?php if ($evento_id && count($proveedores_asignados) > 0): ?>
      <span class="text-xs bg-[#5092ab] text-white px-2 py-1 rounded-full">
        <?= count($proveedores_asignados) ?>
      </span>
    <?php endif; ?>
  </div>
  <button onclick="document.getElementById('modalAsignarProveedores').style.display='flex'" class="bg-[#5092ab] text-white text-xs px-3 py-2 rounded hover:bg-[#3b82f6] transition shadow-none">
    <i class="fas fa-user-plus mr-1"></i>Agregar
  </button>
 </header>
 <ul class="divide-y divide-[#e3e8ee] select-text text-[15px] text-gray-900">
  <?php if ($evento_id && count($proveedores_asignados) > 0): ?>
    <?php foreach($proveedores_asignados as $prov): ?>
  <li class="prov-card px-0 py-0 flex flex-col bg-transparent">
    <?php
      // Calcular estado del proveedor según requisitos
      $reqsProv = isset($requisitos_proveedor_evento[$prov['id']]) ? $requisitos_proveedor_evento[$prov['id']] : [];
      $totalReqs = count($reqsProv);
      $autorizados = 0;
      $pendientes = 0;
      $revision = 0;
      foreach ($reqsProv as $r) {
        $estadoR = $r['estado'] ?? 'pendiente';
        if ($estadoR === 'autorizado') $autorizados++;
        elseif ($estadoR === 'revision') $revision++;
        else $pendientes++;
      }
      if ($totalReqs === 0) {
        $estadoProveedor = 'pendiente';
      } elseif ($revision > 0) {
        $estadoProveedor = 'revision';
      } elseif ($autorizados === $totalReqs) {
        $estadoProveedor = 'autorizado';
      } elseif ($autorizados > 0 && $pendientes > 0) {
        $estadoProveedor = 'pendiente';
      } elseif ($pendientes === $totalReqs) {
        $estadoProveedor = 'pendiente';
      } else {
        $estadoProveedor = 'pendiente';
      }
      $colorProv = 'bg-yellow-100 text-yellow-800 border-yellow-400';
      if ($estadoProveedor === 'autorizado') $colorProv = 'bg-green-100 text-green-700 border-green-400';
      elseif ($estadoProveedor === 'revision') $colorProv = 'bg-red-100 text-red-700 border-red-400';
    ?>
    <div class="prov-header px-4 py-4">
      <div class="flex items-center gap-3 flex-1 cursor-pointer" onclick="toggleProveedorDetalles('prov-<?= $prov['id'] ?>')">
        <?php if ($prov['foto'] && file_exists($prov['foto'])): ?>
          <img src="<?= htmlspecialchars($prov['foto']) ?>" alt="Foto de <?= htmlspecialchars($prov['nombre']) ?>" class="w-10 h-10 rounded-full object-cover border border-[#e3e8ee]">
        <?php else: ?>
          <div class="w-10 h-10 rounded-full bg-[#b6c7d6] flex items-center justify-center text-white text-base font-semibold">
            <?= strtoupper(substr($prov['nombre'], 0, 2)) ?>
          </div>
        <?php endif; ?>
        <div class="flex flex-col justify-center">
          <span class="font-medium text-gray-900 text-base leading-tight"><?= htmlspecialchars($prov['nombre']) ?></span>
          <span class="text-xs text-gray-500 leading-tight"><?= htmlspecialchars($prov['email']) ?></span>
          <span class="inline-block mt-1 px-2 py-1 rounded text-xs font-semibold border <?= $colorProv ?>">Estado: <?= ucfirst($estadoProveedor) ?></span>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <?php if ($evento_id): // show action group only when an event is selected ?>
          <div class="provider-action-group inline-flex items-center gap-2" style="align-items:center;margin-left:24px">
            <!-- Remove provider -->
            <form method="post" class="m-0 p-0" style="display:inline-block">
              <input type="hidden" name="quitar_proveedor" value="1" />
              <input type="hidden" name="proveedor_id" value="<?= $prov['id'] ?>" />
              <button type="submit" class="action-btn" title="Quitar proveedor" style="background:transparent;border:1px solid transparent;color:#6b7280;width:34px;height:34px;padding:0;border-radius:6px;font-size:13px;display:flex;align-items:center;justify-content:center;"> <i class="fas fa-user-minus"></i></button>
            </form>

            <!-- Authorize and send QR (AJAX: avoid redirect, create notification in-place) -->
            <button type="button" class="action-btn send-qr-btn m-0 p-0" data-prov-id="<?= $prov['id'] ?>" data-evento-id="<?= $evento_id ?>" data-prov-email="<?= htmlspecialchars($prov['email']) ?>" title="Autorizar proveedor y enviar mail con QR" style="background:#059669;color:#ffffff;padding:6px 12px;border-radius:6px;border:1px solid rgba(0,0,0,0.05);font-size:12px">Autorizar proveedor: Enviar QR por mail</button>

            <!-- Config mail QR -->
            <a href="config_mail_qr.php" class="action-btn" title="Configurar plantilla de mail con QR" style="background:transparent;border:1px solid #e6eef2;color:#0f172a;padding:5px 8px;border-radius:6px;font-size:12px;text-decoration:none;margin-left:6px">Configurar Mail</a>
          </div>
        <?php else: ?>
          <!-- When no event selected, keep a minimal remove button and chevron -->
          <form method="post" class="m-0 p-0">
            <input type="hidden" name="quitar_proveedor" value="1" />
            <input type="hidden" name="proveedor_id" value="<?= $prov['id'] ?>" />
            <button type="submit" class="text-gray-400 hover:text-red-500 px-2 py-1 rounded transition shadow-none" title="Quitar proveedor"><i class="fas fa-user-minus"></i></button>
          </form>
        <?php endif; ?>

        <button type="button" class="expand-prov-btn px-2 py-1 text-[#5092ab] hover:text-[#3b82f6] bg-transparent border-none" onclick="toggleProveedorDetalles('prov-<?= $prov['id'] ?>')"><i class="fas fa-chevron-down"></i></button>
      </div>
    </div>
    <div id="prov-<?= $prov['id'] ?>-detalles" class="prov-detalles hidden px-6 pb-4">
      <div class="flex flex-row gap-8">
        <!-- Columna izquierda: Empleados -->
        <div class="empleados-col w-1/2">
  <span class="font-semibold text-[#5092ab] text-sm block mb-2">Empleados</span>
  <?php
    // Obtener empleados asignados por este proveedor al evento
    $empleados_evento = [];
    if ($evento_id && isset($prov['id'])) {
      $stmt = $conn->prepare("SELECT e.* FROM empleados_evento ee JOIN empleados_proveedor e ON ee.empleado_id = e.id WHERE ee.evento_id = ? AND e.proveedor_id = ?");
      $stmt->bind_param("ii", $evento_id, $prov['id']);
      $stmt->execute();
      $result = $stmt->get_result();
      while ($row = $result->fetch_assoc()) {
        $empleados_evento[] = $row;
      }
      $stmt->close();
    }
  ?>
  <?php if (count($empleados_evento) > 0): ?>
    <ul class="list-none ml-0 mt-0">
      <?php foreach($empleados_evento as $emp): ?>
        <li class="prov-emp-item">
          <div class="prov-emp-avatar"><?= strtoupper(substr($emp['nombre'], 0, 2)) ?></div>
          <div style="flex:1">
            <div class="prov-emp-name"><?= htmlspecialchars($emp['nombre']) ?> <?= htmlspecialchars($emp['apellido']) ?></div>
            <div class="prov-emp-cuil">CUIL: <?= htmlspecialchars($emp['cuil']) ?></div>
            <div class="prov-emp-meta">
              <div class="prov-emp-badge">Puesto: <?= htmlspecialchars($emp['puesto'] ?: '-') ?></div>
              <div class="prov-emp-badge">Tipo: <?= htmlspecialchars(isset($emp['tipo_relacion']) ? ($emp['tipo_relacion'] === 'monotributista' ? 'Monotributista' : 'Dependencia') : '-') ?></div>
              <?php if (!empty($emp['fechas_asistencia'])): ?>
                <div class="text-sm text-[#64748b]">Fechas: <?php foreach(explode(',', $emp['fechas_asistencia']) as $d) { echo '<span style="margin-right:6px;">'.htmlspecialchars(trim($d)).'</span>'; } ?></div>
              <?php endif; ?>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="text-gray-500 text-sm">No hay empleados registrados para este proveedor en el evento.</div>
  <?php endif; ?>
</div>

        <!-- Columna derecha: Requisitos cargados por el proveedor -->
        <div class="requisitos-col w-1/2">
          <span class="font-semibold text-[#5092ab] text-sm block mb-4">Requisitos del evento</span>
          <?php if (count($requisitos_evento) > 0): ?>
            <ul class="list-none ml-0 mt-0">
              <?php foreach($requisitos_evento as $req): ?>
                <?php 
                  $reqProv = isset($requisitos_proveedor_evento[$prov['id']][$req['id']]) ? $requisitos_proveedor_evento[$prov['id']][$req['id']] : null;
                  $archivo = $reqProv ? $reqProv['archivo'] : null;
                    $estado = $reqProv['estado'] ?? 'pendiente';
                    $comentario = $reqProv['comentario'] ?? '';
                    $estadoColor = 'bg-gray-200 text-gray-800';
                    if ($estado === 'autorizado') $estadoColor = 'bg-green-100 text-green-700 border-green-400';
                    elseif ($estado === 'revision') $estadoColor = 'bg-red-100 text-red-700 border-red-400';
                    elseif ($estado === 'pendiente') $estadoColor = 'bg-yellow-100 text-yellow-800 border-yellow-400';
                ?>
                <li class="requisito-upload-row bg-white rounded-lg shadow-sm px-4 py-3 flex items-center justify-between gap-4 border border-[#e3e8ee]">
                  <div class="flex items-center gap-3 min-w-0">
                    <i class="fas fa-file-alt text-[#5092ab] text-lg"></i>
                    <div class="flex flex-col min-w-0">
                      <span class="font-medium text-gray-900 truncate"><?= htmlspecialchars($req['nombre']) ?></span>
                      <?php if ($archivo): ?>
                        <a href="uploads/proveedor_requisitos/<?= htmlspecialchars($archivo) ?>" target="_blank" class="text-blue-600 underline text-xs mt-1">Ver PDF</a>
                      <?php else: ?>
                        <span class="text-gray-400 text-xs mt-1">Sin archivo</span>
                      <?php endif; ?>
                      <span class="inline-block mt-2 px-2 py-1 rounded text-xs font-semibold border <?= $estadoColor ?>">
                        Estado: <?= ucfirst($estado) ?>
                      </span>
                    </div>
                  </div>
                    <?php if ($archivo): ?>
                    <?php if ($comentario): ?>
                      <span class="ml-4 px-2 py-1 rounded bg-gray-100 text-gray-800 text-xs border border-gray-300">Comentario: <?= htmlspecialchars($comentario) ?></span>
                    <?php endif; ?>
                    <form method="post" class="flex items-center gap-2 ml-4" style="min-width:220px;">
                      <input type="hidden" name="actualizar_estado_requisito" value="1" />
                      <input type="hidden" name="evento_id" value="<?= $evento_id ?>" />
                      <input type="hidden" name="proveedor_id" value="<?= $prov['id'] ?>" />
                      <input type="hidden" name="requisito_id" value="<?= $req['id'] ?>" />
                      <select name="estado" class="border rounded px-2 py-1 text-xs" onchange="mostrarComentario(this, 'comentario-<?= $prov['id'] ?>-<?= $req['id'] ?>')">
                        <option value="pendiente" <?= ($estado == 'pendiente' ? 'selected' : '') ?>>Pendiente</option>
                        <option value="autorizado" <?= ($estado == 'autorizado' ? 'selected' : '') ?>>Autorizado</option>
                        <option value="revision" <?= ($estado == 'revision' ? 'selected' : '') ?>>En revisión</option>
                      </select>
                      <?php if ($estado == 'revision' && !$comentario): ?>
                        <input type="text" name="comentario" id="comentario-<?= $prov['id'] ?>-<?= $req['id'] ?>" class="border rounded px-2 py-1 text-xs" placeholder="Comentario" style="display:inline-block;width:120px;" value="<?= htmlspecialchars($comentario) ?>" />
                      <?php else: ?>
                        <input type="text" name="comentario" id="comentario-<?= $prov['id'] ?>-<?= $req['id'] ?>" class="border rounded px-2 py-1 text-xs" placeholder="Comentario" style="display:none;width:120px;" value="<?= htmlspecialchars($comentario) ?>" disabled />
                      <?php endif; ?>
                      <button type="submit" class="bg-[#5092ab] text-white px-2 py-1 rounded text-xs">Guardar</button>
                    </form>
                    <script>
                    function mostrarComentario(select, inputId) {
                      var input = document.getElementById(inputId);
                      if (select.value === 'revision' && input.value === '') {
                        input.style.display = 'inline-block';
                        input.disabled = false;
                      } else {
                        input.style.display = 'none';
                        input.disabled = true;
                      }
                    }
                    </script>
                    <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="text-gray-500 text-sm">No hay requisitos asignados a este evento.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </li>
    <?php endforeach; ?>
  <?php else: ?>
    <li class="px-4 py-6 text-center">
      <i class="fas fa-users text-3xl text-[#b6c7d6] mb-3"></i>
      <p class="text-gray-400 text-sm mb-4">No hay proveedores asignados</p>
      <button onclick="document.getElementById('modalAsignarProveedores').style.display='flex'" class="bg-[#5092ab] text-white text-xs px-3 py-2 rounded hover:bg-[#3b82f6] transition shadow-none">
        <i class="fas fa-user-plus mr-1"></i>Agregar
      </button>
    </li>
  <?php endif; ?>
 </ul>
 <script>
function toggleProveedorDetalles(id) {
  var el = document.getElementById(id+'-detalles');
  if (el.classList.contains('hidden')) {
    el.classList.remove('hidden');
  } else {
    el.classList.add('hidden');
  }
}
</script>
<script>
// JS para toggle selection en modal de asignar y filtrado por búsqueda/servicio
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
</section>
 </section>
</main>

<!-- Notifications client script -->
<script>
(function(){
  const toggle = document.getElementById('notiToggle');
  const panel = document.getElementById('notiPanel');
  const listEl = document.getElementById('notiList');
  const countEl = document.getElementById('notiCount');
  if(!toggle) return;

  function postForm(data){
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: Object.keys(data).map(k=>encodeURIComponent(k)+'='+encodeURIComponent(data[k])).join('&')
    }).then(function(r){
      // Try to parse JSON when appropriate, otherwise return a safe error object with response text
      var ctype = r.headers.get('Content-Type') || '';
      if (r.ok && ctype.indexOf('application/json') !== -1) {
        return r.json();
      }
      return r.text().then(function(text){
        return { success: false, status: r.status, error: text ? text.substring(0,1000) : 'Non-JSON response' };
      });
    }).catch(function(err){
      return { success: false, status: 0, error: String(err) };
    });
  }

  function renderNotis(items){
    if(!items || items.length === 0){
      listEl.innerHTML = '<div style="color:#666;padding:12px;text-align:center">No tienes notificaciones.</div>';
      countEl.style.display = 'none';
      return;
    }
    listEl.innerHTML = '';
    items.forEach(n=>{
      const li = document.createElement('div');
      li.className = 'noti-row';
      li.style.padding = '8px';
      li.style.borderBottom = '1px solid #f0f0f0';
      li.style.display = 'flex';
      li.style.gap = '8px';
      li.style.alignItems = 'flex-start';
      li.dataset.id = n.id;
      li.innerHTML = '<div style="min-width:36px;color:#3498db"><i class="fas '+(n.icono||'fa-info-circle')+'"></i></div>' +
        '<div style="flex:1"><div style="font-weight:600">'+(n.titulo||'')+'</div><div style="color:#555">'+(n.descripcion||'')+'</div><div style="font-size:12px;color:#888;margin-top:6px">'+(new Date(n.fecha)).toLocaleString()+'</div></div>' +
        '<div style="margin-left:6px;display:flex;flex-direction:column;gap:6px"><button class="noti-mark" style="border:none;background:#10b981;color:#fff;padding:6px 8px;border-radius:6px;font-size:12px;">Marcar</button><button class="noti-del" style="border:none;background:#ef4444;color:#fff;padding:6px 8px;border-radius:6px;font-size:12px;">Eliminar</button></div>';
      if(!n.leida) li.style.background = '#f0f6ff';
      listEl.appendChild(li);
    });
    // Update unread counter
    const unread = items.filter(i=>!i.leida).length;
    if(unread>0){ countEl.textContent = unread; countEl.style.display='inline-block'; } else { countEl.style.display='none'; }

    // Bind actions
    Array.from(listEl.querySelectorAll('.noti-mark')).forEach(btn=>{
      btn.addEventListener('click', function(e){
        const id = this.closest('[data-id]').dataset.id;
        postForm({noti_accion:'leida', id: id}).then(r=>{ if(r.success){ this.closest('[data-id]').style.background=''; fetchList(); } });
      });
    });
    Array.from(listEl.querySelectorAll('.noti-del')).forEach(btn=>{
      btn.addEventListener('click', function(e){
        if(!confirm('Eliminar notificación?')) return;
        const id = this.closest('[data-id]').dataset.id;
        postForm({noti_accion:'eliminar', id: id}).then(r=>{ if(r.success){ fetchList(); } });
      });
    });
    // Clicking a row marks read and may redirect - for now mark read
    Array.from(listEl.querySelectorAll('[data-id]')).forEach(row=>{
      row.addEventListener('click', function(e){
        // if click on button, skip
        if(e.target.closest('button')) return;
        const id = this.dataset.id;
        postForm({noti_accion:'leida', id: id}).then(()=>{ fetchList(); });
      });
    });
  }

  function fetchList(){
    postForm({noti_accion:'list'}).then(r=>{ if(r.success){ renderNotis(r.data); } else { listEl.innerHTML = '<div style="color:#666;padding:12px;text-align:center">Error al cargar</div>'; } });
  }

  toggle.addEventListener('click', function(e){
    e.stopPropagation();
    if(panel.style.display === 'none' || panel.style.display === ''){
      panel.style.display = 'block';
      fetchList();
    } else {
      panel.style.display = 'none';
    }
  });

  document.addEventListener('click', function(e){
    const menu = document.getElementById('empresaMenu');
    if(menu && !menu.contains(e.target)){
      panel.style.display = 'none';
    }
  });

  // On load, update unread count badge
  fetchList();
})();
</script>

<!-- Sección: Empleados de la Empresa (similar a proveedores) -->
<?php if ($evento_id): ?>
<section class="card mx-2 mt-4">
  <header class="text-[15px] font-bold text-[#5092ab] px-4 py-2 border-b border-[#5092ab] select-none rounded-t-lg flex items-center justify-between">
    <div class="flex items-center gap-2">
      <span>Empleados de la empresa</span>
      <?php if ($evento_id && count($empleados_asignados) > 0): ?>
        <span class="text-xs bg-[#5092ab] text-white px-2 py-1 rounded-full"><?= count($empleados_asignados) ?></span>
      <?php endif; ?>
    </div>
    <button onclick="document.getElementById('modalAsignarEmpleadosEmpresa').style.display='flex'" class="bg-[#5092ab] text-white text-xs px-3 py-2 rounded hover:bg-[#3b82f6] transition shadow-none">
      <i class="fas fa-user-plus mr-1"></i>Agregar
    </button>
  </header>
  <div class="px-4 py-3">
    <?php if (count($empleados_asignados) > 0): ?>
      <div class="overflow-x-auto">
        <div style="border-radius:12px; overflow:hidden; box-shadow:0 6px 20px rgba(0,0,0,0.06);">
          <table class="min-w-full bg-white" style="border-collapse: separate; border-spacing:0; width:100%;">
            <thead>
              <tr style="background: linear-gradient(90deg,#2b78e6,#1f63c9); color:#fff;">
                <th style="padding:12px 16px; text-align:left; border-right:1px solid #e3e8ee;">Nombre</th>
                <th style="padding:12px 16px; text-align:left; border-right:1px solid #e3e8ee;">Apellido</th>
                <th style="padding:12px 16px; text-align:left; border-right:1px solid #e3e8ee;">CUIL</th>
                <th style="padding:12px 16px; text-align:left; border-right:1px solid #e3e8ee;">Puesto</th>
                <th style="padding:12px 16px; text-align:left; border-right:1px solid #e3e8ee;">Tipo de Relación</th>
                <th style="padding:12px 16px; text-align:left;">Fechas de asistencia</th>
              </tr>
            </thead>
            <tbody style="background:#fff;">
              <?php foreach($empleados_asignados as $emp): ?>
                <tr style="border-top:1px solid #f0f4f7;">
                  <td style="padding:12px 16px; color:#111; border-right:1px solid #e9eef2; vertical-align:middle;"><?= htmlspecialchars($emp['nombre'] ?? '-') ?></td>
                  <td style="padding:12px 16px; color:#111; border-right:1px solid #e9eef2; vertical-align:middle;"><?= htmlspecialchars($emp['apellido'] ?? '-') ?></td>
                  <td style="padding:12px 16px; color:#111; border-right:1px solid #e9eef2; vertical-align:middle;"><?= htmlspecialchars($emp['cuil'] ?? '-') ?></td>
                  <td style="padding:12px 16px; color:#111; border-right:1px solid #e9eef2; vertical-align:middle;"><?= htmlspecialchars($emp['puesto'] ?? '-') ?></td>
                  <td style="padding:12px 16px; color:#111; border-right:1px solid #e9eef2; vertical-align:middle;"><?= htmlspecialchars(ucfirst(mb_strtolower($emp['tipo_relacion'] ?? '-'))) ?></td>
                  <td style="padding:12px 16px; vertical-align:middle;">
                    <?php if (!empty($emp['fechas_asistencia'])): ?>
                      <?php $fechas = array_filter(array_map('trim', explode(',', $emp['fechas_asistencia']))); ?>
                      <?php foreach($fechas as $f): ?>
                        <span class="inline-block text-sm text-gray-700 px-3 py-1 mr-1 rounded" style="background:#f7fafc; border:1px solid #e3e8ee"><?= date('d/m/Y', strtotime($f)) ?></span>
                      <?php endforeach; ?>
                    <?php elseif (!empty($emp['fecha_asistencia'])): ?>
                      <span class="text-sm text-gray-700"><?= date('d/m/Y', strtotime($emp['fecha_asistencia'])) ?></span>
                    <?php else: ?>
                      <span class="text-sm text-gray-400">No asignadas</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php else: ?>
      <div class="text-gray-500 text-sm py-6 text-center">No hay empleados de la empresa asignados a este evento.</div>
    <?php endif; ?>
  </div>
</section>

<!-- Modal asignar empleados empresa -->
<div id="modalAsignarEmpleadosEmpresa" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display:none;">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-2xl mx-4 relative">
    <button onclick="document.getElementById('modalAsignarEmpleadosEmpresa').style.display='none'" class="absolute top-4 right-4 text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
    <h2 class="text-xl font-bold text-[#5092ab] mb-4"><i class="fas fa-user-plus text-[#5092ab] mr-2"></i>Asignar empleados de la empresa al evento</h2>
    <form method="post">
      <input type="hidden" name="asignar_empleados_empresa" value="1" />
      <div class="max-h-64 overflow-y-auto mb-4">
            <?php if (count($empleados_disponibles) > 0): ?>
          <?php foreach($empleados_disponibles as $idx => $emp): ?>
            <label class="flex items-center space-x-3 mb-3 bg-[#f8fafc] rounded-lg p-3 shadow-sm hover:bg-[#e3f2fd] transition cursor-pointer border border-[#5092ab]/20">
              <input type="checkbox" name="empleados_empresa[]" value="<?= $emp['id'] ?>" class="form-checkbox h-5 w-5 text-[#5092ab]">
              <div class="flex flex-col flex-1 min-w-0">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-[#b6c7d6] flex items-center justify-center text-white text-sm font-semibold"><?= strtoupper(substr($emp['nombre'],0,2)) ?></div>
                    <div class="flex flex-col min-w-0">
                      <span class="font-medium text-gray-900 truncate text-sm"><?= htmlspecialchars($emp['nombre']) ?> <?= htmlspecialchars($emp['apellido']) ?></span>
                      <span class="text-xs text-gray-500">CUIL: <?= htmlspecialchars($emp['cuil']) ?></span>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    <?php $empId = intval($emp['id']); ?>
                    <?php if (count($event_dates) == 1): ?>
                      <input type="date" name="fecha_asistencia_empresa[<?= $empId ?>]" value="<?= htmlspecialchars($event_dates[0]) ?>" class="border rounded px-2 py-1 text-xs" readonly />
                    <?php elseif (count($event_dates) > 1): ?>
                      <div class="flex flex-wrap gap-1">
                        <?php foreach($event_dates as $ed): ?>
                          <label class="flex items-center gap-1 text-sm bg-gray-100 px-2 py-1 rounded">
                            <input type="checkbox" name="fecha_asistencia_empresa[<?= $empId ?>][]" value="<?= $ed ?>"> <?= date('d/m/Y', strtotime($ed)) ?>
                          </label>
                        <?php endforeach; ?>
                      </div>
                      <span class="text-xs text-gray-500 block mt-1">Selecciona los días que asistirá este empleado</span>
                    <?php else: ?>
                      <input type="date" name="fecha_asistencia_empresa[<?= $empId ?>]" class="border rounded px-2 py-1 text-xs" />
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </label>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="text-gray-500 text-center py-6">No hay empleados disponibles.</div>
        <?php endif; ?>
      </div>
      <div class="flex space-x-3 pt-4">
        <button type="submit" class="flex-1 btn-primary"><i class="fas fa-save mr-2"></i>Asignar seleccionados</button>
        <button type="button" onclick="document.getElementById('modalAsignarEmpleadosEmpresa').style.display='none'" class="px-4 py-2 border border-[#5092ab] text-[#5092ab] rounded-lg hover:bg-[#5092ab] hover:text-white transition">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Modal para subir protocolo/cláusulas -->

<?php if ($evento_id): ?>
<div id="modalProtocoloClausulas" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display:none;">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-lg mx-4 relative flex flex-col items-center justify-center" style="min-width:340px;">
  <button onclick="document.getElementById('modalProtocoloClausulas').style.display='none'" class="absolute right-4 top-4 text-gray-400 hover:text-red-500 text-2xl font-bold" style="z-index:10;">&times;</button>
    <h2 class="text-xl font-bold text-[#5092ab] mb-6 flex items-center justify-center" style="margin-top:16px;">
      <i class="fas fa-file-upload text-[#5092ab] mr-2"></i>
      <span style="font-size:1.2em;">Subir protocolo o cláusulas</span>
    </h2>
    <div class="w-full flex flex-col gap-4">
      <form method="post" enctype="multipart/form-data" class="space-y-4">
        <div class="w-full flex flex-col items-center justify-center">
          <label class="block text-sm font-medium text-[#5092ab] mb-2 text-center">Cargue aquí su protocolo de seguridad o sus cláusulas de no repetición para los proveedores</label>
          <input type="file" name="protocolo_clausulas[]" multiple required accept="application/pdf" class="w-full border border-[#5092ab] rounded-md px-3 py-2 text-[14px] text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#5092ab] transition bg-white mb-2" />
        </div>
        <div class="flex flex-row gap-4 pt-4 w-full justify-center">
          <button type="submit" name="subir_protocolo_clausulas" class="btn-primary flex items-center justify-center gap-2 px-6 py-2 rounded-lg text-base">
            <i class="fas fa-save"></i>Subir
          </button>
          <button type="button" onclick="document.getElementById('modalProtocoloClausulas').style.display='none'" class="px-6 py-2 border border-[#5092ab] text-[#5092ab] rounded-lg hover:bg-[#5092ab] hover:text-white transition text-base">Cancelar</button>
        </div>
      </form>
      <div class="mt-6 w-full bg-[#f7fafc] border border-[#e3e8ee] rounded-xl shadow px-4 py-3">
        <h3 class="text-[#5092ab] font-bold mb-2 text-base flex items-center gap-2"><i class="fas fa-file-upload"></i> Protocolos/Cláusulas subidos</h3>
        <ul class="divide-y divide-[#5092ab]/20 w-full">
          <?php
          $archivos = [];
          $stmt = $conn->prepare("SELECT id, archivo, nombre_original FROM protocolo_clausulas WHERE evento_id = ? ORDER BY id DESC");
          $stmt->bind_param("i", $evento_id);
          $stmt->execute();
          $stmt->bind_result($id, $archivo, $nombre_original);
          while ($stmt->fetch()) {
            $archivos[] = ['id' => $id, 'archivo' => $archivo, 'nombre' => $nombre_original];
          }
          $stmt->close();
          if (count($archivos) > 0) {
            foreach ($archivos as $archivo) {
          ?>
              <li class="flex items-center justify-between py-2 px-2 mb-2 bg-transparent">
                <span class="archivo-nombre flex items-center gap-2"><i class="fas fa-file-pdf text-[#e63946]"></i> <?= htmlspecialchars($archivo['nombre']) ?></span>
                <div class="flex items-center gap-2">
                  <a href="uploads/protocolo_clausulas/<?= htmlspecialchars($archivo['archivo']) ?>" 
                   target="_blank" 
                   class="btn-upload-pdf flex items-center gap-1 px-3 py-1 text-xs bg-[#5092ab] text-white rounded hover:bg-[#3b82f6] transition">
                   <i class="fas fa-eye"></i> Ver
                  </a>
                  <form method="post" style="display:inline;">
                   <input type="hidden" name="eliminar_protocolo_clausulas" value="1" />
                   <input type="hidden" name="archivo_id" value="<?= $archivo['id'] ?>" />
                   <button 
                    type="submit" 
                    class="flex items-center gap-1 px-3 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-700 transition focus:outline-none"
                    onclick="return confirm('¿Seguro que desea eliminar este archivo?');">
                    <i class="fas fa-trash"></i> Eliminar
                   </button>
                  </form>
                </div>
              </li>
          <?php
            }
          } else {
          ?>
              <li class="text-gray-500 text-sm py-2">No hay archivos subidos aún.</li>
          <?php
          }
          ?>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php

// Procesar subida de protocolo/cláusulas y eliminar archivos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_protocolo_clausulas']) && $evento_id) {
  $dir = 'uploads/protocolo_clausulas/';
  if (!is_dir($dir)) mkdir($dir, 0777, true);
  foreach ($_FILES['protocolo_clausulas']['name'] as $idx => $nombre) {
    if ($_FILES['protocolo_clausulas']['error'][$idx] === UPLOAD_ERR_OK) {
      $ext = pathinfo($nombre, PATHINFO_EXTENSION);
      $archivo_nombre = 'evento'.$evento_id.'_protocolo_'.time().'_'.$idx.'.'.$ext;
      move_uploaded_file($_FILES['protocolo_clausulas']['tmp_name'][$idx], $dir.$archivo_nombre);
      // Guardar en la base de datos
      $stmt = $conn->prepare("INSERT INTO protocolo_clausulas (evento_id, archivo, nombre_original) VALUES (?, ?, ?)");
      $stmt->bind_param("iss", $evento_id, $archivo_nombre, $nombre);
      $stmt->execute();
      $stmt->close();
    }
  }
  echo '<script>setTimeout(function(){document.getElementById("modalProtocoloClausulas").style.display="none";}, 1000);</script>';
}

// Eliminar archivo de protocolo/cláusulas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_protocolo_clausulas']) && isset($_POST['archivo_id'])) {
  $archivo_id = intval($_POST['archivo_id']);
  // Obtener nombre de archivo
  $stmt = $conn->prepare("SELECT archivo FROM protocolo_clausulas WHERE id = ?");
  $stmt->bind_param("i", $archivo_id);
  $stmt->execute();
  $stmt->bind_result($archivo_nombre);
  if ($stmt->fetch()) {
    $stmt->close();
    // Eliminar archivo físico
    $dir = 'uploads/protocolo_clausulas/';
    $ruta = $dir . $archivo_nombre;
    if (file_exists($ruta)) {
      unlink($ruta);
    }
    // Eliminar registro de la base
    $stmt2 = $conn->prepare("DELETE FROM protocolo_clausulas WHERE id = ?");
    $stmt2->bind_param("i", $archivo_id);
    $stmt2->execute();
    $stmt2->close();
  } else {
    $stmt->close();
  }
}
?>


<!-- Modal para agregar requisito -->
<?php if ($evento_id): ?>
<div id="modalAgregarRequisito" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display:none;">
    <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md mx-4 relative">
        <button onclick="document.getElementById('modalAgregarRequisito').style.display='none'" 
                class="absolute top-4 right-4 text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
        
        <h2 class="text-xl font-bold text-[#5092ab] mb-6 flex items-center">
            <i class="fas fa-plus-circle text-[#5092ab] mr-2"></i>
            Agregar Requisito al Evento
        </h2>
        
        <form method="post" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-[#5092ab] mb-2">Seleccionar Requisito</label>
                <select name="requisito_id" required class="w-full border border-[#5092ab] rounded-md px-3 py-2 text-[14px] text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#5092ab] transition bg-white">
                    <option value="">Selecciona un requisito</option>
                    <?php foreach($requisitos_disponibles as $req): ?>
                        <option value="<?= $req['id'] ?>"><?= htmlspecialchars($req['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (count($requisitos_disponibles) == 0): ?>
                    <p class="text-sm text-orange-600 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Todos los requisitos disponibles ya están asignados a este evento.
                    </p>
                <?php endif; ?>
            </div>
            
            <div class="flex space-x-3 pt-4">
                <button type="submit" name="agregar_requisito" 
                        class="flex-1 bg-[#5092ab] text-white px-4 py-2 rounded-lg hover:bg-[#3b82f6] transition"
                        <?= (count($requisitos_disponibles) == 0) ? 'disabled' : '' ?>>
                    <i class="fas fa-plus mr-2"></i>Agregar
                </button>
                <button type="button" onclick="document.getElementById('modalAgregarRequisito').style.display='none'"
                        class="px-4 py-2 border border-[#5092ab] text-[#5092ab] rounded-lg hover:bg-[#5092ab] hover:text-white transition">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- Modal para asignar proveedores -->
<?php if ($evento_id): ?>
<div id="modalAsignarProveedores" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display:none;">
  <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-lg mx-4 relative">
    <button onclick="document.getElementById('modalAsignarProveedores').style.display='none'" class="absolute top-4 right-4 text-gray-400 hover:text-red-500 text-xl font-bold">&times;</button>
    <h2 class="text-xl font-bold text-[#5092ab] mb-6 flex items-center"><i class="fas fa-user-plus text-[#5092ab] mr-2"></i>Asignar proveedores al evento</h2>
    <form method="post">
      <input type="hidden" name="asignar_proveedores" value="1"/>
      <div class="max-h-64 overflow-y-auto mb-4">
        <?php if (count($proveedores_disponibles) > 0): ?>
          <?php foreach($proveedores_disponibles as $prov): ?>
            <label class="flex items-center space-x-3 mb-3 bg-[#f8fafc] rounded-lg p-3 shadow-sm hover:bg-[#e3f2fd] transition cursor-pointer border border-[#5092ab]/20">
              <input type="checkbox" name="proveedores[]" value="<?= $prov['id'] ?>" class="form-checkbox h-5 w-5 text-[#5092ab]">
              <?php if ($prov['foto'] && file_exists($prov['foto'])): ?>
                <img src="<?= htmlspecialchars($prov['foto']) ?>" alt="Foto de <?= htmlspecialchars($prov['nombre']) ?>" class="w-8 h-8 rounded-full object-cover border-2 border-[#5092ab]/30">
              <?php else: ?>
                <div class="w-8 h-8 rounded-full bg-[#5092ab] flex items-center justify-center text-white text-sm font-semibold">
                  <?= strtoupper(substr($prov['nombre'], 0, 2)) ?>
                </div>
              <?php endif; ?>
              <div class="flex flex-col">
                <span class="font-medium text-gray-900 text-base"><?= htmlspecialchars($prov['nombre']) ?></span>
                <span class="text-xs text-gray-500"><?= htmlspecialchars($prov['email']) ?></span>
              </div>
            </label>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="text-gray-500 text-center py-8">No hay proveedores disponibles para asignar.</div>
        <?php endif; ?>
      </div>
      <div class="flex space-x-3 pt-4">
        <button type="submit" class="flex-1 btn-primary"><i class="fas fa-save mr-2"></i>Asignar seleccionados</button>
        <button type="button" onclick="document.getElementById('modalAsignarProveedores').style.display='none'" class="px-4 py-2 border border-[#5092ab] text-[#5092ab] rounded-lg hover:bg-[#5092ab] hover:text-white transition">Cancelar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
</body>
</html>

<script>
// Auto-check employee checkbox when date checkboxes are toggled in the modal
document.addEventListener('change', function(e) {
  var target = e.target;
  if (!target) return;
  // date checkbox names look like fecha_asistencia_empresa[<id>][]
  if (target.name && target.name.indexOf('fecha_asistencia_empresa[') === 0) {
    // extract id
    var m = target.name.match(/^fecha_asistencia_empresa\[(\d+)\]/);
    if (m && m[1]) {
      var empId = m[1];
      var empCheckbox = document.querySelector('input[name="empleados_empresa[]"][value="' + empId + '"]');
      if (empCheckbox) {
        if (target.checked) empCheckbox.checked = true;
        else {
          // if all date checkboxes for this emp are unchecked, uncheck the emp checkbox
          var any = document.querySelectorAll('input[name="fecha_asistencia_empresa['+empId+'][]"]:checked');
          if (!any || any.length === 0) empCheckbox.checked = false;
        }
      }
    }
  }
});
</script>