<?php
session_start();
include('dbconn.php');

// Simple access control
if (!isset($_SESSION['tipo_usuario']) || $_SESSION['tipo_usuario'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Fetch summary statistics
$stats = [];
$res = $conn->query("SELECT COUNT(*) AS total FROM empresas");
$row = $res->fetch_assoc(); $stats['empresas_activas'] = $row['total'] ?? 0;
$res = $conn->query("SELECT COUNT(*) AS total FROM proveedores");
$row = $res->fetch_assoc(); $stats['proveedores'] = $row['total'] ?? 0;
$res = $conn->query("SELECT COUNT(*) AS total FROM empleados");
$row = $res->fetch_assoc(); $stats['empleados'] = $row['total'] ?? 0;
$res = $conn->query("SELECT COUNT(*) AS total FROM eventos WHERE fecha >= NOW()");
$row = $res->fetch_assoc(); $stats['eventos_proximos'] = $row['total'] ?? 0;

// Basic page with navigation matching current visual style
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Admin - RADEP</title>
<link rel="stylesheet" href="estilos.css">
<style>
/* Layout */
.container { max-width: 1200px; margin: 2rem auto; padding: 1rem; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial; }
.header { display:flex; justify-content:space-between; align-items:center; gap:1rem; }
.header h1 { margin:0; color:#073b4c; }
.card { background:#fff; border-radius:12px; padding:1rem; box-shadow:0 8px 30px rgba(7,59,76,0.08); }
.grid { display:grid; grid-template-columns: repeat(4,1fr); gap:1rem; margin-top:1rem; }
@media(max-width:1000px){ .grid{grid-template-columns:repeat(2,1fr);} }
@media(max-width:600px){ .grid{grid-template-columns:repeat(1,1fr);} }

/* Table and text */
.table { width:100%; border-collapse:collapse; }
.table th, .table td{ padding:0.9rem 0.8rem; border-bottom:1px solid #f1f5f9; }
.table th{ background:#0b6b4f; color:#fff; text-align:left; font-weight:700; }
.table td{ color:#0f1724; }

/* Nav */
.nav { display:flex; gap:0.6rem; flex-wrap:wrap; }
.nav a{ padding:0.5rem 0.8rem; background:linear-gradient(135deg,#059669,#06b6d4); color:#fff; border-radius:8px; text-decoration:none; font-weight:600; }

/* Create company form */
.create-panel { margin-top:1rem; }
.create-toggle { background:#06b6d4; color:#fff; border:none; padding:0.6rem 1rem; border-radius:10px; cursor:pointer; font-weight:700; }
.create-form { margin-top:1rem; display:none; background:linear-gradient(180deg,#f8fafc,#ffffff); padding:1rem; border-radius:10px; border:1px solid #e6eef2; }
.create-form label{ display:block; font-weight:600; margin:0.4rem 0 0.2rem; }
.create-form input{ width:100%; padding:0.6rem 0.8rem; border-radius:8px; border:1px solid #cbd5e1; box-sizing:border-box; }
.form-row{ display:grid; grid-template-columns: repeat(3,1fr); gap:0.8rem; }
@media(max-width:800px){ .form-row{ grid-template-columns: repeat(1,1fr); } }
.submit-btn{ background:linear-gradient(135deg,#059669,#06b6d4); color:#fff; padding:0.7rem 1rem; border-radius:10px; border:none; cursor:pointer; font-weight:700; }

.small-note{ color:#334155; font-size:0.95rem; margin-top:0.6rem; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Panel Administrativo</h1>
    <div class="nav">
      <a href="admin.php">Inicio</a>
      <a href="admin.php?section=empresas">Empresas</a>
      <a href="admin.php?section=proveedores">Proveedores</a>
      <a href="admin.php?section=eventos">Eventos</a>
      <a href="admin.php?section=documentos">Documentos</a>
      <a href="admin.php?section=admins">Usuarios Admin</a>
      <a href="admin.php?section=logs">Auditoría</a>
      <a href="admin.php?section=settings">Configuración</a>
      <a href="logout.php">Salir</a>
    </div>
  </div>

  <!-- Create company quick panel -->
  <div class="create-panel">
    <button id="toggleCreate" class="create-toggle">+ Crear nueva empresa</button>
    <div id="createForm" class="create-form" aria-hidden="true">
      <form method="POST" action="admin_actions.php" onsubmit="return validateCreateEmpresa(this)">
        <input type="hidden" name="action" value="create_empresa">
        <div class="form-row">
          <div>
            <label for="nombre">Nombre de la empresa</label>
            <input id="nombre" name="nombre" type="text" required placeholder="Razón social o nombre visible">
          </div>
          <div>
            <label for="user">Usuario (login)</label>
            <input id="user" name="user" type="text" required placeholder="usuario_unico">
          </div>
          <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required placeholder="contacto@empresa.com">
          </div>
        </div>
        <div style="margin-top:0.8rem;">
          <label for="pass">Contraseña</label>
          <input id="pass" name="pass" type="password" required placeholder="Contraseña segura">
        </div>
        <div style="margin-top:0.9rem; display:flex; gap:0.8rem; align-items:center;">
          <button type="submit" class="submit-btn">Crear empresa</button>
          <div class="small-note">Se creará la empresa y su usuario. La contraseña quedará hasheada.</div>
        </div>
      </form>
    </div>
  </div>

  <div style="margin-top:1rem;" class="card">
    <h2>Estadísticas rápidas</h2>
    <div class="grid">
      <div class="card">
        <h3>Empresas activas</h3>
        <p style="font-size:1.6rem; font-weight:700;"><?php echo $stats['empresas_activas']; ?></p>
      </div>
      <div class="card">
        <h3>Proveedores registrados</h3>
        <p style="font-size:1.6rem; font-weight:700;"><?php echo $stats['proveedores']; ?></p>
      </div>
      <div class="card">
        <h3>Empleados habilitados</h3>
        <p style="font-size:1.6rem; font-weight:700;"><?php echo $stats['empleados']; ?></p>
      </div>
      <div class="card">
        <h3>Eventos próximos</h3>
        <p style="font-size:1.6rem; font-weight:700;"><?php echo $stats['eventos_proximos']; ?></p>
      </div>
    </div>
  </div>

  <div style="margin-top:1rem;" class="card">
    <h2>Accesos rápidos</h2>
    <div style="display:flex; gap:0.6rem; flex-wrap:wrap;">
      <a class="nav" href="admin_empresas.php">Gestionar Empresas</a>
      <a class="nav" href="admin_proveedores.php">Gestionar Proveedores</a>
      <a class="nav" href="admin_eventos.php">Gestionar Eventos</a>
      <a class="nav" href="admin_documentos.php">Gestionar Documentos</a>
      <a class="nav" href="admin_admins.php">Usuarios Admin</a>
      <a class="nav" href="admin_logs.php">Ver Auditoría</a>
    </div>
  </div>

  <script>
  // Toggle create form and basic validation
  ;(function(){
    console.log('dashboard_admin: init scripts');
    var toggle = document.getElementById('toggleCreate');
    var form = document.getElementById('createForm');
    if(toggle && form){
      toggle.addEventListener('click', function(e){
        e.preventDefault();
        var open = form.style.display !== 'block';
        form.style.display = open ? 'block' : 'none';
        form.setAttribute('aria-hidden', open ? 'false' : 'true');
        if(open){ document.getElementById('nombre').focus(); }
      });
    }
    window.validateCreateEmpresa = function(f){
      if(!f.nombre.value.trim()||!f.user.value.trim()||!f.pass.value.trim()){
        alert('Complete los campos requeridos');
        return false;
      }
      if(f.pass.value.length < 6){ alert('Use una contraseña de al menos 6 caracteres'); return false; }
      return true;
    };
  })();
  </script>
</body>
</html>
