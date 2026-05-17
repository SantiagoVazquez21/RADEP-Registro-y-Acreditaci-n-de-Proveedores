<?php
require_once 'dbconn.php';

$evento_id = isset($_GET['evento']) ? intval($_GET['evento']) : 0;
if (!$evento_id) {
    http_response_code(400);
    echo "Falta parámetro evento";
    exit;
}

// Try to load phpspreadsheet
$autoloader = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    // Friendly message if library not installed
    http_response_code(500);
    echo "PhpSpreadsheet no está instalado. Ejecuta: composer require phpoffice/phpspreadsheet";
    exit;
}
require $autoloader;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// Default style: slightly larger font for better readability
$spreadsheet = new Spreadsheet();
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);

// Obtener datos del evento
$evento = ['nombre' => '', 'lugar' => '', 'fecha' => ''];
$st = $conn->prepare("SELECT nombre, lugar, fecha FROM eventos WHERE id = ?");
$st->bind_param('i', $evento_id);
$st->execute();
$st->bind_result($enombre, $elugar, $efecha);
if ($st->fetch()) {
    $evento['nombre'] = $enombre;
    $evento['lugar'] = $elugar;
    $evento['fecha'] = $efecha;
}
$st->close();

// ----------------------------
// Hoja 1: EMPRESA (empleados de la empresa)
// ----------------------------
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('EMPRESA');
$r = 2; // Comenzar en la fila 2
$startCol = 3; // Comenzar en la columna C

// Encabezado del evento
$sheet1->mergeCells(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 2) . $r);
$sheet1->setCellValue(Coordinate::stringFromColumnIndex($startCol) . $r, 'PLANILLA DEL ' . ($evento['nombre'] ?: 'EVENTO'));
$sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r)->getFont()->setBold(true)->setSize(16);
$sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r)->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$r++;
$sheet1->mergeCells(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 2) . $r);
$sheet1->setCellValue(Coordinate::stringFromColumnIndex($startCol) . $r, 'LUGAR: ' . ($evento['lugar'] ?: '-'));
$sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r)->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$r++;
$sheet1->mergeCells(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 2) . $r);
$sheet1->setCellValue(Coordinate::stringFromColumnIndex($startCol) . $r, 'FECHA: ' . ($evento['fecha'] ?: '-'));
$sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r)->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$r += 2;

// Encabezados de la tabla
$headers1 = ['NOMBRE DEL EMPLEADO', 'CUIL', 'PUESTO'];
foreach ($headers1 as $i => $h) {
    $col = Coordinate::stringFromColumnIndex($startCol + $i);
    $sheet1->setCellValue($col . $r, $h);
}
$sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 2) . $r)
    ->getFont()->setBold(true);
$sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 2) . $r)
    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF0');
$sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 2) . $r)
    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$r++;

// Obtener empleados de la empresa asignados al evento (la consulta real se realiza más abajo usando detección de tabla)
    
    // Detectar correctamente la tabla junction que puede variar según la versión/instalación
    $eventEmployeeTable = 'evento_empleado';
    $tbl = $conn->query("SHOW TABLES LIKE 'empleados_evento'");
    if ($tbl && $tbl->num_rows > 0) {
        $eventEmployeeTable = 'empleados_evento';
    }
    
    $sql_emp = "SELECT e.id, e.nombre, e.apellido, e.cuil, e.puesto FROM {$eventEmployeeTable} ee JOIN empleados e ON ee.empleado_id = e.id WHERE ee.evento_id = ? ORDER BY e.apellido, e.nombre";
    $st = $conn->prepare($sql_emp);
    $st->bind_param('i', $evento_id);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $sheet1->setCellValue(Coordinate::stringFromColumnIndex($startCol) . $r, trim($row['nombre'] . ' ' . $row['apellido']));
        // Escribir CUIL como texto para evitar notación científica en Excel
        $sheet1->setCellValueExplicit(Coordinate::stringFromColumnIndex($startCol + 1) . $r, (string)$row['cuil'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet1->setCellValue(Coordinate::stringFromColumnIndex($startCol + 2) . $r, $row['puesto']);
        $sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 2) . $r)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        // Asegurar ajuste de texto si es necesario
        $sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 2) . $r)
            ->getAlignment()->setWrapText(true);
        $r++;
    }
    $st->close();
    
    // Auto size y bordes para hoja 1 (igual que en hoja 2)
    $lastRow1 = $r - 1;
    if ($lastRow1 >= 2) {
        foreach (range($startCol, $startCol + 2) as $ci) {
            $sheet1->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
        }
        $sheet1->getStyle(Coordinate::stringFromColumnIndex($startCol) . '2:' . Coordinate::stringFromColumnIndex($startCol + 2) . $lastRow1)
            ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet1->getPageSetup()->setHorizontalCentered(true);
    }

// ----------------------------
// Hoja 2: PROVEEDORES (empleados de proveedores)
// ----------------------------
// Obtener listados de proveedores asignados al evento agrupados por servicio
$proveedores = [];
$stmt = $conn->prepare("SELECT p.* FROM proveedores_evento pe JOIN proveedores p ON pe.proveedor_id = p.id WHERE pe.evento_id = ? ORDER BY p.servicio, p.nombre");
$stmt->bind_param('i', $evento_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $serv = trim($row['servicio'] ?? 'Sin servicio');
    if (!isset($proveedores[$serv])) $proveedores[$serv] = [];
    $proveedores[$serv][] = $row;
}
$stmt->close();

