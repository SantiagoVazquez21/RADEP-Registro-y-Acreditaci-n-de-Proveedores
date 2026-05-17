<?php
// Small notifications helper library for the app
// Usage: require_once __DIR__ . '/../includes/notifications_lib.php' or require_once 'includes/notifications_lib.php'
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../dbconn.php';

function _noti_has_col($col) {
    global $conn;
    $col = $conn->real_escape_string($col);
    $res = $conn->query("SHOW COLUMNS FROM notificaciones LIKE '" . $col . "'");
    return ($res && $res->num_rows > 0);
}

function crear_notificacion($usuario_id, $tipo_usuario, $icono, $titulo, $descripcion) {
    global $conn;
    // Build dynamic insert depending on available columns
    $cols = ['usuario_id', 'tipo_usuario', 'titulo', 'descripcion', 'fecha', 'leida', 'eliminada'];
    $placeholders = [];
    $values = [];
    $types = '';

    // Always include usuario_id and tipo_usuario
    $placeholders[] = '?'; $types .= 'i'; $values[] = $usuario_id;
    $placeholders[] = '?'; $types .= 's'; $values[] = $tipo_usuario;

    // icono optional
    $has_icono = _noti_has_col('icono');
    if ($has_icono) {
        $placeholders[] = '?'; $types .= 's'; $values[] = $icono;
    }

    // titulo and descripcion
    $placeholders[] = '?'; $types .= 's'; $values[] = $titulo;
    $placeholders[] = '?'; $types .= 's'; $values[] = $descripcion;

    // fecha set to NOW() in SQL
    $sqlCols = ['usuario_id', 'tipo_usuario'];
    if ($has_icono) $sqlCols[] = 'icono';
    $sqlCols[] = 'titulo'; $sqlCols[] = 'descripcion';

    // add fecha, leida, eliminada if they exist
    $has_fecha = _noti_has_col('fecha');
    $has_leida = _noti_has_col('leida');
    $has_eliminada = _noti_has_col('eliminada');

    $sql = "INSERT INTO notificaciones (" . implode(',', $sqlCols);
    $valsSql = [];
    // placeholders already built for usuario_id,tipo_usuario,(icono),titulo,descripcion
    $sql .= ") VALUES (" . implode(',', $placeholders) . ")";

    // If fecha/leida/eliminada columns don't exist, we won't set them here; assume DB defaults.
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    // bind params dynamically
    $stmt->bind_param($types, ...$values);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

function obtener_notificaciones($usuario_id, $tipo_usuario, $limit = 100) {
    global $conn;
    // Build select list based on available columns
    $baseCols = ['id','usuario_id','tipo_usuario','titulo','descripcion','fecha','leida'];
    $cols = [];
    foreach ($baseCols as $c) {
        if (_noti_has_col($c)) $cols[] = $c;
    }
    // include icono if exists
    if (_noti_has_col('icono')) {
        // insert icono after tipo_usuario if possible
        $pos = array_search('tipo_usuario', $cols);
        if ($pos === false) $cols[] = 'icono'; else { array_splice($cols, $pos+1, 0, 'icono'); }
    }
    if (empty($cols)) return [];
    $sql = "SELECT " . implode(',', $cols) . " FROM notificaciones WHERE usuario_id = ? AND tipo_usuario = ? AND (eliminada = 0 OR eliminada IS NULL) ORDER BY fecha DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("isi", $usuario_id, $tipo_usuario, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $notificaciones = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $notificaciones;
}

function marcar_notificacion_leida($id, $usuario_id) {
    global $conn;
    if (!_noti_has_col('leida')) return false;
    $stmt = $conn->prepare("UPDATE notificaciones SET leida = 1 WHERE id = ? AND usuario_id = ?");
    if (!$stmt) return false;
    $stmt->bind_param("ii", $id, $usuario_id);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

function eliminar_notificacion($id, $usuario_id) {
    global $conn;
    if (_noti_has_col('eliminada')) {
        $stmt = $conn->prepare("UPDATE notificaciones SET eliminada = 1 WHERE id = ? AND usuario_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $id, $usuario_id);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }
    // Fallback: try delete
    $stmt = $conn->prepare("DELETE FROM notificaciones WHERE id = ? AND usuario_id = ?");
    if (!$stmt) return false;
    $stmt->bind_param("ii", $id, $usuario_id);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

function contar_no_leidas($usuario_id, $tipo_usuario) {
    global $conn;
    $sql = "SELECT COUNT(*) as c FROM notificaciones WHERE usuario_id = ? AND tipo_usuario = ? AND (eliminada = 0 OR eliminada IS NULL)";
    if (_noti_has_col('leida')) $sql .= " AND leida = 0";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $stmt->bind_param("is", $usuario_id, $tipo_usuario);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($res['c'] ?? 0);
}
