<?php
// Simple editor (amigable) para la plantilla de mail QR
$templateFile = __DIR__ . '/mail_qr_template.html';
if (!file_exists($templateFile)) {
    // plantilla por defecto (con placeholders)
    $default = "<html><body><p>Hola {PROVEEDOR_NOMBRE},</p><p>Adjunto los códigos QR para que sus empleados ingresen al evento {EVENTO_NOMBRE} el día {EVENTO_FECHA}.</p><p>{EMPLEADOS_QR}</p><p>Saludos,<br>Organización</p></body></html>";
    file_put_contents($templateFile, $default);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Campos amigables
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
    $greeting = isset($_POST['greeting']) ? trim($_POST['greeting']) : '';
    $body_text = isset($_POST['body_text']) ? trim($_POST['body_text']) : '';
    $closing = isset($_POST['closing']) ? trim($_POST['closing']) : '';
  // Armar HTML final usando los placeholders (no incluimos CSS en la plantilla guardada para evitar que aparezca como texto)
  $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>';
    if ($greeting !== '') $html .= '<p>' . htmlspecialchars($greeting) . '</p>';
    if ($body_text !== '') $html .= '<p>' . nl2br(htmlspecialchars($body_text)) . '</p>';
    // Insertamos placeholder EMPL
    $html .= '<p>{EMPLEADOS_QR}</p>';
    if ($closing !== '') $html .= '<p>' . nl2br(htmlspecialchars($closing)) . '</p>';
    $html .= '</body></html>';

    // Guardar plantilla y asunto por separado (simplemente guardamos la plantilla HTML)
    file_put_contents($templateFile, $html);
    // También guardar subject en un archivo auxiliar
    file_put_contents(__DIR__ . '/mail_qr_subject.txt', $subject);
    header('Location: config_mail_qr.php?saved=1');
    exit;
}

$template = file_get_contents($templateFile);
$subject = file_exists(__DIR__ . '/mail_qr_subject.txt') ? file_get_contents(__DIR__ . '/mail_qr_subject.txt') : 'Accesos para evento {EVENTO_NOMBRE}';

