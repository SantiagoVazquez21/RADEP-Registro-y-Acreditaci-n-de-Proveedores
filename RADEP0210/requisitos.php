<?php
session_start();
require_once 'dbconn.php';

// Migración: agregar columna 'aplica_a' en la tabla `requisitos` si no existe
$check = $conn->query("SHOW COLUMNS FROM requisitos LIKE 'aplica_a'");
if ($check && $check->num_rows == 0) {
    $conn->query("ALTER TABLE requisitos ADD aplica_a VARCHAR(20) NOT NULL DEFAULT 'ambos'");
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario'])) {
    header('Location: ingreso.php');
    exit();
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['nuevo_requisito'])) {
        $nombre = trim($_POST['nombre']);
        $aplica_a = $_POST['aplica_a'] ?? 'ambos';
        if (!in_array($aplica_a, ['dependencia','monotributista','ambos'])) $aplica_a = 'ambos';
        if (!empty($nombre)) {
            $stmt = $conn->prepare("INSERT INTO requisitos (nombre, aplica_a) VALUES (?, ?)");
            $stmt->bind_param("ss", $nombre, $aplica_a);
            if ($stmt->execute()) {
                $mensaje = "Requisito agregado exitosamente";
                $tipo_mensaje = "success";
            } else {
                $error = "Error al agregar el requisito";
            }
        } else {
            $error = "El nombre del requisito es obligatorio";
        }
    }
    
    if (isset($_POST['editar_requisito'])) {
        $id = $_POST['id_requisito'];
        $nombre = trim($_POST['nombre']);
        $aplica_a = $_POST['aplica_a'] ?? 'ambos';
        if (!in_array($aplica_a, ['dependencia','monotributista','ambos'])) $aplica_a = 'ambos';
        if (!empty($nombre)) {
            $stmt = $conn->prepare("UPDATE requisitos SET nombre = ?, aplica_a = ? WHERE id = ?");
            $stmt->bind_param("ssi", $nombre, $aplica_a, $id);
            if ($stmt->execute()) {
                $mensaje = "Requisito actualizado exitosamente";
                $tipo_mensaje = "success";
            } else {
                $error = "Error al actualizar el requisito";
            }
        } else {
            $error = "El nombre del requisito es obligatorio";
        }
    }
}

// Procesar eliminación
if (isset($_GET['borrar'])) {
    $id = $_GET['borrar'];
    $stmt = $conn->prepare("DELETE FROM requisitos WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $mensaje = "Requisito eliminado exitosamente";
        $tipo_mensaje = "success";
    } else {
        $error = "Error al eliminar el requisito";
    }
}

// Obtener todos los requisitos (incluye aplica_a)
$requisitos = [];
$result = $conn->query("SELECT id, nombre, aplica_a FROM requisitos ORDER BY nombre");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $requisitos[] = $row;
    }
}

