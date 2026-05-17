<?php
session_start(); include('dbconn.php'); if(!isset($_SESSION['tipo_usuario']) || $_SESSION['tipo_usuario']!=='admin'){ header('Location: index.php'); exit(); }
$section = $_GET['section'] ?? 'home';

function esc($s){ return htmlspecialchars($s); }

// Shared queries
switch($section){
    case 'empresas':
  $rows=[]; $res = $conn->query("SELECT id,nombre,user,email FROM empresas ORDER BY id DESC"); if($res){ while($r=$res->fetch_assoc()) $rows[]=$r; }
        break;
    case 'proveedores':
  $rows=[]; $res = $conn->query("SELECT id,nombre,usuario,email FROM proveedores ORDER BY id DESC"); if($res){ while($r=$res->fetch_assoc()) $rows[]=$r; }
        break;
    case 'eventos':
  $rows=[]; $res = $conn->query("SELECT e.id,e.nombre,e.fecha,e.estado, emp.nombre AS empresa FROM eventos e LEFT JOIN empresas emp ON e.empresa_id = emp.id ORDER BY e.fecha DESC"); if($res){ while($r=$res->fetch_assoc()) $rows[]=$r; }
        break;
  case 'evento_empleados':
    $rows=[]; $id = intval($_GET['id'] ?? 0);
    if($id){
      // Not all deployments have an email column on empleados; select commonly available fields instead
      $stmt = $conn->prepare("SELECT em.id, em.nombre, em.apellido, em.cuil, em.puesto FROM empleados em JOIN eventos e ON e.empresa_id = em.empresa_id WHERE e.id = ?");
      $stmt->bind_param('i',$id);
      $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()) $rows[]=$r;
    }
        break;
    case 'documentos':
        $rows=[]; $res = $conn->query("SELECT d.id,d.filename,d.filepath,d.status,d.comentario,d.uploaded_at,d.uploader_type,d.uploader_id, e.nombre AS evento FROM documentos d LEFT JOIN eventos e ON d.evento_id = e.id ORDER BY d.uploaded_at DESC"); if($res){ while($r=$res->fetch_assoc()) $rows[]=$r; }
        break;
    case 'admins':
  $rows=[]; $res = $conn->query("SELECT id,username,role,nombre,apellido,email FROM users ORDER BY id DESC"); if($res){ while($r=$res->fetch_assoc()) $rows[]=$r; }
        break;
  case 'logs':
    $rows=[];
    // Some schemas may not have created_at; select available columns and optionally load date when present
    $res = $conn->query("SELECT id,username,action,details,ip FROM audit_logs ORDER BY id DESC LIMIT 200");
    if($res){ while($r=$res->fetch_assoc()) $rows[]=$r; }
    break;
    case 'settings':
        // nothing special
        break;
    default:
        // dashboard stats
        $stats=[];
  $res = $conn->query("SELECT COUNT(*) AS total FROM empresas"); $stats['empresas_activas'] = ($res->fetch_assoc()['total'] ?? 0);
  $res = $conn->query("SELECT COUNT(*) AS total FROM proveedores"); $stats['proveedores'] = ($res->fetch_assoc()['total'] ?? 0);
  $res = $conn->query("SELECT COUNT(*) AS total FROM empleados"); $stats['empleados'] = ($res->fetch_assoc()['total'] ?? 0);
        $res = $conn->query("SELECT COUNT(*) AS total FROM eventos WHERE fecha >= NOW()"); $stats['eventos_proximos'] = ($res->fetch_assoc()['total'] ?? 0);
}

