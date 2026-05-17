<?php
// Conexión con mysqli orientado a objetos
/*$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bg07"; */// Cambia nombre_base por tu base real

$servername = "192.168.101.93";
$username = "BG07";
$password = "St2025#Qkcwf4";
$dbname = "bg07"; // Cambia nombre_base por tu base real

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Helper: registrar acción en audit_logs
function audit_log($conn, $username, $action, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $stmt = $conn->prepare("INSERT INTO audit_logs (username, action, details, ip) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('ssss', $username, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

// Helper: check if username is admin
function is_admin($conn, $username) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE username = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($role);
    if ($stmt->fetch()) {
        $stmt->close();
        return trim($role) === 'admin';
    }
    $stmt->close();
    return false;
}
?>
