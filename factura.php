<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_activo'])) {
    header('Location: index.php');
    exit();
}

require_once 'backend/conexion.php';
require_once __DIR__ . '/vendor/autoload.php'; // composer require dompdf/dompdf

use Dompdf\Dompdf;
use Dompdf\Options;

// -----------------------------------------------------------------
// 1. Validar y obtener el ID de la venta
// -----------------------------------------------------------------
$ventaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$ventaId || $ventaId <= 0) {
    http_response_code(400);
    die('ID de venta inválido');
}

// -----------------------------------------------------------------
// 2. Obtener la venta + cliente
// -----------------------------------------------------------------
$stmtVenta = $pdo->prepare("
    SELECT v.id, v.total_factura, v.pago, v.cambio, v.fecha_emision,
           c.nombre_completo, c.cedula, c.correo,
           COALESCE(u.usuario, '—') AS cajero
    FROM ventas v
    LEFT JOIN clientes c ON c.id = v.cliente_id
    LEFT JOIN usuarios u ON u.id = v.usuario_id
    WHERE v.id = ?
");
$stmtVenta->execute([$ventaId]);
$venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
    http_response_code(404);
    die('Venta no encontrada');
}

// -----------------------------------------------------------------
// 3. Obtener el detalle de productos de esa venta
// -----------------------------------------------------------------
$stmtDetalle = $pdo->prepare("
    SELECT p.nombre_producto, d.cantidad, d.precio_congelado,
           (d.cantidad * d.precio_congelado) AS subtotal_linea
    FROM detalles_venta d
    INNER JOIN productos p ON p.id = d.producto_id
    WHERE d.venta_id = ?
    ORDER BY d.id ASC
");
$stmtDetalle->execute([$ventaId]);
$detalles = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

if (!$detalles) {
    http_response_code(404);
    die('Esta venta no tiene productos registrados');
}

// -----------------------------------------------------------------
// 4. Calcular totales (recalculados desde la BD, no confiar en nada externo)
// -----------------------------------------------------------------
$subtotal = 0.0;
foreach ($detalles as $d) {
    $subtotal += (float)$d['subtotal_linea'];
}
$iva   = round($subtotal * 0.15, 2);
$total = round($subtotal + $iva, 2);

$numeroFactura = str_pad((string)$venta['id'], 6, '0', STR_PAD_LEFT);
$fechaEmision  = date('d/m/Y H:i', strtotime($venta['fecha_emision']));
$nombreCliente = $venta['nombre_completo'] ?? 'Consumidor Final';
$cedulaCliente = $venta['cedula'] ?? '9999999999';
$correoCliente = $venta['correo'] ?? null;
$esConsumidorFinal = ($cedulaCliente === '9999999999');
$nombreCajero = $venta['cajero'] ?? '—';

// -----------------------------------------------------------------
// 5. Construir el HTML de la factura
// -----------------------------------------------------------------
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 25px 30px; }
    body {
        font-family: 'Helvetica', Arial, sans-serif;
        color: #222;
        font-size: 12px;
    }
    .encabezado {
        border-bottom: 3px solid #1b4332;
        padding-bottom: 10px;
        margin-bottom: 15px;
        width: 100%;
    }
    .encabezado table {
        width: 100%;
        border-collapse: collapse;
    }
    .encabezado td {
        vertical-align: top;
        padding: 0;
    }
    .encabezado .empresa {
        width: 60%;
    }
    .encabezado .empresa h1 {
        color: #1b4332;
        font-size: 20px;
        margin: 0 0 4px 0;
    }
    .encabezado .empresa p {
        margin: 0;
        font-size: 11px;
        color: #555;
    }
    .encabezado .factura-info {
        width: 40%;
        text-align: right;
    }
    .encabezado .factura-info .badge {
        background-color: #1b4332;
        color: #fff;
        padding: 6px 10px;
        border-radius: 4px;
        font-weight: bold;
        display: inline-block;
        margin-bottom: 6px;
    }
    .datos-cliente {
        background-color: #eef4ef;
        border-left: 4px solid #2d6a4f;
        padding: 10px 12px;
        margin-bottom: 18px;
    }
    .datos-cliente p {
        margin: 2px 0;
    }
    .datos-cliente strong {
        color: #1b4332;
    }
    table.detalle {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 15px;
    }
    table.detalle thead th {
        background-color: #1b4332;
        color: #fff;
        text-align: left;
        padding: 8px;
        font-size: 11px;
    }
    table.detalle tbody td {
        padding: 7px 8px;
        border-bottom: 1px solid #ddd;
        font-size: 11px;
    }
    table.detalle tbody tr:nth-child(even) {
        background-color: #f7faf7;
    }
    .col-derecha { text-align: right; }
    .col-centro { text-align: center; }

    .totales-wrap {
        width: 100%;
    }
    .totales-wrap table.wrap-outer {
        width: 100%;
        border-collapse: collapse;
    }
    .totales-wrap .celda-vacia {
        width: 55%;
    }
    .totales {
        width: 45%;
        margin-top: 10px;
    }
    .totales table {
        width: 100%;
        border-collapse: collapse;
    }
    .totales td {
        padding: 6px 8px;
        font-size: 12px;
    }
    .totales .fila-total td {
        border-top: 2px solid #1b4332;
        font-weight: bold;
        font-size: 15px;
        color: #1b4332;
    }
    .pie {
        clear: both;
        margin-top: 60px;
        padding-top: 10px;
        border-top: 1px solid #ccc;
        font-size: 10px;
        color: #777;
        text-align: center;
    }
