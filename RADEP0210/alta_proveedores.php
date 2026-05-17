<?php

include("dbconn.php");

// --- VERIFICAR Y ACTUALIZAR ESTRUCTURA DE LA TABLA ---
$checkTable = $conn->query("SHOW TABLES LIKE 'proveedores'");
if ($checkTable->num_rows == 0) {
    // La tabla no existe, la creamos
    $conn->query("CREATE TABLE `proveedores` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `nombre` varchar(100) NOT NULL,
        `cuilcuit` varchar(20) NOT NULL,
        `email` varchar(100) NOT NULL,
        `usuario` varchar(50) NOT NULL,
        `password` varchar(255) NOT NULL,
        `estado` varchar(20) NOT NULL DEFAULT 'activo',
        `fechaalta` timestamp NOT NULL DEFAULT current_timestamp(),
        `foto` varchar(255) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `cuilcuit` (`cuilcuit`),
        UNIQUE KEY `usuario` (`usuario`),
        UNIQUE KEY `email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

// Verificar si la columna foto existe
$checkColumns = $conn->query("SHOW COLUMNS FROM `proveedores` LIKE 'foto'");
if ($checkColumns->num_rows == 0) {
    $conn->query("ALTER TABLE `proveedores` ADD `foto` varchar(255) DEFAULT NULL");
}

// Crear directorio para fotos si no existe
$uploadDir = 'uploads/proveedores/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// --- BORRAR PROVEEDOR ---
if (isset($_GET['borrar']) && is_numeric($_GET['borrar'])) {
    $id = intval($_GET['borrar']);
    // Eliminar primero los requisitos relacionados
    $stmt = $conn->prepare("DELETE FROM proveedor_requisito_evento WHERE proveedor_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    // Eliminar empleados relacionados
    $stmt = $conn->prepare("DELETE FROM empleados_proveedor WHERE proveedor_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    // Eliminar eventos relacionados
    $stmt = $conn->prepare("DELETE FROM proveedores_evento WHERE proveedor_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    // Eliminar foto
    $stmt = $conn->prepare("SELECT foto FROM proveedores WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if ($row['foto'] && file_exists($row['foto'])) {
            unlink($row['foto']);
        }
    }
    $stmt->close();
    // Eliminar proveedor
    $stmt = $conn->prepare("DELETE FROM proveedores WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- AGREGAR PROVEEDOR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_proveedor'])) {
    $nombre = trim($_POST['nombre']);
    $cuilcuit = trim($_POST['cuilcuit']);
    $email = trim($_POST['email']);
    $usuario = trim($_POST['usuario']);
    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    $foto = null;

    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['foto']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $newName = uniqid('prov_') . '.' . $ext;
            $uploadPath = $uploadDir . $newName;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $uploadPath)) {
                $foto = $uploadPath;
            }
        }
    }

    if ($nombre !== "" && $cuilcuit !== "" && $email !== "" && $usuario !== "" && $_POST['password'] !== "") {
        try {
                    $servicio = trim($_POST['servicio'] ?? '');
                    $stmt = $conn->prepare("INSERT INTO proveedores (nombre, cuilcuit, email, usuario, password, foto, servicio) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssssss", $nombre, $cuilcuit, $email, $usuario, $password, $foto, $servicio);
            if ($stmt->execute()) {
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            $error = "Error al guardar: ";
            if (strpos($e->getMessage(), 'cuilcuit') !== false) {
                $error .= "El CUIL/CUIT ya está registrado.";
            } else if (strpos($e->getMessage(), 'usuario') !== false) {
                $error .= "El nombre de usuario ya está en uso.";
            } else if (strpos($e->getMessage(), 'email') !== false) {
                $error .= "El email ya está registrado.";
            } else {
                $error .= $e->getMessage();
            }
        }
        $stmt->close();
    }
}

// --- EDITAR PROVEEDOR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_proveedor'])) {
    $id = intval($_POST['id_proveedor']);
    $nombre = trim($_POST['nombre']);
    $cuilcuit = trim($_POST['cuilcuit']);
    $email = trim($_POST['email']);
    $usuario = trim($_POST['usuario']);

    $servicio = trim($_POST['servicio'] ?? '');
    $sql = "UPDATE proveedores SET nombre=?, cuilcuit=?, email=?, usuario=?, servicio=?";
    $params = [$nombre, $cuilcuit, $email, $usuario, $servicio];
    $types = "sssss";

    if (!empty($_POST['password'])) {
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $sql .= ", password=?";
        $params[] = $password;
        $types .= "s";
    }

    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['foto']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $newName = uniqid('prov_') . '.' . $ext;
            $uploadPath = $uploadDir . $newName;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $uploadPath)) {
                $stmt = $conn->prepare("SELECT foto FROM proveedores WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    if ($row['foto'] && file_exists($row['foto'])) {
                        unlink($row['foto']);
                    }
                }
                $stmt->close();
                $sql .= ", foto=?";
                $params[] = $uploadPath;
                $types .= "s";
            }
        }
    }

    $sql .= " WHERE id=?";
    $params[] = $id;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $error = "Error al actualizar: " . $stmt->error;
    }
    $stmt->close();
}

// --- OBTENER PROVEEDORES ---
$proveedores = [];
$res = $conn->query("SELECT id, nombre, cuilcuit, email, usuario, fechaalta, estado, foto, servicio FROM proveedores ORDER BY fechaalta DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $proveedores[] = $row;
    }
    $res->free();
} else {
    echo "<div style='color:red;padding:10px;'>Error en la consulta de proveedores: " . $conn->error . "</div>";
}
// Obtener lista de servicios para el filtro (valores distintos)
$servicios = [];
$resS = $conn->query("SELECT DISTINCT servicio FROM proveedores WHERE servicio IS NOT NULL AND servicio <> '' ORDER BY servicio");
if ($resS) {
    while ($s = $resS->fetch_assoc()) $servicios[] = $s['servicio'];
    $resS->free();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>Gestión de Proveedores - RADEP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="code.css">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, #a9bec7 0%, #8ba3b0 50%, #6b8a9a 100%);
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        
        .proveedores-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 2rem 4rem 2rem;
            box-sizing: border-box;
        }

        .sidebar {
            background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
            border: 2px solid #5092ab;
            border-radius: 20px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            box-shadow: 0 15px 40px rgba(80, 146, 171, 0.2);
            height: fit-content;
            position: sticky;
            top: 2rem;
        }
        
        .main-content {
            flex: 1;
        }
        
        .header {
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            color: white;
            padding: 1.5rem 2rem;
            box-shadow: 0 8px 32px rgba(80, 146, 171, 0.3);
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
            border-radius: 20px;
            grid-column: 1 / -1;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s ease-in-out infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { transform: rotate(45deg) translate(-50%, -50%); }
            50% { transform: rotate(45deg) translate(50%, 50%); }
        }
        
        .header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            background: linear-gradient(135deg, #ffffff, #e2e8f0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: titleGlow 2s ease-in-out infinite;
        }
        
        @keyframes titleGlow {
            0%, 100% { filter: brightness(1); }
            50% { filter: brightness(1.2); }
        }
        
        .stats-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border: 2px solid #5092ab;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(80, 146, 171, 0.15);
            backdrop-filter: blur(10px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            padding: 1.5rem;
            animation: slideInUp 0.8s ease-out;
            animation-fill-mode: both;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        
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
        
        .stat-card::before {
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
        
        .stat-card:hover::before {
            transform: rotate(45deg) translate(50%, 50%);
        }
        
        .stat-card:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 15px 40px rgba(80, 146, 171, 0.3);
            border-color: #3b82f6;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            box-shadow: 0 8px 25px rgba(80, 146, 171, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: bounce 2s infinite;
            margin-bottom: 1rem;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
        
        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 12px 35px rgba(80, 146, 171, 0.4);
            animation: none;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: countUp 2s ease-out;
            margin-bottom: 0.5rem;
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
        
        .proveedores-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2.5rem;
            justify-items: center;
            margin-top: 2rem;
            width: 100%;
        }
        
        @media (max-width: 1200px) {
            .proveedores-grid {
                grid-template-columns: repeat(1, 1fr);
            }
        }
        
     .proveedor-card {
            background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
            border: 2px solid #5092ab;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(80, 146, 171, 0.2);
            backdrop-filter: blur(15px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideInUp 0.8s ease-out;
            animation-fill-mode: both;
            overflow: hidden;
            position: relative;
            width: 100%;
            max-width: 400px;
        }
        
        .proveedor-card:nth-child(1) { animation-delay: 0.1s; }
        .proveedor-card:nth-child(2) { animation-delay: 0.2s; }
        .proveedor-card:nth-child(3) { animation-delay: 0.3s; }
        .proveedor-card:nth-child(4) { animation-delay: 0.4s; }
        .proveedor-card:nth-child(5) { animation-delay: 0.5s; }
        .proveedor-card:nth-child(6) { animation-delay: 0.6s; }
        
        .proveedor-card::before {
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
        
        .proveedor-card:hover::before {
            transform: rotate(45deg) translate(50%, 50%);
        }
        
     .proveedor-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(80, 146, 171, 0.3);
            border-color: #3b82f6;
        }
        
        .proveedor-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(80, 146, 171, 0.2);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .proveedor-info {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .proveedor-foto {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #5092ab;
            box-shadow: 0 4px 15px rgba(80, 146, 171, 0.3);
            transition: all 0.3s ease;
        }
        
        .proveedor-card:hover .proveedor-foto {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(80, 146, 171, 0.4);
        }
        
        .proveedor-foto-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            border: 3px solid #5092ab;
            box-shadow: 0 4px 15px rgba(80, 146, 171, 0.3);
            transition: all 0.3s ease;
        }
        
        .proveedor-card:hover .proveedor-foto-placeholder {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(80, 146, 171, 0.4);
        }
        
        .proveedor-nombre {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .proveedor-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-edit, .btn-delete {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            color: white;
            box-shadow: 0 4px 15px rgba(80, 146, 171, 0.3);
        }
        
        .btn-edit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn-edit:hover::before {
            left: 100%;
        }
        
        .btn-edit:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 6px 20px rgba(80, 146, 171, 0.4);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }
        
        .btn-delete:hover {
            transform: scale(1.1) rotate(-5deg);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }
        
        .proveedor-details {
            padding: 1.5rem;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(80, 146, 171, 0.1);
            transition: all 0.3s ease;
        }
        
        .detail-row:hover {
            background: rgba(80, 146, 171, 0.05);
            border-radius: 8px;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 500;
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .detail-value {
            color: #1f2937;
            font-weight: 500;
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
        
        .btn-secondary {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
            font-weight: bold;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
       cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 25px rgba(107, 114, 128, 0.3);
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #4b5563, #374151);
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(107, 114, 128, 0.5);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
            border: 2px solid #5092ab;
            backdrop-filter: blur(20px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            margin: 2% auto;
            padding: 2rem;
            border-radius: 20px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideInDown 0.4s ease-out;
            position: relative;
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
            z-index: 10;
        }
        
        .modal-close:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(80, 146, 171, 0.2);
        }
        
        .modal-header i {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            box-shadow: 0 4px 15px rgba(80, 146, 171, 0.3);
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .form-section {
            margin-bottom: 2rem;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.8), rgba(248, 250, 252, 0.8));
            border: 1px solid rgba(80, 146, 171, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        
        .form-section:hover {
            border-color: #5092ab;
            box-shadow: 0 8px 25px rgba(80, 146, 171, 0.15);
        }
        
        .form-section-title {
            color: #5092ab;
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-section-title::before {
            content: '';
            width: 4px;
            height: 20px;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            border-radius: 2px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        
        .form-full {
            grid-column: 1 / -1;
        }
        
        .photo-upload {
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .photo-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border: 3px solid #5092ab;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(80, 146, 171, 0.2);
            transition: all 0.3s ease;
        }
        
        .photo-preview:hover {
            transform: scale(1.05);
            box-shadow: 0 12px 35px rgba(80, 146, 171, 0.3);
        }
        
        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .photo-upload label {
            color: #5092ab;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border: 2px solid #5092ab;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .photo-upload label:hover {
            background: #5092ab;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(80, 146, 171, 0.3);
        }
        
        .hidden {
            display: none !important;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #1f2937;
        }
        
        .form-input {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border: 2px solid #5092ab;
            border-radius: 12px;
            padding: 12px 16px;
            color: #374151;
            -webkit-text-fill-color: #374151; /* ensures text is visible in WebKit-based browsers (Chrome) */
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
            width: 100%;
            box-sizing: border-box;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1), inset 0 2px 4px rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
        }
        
        .form-select {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border: 2px solid #5092ab;
            border-radius: 12px;
            padding: 12px 16px;
            color: #374151;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
            width: 100%;
            box-sizing: border-box;
        }
        
        .form-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1), inset 0 2px 4px rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
        }

        /* Ensure placeholder and autofill text remain visible */
        .form-input::placeholder { color: rgba(55,65,81,0.45); }
        .form-input:focus { color: #0f172a; -webkit-text-fill-color: #0f172a; }

        /* Modal-specific inputs slightly darker for contrast */
        .modal .form-input { background: rgba(255,255,255,0.98); color: #0f172a; -webkit-text-fill-color: #0f172a; }

        /* WebKit autofill fix: keep text color and remove yellow background override */
        input:-webkit-autofill,
        textarea:-webkit-autofill,
        select:-webkit-autofill {
            -webkit-text-fill-color: #374151 !important;
            -webkit-box-shadow: 0 0 0px 1000px rgba(255,255,255,0.98) inset !important;
            box-shadow: 0 0 0px 1000px rgba(255,255,255,0.98) inset !important;
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid rgba(80, 146, 171, 0.2);
        }
        
        .form-actions .btn-primary {
            flex: 1;
        }
        
        .empty-state {
            background: linear-gradient(145deg, #d9e3e6, #c8d4d8);
            border-radius: 24px;
            border: 2px solid #5092ab;
            backdrop-filter: blur(10px);
            box-shadow: 0 15px 40px rgba(80, 146, 171, 0.2);
            animation: fadeInUp 0.8s ease-out;
            text-align: center;
            padding: 3rem 2rem;
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
        
        .nuevo-proveedor-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(80, 146, 171, 0.4);
            padding: 1.2rem 2.5rem 1.2rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            z-index: 100;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1.3rem;
            animation: pulse 2s infinite;
        }
        .nuevo-proveedor-btn:hover {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            transform: translateY(-4px) scale(1.05);
            box-shadow: 0 15px 50px rgba(80, 146, 171, 0.6);
            animation: none;
        }
        .nuevo-proveedor-btn i {
            font-size: 1.5rem;
        }
        @media (max-width: 900px) {
            .proveedores-container {
                padding: 1rem;
            }
            .proveedores-grid {
                gap: 1.5rem;
                grid-template-columns: 1fr;
            }
            .proveedor-card {
                max-width: 100%;
            }
            .nuevo-proveedor-btn {
                padding: 1rem 1.5rem;
                font-size: 1rem;
                bottom: 1rem;
                right: 1rem;
            }
        }
        @media (max-width: 768px) {
            .proveedor-container {
                padding: 1rem;
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .sidebar {
                position: static;
                order: 2;
            }
            
            .main-content {
                order: 1;
            }
            
            .proveedores-grid {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .nuevo-proveedor-btn {
                bottom: 1rem;
                right: 1rem;
                width: auto;
                height: auto;
                font-size: 1rem;
            }
            
            .stat-card:hover {
                transform: translateY(-3px) scale(1.02);
            }
            
            .proveedor-card:hover {
                transform: translateY(-5px) scale(1.01);
            }
            
            .modal-content {
                margin: 1rem;
                padding: 1.5rem;
                max-width: calc(100% - 2rem);
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .modal-header {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
            .volver-dashboard-btn {
                box-shadow: 0 8px 25px rgba(80, 146, 171, 0.3);
                padding: 12px 24px;
                border-radius: 12px;
                font-size: 1rem;
                font-weight: bold;
                background: linear-gradient(135deg, #5092ab, #3b82f6);
                color: white;
                border: none;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .volver-dashboard-btn:hover {
                background: linear-gradient(135deg, #3b82f6, #1d4ed8);
                transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(80, 146, 171, 0.5);
            }
     }
    </style>
</head>
<body>
<div class="proveedores-container">
    <a href="dashboard_empresa.php"
        class="btn-primary volver-dashboard-btn"
        style="position: fixed; top: 2rem; left: 2rem; text-decoration:none; display:inline-flex; align-items:center; gap:0.5rem; z-index:1001;">
        <i class="fas fa-arrow-left"></i>
        Volver al Dashboard
    </a>

    <!-- Título centrado fuera de header-content -->
    <div style="width: 100%; text-align: center; margin-top: 6rem; margin-bottom: 2rem;">
        <h1 class="header-title" style="font-size:2.5rem; font-weight:800; color:white; display:inline-block;">
            <i class="fas fa-users"></i>
            Gestión de Proveedores
        </h1>
    </div>
    <?php if (count($proveedores) > 0): ?>
    <!-- Filter bar is not sticky here so it scrolls away with the page -->
    <div id="prov-filter-bar" style="width:100%; text-align:center; margin-bottom:1rem;">
        <div style="display:inline-flex; gap:0.75rem; align-items:center; width:min(980px,100%); background: linear-gradient(145deg, rgba(217,227,230,0.9), rgba(200,212,216,0.9)); padding:10px 12px; border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,0.06);">
            <input id="prov-search" type="search" placeholder="Buscar por nombre, email o servicio" class="form-input" style="flex:1; min-width:320px;" />
            <select id="prov-filter" class="form-select" style="width:160px;">
                <option value="">Todos</option>
                <?php foreach($servicios as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="prov-clear" class="btn-secondary" style="padding:8px 10px; font-size:0.95rem;">Limpiar</button>
        </div>
        <div style="margin-top:6px; color:#074b57; font-size:0.95rem;">Filtra proveedores por servicio o busca por nombre/email</div>
        <div id="prov-noresults" style="display:none; margin-top:10px; color:#b91c1c; font-weight:600;">No se encontraron proveedores que coincidan con la búsqueda.</div>
    </div>
    <?php endif; ?>

    <div class="header-content" style="position: relative;">
        <?php if (isset($error)): ?>
            <div class="error-msg">
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>
        <?php if (count($proveedores) > 0): ?>
            <div class="proveedores-grid">
                <?php foreach($proveedores as $proveedor): ?>
                    <div class="proveedor-card" data-nombre="<?= htmlspecialchars($proveedor['nombre']) ?>" data-email="<?= htmlspecialchars($proveedor['email']) ?>" data-servicio="<?= htmlspecialchars($proveedor['servicio'] ?? '') ?>">
                        <div class="proveedor-header">
                            <div class="proveedor-info">
                                <?php if ($proveedor['foto']): ?>
                                    <img src="<?= htmlspecialchars($proveedor['foto']) ?>" alt="Foto de <?= htmlspecialchars($proveedor['nombre']) ?>" class="proveedor-foto"/>
                                <?php else: ?>
                                    <div class="proveedor-foto-placeholder">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <h3 class="proveedor-nombre"><?= htmlspecialchars($proveedor['nombre']) ?></h3>
                            </div>
                            <div class="proveedor-actions">
                                <button class="btn-edit" onclick="editarProveedor(<?= htmlspecialchars(json_encode($proveedor)) ?>)" title="Editar proveedor">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="?borrar=<?= $proveedor['id'] ?>" onclick="return confirm('¿Seguro que deseas borrar este proveedor?');" class="btn-delete" title="Borrar proveedor">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                            </div>
                        </div>
                        <div class="proveedor-details">
                            <div class="detail-row">
                                <span class="detail-label">CUIT:</span>
                                <span class="detail-value"><?= substr(preg_replace('/[^0-9]/', '', $proveedor['cuilcuit']), -11) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Servicio:</span>
                                <span class="detail-value"><?= htmlspecialchars($proveedor['servicio'] ?? '-') ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Email:</span>
                                <span class="detail-value"><?= htmlspecialchars($proveedor['email']) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Usuario:</span>
                                <span class="detail-value"><?= htmlspecialchars($proveedor['usuario']) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Fecha Alta:</span>
                                <span class="detail-value"><?= date('d/m/Y H:i', strtotime($proveedor['fechaalta'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h3>No hay proveedores registrados</h3>
                <p>Comienza agregando tu primer proveedor</p>
                <button onclick="document.getElementById('modalProveedor').style.display='flex'" class="btn-primary">
                    Agregar Proveedor
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Botón flotante para agregar proveedor -->
<button class="nuevo-proveedor-btn" onclick="document.getElementById('modalProveedor').style.display='flex'" title="Agregar nuevo proveedor">
    <i class="fas fa-plus"></i>
    Agregar Proveedor
</button>

<!-- Modal para agregar proveedor -->
<div id="modalProveedor" class="modal">
    <div class="modal-content">
        <button onclick="document.getElementById('modalProveedor').style.display='none'" class="modal-close">&times;</button>
        <div class="modal-header">
            <i class="fas fa-user-plus"></i>
            <h2>Nuevo Proveedor</h2>
        </div>
        <form method="post" enctype="multipart/form-data">
            <div class="form-section">
                <div class="form-section-title">Foto de Perfil</div>
                <div class="photo-upload">
                    <div id="preview_new" class="photo-preview">
                        <i class="fas fa-user" style="font-size: 2rem; color: #5092ab;"></i>
                    </div>
                    <label>
                        <span>Subir foto</span>
                        <input type="file" name="foto" accept="image/*" class="hidden" onchange="previewImage(this, 'preview_new')"/>
                    </label>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Información de la Empresa</div>
                <div class="form-group">
                    <label class="form-label">Nombre de la Empresa</label>
                    <input type="text" name="nombre" required placeholder="Ingrese el nombre de la empresa" class="form-input"/>
                </div>
                <div class="form-group">
                    <label class="form-label">CUIT</label>
                    <input type="text" name="cuilcuit" required pattern="[0-9-]{11,13}" placeholder="Ej: 20123456789" class="form-input"/>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Información de Contacto</div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" required placeholder="ejemplo@empresa.com" class="form-input"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Servicio / Tipo de servicio</label>
                    <input type="text" name="servicio" placeholder="Ej: Seguridad, Catering, Limpieza" class="form-input" />
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Credenciales de Acceso</div>
                <div class="form-group">
                    <label class="form-label">Usuario</label>
                    <input type="text" name="usuario" required placeholder="Nombre de usuario" class="form-input"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Contraseña</label>
                    <input type="password" name="password" required placeholder="Contraseña segura" class="form-input"/>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" name="nuevo_proveedor" class="btn-primary">
                    <i class="fas fa-save"></i>Guardar Proveedor
                </button>
                <button type="button" onclick="document.getElementById('modalProveedor').style.display='none'" class="btn-secondary">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para editar proveedor -->
<div id="modalEditar" class="modal">
    <div class="modal-content">
        <button onclick="document.getElementById('modalEditar').style.display='none'" class="modal-close">&times;</button>
        <div class="modal-header">
            <i class="fas fa-user-edit"></i>
            <h2>Editar Proveedor</h2>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="id_proveedor" id="edit_id"/>
            <!-- ...campos igual, sin apellido... -->
            <div class="form-section">
                <div class="form-section-title">Foto de Perfil</div>
                <div class="photo-upload">
                    <div id="preview_edit" class="photo-preview">
                        <i class="fas fa-user" style="font-size: 2rem; color: #5092ab;"></i>
                    </div>
                    <label>
                        <span>Cambiar foto</span>
                        <input type="file" name="foto" accept="image/*" class="hidden" onchange="previewImage(this, 'preview_edit')"/>
                    </label>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Información Personal</div>
                <div class="form-group">
                    <label class="form-label">Nombre</label>
                    <input type="text" name="nombre" id="edit_nombre" required placeholder="Ingrese el nombre" class="form-input"/>
                </div>
                <div class="form-group">
                    <label class="form-label">CUIT</label>
                    <input type="text" name="cuilcuit" id="edit_cuilcuit" required pattern="[0-9-]{11,13}" placeholder="Ej: 20123456789" class="form-input"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Servicio / Tipo de servicio</label>
                    <input type="text" name="servicio" id="edit_servicio" placeholder="Ej: Seguridad, Catering, Limpieza" class="form-input" />
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Información de Contacto</div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="edit_email" required placeholder="ejemplo@empresa.com" class="form-input"/>
                </div>
            </div>
            <div class="form-section">
                <div class="form-section-title">Credenciales de Acceso</div>
                <div class="form-group">
                    <label class="form-label">Usuario</label>
                    <input type="text" name="usuario" id="edit_usuario" required placeholder="Nombre de usuario" class="form-input"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Nueva Contraseña (dejar en blanco para mantener la actual)</label>
                    <input type="password" name="password" placeholder="Nueva contraseña (opcional)" class="form-input"/>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" name="editar_proveedor" class="btn-primary">
                    <i class="fas fa-save"></i>Guardar Cambios
                </button>
                <button type="button" onclick="document.getElementById('modalEditar').style.display='none'" class="btn-secondary">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>


<script>
    // Función para previsualizar la imagen (modificada para soportar múltiples previews)
    function previewImage(input, previewId) {
        const preview = document.getElementById(previewId);
        const file = input.files[0];
        
        if (file) {
            // Validar tipo de archivo
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!allowedTypes.includes(file.type)) {
                alert('Por favor selecciona una imagen válida (JPG, PNG, GIF)');
                input.value = '';
                return;
            }
            
            // Validar tamaño (máximo 5MB)
            const maxSize = 5 * 1024 * 1024; // 5MB
            if (file.size > maxSize) {
                alert('La imagen es demasiado grande. Máximo 5MB permitido.');
                input.value = '';
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = `<img src="${e.target.result}" style="width: 100%; height: 100%; object-fit: cover;"/>`;
            }
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = '<i class="fas fa-user" style="font-size: 2rem; color: #38BDF8;"></i>';
        }
    }

    // Función para cargar los datos del proveedor en el modal de edición
   function editarProveedor(proveedor) {
    document.getElementById('edit_id').value = proveedor.id;
    document.getElementById('edit_nombre').value = proveedor.nombre;
    document.getElementById('edit_cuilcuit').value = proveedor.cuilcuit;
    document.getElementById('edit_email').value = proveedor.email;
    document.getElementById('edit_usuario').value = proveedor.usuario;
    document.getElementById('edit_servicio').value = proveedor.servicio || '';

    const preview = document.getElementById('preview_edit');
    if (proveedor.foto) {
        preview.innerHTML = `<img src="${proveedor.foto}" style="width: 100%; height: 100%; object-fit: cover;"/>`;
    } else {
        preview.innerHTML = '<i class="fas fa-user" style="font-size: 2rem; color: #38BDF8;\"></i>';
    }

    document.getElementById('modalEditar').style.display = 'flex';
}

    // Función para validar formularios
    function validateForm(form) {
        const inputs = form.querySelectorAll('input[required], select[required]');
        let isValid = true;
        
        inputs.forEach(input => {
            if (!input.value.trim()) {
                input.style.borderColor = '#EF4444';
                input.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                isValid = false;
            } else {
                input.style.borderColor = '';
                input.style.boxShadow = '';
            }
        });
        
        // Validar email
        const emailInput = form.querySelector('input[type="email"]');
        if (emailInput && emailInput.value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailInput.value)) {
                emailInput.style.borderColor = '#EF4444';
                emailInput.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                isValid = false;
            }
        }
        
        // Validar CUIL/CUIT
        const cuilInput = form.querySelector('input[name="cuilcuit"]');
        if (cuilInput && cuilInput.value) {
            const cuilRegex = /^\d{2}-\d{8}-\d{1}$|^\d{11}$/;
            if (!cuilRegex.test(cuilInput.value)) {
                cuilInput.style.borderColor = '#EF4444';
                cuilInput.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                isValid = false;
            }
        }
        
        return isValid;
    }

    // Función para mostrar notificaciones
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            color: #E0F2FE;
            font-weight: 600;
            z-index: 10000;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(56, 189, 248, 0.3);
            transform: translateX(100%);
            transition: transform 0.3s ease;
        `;
        
        if (type === 'success') {
            notification.style.background = 'rgba(34, 197, 94, 0.9)';
        } else if (type === 'error') {
            notification.style.background = 'rgba(239, 68, 68, 0.9)';
        } else {
            notification.style.background = 'rgba(56, 189, 248, 0.9)';
        }
        
        notification.textContent = message;
        document.body.appendChild(notification);
        
        // Animar entrada
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);
        
        // Remover después de 3 segundos
        setTimeout(() => {
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => {
                document.body.removeChild(notification);
            }, 300);
        }, 3000);
    }

    // Función para limpiar formularios
    function clearForm(formId) {
        const form = document.getElementById(formId);
        if (form) {
            form.reset();
            const preview = form.querySelector('.photo-preview');
            if (preview) {
                preview.innerHTML = '<i class="fas fa-user" style="font-size: 2rem; color: #38BDF8;"></i>';
            }
        }
    }

    // Event listeners para formularios
    document.addEventListener('DOMContentLoaded', function() {
        // Validar formularios antes de enviar
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!validateForm(this)) {
                    e.preventDefault();
                    showNotification('Por favor completa todos los campos requeridos correctamente', 'error');
                }
            });
        });
        
        // Limpiar formulario al abrir modal de nuevo Proveedor
        const modalProveedor = document.getElementById('modalProveedor');
        modalProveedor.addEventListener('show', function() {
            clearForm('modalProveedor');
        });
        
        // Mejorar experiencia de inputs
        const inputs = document.querySelectorAll('input, select');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
    });

    // Cerrar modal al hacer clic fuera
    document.getElementById('modalProveedor').addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
    
    document.getElementById('modalEditar').addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
    
    // Cerrar modal con Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.getElementById('modalProveedor').style.display = 'none';
            document.getElementById('modalEditar').style.display = 'none';
        }
    });
    
    // Confirmar eliminación con mejor UX
    document.addEventListener('click', function(e) {
        if (e.target.closest('a[href*="borrar="]')) {
            const link = e.target.closest('a[href*="borrar="]');
            const ProveedorName = link.closest('.proveedor-card').querySelector('.proveedor-nombre').textContent;
                // Eliminada la doble confirmación para borrar proveedor
        }
    });
    
    // Animación de entrada para las tarjetas
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);
    
    // Observar tarjetas de Proveedores
    // Observer + filtro cliente
    var provCards = Array.from(document.querySelectorAll('.proveedor-card'));
    provCards.forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(card);
    });

    // Filtrado cliente: búsqueda por nombre/email/servicio + filtro por servicio
    var provSearch = document.getElementById('prov-search');
    var provFilter = document.getElementById('prov-filter');
    var provClear = document.getElementById('prov-clear');
    function applyProvFilter() {
        var q = (provSearch && provSearch.value || '').trim().toLowerCase();
        var cat = (provFilter && provFilter.value) || '';
        var visible = 0;
        provCards.forEach(function(c) {
            var n = (c.dataset.nombre||'').toLowerCase();
            var e = (c.dataset.email||'').toLowerCase();
            var s = (c.dataset.servicio||'').toLowerCase();
            var matchText = q === '' || n.indexOf(q) !== -1 || e.indexOf(q) !== -1 || s.indexOf(q) !== -1;
            var matchCat = !cat || (c.dataset.servicio || '') === cat;
            var show = (matchText && matchCat);
            c.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        // Mostrar mensaje si no hay resultados
        var nores = document.getElementById('prov-noresults');
        if (nores) nores.style.display = (visible === 0) ? 'block' : 'none';
    }

    // Debounce helper
    function debounce(fn, wait) {
        var t = null;
        return function() { clearTimeout(t); var args = arguments; t = setTimeout(function(){ fn.apply(null, args); }, wait); }
    }

    if (provSearch) provSearch.addEventListener('input', debounce(applyProvFilter, 180));
    if (provFilter) provFilter.addEventListener('change', applyProvFilter);
    if (provClear) provClear.addEventListener('click', function(){ if (provSearch) provSearch.value=''; if (provFilter) provFilter.value=''; applyProvFilter(); window.scrollTo({ top: document.querySelector('.header-title').offsetTop - 20, behavior: 'smooth' }); });
</script>
</body>
</html>