// Preparar valores iniciales para los campos (extraer antes/después del placeholder {EMPLEADOS_QR})
$initial_greeting = '';
$initial_body_text = '';
$initial_closing = '';
$parts = preg_split('/\{EMPLEADOS_QR\}/', $template);
$before_html = $parts[0] ?? '';
$after_html = $parts[1] ?? '';
// Eliminar bloques <style>...</style> (si existen) para que no queden como texto al usar strip_tags
$before_html = preg_replace('/<style.*?>.*?<\/style>/is', '', $before_html);
$after_html = preg_replace('/<style.*?>.*?<\/style>/is', '', $after_html);
// extraer primer <p> del before_html como saludo
if (preg_match('/<p>(.*?)<\/p>/s', $before_html, $m)) {
  $initial_greeting = trim(strip_tags($m[1]));
  // quitar ese primer párrafo del resto
  $before_html_rest = preg_replace('/<p>.*?<\/p>/s', '', $before_html, 1);
  $initial_body_text = trim(strip_tags($before_html_rest));
} else {
  $initial_body_text = trim(strip_tags($before_html));
}
// cerrar: primer <p> del after_html
if (preg_match('/<p>(.*?)<\/p>/s', $after_html, $m2)) {
  $initial_closing = trim(strip_tags($m2[1]));
} else {
  $initial_closing = trim(strip_tags($after_html));
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Configurar Mail QR (fácil)</title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;background:#f7fafc;padding:18px}
  .box{background:#fff;border:1px solid #e3e8ee;padding:18px;border-radius:10px;max-width:1000px;margin:0 auto}
  label{display:block;margin-top:10px;font-weight:600;color:#274151}
  input[type=text], textarea{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px}
  .row{display:flex;gap:12px}
  .col{flex:1}
  .btn{background:#5092ab;color:#fff;padding:8px 12px;border-radius:8px;border:none;cursor:pointer}
  .placeholders{margin-top:8px}
  .ph-btn{display:inline-block;background:#eef2f7;border:1px solid #d1e6ef;padding:6px 8px;border-radius:6px;margin-right:6px;cursor:pointer}
  .preview{border:1px solid #e3e8ee;padding:12px;border-radius:8px;background:#fff;margin-top:12px}
  .help{font-size:13px;color:#475569;margin-top:6px}
</style>
</head>
<body class="bg-gradient-to-br from-[#a9bec7] via-[#8ba3b0] to-[#6b8a9a] min-h-screen">
  <div class="max-w-6xl mx-auto px-4 py-6">
    <header class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <img src="images/logo-radep.svg" alt="RADEP" style="height:48px" />
      </div>
  <a href="dashboard_empresa.php" title="Volver al dashboard" class="inline-flex items-center gap-2 bg-[#5092ab] hover:bg-[#3b82f6] text-white px-4 py-2 rounded-lg font-semibold shadow-md transition-colors duration-200" style="color:#fff;text-decoration:none">
        <span style="font-size:18px;line-height:1">&larr;</span>
        <span>Volver al dashboard</span>
      </a>
    </header>
    <div class="box">
      <h2 class="text-lg font-semibold text-[#274151]">Editor del mail a proveedores</h2>
      <p class="help">Completa estos campos; usa los botones para insertar valores dinámicos.</p>
    <form method="post" id="frm">
      <label>Asunto</label>
      <input type="text" name="subject" id="subject" value="<?= htmlspecialchars($subject) ?>" />

  <label>Saludo (por ejemplo: Hola {PROVEEDOR_NOMBRE},)</label>
  <input type="text" name="greeting" id="greeting" value="<?= htmlspecialchars($initial_greeting) ?>" />

  <label>Texto principal</label>
  <textarea name="body_text" id="body_text" rows="6"><?= htmlspecialchars($initial_body_text) ?></textarea>

  <label>Despedida / Firma</label>
  <input type="text" name="closing" id="closing" value="<?= htmlspecialchars($initial_closing ?: "Saludos,\nOrganización") ?>" />

      <div class="placeholders">
        <span class="ph-btn" data-ph="{PROVEEDOR_NOMBRE}">{PROVEEDOR_NOMBRE}</span>
        <span class="ph-btn" data-ph="{EVENTO_NOMBRE}">{EVENTO_NOMBRE}</span>
        <span class="ph-btn" data-ph="{EVENTO_FECHA}">{EVENTO_FECHA}</span>
        <span class="ph-btn" data-ph="{EMPLEADOS_QR}">{EMPLEADOS_QR}</span>
      </div>

      <div style="margin-top:12px">
        <button class="btn" type="submit">Guardar plantilla</button>
        <?php if (isset($_GET['saved'])): ?> <span style="margin-left:12px;color:green">Guardado</span><?php endif; ?>
      </div>
    </form>

    <h3 style="margin-top:18px">Vista previa</h3>
    <div class="preview" id="preview"></div>
    <p class="help">La vista previa usa datos de ejemplo; cuando se envíe se reemplazarán las variables reales.</p>
  </div>

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    // insertar placeholder donde esté el cursor
    document.querySelectorAll('.ph-btn').forEach(function(b){
      b.addEventListener('click', function(){
        var ph = this.getAttribute('data-ph');
        // si es empleados_qr, pondremos un marcador especial en preview
        if (ph === '{EMPLEADOS_QR}') {
          // no insert en campos; solo actualizar preview
          updatePreview();
          return;
        }
        var el = document.getElementById('body_text');
        var start = el.selectionStart || 0; var end = el.selectionEnd || 0;
        var val = el.value;
        el.value = val.substring(0,start) + ph + val.substring(end);
        el.focus(); el.selectionStart = start + ph.length; el.selectionEnd = start + ph.length;
        updatePreview();
      });
    });

    function updatePreview(){
      var subj = document.getElementById('subject').value;
      var greeting = document.getElementById('greeting').value;
      var body = document.getElementById('body_text').value;
      var closing = document.getElementById('closing').value;
      var html = '';
      if (greeting) html += '<p>' + escapeHtml(greeting) + '</p>';
      if (body) html += '<p>' + nl2br(escapeHtml(body)) + '</p>';
      // empleados QR placeholder preview
      html += '<div style="padding:8px;background:#fff;border:1px dashed #cbd5e1;margin:8px 0">';
      html += '<strong>Códigos QR (ejemplo)</strong><div style="display:flex;gap:8px;margin-top:8px">';
      for (var i=0;i<3;i++) html += '<div style="text-align:center"><img src="https://chart.googleapis.com/chart?cht=qr&chs=120x120&chl=EJEMPLO'+i+'" style="width:80px;height:80px"/><div style="font-size:12px">Empleado '+(i+1)+'</div></div>';
      html += '</div></div>';
      if (closing) html += '<p>' + nl2br(escapeHtml(closing)) + '</p>';
      document.getElementById('preview').innerHTML = html;
    }
    function escapeHtml(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'\n'); }
    function nl2br(s){ return s.replace(/\n/g,'<br/>'); }
    document.getElementById('subject').addEventListener('input', updatePreview);
    document.getElementById('greeting').addEventListener('input', updatePreview);
    document.getElementById('body_text').addEventListener('input', updatePreview);
    document.getElementById('closing').addEventListener('input', updatePreview);
    // inicializar preview
    updatePreview();
  </script>
</body>
</html>