// Obtener estadísticas
$total_requisitos = count($requisitos);
$requisitos_activos = $total_requisitos; // Por ahora todos están activos
$requisitos_utilizados = $conn->query("SELECT COUNT(DISTINCT requisito_id) as total FROM evento_requisito")->fetch_assoc()['total'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>Gestión de Requisitos - RADEP</title>
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
        
        .requisitos-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 1rem;
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            min-height: 100vh;
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
        
        .back-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
        }
        
        .back-link:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
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
        
        .requisitos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        
        .requisito-card {
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
        }
        
        .requisito-card:nth-child(1) { animation-delay: 0.1s; }
        .requisito-card:nth-child(2) { animation-delay: 0.2s; }
        .requisito-card:nth-child(3) { animation-delay: 0.3s; }
        .requisito-card:nth-child(4) { animation-delay: 0.4s; }
        .requisito-card:nth-child(5) { animation-delay: 0.5s; }
        .requisito-card:nth-child(6) { animation-delay: 0.6s; }
        
        .requisito-card::before {
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
        
        .requisito-card:hover::before {
            transform: rotate(45deg) translate(50%, 50%);
        }
        
        .requisito-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(80, 146, 171, 0.3);
            border-color: #3b82f6;
        }
        
        .requisito-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(80, 146, 171, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .requisito-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .requisito-icon {
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
            transition: all 0.3s ease;
        }
        
        .requisito-card:hover .requisito-icon {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(80, 146, 171, 0.4);
        }
        
        .requisito-nombre {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .requisito-actions {
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
        
        .requisito-details {
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
            align-items: center;
            justify-content: center;
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
            padding: 2rem;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            animation: slideInDown 0.4s ease-out;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
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
        
        .modal-close:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.3);
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
        
        .floating-add-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #5092ab, #3b82f6);
            border: none;
            color: white;
            font-size: 1.8rem;
            cursor: pointer;
            box-shadow: 0 10px 40px rgba(80, 146, 171, 0.4);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
        
        .floating-add-btn:hover {
            transform: translateY(-8px) scale(1.1);
            box-shadow: 0 15px 50px rgba(80, 146, 171, 0.6);
            animation: none;
        }
        
        .floating-add-btn:active {
            transform: translateY(-4px) scale(1.05);
        }
        
        .floating-add-btn::before {
            content: 'Agregar Requisito';
            position: absolute;
            right: 80px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            white-space: nowrap;
            opacity: 0;
            transform: translateX(10px);
            transition: all 0.3s ease;
            pointer-events: none;
        }
        
        .floating-add-btn:hover::before {
            opacity: 1;
            transform: translateX(0);
        }
        
        .mensaje {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        .mensaje.success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        .mensaje.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }
        
        @media (max-width: 768px) {
            .requisitos-container {
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
            
            .requisitos-grid {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .floating-add-btn {
                bottom: 1rem;
                right: 1rem;
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .stat-card:hover {
                transform: translateY(-3px) scale(1.02);
            }
            
            .requisito-card:hover {
                transform: translateY(-5px) scale(1.01);
            }
            
            .modal-content {
                margin: 1rem;
                padding: 1.5rem;
                max-width: calc(100% - 2rem);
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .modal-header {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="requisitos-container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1 class="header-title">
                    <i class="fas fa-clipboard-list"></i>
                    Gestión de Requisitos
                </h1>
                <a href="dashboard_empresa.php" class="back-link">
                    <i class="fas fa-arrow-left"></i>
                    Volver al Dashboard
                </a>
            </div>
        </div>

        <!-- Sidebar con estadísticas -->
        <div class="sidebar">
            <h3 style="margin-bottom: 1.5rem; color: #5092ab; font-size: 1.2rem; font-weight: 600;">Estadísticas</h3>
            
            <!-- Estadísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-info">
                        <h3 class="stat-number"><?= $total_requisitos ?></h3>
                        <p style="margin: 0; color: #6b7280; font-size: 0.9rem;">Total Requisitos</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3 class="stat-number"><?= $requisitos_activos ?></h3>
                        <p style="margin: 0; color: #6b7280; font-size: 0.9rem;">Requisitos Activos</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-link"></i>
                    </div>
                    <div class="stat-info">
                        <h3 class="stat-number"><?= $requisitos_utilizados ?></h3>
                        <p style="margin: 0; color: #6b7280; font-size: 0.9rem;">En Eventos</p>
                    </div>
                </div>
            </div>

            <!-- Botón de agregar requisito en sidebar -->
            <button onclick="abrirModalNuevo()" 
                    class="btn-primary" style="width: 100%; margin-top: 1rem;">
                <i class="fas fa-plus"></i>
                Nuevo Requisito
            </button>
        </div>

        <!-- Contenido principal -->
        <div class="main-content">
            <!-- Listado de requisitos -->
            <div class="header-actions" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <h2 class="header-title" style="font-size: 1.5rem; margin: 0;">Requisitos Registrados</h2>
            </div>
            
            <?php if (isset($mensaje)): ?>
            <div class="mensaje <?= $tipo_mensaje ?>">
                <?= htmlspecialchars($mensaje) ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
            <div class="mensaje error">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <?php if (count($requisitos) > 0): ?>
            <div class="requisitos-grid">
                <?php foreach($requisitos as $requisito): ?>
                <div class="requisito-card">
                    <div class="requisito-header">
                        <div class="requisito-info">
                            <div class="requisito-icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                                                        <h3 class="requisito-nombre">
                                                                <?= htmlspecialchars($requisito['nombre']) ?>
                                                                <?php if (!empty($requisito['aplica_a'])): ?>
                                                                    <small style="font-size:0.75rem; margin-left:8px; color:#000000; background:#eef2f7; padding:2px 6px; border-radius:6px; font-weight:600;"><?= $requisito['aplica_a'] === 'ambos' ? 'Ambos' : ($requisito['aplica_a'] === 'dependencia' ? 'Dependencia' : 'Monotributista') ?></small>
                                                                <?php endif; ?>
                                                        </h3>
                        </div>
                        <div class="requisito-actions">
                            <button class="btn-edit" 
                                    onclick="editarRequisito(<?= htmlspecialchars(json_encode($requisito)) ?>)" 
                                    title="Editar requisito">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="?borrar=<?= $requisito['id'] ?>" 
                               onclick="return confirm('¿Seguro que deseas borrar este requisito?');"
                               class="btn-delete" 
                               title="Borrar requisito">
                                <i class="fas fa-trash-alt"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="requisito-details">
                        <div class="detail-row">
                            <span class="detail-label">ID:</span>
                            <span class="detail-value">#<?= $requisito['id'] ?></span>
                        </div>
                        
                        <div class="detail-row">
                            <span class="detail-label">Nombre:</span>
                            <span class="detail-value"><?= htmlspecialchars($requisito['nombre']) ?></span>
                        </div>
                        
                        <div class="detail-row">
                            <span class="detail-label">Estado:</span>
                            <span class="detail-value" style="color: #10b981; font-weight: 600;">Activo</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list" style="font-size: 4rem; color: #5092ab; margin-bottom: 1rem;"></i>
                <h3>No hay requisitos registrados</h3>
                <p>Comienza agregando tu primer requisito</p>
                <button onclick="abrirModalNuevo()" 
                        class="btn-primary">
                    Agregar Requisito
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Botón flotante para agregar requisito -->
    <button class="floating-add-btn" onclick="abrirModalNuevo()" 
            title="Agregar nuevo requisito">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Modal para agregar requisito -->
    <div id="modalRequisito" class="modal">
        <div class="modal-content">
            <button onclick="cerrarModal('modalRequisito')" 
                    class="modal-close">&times;</button>
            
            <div class="modal-header">
                <i class="fas fa-plus-circle"></i>
                <h2>Nuevo Requisito</h2>
            </div>
            
            <form method="post">
                <div class="form-group">
                    <label class="form-label">Nombre del Requisito</label>
                    <input type="text" name="nombre" required placeholder="Ej: Documento de identidad" class="form-input"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Aplica a</label>
                    <select name="aplica_a" class="form-input">
                        <option value="ambos">Ambos (dependencia y monotributistas)</option>
                        <option value="dependencia">Relación de dependencia</option>
                        <option value="monotributista">Monotributista</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="nuevo_requisito" class="btn-primary">
                        <i class="fas fa-save"></i>Guardar Requisito
                    </button>
                    <button type="button" onclick="cerrarModal('modalRequisito')"
                            class="btn-secondary">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para editar requisito -->
    <div id="modalEditar" class="modal">
        <div class="modal-content">
            <button onclick="cerrarModal('modalEditar')" 
                    class="modal-close">&times;</button>
            
            <div class="modal-header">
                <i class="fas fa-edit"></i>
                <h2>Editar Requisito</h2>
            </div>
            
            <form method="post">
                <input type="hidden" name="id_requisito" id="edit_id"/>
                
                <div class="form-group">
                    <label class="form-label">Nombre del Requisito</label>
                    <input type="text" name="nombre" id="edit_nombre" required placeholder="Ej: Documento de identidad" class="form-input"/>
                </div>
                    <div class="form-group">
                        <label class="form-label">Aplica a</label>
                        <select name="aplica_a" id="edit_aplica_a" class="form-input">
                            <option value="ambos">Ambos (dependencia y monotributistas)</option>
                            <option value="dependencia">Relación de dependencia</option>
                            <option value="monotributista">Monotributista</option>
                        </select>
                    </div>
                
                <div class="form-actions">
                    <button type="submit" name="editar_requisito" class="btn-primary">
                        <i class="fas fa-save"></i>Guardar Cambios
                    </button>
                    <button type="button" onclick="cerrarModal('modalEditar')"
                            class="btn-secondary">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Función para cargar los datos del requisito en el modal de edición
        function editarRequisito(requisito) {
            document.getElementById('edit_id').value = requisito.id;
            document.getElementById('edit_nombre').value = requisito.nombre;
            // Preload aplica_a if provided
            if (requisito.aplica_a) {
                const sel = document.getElementById('edit_aplica_a');
                if (sel) sel.value = requisito.aplica_a;
            }
            document.getElementById('modalEditar').style.display = 'flex';
        }

        // Función para abrir modal de nuevo requisito
        function abrirModalNuevo() {
            document.getElementById('modalRequisito').style.display = 'flex';
        }

        // Función para cerrar modales
        function cerrarModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Cerrar modales al hacer clic fuera de ellos
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Cerrar modales con ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                    }
                });
            }
        });

        // Auto-hide mensajes después de 5 segundos
        setTimeout(function() {
            const mensajes = document.querySelectorAll('.mensaje');
            mensajes.forEach(mensaje => {
                mensaje.style.opacity = '0';
                mensaje.style.transform = 'translateY(-20px)';
                setTimeout(() => mensaje.remove(), 300);
            });
        }, 5000);

        // Validación de formularios
        function validarFormulario(form) {
            const inputs = form.querySelectorAll('input[required]');
            let isValid = true;
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.style.borderColor = '#ef4444';
                    input.style.boxShadow = '0 0 0 4px rgba(239, 68, 68, 0.1)';
                    isValid = false;
                } else {
                    input.style.borderColor = '#5092ab';
                    input.style.boxShadow = '';
                }
            });
            
            return isValid;
        }

        // Agregar validación a formularios
        document.addEventListener('DOMContentLoaded', function() {
            const formularios = document.querySelectorAll('form');
            formularios.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!validarFormulario(this)) {
                        e.preventDefault();
                        alert('Por favor completa todos los campos obligatorios');
                    }
                });
            });

            // Asegurar que los modales estén cerrados al inicio
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.style.display = 'none';
            });

            // Agregar event listeners para botones de cerrar
            const closeButtons = document.querySelectorAll('.modal-close');
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    if (modal) {
                        modal.style.display = 'none';
                    }
                });
            });

            // Agregar event listeners para botones de cancelar
            const cancelButtons = document.querySelectorAll('button[onclick*="cerrarModal"]');
            cancelButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    if (modal) {
                        modal.style.display = 'none';
                    }
                });
            });

            console.log('Requisitos page initialized successfully');
        });
    </script>
</body>
</html>
