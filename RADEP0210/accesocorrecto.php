<link rel="stylesheet" type="text/css" href="./estilos.css">
<?php
session_start();

if (!isset($_SESSION['usuario']) || !isset($_SESSION['rol'])) {
    echo "Acceso no autorizado.";
    exit();
}

$usuario = $_SESSION['usuario'];
$rol = strtolower(trim($_SESSION['rol']));  // Convierte a minúsculas y limpia espacios

// Validación case-insensitive para roles
if ($rol === 'proveedor') {
    header("Location: dashboard_proveedor.php");
    exit();
} elseif ($rol === 'empresa') {
    header("Location: dashboard_empresa.php");
    exit();
} elseif ($rol === 'empleado') {
    // El dashboard de empleado no existe: denegar acceso
    echo "<div style='padding:20px;background:#fef3c7;color:#92400e;font-weight:600;'>";
    echo "Acceso denegado para rol 'empleado'. Contacte al administrador para asistencia.";
    echo "<br/><a href='index.php' style='color:#92400e;text-decoration:underline;'>Volver al login</a>";
    echo "</div>";
    exit();
} elseif ($rol === 'admin' || $rol === 'administrator') {
    // Redirigir a panel de administración
    header("Location: dashboard_admin.php");
    exit();
} else {
    // Mensaje más informativo para debugging/administración
    echo "<div style='padding:20px;background:#0ea5e9;color:#012; font-weight:600;'>";
    echo "Rol no reconocido: '" . htmlspecialchars($rol) . "'<br/><br/>";
    echo "Roles válidos: 'proveedor', 'empresa', 'admin'";
    echo "<br/><br/>";
    echo "Para comprobar las roles en la base de datos ejecuta: <code>SELECT role, COUNT(*) as cnt FROM users GROUP BY role;</code>";
    echo "<br/><a href='index.php' style='color:#012;text-decoration:underline;'>Volver al login</a>";
    echo "</div>";
    exit();
}
//echo "BIENVENIDO a nuestra pagina "; // Display the logged-in username
//echo "<br><a href='logout.php'>Cerrar Sesión</a>"; // Add a logout link
?>