</style>
</head>
<body>

    <div class="encabezado">
        <table>
            <tr>
                <td class="empresa">
                    <h1>Mi Empresa S.A.</h1>
                    <p>Dirección: Av. Principal y Secundaria, Quito - Ecuador</p>
                    <p>Teléfono: 02-000-0000 &nbsp;|&nbsp; RUC: 1790000000001</p>
                </td>
                <td class="factura-info">
                    <span class="badge">FACTURA N° <?= htmlspecialchars($numeroFactura) ?></span>
                    <p>Fecha de emisión: <?= htmlspecialchars($fechaEmision) ?></p>
                </td>
            </tr>
        </table>
    </div>

    <div class="datos-cliente">
        <p><strong>Cliente:</strong> <?= htmlspecialchars($nombreCliente) ?></p>
        <?php if (!$esConsumidorFinal): ?>
        <p><strong>Cédula/RUC:</strong> <?= htmlspecialchars($cedulaCliente) ?></p>
        <?php endif; ?>
        <?php if ($correoCliente && !$esConsumidorFinal): ?>
        <p><strong>Correo:</strong> <?= htmlspecialchars($correoCliente) ?></p>
        <?php endif; ?>
        <p><strong>Atendido por:</strong> <?= htmlspecialchars($nombreCajero) ?></p>
    </div>

    <table class="detalle">
        <thead>
            <tr>
                <th style="width:45%">Producto</th>
                <th class="col-centro" style="width:15%">Cantidad</th>
                <th class="col-derecha" style="width:20%">Precio Unit.</th>
                <th class="col-derecha" style="width:20%">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($detalles as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['nombre_producto']) ?></td>
                <td class="col-centro"><?= (int)$d['cantidad'] ?></td>
                <td class="col-derecha">$<?= number_format((float)$d['precio_congelado'], 2) ?></td>
                <td class="col-derecha">$<?= number_format((float)$d['subtotal_linea'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totales-wrap">
    <table class="wrap-outer">
        <tr>
            <td class="celda-vacia"></td>
            <td>
                <div class="totales">
                    <table>
                        <tr>
                            <td>Subtotal</td>
                            <td class="col-derecha">$<?= number_format($subtotal, 2) ?></td>
                        </tr>
                        <tr>
                            <td>IVA (15%)</td>
                            <td class="col-derecha">$<?= number_format($iva, 2) ?></td>
                        </tr>
                        <tr class="fila-total">
                            <td>TOTAL</td>
                            <td class="col-derecha">$<?= number_format($total, 2) ?></td>
                        </tr>
                        <tr>
                            <td>Paga con</td>
                            <td class="col-derecha">$<?= number_format((float)$venta['pago'], 2) ?></td>
                        </tr>
                        <tr>
                            <td>Cambio</td>
                            <td class="col-derecha">$<?= number_format((float)$venta['cambio'], 2) ?></td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>
    </div>

    <div class="pie">
        Gracias por su compra &middot; Documento generado electrónicamente por el sistema de Punto de Venta
    </div>

</body>
</html>
<?php
$html = ob_get_clean();

// -----------------------------------------------------------------
// 6. Generar el PDF con DomPDF
// -----------------------------------------------------------------
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dompdf->stream("factura_{$numeroFactura}.pdf", ['Attachment' => false]);
exit();