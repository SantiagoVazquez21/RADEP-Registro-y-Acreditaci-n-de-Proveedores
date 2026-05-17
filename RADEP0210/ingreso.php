<link rel="stylesheet" type="text/css" href="./estilos.css">
<?php
session_start();
include('dbconn.php'); // tu conexión orientada a objetos en $conn

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['Enviar'])) {
    $usuario = trim($_POST['usuario']);
    $password = $_POST['password'];
    $rol = trim($_POST['rol']); // Limpiar espacios del rol seleccionado

    // --- EMPRESA ---
    if ($rol === 'empresa') {
        $stmt = $conn->prepare("SELECT pass, rol, nombre, apellido FROM registronuevo WHERE user = ?");
        if (!$stmt) {
            die("Error en la preparación de la consulta: " . $conn->error);
        }
        $stmt->bind_param("s", $usuario);

        if (!$stmt->execute()) {
            die("Error en la ejecución de la consulta: " . $stmt->error);
        }

        $stmt->bind_result($pass_hash, $rol_db, $nombre, $apellido);

        if ($stmt->fetch()) {
            $rol_db = trim($rol_db);
            if ($rol_db !== $rol) {
                echo "El rol seleccionado no coincide con el usuario.";
                echo "<br><br>";
                echo "Rol seleccionado: '$rol'<br>";
                echo "Rol en base de datos: '$rol_db'<br>";
                echo "<br><a href='index.php'>Volver al login</a>";
                exit();
            }
            if (password_verify($password, $pass_hash)) {
                $_SESSION['usuario'] = $usuario;
                $_SESSION['usuario_id'] = $usuario;
                $_SESSION['rol'] = $rol_db;
                $_SESSION['tipo_usuario'] = 'empresa';
                $_SESSION['nombre'] = $nombre;
                $_SESSION['apellido'] = $apellido;
                header("Location: accesocorrecto.php");
                exit();
            } else {
                echo "Contraseña incorrecta.";
                echo "<br><a href='index.php'>Volver al login</a>";
                exit();
            }
        } else {
            echo "Usuario no encontrado.";
            echo "<br><a href='index.php'>Volver al login</a>";
            exit();
        }
        $stmt->close();
    }

    // --- EMPLEADO ---
    elseif ($rol === 'empleado') {
        $stmt = $conn->prepare("SELECT id, password, rol, nombre, apellido, email, estado FROM empleados WHERE usuario = ? AND rol = 'empleado'");
        if (!$stmt) {
            die("Error en la preparación de la consulta: " . $conn->error);
        }
        $stmt->bind_param("s", $usuario);

        if (!$stmt->execute()) {
            die("Error en la ejecución de la consulta: " . $stmt->error);
        }

        $stmt->bind_result($id, $pass_hash, $rol_db, $nombre, $apellido, $email, $estado);

        if ($stmt->fetch()) {
            if ($estado !== 'activo') {
                echo "Tu cuenta de empleado está inactiva. Contacta a tu administrador.";
                echo "<br><a href='index.php'>Volver al login</a>";
                exit();
            }
            if (password_verify($password, $pass_hash)) {
                $_SESSION['usuario'] = $usuario;
                $_SESSION['usuario_id'] = $id;
                $_SESSION['rol'] = $rol_db;
                $_SESSION['tipo_usuario'] = 'empleado';
                $_SESSION['nombre'] = $nombre;
                $_SESSION['apellido'] = $apellido;
                $_SESSION['email'] = $email;
                header("Location: accesocorrecto.php");
                exit();
            } else {
                echo "Contraseña incorrecta.";
                echo "<br><a href='index.php'>Volver al login</a>";
                exit();
            }
        } else {
            echo "Usuario de empleado no encontrado o no tienes permisos de empleado.";
            echo "<br><a href='index.php'>Volver al login</a>";
            exit();
        }
        $stmt->close();
    }

    // --- PROVEEDOR ---
    elseif ($rol === 'proveedor') {
        // Solo permitir login de proveedor si el usuario existe en la tabla proveedores
        // Pedimos también el nombre para guardarlo en sesión y ayudar al dashboard
        $stmt = $conn->prepare("SELECT id, usuario, password, nombre FROM proveedores WHERE usuario = ? AND estado = 'activo' LIMIT 1");
        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 1) {
            $stmt->bind_result($db_id, $db_usuario, $db_password, $db_nombre);
            $stmt->fetch();
            if (password_verify($password, $db_password)) {
                // Normalizar varias claves de sesión que otras partes del sistema pueden usar
                $_SESSION['usuario'] = $db_usuario;
                $_SESSION['usuario_id'] = $db_id;
                $_SESSION['proveedor_id'] = $db_id; // explícito para los dashboards de proveedor
                $_SESSION['id'] = $db_id; // fallback por compatibilidad
                $_SESSION['rol'] = 'proveedor';
                // Guardar nombre para mostrar en el header
                if (!empty($db_nombre)) $_SESSION['nombre'] = $db_nombre;
                header('Location: dashboard_proveedor.php');
                exit();
            } else {
                $error = "Contraseña incorrecta.";
            }
        } else {
            $error = "Usuario proveedor no encontrado o inactivo.";
        }
        $stmt->close();
        // Evitar que siga el login normal si es proveedor
        return;
    }

    $conn->close();
} else {
    echo "Acceso no autorizado.";
    echo "<br><a href='index.php'>Volver al login</a>";
    $conn->close();
}
?>
