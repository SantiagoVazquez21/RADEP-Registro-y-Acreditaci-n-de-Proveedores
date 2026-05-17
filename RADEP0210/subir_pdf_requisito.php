<?php
session_start();
require_once 'conexion.php';

// Verificar que el usuario esté logueado y sea proveedor
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'proveedor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $evento_id = $_POST['evento_id'] ?? null;
    $requisito_id = $_POST['requisito_id'] ?? null;
    $proveedor_usuario = $_POST['proveedor_usuario'] ?? null;
    
    if (!$evento_id || !$requisito_id || !$proveedor_usuario) {
        echo json_encode(['success' => false, 'error' => 'Faltan parámetros requeridos']);
        exit;
    }
    
    // Verificar que el archivo se haya subido
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Error al subir el archivo']);
        exit;
    }
    
    $file = $_FILES['pdf'];
    
    // Verificar que sea un PDF
    $allowed_types = ['application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        echo json_encode(['success' => false, 'error' => 'Solo se permiten archivos PDF']);
        exit;
    }
    
    // Verificar tamaño del archivo (máximo 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'El archivo es demasiado grande (máximo 10MB)']);
        exit;
    }
    
    // Crear directorio si no existe
    $upload_dir = 'uploads/proveedor_requisitos/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generar nombre único para el archivo
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'requisito_' . $evento_id . '_' . $requisito_id . '_' . $proveedor_usuario . '_' . time() . '.' . $file_extension;
    $filepath = $upload_dir . $filename;
    
    // Mover el archivo
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Actualizar o insertar en la base de datos
        $conn = new mysqli($host, $username, $password, $database);
        
        if ($conn->connect_error) {
            echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos']);
            exit;
        }
        
        // Verificar si ya existe un registro para este proveedor, evento y requisito
        $check_sql = "SELECT id FROM proveedor_requisito_evento WHERE proveedor_usuario = ? AND evento_id = ? AND requisito_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("sii", $proveedor_usuario, $evento_id, $requisito_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Actualizar registro existente (do not write 'estado' column)
            $update_sql = "UPDATE proveedor_requisito_evento SET archivo = ?, fecha_subida = NOW() WHERE proveedor_usuario = ? AND evento_id = ? AND requisito_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ssii", $filename, $proveedor_usuario, $evento_id, $requisito_id);
            
            if ($update_stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'PDF actualizado correctamente']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al actualizar en la base de datos']);
            }
            $update_stmt->close();
        } else {
            // Insertar nuevo registro (do not set 'estado' column)
            $insert_sql = "INSERT INTO proveedor_requisito_evento (proveedor_usuario, evento_id, requisito_id, archivo, fecha_subida) VALUES (?, ?, ?, ?, NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("siis", $proveedor_usuario, $evento_id, $requisito_id, $filename);
            
            if ($insert_stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'PDF subido correctamente']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al insertar en la base de datos']);
            }
            $insert_stmt->close();
        }
        
        $check_stmt->close();
        $conn->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al mover el archivo']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
}
?> 