// HTML output
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin - RADEP</title>
<link rel="stylesheet" href="estilos.css">
<style>
/* Minimal admin styles (palette and tone copied from main dashboards) */
#admin-container{max-width:1200px;margin:1.5rem auto;padding:1rem;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#0f1724}
#admin-container .card{background:#ffffff;padding:1rem;border-radius:12px;box-shadow:0 10px 30px rgba(7,59,76,0.06);color:inherit}
#admin-container .card h2,#admin-container .card h3{color:#0b1220;margin:0 0 .6rem 0;font-weight:700}
#admin-container .header{display:flex;justify-content:space-between;align-items:center;gap:1rem;background:linear-gradient(90deg,#059669,#06b6d4);padding:1rem;border-radius:10px}
#admin-container .header h1{margin:0;color:#fff}
#admin-container .nav{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
#admin-container .nav a{padding:.35rem .75rem;background:transparent;color:#fff;text-decoration:none;border-radius:8px}
#admin-container .table{width:100%;border-collapse:collapse;margin-top:12px}
#admin-container .table th,#admin-container .table td{padding:.85rem .9rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#0f1724}
#admin-container .table th{font-weight:700;background:#0b6b4f;color:#ffffff}
#admin-container button,#admin-container input[type=submit]{background:linear-gradient(90deg,#059669,#06b6d4);color:#ffffff;border:none;padding:8px 12px;border-radius:10px;cursor:pointer}
#admin-container a.action-link{color:#0ea5e9;text-decoration:underline}
#admin-container .admin-actions{display:flex;gap:.5rem;align-items:center}
#admin-container .modal{display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(2,6,23,0.45);z-index:999}
#admin-container .modal .modal-content{background:#fff;color:#0f1724;border-radius:14px;padding:18px;max-width:900px;width:calc(100% - 40px)}
#admin-container .form-input{padding:10px;border-radius:10px;border:1px solid #e2e8f0;width:100%;box-sizing:border-box;background:#fff;color:inherit}
@media(max-width:900px){#admin-container .card{padding:.9rem}}
</style>
</head>
<body>
<div id="admin-container" class="container">
  <div class="header"><h1>Panel Admin</h1><div><a class="nav" href="admin.php">Inicio</a> <a class="nav" href="admin.php?section=empresas">Empresas</a> <a class="nav" href="admin.php?section=proveedores">Proveedores</a> <a class="nav" href="admin.php?section=eventos">Eventos</a> <a class="nav" href="admin.php?section=documentos">Documentos</a> <a class="nav" href="admin.php?section=admins">Usuarios Admin</a> <a class="nav" href="admin.php?section=logs">Auditoría</a> <a class="nav" href="admin.php?section=settings">Configuración</a> <a class="nav" href="logout.php">Salir</a></div></div>

<?php
if (isset($_SESSION['flash'])) {
  $f = $_SESSION['flash'];
  $left = ($f['type'] === 'success') ? '#16a34a' : '#dc2626';
  echo '<div class="card" style="margin:1rem 0; padding:0.8rem; border-left:4px solid ' . $left . ';">' . htmlspecialchars($f['msg']) . '</div>';
  unset($_SESSION['flash']);
}
?>

<?php if($section==='home' || $section===''): ?>
  <div class="card"><h2>Estadísticas</h2>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem">
      <div class="card"><h4>Empresas activas</h4><div style="font-size:1.6rem;font-weight:700"><?php echo esc($stats['empresas_activas']); ?></div></div>
      <div class="card"><h4>Proveedores</h4><div style="font-size:1.6rem;font-weight:700"><?php echo esc($stats['proveedores']); ?></div></div>
      <div class="card"><h4>Empleados</h4><div style="font-size:1.6rem;font-weight:700"><?php echo esc($stats['empleados']); ?></div></div>
      <div class="card"><h4>Eventos próximos</h4><div style="font-size:1.6rem;font-weight:700"><?php echo esc($stats['eventos_proximos']); ?></div></div>
    </div>
  </div>
<?php elseif($section==='empresas'): ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <h2>Empresas</h2>
      <div><a href="#" class="nav" onclick="openCreateEmpresa(); return false;">Crear nueva</a></div>
    </div>
    <table class="table" style="margin-top:12px"><thead><tr><th style="width:80px">ID</th><th>Nombre</th><th>User</th><th>Email</th><th style="width:220px">Acciones</th></tr></thead><tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?php echo esc($r['id']); ?></td>
          <td><?php echo esc($r['nombre']); ?></td>
          <td><?php echo esc($r['user']); ?></td>
          <td><?php echo esc($r['email']); ?></td>
          <td class="admin-actions">
            <a href="#" class="action-link" onclick='openEditEmpresa(<?php echo json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT); ?>); return false;'>Editar</a>
            <form style="display:inline" method="POST" action="admin_actions.php" onsubmit="return confirm('¿Confirmas eliminar esta empresa? Esta acción es irreversible')">
              <input type="hidden" name="action" value="delete_empresa">
              <input type="hidden" name="id" value="<?php echo esc($r['id']); ?>">
              <button type="submit" style="margin-left:.5rem;padding:.45rem .7rem;border-radius:8px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;">Eliminar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?></tbody></table>

    <!-- Create Modal -->
    <div id="modalCreateEmpresa" class="modal" aria-hidden="true">
      <div class="modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><h3 style="margin:0">Crear Empresa</h3><button onclick="closeModals()" style="background:transparent;border:none;font-size:1.4rem;cursor:pointer">&times;</button></div>
        <form method="POST" action="admin_actions.php">
          <input type="hidden" name="action" value="create_empresa">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
            <div><label>Nombre</label><input class="form-input" name="nombre" required></div>
            <div><label>Usuario</label><input class="form-input" name="user" required></div>
            <div><label>Contraseña</label><input class="form-input" name="pass" type="password" required></div>
            <div><label>Email</label><input class="form-input" name="email" type="email"></div>
          </div>
          <div style="margin-top:12px;display:flex;gap:.5rem;justify-content:flex-end">
            <button type="button" onclick="closeModals()" class="btn-secondary">Cancelar</button>
            <button type="submit" class="btn-primary">Crear Empresa</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Modal -->
    <div id="modalEditEmpresa" class="modal" aria-hidden="true">
      <div class="modal-content">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><h3 style="margin:0">Editar Empresa</h3><button onclick="closeModals()" style="background:transparent;border:none;font-size:1.4rem;cursor:pointer">&times;</button></div>
        <form method="POST" action="admin_actions.php">
          <input type="hidden" name="action" value="edit_empresa">
          <input type="hidden" name="id" id="edit_empresa_id">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
            <div><label>Nombre</label><input class="form-input" id="edit_nombre" name="nombre" required></div>
            <div><label>Usuario</label><input class="form-input" id="edit_user" name="user" required></div>
            <div><label>Nueva Contraseña (opcional)</label><input class="form-input" id="edit_pass" name="pass" type="password"></div>
            <div><label>Email</label><input class="form-input" id="edit_email" name="email" type="email"></div>
          </div>
          <div style="margin-top:12px;display:flex;gap:.5rem;justify-content:flex-end">
            <button type="button" onclick="closeModals()" class="btn-secondary">Cancelar</button>
            <button type="submit" class="btn-primary">Guardar Cambios</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<?php elseif($section==='proveedores'): ?>
  <div class="card"><h2>Proveedores</h2>
    <table class="table"><thead><tr><th>ID</th><th>Nombre</th><th>Usuario</th><th>Email</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?>
      <tr><td><?php echo esc($r['id']); ?></td><td><?php echo esc($r['nombre']); ?></td><td><?php echo esc($r['usuario']); ?></td><td><?php echo esc($r['email']); ?></td>
      <td><!-- no state toggle --></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>

<?php elseif($section==='eventos'): ?>
  <div class="card"><h2>Eventos</h2>
    <table class="table"><thead><tr><th>ID</th><th>Nombre</th><th>Fecha</th><th>Empresa</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?>
      <tr><td><?php echo esc($r['id']); ?></td><td><?php echo esc($r['nombre']); ?></td><td><?php echo esc($r['fecha']); ?></td><td><?php echo esc($r['empresa']); ?></td><td><?php echo esc($r['estado']); ?></td>
      <td><a href="admin.php?section=evento_empleados&id=<?php echo esc($r['id']); ?>">Ver empleados</a></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>

<?php elseif($section==='evento_empleados'): ?>
  <div class="card"><h2>Empleados asignados al evento</h2>
    <table class="table"><thead><tr><th>ID</th><th>Nombre</th><th>CUIL</th><th>Puesto</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?>
      <tr><td><?php echo esc($r['id']); ?></td><td><?php echo esc($r['nombre'].' '.$r['apellido']); ?></td><td><?php echo esc($r['cuil'] ?? '-'); ?></td><td><?php echo esc($r['puesto'] ?? '-'); ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>

<?php elseif($section==='documentos'): ?>
  <div class="card"><h2>Documentos</h2>
  <table class="table"><thead><tr><th>ID</th><th>Archivo</th><th>Uploader</th><th>Evento</th><th>Estado</th><th>Comentario</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?>
      <tr><td><?php echo esc($r['id']); ?></td><td><a href="<?php echo esc($r['filepath']); ?>" target="_blank"><?php echo esc($r['filename']); ?></a></td><td><?php echo esc($r['uploader_type'].' #'.$r['uploader_id']); ?></td><td><?php echo esc($r['evento']); ?></td><td><?php echo esc($r['status']); ?></td><td><?php echo esc($r['comentario']); ?></td>
      <td>
        <form method="POST" action="admin_actions.php" style="display:inline"><input type="hidden" name="action" value="approve_document"><input type="hidden" name="id" value="<?php echo esc($r['id']); ?>"><input name="comentario" placeholder="Comentario (opcional)"><button type="submit">Aprobar</button></form>
        <form method="POST" action="admin_actions.php" style="display:inline"><input type="hidden" name="action" value="reject_document"><input type="hidden" name="id" value="<?php echo esc($r['id']); ?>"><input name="comentario" placeholder="Comentario (opcional)"><button type="submit">Rechazar</button></form>
      </td></tr>
    <?php endforeach; ?></tbody></table>
  </div>

<?php elseif($section==='admins'): ?>
  <div class="card"><h2>Usuarios Admin <a href="#" onclick="document.getElementById('crear-admin').style.display='block'">Crear nuevo</a></h2>
    <div id="crear-admin" style="display:none"><form method="POST" action="admin_actions.php"><input type="hidden" name="action" value="create_admin"><label>Usuario</label><input name="username" required><label>Contraseña</label><input name="password" type="password" required><label>Nombre</label><input name="nombre"><label>Apellido</label><input name="apellido"><label>Email</label><input name="email" type="email"><button type="submit">Crear</button></form></div>
  <table class="table"><thead><tr><th>ID</th><th>Usuario</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?>
      <tr><td><?php echo esc($r['id']); ?></td><td><?php echo esc($r['username']); ?></td><td><?php echo esc($r['nombre'].' '.$r['apellido']); ?></td><td><?php echo esc($r['email']); ?></td><td><?php echo esc($r['role']); ?></td>
      <td><a href="admin.php?section=admins&edit=<?php echo esc($r['id']); ?>">Editar</a>
      <form method="POST" style="display:inline" action="admin_actions.php"><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="id" value="<?php echo esc($r['id']); ?>"><button type="submit">Eliminar</button></form>
      </td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if(isset($_GET['edit'])){ $eid=intval($_GET['edit']); $s=$conn->prepare("SELECT id,username,nombre,apellido,email FROM users WHERE id=? LIMIT 1"); $s->bind_param('i',$eid); $s->execute(); $er=$s->get_result()->fetch_assoc(); ?>
      <div class="card"><h3>Editar Admin</h3><form method="POST" action="admin_actions.php"><input type="hidden" name="action" value="edit_admin"><input type="hidden" name="id" value="<?php echo esc($er['id']); ?>"><label>Nombre</label><input name="nombre" value="<?php echo esc($er['nombre']); ?>"><label>Apellido</label><input name="apellido" value="<?php echo esc($er['apellido']); ?>"><label>Email</label><input name="email" value="<?php echo esc($er['email']); ?>" type="email"><button type="submit">Guardar</button></form></div>
    <?php } ?>
  </div>

<?php elseif($section==='logs'): ?>
  <div class="card"><h2>Auditoría</h2><table class="table"><thead><tr><th>ID</th><th>User</th><th>Action</th><th>Details</th><th>IP</th><th>Date</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?>
      <tr>
        <td><?php echo esc($r['id']); ?></td>
        <td><?php echo esc($r['username']); ?></td>
        <td><?php echo esc($r['action']); ?></td>
        <td><?php echo esc($r['details']); ?></td>
        <td><?php echo esc($r['ip']); ?></td>
        <td><?php echo isset($r['created_at']) ? esc($r['created_at']) : '-'; ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>

<?php elseif($section==='settings'): ?>
  <div class="card"><h2>Configuración</h2>
    <form method="POST" action="admin_actions.php"><input type="hidden" name="action" value="change_password"><label>Contraseña actual</label><input name="current_password" type="password" required><label>Nueva contraseña</label><input name="new_password" type="password" required><button type="submit">Cambiar</button></form>
  </div>

<?php endif; ?>

<script>
function openCreateEmpresa(){
  document.getElementById('modalCreateEmpresa').style.display='flex';
}
function openEditEmpresa(data){
  try{
    var obj = (typeof data === 'string')? JSON.parse(data) : data;
    document.getElementById('edit_empresa_id').value = obj.id || '';
    document.getElementById('edit_nombre').value = obj.nombre || '';
    document.getElementById('edit_user').value = obj.user || obj.usuario || '';
    document.getElementById('edit_email').value = obj.email || '';
    document.getElementById('modalEditEmpresa').style.display='flex';
  }catch(e){ console.error('openEditEmpresa parse error', e); }
}
function closeModals(){
  var modals = document.querySelectorAll('#admin-container .modal');
  modals.forEach(function(m){ m.style.display='none'; });
}

// Close modals when clicking outside content
document.addEventListener('click', function(e){
  var mod = document.querySelector('#admin-container .modal[style*="display:flex"]');
  if(mod && e.target === mod) closeModals();
});

// Close modals with Escape
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModals(); });
</script>
</div></body></html>
