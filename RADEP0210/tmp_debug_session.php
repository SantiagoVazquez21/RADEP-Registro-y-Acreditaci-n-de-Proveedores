<?php
// TEMP DEBUG - eliminar después de usar
session_start();
header('Content-Type: text/plain; charset=utf-8');

// Volcado de sesión
echo "-- SESSION DUMP --\n";
var_export($_SESSION);

echo "\n\n-- canonical_proveedor_id (require proveedor_id) --\n";
$used = null; $prov = 0;
if (isset($_SESSION['proveedor_id']) && strlen(trim((string)$_SESSION['proveedor_id']))>0) { $used='proveedor_id'; $prov = intval($_SESSION['proveedor_id']);
	var_export($prov);
	echo "\nused_key: "; var_export($used);
} else {
	echo 'No se encontró \'$_SESSION[\'proveedor_id\']\'. Por favor inicia sesión como proveedor o añade \'proveedor_id\' a la sesión.\n';
}

echo "\n\n-- END --\n";
exit;
?>
