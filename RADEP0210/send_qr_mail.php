<?php
// send_qr_mail.php - envía correo por SMTP con PHPMailer y QRs embebidos
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/dbconn.php';
if (!file_exists(__DIR__ . '/config_smtp.php')) {
    die('Falta config_smtp.php. Copiar config_smtp.php.example y completar.');
}
require_once __DIR__ . '/config_smtp.php';
// Optional app config (SITE_BASE_URL)
if (file_exists(__DIR__ . '/config_app.php')) require_once __DIR__ . '/config_app.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use chillerlan\QRCode\{QRCode, QROptions};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['proveedor_id']) || !isset($_POST['evento_id'])) {
    http_response_code(400); echo 'Bad request'; exit;
}
$proveedor_id = intval($_POST['proveedor_id']);
$evento_id = intval($_POST['evento_id']);

// Cargar datos
$stmt = $conn->prepare("SELECT nombre, fecha FROM eventos WHERE id = ?");
$stmt->bind_param("i", $evento_id); $stmt->execute(); $res = $stmt->get_result(); $evento = $res->fetch_assoc(); $stmt->close();
    $stmt = $conn->prepare("SELECT nombre, email, servicio FROM proveedores WHERE id = ?");
$stmt->bind_param("i", $proveedor_id); $stmt->execute(); $res = $stmt->get_result(); $prov = $res->fetch_assoc(); $stmt->close();

if (empty($prov['email']) || !filter_var($prov['email'], FILTER_VALIDATE_EMAIL)) {
    echo 'Proveedor sin email válido'; exit;
}

// Obtener empleados asignados
$emps = [];
$stmt = $conn->prepare("SELECT e.id, e.nombre, e.apellido, e.cuil FROM empleados_evento ee JOIN empleados_proveedor e ON ee.empleado_id = e.id WHERE ee.evento_id = ? AND e.proveedor_id = ?");
$stmt->bind_param("ii", $evento_id, $proveedor_id);
$stmt->execute(); $res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $emps[] = $r;
$stmt->close();

if (count($emps) === 0) { echo 'No hay empleados asignados'; exit; }