// Crear la segunda hoja y configurarla
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('PROVEEDORES');
$r = 2; // reset fila para hoja2
$startCol = 3; // misma columna inicial que hoja1

// Encabezado similar al de la hoja 1
$sheet2->mergeCells(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 4) . $r);
$sheet2->setCellValue(Coordinate::stringFromColumnIndex($startCol) . $r, 'PLANILLA PROVEEDORES - ' . ($evento['nombre'] ?: 'EVENTO'));
$sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r)->getFont()->setBold(true)->setSize(14);
$sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r)->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$r += 2;

// Encabezados para la hoja2: Servicio | Proveedor | Empleado | CUIL | Puesto
$headers2 = ['SERVICIO', 'PROVEEDOR', 'NOMBRE DEL EMPLEADO', 'CUIL', 'PUESTO'];
foreach ($headers2 as $i => $h) {
    $col = Coordinate::stringFromColumnIndex($startCol + $i);
    $sheet2->setCellValue($col . $r, $h);
}
$sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 4) . $r)
    ->getFont()->setBold(true);
$sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 4) . $r)
    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF0');
$sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 4) . $r)
    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER);
$r++;
if (is_array($proveedores) && count($proveedores) > 0) {
    foreach ($proveedores as $servicio => $provList) {
        $startRow = $r; // Fila inicial para el servicio
        $servicioHasData = false;
        
        foreach ($provList as $prov) {
            $provStartRow = $r; // Fila inicial para el proveedor
            $st = $conn->prepare("SELECT e.id, e.nombre, e.apellido, e.cuil, e.puesto FROM empleados_evento ee JOIN empleados_proveedor e ON ee.empleado_id = e.id WHERE ee.evento_id = ? AND e.proveedor_id = ? ORDER BY e.apellido, e.nombre");
            $st->bind_param('ii', $evento_id, $prov['id']);
            $st->execute();
            $res = $st->get_result();
            $hasEmployees = false;
            
            while ($er = $res->fetch_assoc()) {
                $sheet2->setCellValue(Coordinate::stringFromColumnIndex($startCol + 2) . $r, trim($er['nombre'] . ' ' . $er['apellido']));
                // Escribir CUIL como texto para evitar notación científica en Excel
                $sheet2->setCellValueExplicit(Coordinate::stringFromColumnIndex($startCol + 3) . $r, (string)$er['cuil'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet2->setCellValue(Coordinate::stringFromColumnIndex($startCol + 4) . $r, $er['puesto']);
                $sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 4) . $r)
                    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $r++;
                $hasEmployees = true;
                $servicioHasData = true;
            }
            $st->close();

            // Si no hay empleados para este proveedor, agregar una fila vacía
            if (!$hasEmployees) {
                $sheet2->setCellValue(Coordinate::stringFromColumnIndex($startCol + 2) . $r, 'Sin empleados asignados');
                $sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol + 2) . $r)
                    ->getFont()->setItalic(true);
                $sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol) . $r . ':' . Coordinate::stringFromColumnIndex($startCol + 4) . $r)
                    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $r++;
                $servicioHasData = true;
            }

            // Unir celdas para el proveedor solo si hay datos y el rango es válido
            if ($r > $provStartRow) {
                $endRow = $r - 1;
                if ($endRow >= $provStartRow) {
                    $sheet2->mergeCells(Coordinate::stringFromColumnIndex($startCol + 1) . $provStartRow . ':' . Coordinate::stringFromColumnIndex($startCol + 1) . $endRow);
                    $sheet2->setCellValue(Coordinate::stringFromColumnIndex($startCol + 1) . $provStartRow, $prov['nombre']);
                    $sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol + 1) . $provStartRow . ':' . Coordinate::stringFromColumnIndex($startCol + 1) . $endRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
                        ->setVertical(Alignment::VERTICAL_CENTER);
                }
            }
        }

        // Unir celdas para el servicio solo si hay datos y el rango es válido
        if ($servicioHasData && $r > $startRow) {
            $endRow = $r - 1;
            if ($endRow >= $startRow) {
                $sheet2->mergeCells(Coordinate::stringFromColumnIndex($startCol) . $startRow . ':' . Coordinate::stringFromColumnIndex($startCol) . $endRow);
                $sheet2->setCellValue(Coordinate::stringFromColumnIndex($startCol) . $startRow, $servicio);
                $sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol) . $startRow . ':' . Coordinate::stringFromColumnIndex($startCol) . $endRow)
                    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
            }
        }
    }
} else {
    // No hay proveedores asignados: dejar una nota
    $sheet2->setCellValue(Coordinate::stringFromColumnIndex($startCol) . $r, 'No hay proveedores asignados a este evento');
    $r++;
}

// Auto size columns
// Auto size columns (solo si sheet2 existe)
if ($sheet2 instanceof \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet) {
    foreach (range($startCol, $startCol + 4) as $ci) {
        $sheet2->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
    }
}

// Agregar bordes a la tabla
$lastRow = $r - 1;
if ($sheet2 instanceof \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet && $lastRow >= 2) {
    $sheet2->getStyle(Coordinate::stringFromColumnIndex($startCol) . '2:' . Coordinate::stringFromColumnIndex($startCol + 4) . $lastRow)
        ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
}

// Centrar la página
if ($sheet2 instanceof \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet) {
    $sheet2->getPageSetup()->setHorizontalCentered(true);
}

// Output
$filename = 'lista_proveedores_empleados_evento_' . $evento_id . '_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;