// Preparar mail
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = 'tls';
    $mail->Port = SMTP_PORT;

    // modo debug opcional: pasar debug=1 en POST para generar smtp_debug.txt
    $logDir = __DIR__ . '/tmp_mail_previews'; if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    if (isset($_POST['debug']) && $_POST['debug'] == '1') {
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) use ($logDir) {
            file_put_contents($logDir . '/smtp_debug.txt', date('c') . " [$level] " . $str . "\n", FILE_APPEND | LOCK_EX);
        };
    }

    // Use SMTP user as From to avoid provider rejections (Gmail may enforce From=authenticated user)
    $mail->setFrom(SMTP_USER, FROM_NAME);
    $mail->addAddress($prov['email'], $prov['nombre']);
    $mail->isHTML(true);
    $mail->Subject = 'Accesos para evento ' . ($evento['nombre'] ?? '');

    // Cargar plantilla guardada si existe
    $templateFile = __DIR__ . '/mail_qr_template.html';
    $subjectFile = __DIR__ . '/mail_qr_subject.txt';
    $templateHtml = '';
    if (file_exists($templateFile)) {
        $templateHtml = file_get_contents($templateFile);
    }
    $subjectText = file_exists($subjectFile) ? file_get_contents($subjectFile) : '';

    // Valores por defecto si no hay plantilla
    if (trim($templateHtml) === '') {
        $templateHtml = '<p>Hola {PROVEEDOR_NOMBRE},</p><p>A continuación se adjuntan los códigos QR para los empleados asignados al evento <strong>{EVENTO_NOMBRE}</strong> ({EVENTO_FECHA}):</p><p>{EMPLEADOS_QR}</p><p>Saludos,<br/>Organización</p>';
    }
    if (trim($subjectText) === '') {
        $subjectText = 'Accesos para evento {EVENTO_NOMBRE}';
    }

    $options = new QROptions(['outputType' => QRCode::OUTPUT_IMAGE_PNG, 'eccLevel' => QRCode::ECC_L, 'scale' => 4]);
    $qrGen = new QRCode($options);

    $empleadosHtml = '';
    $tmpFiles = [];
    foreach ($emps as $emp) {
        // Generate a cryptographically secure token
        try {
            $token = bin2hex(random_bytes(20)); // 40 hex chars
        } catch (Exception $e) {
            // fallback
            $token = substr(hash('sha256', $evento_id . '|' . $emp['id'] . '|' . time()), 0, 40);
        }
        // Ensure columns exist (migration preferred)
        $colRes = $conn->query("SHOW COLUMNS FROM empleados_evento LIKE 'qr_token'");
        if ($colRes && $colRes->num_rows == 0) $conn->query("ALTER TABLE empleados_evento ADD COLUMN qr_token VARCHAR(64) NULL, ADD COLUMN qr_generated_at DATETIME NULL, ADD COLUMN qr_revoked TINYINT(1) NOT NULL DEFAULT 0");
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE empleados_evento SET qr_token = ?, qr_generated_at = ?, qr_revoked = 0 WHERE evento_id = ? AND empleado_id = ?");
        $stmt->bind_param('ssii', $token, $now, $evento_id, $emp['id']);
        $stmt->execute(); $stmt->close();

    // Construir URL de validación segura
    // Construir URL de validación: si el admin definió SITE_BASE_URL en config.php lo usamos
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    if (defined('SITE_BASE_URL') && trim(SITE_BASE_URL) !== '') {
        $qrUrl = rtrim(SITE_BASE_URL, '/') . '/validar_empleado_qr.php?token=' . urlencode($token);
    } else {
        $qrUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/validar_empleado_qr.php?token=' . urlencode($token);
    }
    $pngData = $qrGen->render($qrUrl);

        // Algunas versiones pueden devolver una data-uri (data:image/png;base64,...)
        // Tratar robustamente: recortar y detectar pattern base64
        if (is_string($pngData)) {
            $pngTrim = ltrim($pngData);
            if (preg_match('#^data:.*;base64,#i', $pngTrim)) {
                $comma = strpos($pngTrim, ',');
                if ($comma !== false) {
                    $pngData = base64_decode(substr($pngTrim, $comma + 1));
                }
            } else {
                // mantener $pngData sin cambios (binario)
            }
        }

        // Guardar PNG temporal y adjuntarlo como embedded image
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radep_qr_' . $emp['id'] . '_' . uniqid() . '.png';
        file_put_contents($tmpFile, $pngData);
        $tmpFiles[] = $tmpFile;

        $cid = 'qr' . $emp['id'];
        // agregar como embedded image
        $mail->addEmbeddedImage($tmpFile, $cid, 'qr_' . $emp['id'] . '.png');

        $empleadosHtml .= '<div style="margin-bottom:12px"><strong>' . htmlspecialchars($emp['nombre'] . ' ' . $emp['apellido']) . '</strong><br/>CUIL: ' . htmlspecialchars($emp['cuil']) . '<br/><img src="cid:' . $cid . '" style="width:150px;height:150px" alt="QR"/></div>';
    }
    // Reemplazar placeholders en plantilla
    $replacements = [
        '{PROVEEDOR_NOMBRE}' => htmlspecialchars($prov['nombre']),
        '{EVENTO_NOMBRE}' => htmlspecialchars($evento['nombre'] ?? ''),
        '{EVENTO_FECHA}' => htmlspecialchars($evento['fecha'] ?? ''),
        '{EMPLEADOS_QR}' => $empleadosHtml,
    ];
    // Evitar que la plantilla tenga <p>{EMPLEADOS_QR}</p> (causa <p><div> inválido). Normalizamos primero.
    $templateHtml = preg_replace('/<p>\s*\{EMPLEADOS_QR\}\s*<\/p>/i', '{EMPLEADOS_QR}', $templateHtml);
    $rendered = strtr($templateHtml, $replacements);
    $mail->Body = $rendered;
    $mail->Subject = strtr($subjectText, ['{EVENTO_NOMBRE}' => ($evento['nombre'] ?? '')]);
    $mail->AltBody = strip_tags(str_replace(['<br/>','<br>','</p>','</div>'], "\n", $rendered));

    $mail->send();
    // log success
    $logDir = __DIR__ . '/tmp_mail_previews'; if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    file_put_contents($logDir . '/mail_log.txt', date('c') . " | prov:$proveedor_id | email:" . $prov['email'] . " | sent:1\n", FILE_APPEND | LOCK_EX);

    // limpiar archivos temporales creados al adjuntar images
    if (!empty($tmpFiles)) {
        foreach ($tmpFiles as $f) { if (file_exists($f)) @unlink($f); }
    }

    echo 'Enviado correctamente';
} catch (Exception $e) {
    $logDir = __DIR__ . '/tmp_mail_previews'; if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    $previewFile = $logDir . '/mail_prov_' . $proveedor_id . '_' . time() . '.html';
    $previewHtml = isset($rendered) && trim($rendered) !== '' ? $rendered : (isset($mail) && !empty($mail->Body) ? $mail->Body : (isset($body) ? $body : ''));
    file_put_contents($previewFile, $previewHtml);
    $mailerErr = isset($mail) ? $mail->ErrorInfo : '';
    $logLine = date('c') . " | prov:$proveedor_id | email:" . $prov['email'] . " | sent:0 | error:" . $e->getMessage() . " | mailerErr:" . $mailerErr . " | preview:" . $previewFile . "\n";
    file_put_contents($logDir . '/mail_log.txt', $logLine, FILE_APPEND | LOCK_EX);
    // no hay temporales con data URIs
    echo 'Error al enviar, preview guardado: ' . $previewFile;
}
