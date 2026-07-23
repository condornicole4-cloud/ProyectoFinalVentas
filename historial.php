<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_activo'])) {
    header('Location: index.php');
    exit();
}

require_once 'backend/conexion.php';

$accion = $_GET['accion'] ?? '';

// -----------------------------------------------------------------
// Endpoint AJAX: aplica los filtros y devuelve totales + facturas
// -----------------------------------------------------------------
if ($accion === 'obtener_historial') {
    header('Content-Type: application/json; charset=UTF-8');

    $fechaInicio = $_GET['fecha_inicio'] ?? '';
    $fechaFin    = $_GET['fecha_fin'] ?? '';
    $cliente     = trim($_GET['cliente'] ?? '');
    $factura     = trim($_GET['factura'] ?? '');

    $condiciones = [];
    $parametros  = [];

    if ($fechaInicio !== '') {
        $condiciones[] = 'v.fecha_emision >= ?';
        $parametros[]  = $fechaInicio . ' 00:00:00';
    }
    if ($fechaFin !== '') {
        $condiciones[] = 'v.fecha_emision <= ?';
        $parametros[]  = $fechaFin . ' 23:59:59';
    }
    if ($cliente !== '') {
        $condiciones[] = '(c.nombre_completo LIKE ? OR c.cedula LIKE ?)';
        $parametros[]  = "%$cliente%";
        $parametros[]  = "%$cliente%";
    }
    if ($factura !== '') {
        // Acepta el N° con o sin ceros a la izquierda (000014 o 14)
        $condiciones[] = 'v.id = ?';
        $parametros[]  = (int)ltrim($factura, '0');
    }

    $whereSql = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

    // --- Tabla de facturas ---
    $sqlTabla = "
        SELECT v.id, v.fecha_emision, v.total_factura, v.estado,
               COALESCE(c.nombre_completo, 'Consumidor Final') AS cliente,
               COALESCE(u.usuario, '—') AS vendedor,
               COALESCE(u.rol, '') AS rol_vendedor
        FROM ventas v
        LEFT JOIN clientes c ON c.id = v.cliente_id
        LEFT JOIN usuarios u ON u.id = v.usuario_id
        $whereSql
        ORDER BY v.fecha_emision DESC
        LIMIT 500
    ";
    $stmt = $pdo->prepare($sqlTabla);
    $stmt->execute($parametros);
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Totalizadores (solo ventas pagadas cuentan como dinero real) ---
    $sqlTotales = "
        SELECT
            COALESCE(SUM(CASE WHEN v.estado = 'pagada' THEN v.total_factura ELSE 0 END), 0) AS total_vendido,
            COUNT(*) AS cantidad_facturas,
            SUM(CASE WHEN v.estado = 'pagada' THEN 1 ELSE 0 END) AS cantidad_pagadas
        FROM ventas v
        LEFT JOIN clientes c ON c.id = v.cliente_id
        $whereSql
    ";
    $stmtTot = $pdo->prepare($sqlTotales);
    $stmtTot->execute($parametros);
    $tot = $stmtTot->fetch(PDO::FETCH_ASSOC);

    $totalVendido     = (float)$tot['total_vendido'];
    $cantidadFacturas = (int)$tot['cantidad_facturas'];
    $cantidadPagadas  = (int)$tot['cantidad_pagadas'];
    $ticketPromedio   = $cantidadPagadas > 0 ? $totalVendido / $cantidadPagadas : 0.0;

    echo json_encode([
        'totales' => [
            'total_vendido'     => round($totalVendido, 2),
            'cantidad_facturas' => $cantidadFacturas,
            'ticket_promedio'   => round($ticketPromedio, 2),
        ],
        'facturas' => $facturas,
    ]);
    exit();
}
// -----------------------------------------------------------------
// Endpoint AJAX: detalle de productos de una factura (para el modal)
// -----------------------------------------------------------------
if ($accion === 'ver_detalle') {
    header('Content-Type: application/json; charset=UTF-8');

    $ventaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$ventaId || $ventaId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de venta inválido']);
        exit();
    }

    $stmtVenta = $pdo->prepare("
        SELECT v.id, v.fecha_emision, v.total_factura, v.pago, v.cambio, v.estado,
               COALESCE(c.nombre_completo, 'Consumidor Final') AS cliente,
               COALESCE(u.usuario, '—') AS vendedor,
               COALESCE(u.rol, '') AS rol_vendedor
        FROM ventas v
        LEFT JOIN clientes c ON c.id = v.cliente_id
        LEFT JOIN usuarios u ON u.id = v.usuario_id
        WHERE v.id = ?
    ");
    $stmtVenta->execute([$ventaId]);
    $venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        http_response_code(404);
        echo json_encode(['error' => 'Factura no encontrada']);
        exit();
    }

    $stmtProd = $pdo->prepare("
        SELECT p.nombre_producto, d.cantidad, d.precio_congelado,
               (d.cantidad * d.precio_congelado) AS subtotal_linea
        FROM detalles_venta d
        INNER JOIN productos p ON p.id = d.producto_id
        WHERE d.venta_id = ?
        ORDER BY d.id ASC
    ");
    $stmtProd->execute([$ventaId]);
    $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'venta' => $venta,
        'productos' => $productos,
    ]);
    exit();
}

// -----------------------------------------------------------------
// Endpoint AJAX: anular una factura y devolver el stock (POST)
// -----------------------------------------------------------------
if ($accion === 'anular_factura') {
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $ventaId = (int)($input['id'] ?? 0);

    if ($ventaId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de venta inválido']);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Bloquea la fila de la venta para evitar doble anulación simultánea
        $stmtVenta = $pdo->prepare("SELECT estado FROM ventas WHERE id = ? FOR UPDATE");
        $stmtVenta->execute([$ventaId]);
        $venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            throw new Exception('La factura no existe');
        }
        if ($venta['estado'] === 'anulada') {
            throw new Exception('Esta factura ya está anulada');
        }

        // Devolver el stock de cada producto de la venta
        $stmtDetalles = $pdo->prepare("SELECT producto_id, cantidad FROM detalles_venta WHERE venta_id = ?");
        $stmtDetalles->execute([$ventaId]);
        $detalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

        $stmtDevolverStock = $pdo->prepare("UPDATE productos SET stock_disponible = stock_disponible + ? WHERE id = ?");
        foreach ($detalles as $d) {
            $stmtDevolverStock->execute([$d['cantidad'], $d['producto_id']]);
        }

        // Marcar la venta como anulada (nunca se borra el registro)
        $stmtAnular = $pdo->prepare("UPDATE ventas SET estado = 'anulada' WHERE id = ?");
        $stmtAnular->execute([$ventaId]);

        $pdo->commit();
        echo json_encode(['exito' => true]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}
?>

<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Facturas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="frontend/css/dashboard.css">
    <style>
        .historial-content {
            margin-left: 280px;
            padding: 20px;
        }
        .tarjeta-total {
            border: none;
            border-radius: 10px;
            color: #fff;
            padding: 18px 20px;
        }
        .tarjeta-total .etiqueta {
            font-size: 0.85rem;
            opacity: 0.85;
            margin-bottom: 6px;
        }
        .tarjeta-total .valor {
            font-size: 1.8rem;
            font-weight: bold;
        }
        .tarjeta-vendido   { background-color: var(--verde-oscuro); }
        .tarjeta-cantidad  { background-color: var(--verde-medio); }
        .tarjeta-ticket    { background-color: var(--verde-claro); }

        .card-filtros {
            border: none;
            border-radius: 10px;
            background-color: #fff;
            padding: 16px 20px;
            margin-bottom: 20px;
        }
        .card-filtros label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--verde-oscuro);
        }
        .btn-filtrar {
            background-color: var(--verde-oscuro);
            color: #fff;
        }
        .btn-filtrar:hover {
            background-color: var(--verde-medio);
            color: #fff;
        }
        table thead th {
            background-color: var(--verde-oscuro);
            color: #fff;
            font-size: 0.85rem;
        }
        /* Mismos tonos que clientes.php, para que los badges de estado
           se vean idénticos en cualquier página del sistema */
        .badge-pagada {
            background-color: #2e7d32;
            color: #fff;
        }
        .badge-anulada {
            background-color: #c62828;
            color: #fff;
        }
        #mensajeVacio {
            display: none;
        }
        .modal-header {
            background-color: var(--verde-oscuro);
            color: #fff;
        }
        .modal-header .btn-close {
            filter: invert(1);
        }
        #tablaDetalleModal thead th {
            background-color: var(--fondo-gris);
            color: var(--verde-oscuro);
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php include __DIR__ . '/backend/includes/sidebar.php'; ?>

        <div class="historial-content w-100">
            <h3 class="mb-3">Historial de Facturas</h3>

            <!-- Tarjetas de totalizadores -->
            <div class="row mb-3">
                <div class="col-md-4 mb-3">
                    <div class="tarjeta-total tarjeta-vendido">
                        <div class="etiqueta">TOTAL VENDIDO</div>
                        <div class="valor" id="cardTotalVendido">$0.00</div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="tarjeta-total tarjeta-cantidad">
                        <div class="etiqueta">CANTIDAD DE FACTURAS</div>
                        <div class="valor" id="cardCantidadFacturas">0</div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="tarjeta-total tarjeta-ticket">
                        <div class="etiqueta">TICKET PROMEDIO</div>
                        <div class="valor" id="cardTicketPromedio">$0.00</div>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="card-filtros">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">Fecha Inicio</label>
                        <input type="date" id="filtroFechaInicio" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Fecha Fin</label>
                        <input type="date" id="filtroFechaFin" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cliente (nombre o cédula)</label>
                        <input type="text" id="filtroCliente" class="form-control" placeholder="Ej: María Gómez o 1700000002">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">N° Factura</label>
                        <input type="text" id="filtroFactura" class="form-control" placeholder="Ej: 14">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="button" id="btnFiltrar" class="btn btn-filtrar flex-fill">Filtrar</button>
                        <button type="button" id="btnLimpiar" class="btn btn-outline-secondary flex-fill">Limpiar</button>
                    </div>
                </div>
            </div>

            <!-- Tabla principal -->
            <div class="card p-0" style="border:none; border-radius:10px; overflow:hidden;">
                <table class="table table-hover mb-0 bg-white">
                    <thead>
                        <tr>
                            <th>N° Factura</th>
                            <th>Fecha y Hora</th>
                            <th>Cliente</th>
                            <th>Usuario / Rol</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoTabla">
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Cargando facturas...</td>
                        </tr>
                    </tbody>
                </table>
                <p id="mensajeVacio" class="text-center text-muted py-4 mb-0">No se encontraron facturas con esos filtros</p>
            </div>
        </div>
    </div>

    <!-- Modal: Ver detalles de la factura -->
    <div class="modal fade" id="modalDetalle" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalDetalleTitulo">Detalle de Factura</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div id="modalDetalleInfo" class="mb-3"></div>
                    <table id="tablaDetalleModal" class="table table-sm">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cantidad</th>
                                <th class="text-end">Precio Unit.</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoDetalleModal"></tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <a id="btnVerPdfModal" href="#" target="_blank" class="btn btn-outline-secondary">Ver / Reimprimir PDF</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var cuerpoTabla       = document.getElementById('cuerpoTabla');
        var mensajeVacio      = document.getElementById('mensajeVacio');
        var inputFechaInicio  = document.getElementById('filtroFechaInicio');
        var inputFechaFin     = document.getElementById('filtroFechaFin');
        var inputCliente      = document.getElementById('filtroCliente');
        var inputFactura      = document.getElementById('filtroFactura');

        // Si se llega desde clientes.php (botón "Compras"), viene ?cliente=<cedula>
        // y precargamos ese filtro antes de la primera búsqueda.
        var parametrosUrl = new URLSearchParams(window.location.search);
        if (parametrosUrl.get('cliente')) {
            inputCliente.value = parametrosUrl.get('cliente');
        }

        // ---------------------------------------------------------------
        // Alertas propias (mismo componente que ya usa clientes.php,
        // reemplaza los alert() nativos del navegador).
        // ---------------------------------------------------------------
        function mostrarAlerta(mensaje, tipo) {
            tipo = tipo || 'error';

            var contenedor = document.getElementById('contenedorAlertas');
            if (!contenedor) {
                contenedor = document.createElement('div');
                contenedor.id = 'contenedorAlertas';
                document.body.appendChild(contenedor);
            }

            var alerta = document.createElement('div');
            alerta.className = 'alerta-pos alerta-pos-' + tipo;

            var icono = document.createElement('span');
            icono.className = 'alerta-pos-icono';
            icono.textContent = (tipo === 'info') ? '✔' : '⚠';

            var texto = document.createElement('span');
            texto.className = 'alerta-pos-texto';
            texto.textContent = mensaje;

            var btnCerrar = document.createElement('button');
            btnCerrar.type = 'button';
            btnCerrar.className = 'alerta-pos-cerrar';
            btnCerrar.setAttribute('aria-label', 'Cerrar');
            btnCerrar.innerHTML = '&times;';

            alerta.appendChild(icono);
            alerta.appendChild(texto);
            alerta.appendChild(btnCerrar);
            contenedor.appendChild(alerta);

            function cerrarAlerta() {
                alerta.classList.add('alerta-pos-salida');
                setTimeout(function () { alerta.remove(); }, 250);
            }

            btnCerrar.addEventListener('click', cerrarAlerta);
            setTimeout(cerrarAlerta, 4000);
        }

        // Misma función defensiva que usa clientes.php: evita inyectar
        // HTML sin escapar cuando se insertan datos que vienen de la BD.
        function escaparHtml(texto) {
            var div = document.createElement('div');
            div.textContent = texto == null ? '' : texto;
            return div.innerHTML;
        }

        function formatearMoneda(valor) {
            return '$' + Number(valor).toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function formatearFecha(fechaSql) {
            // fechaSql viene como "YYYY-MM-DD HH:MM:SS"
            var partes = fechaSql.replace('T', ' ').split(' ');
            var fecha = partes[0].split('-');
            var hora = partes[1] ? partes[1].substring(0, 5) : '';
            return fecha[2] + '/' + fecha[1] + '/' + fecha[0] + ' ' + hora;
        }

        function badgeEstado(estado) {
            if (estado === 'anulada') {
                return '<span class="badge badge-anulada">Anulada</span>';
            }
            return '<span class="badge badge-pagada">Pagada</span>';
        }

        function textoVendedor(vendedor, rol) {
            var vendedorSeguro = escaparHtml(vendedor);
            if (!rol) return vendedorSeguro;
            return vendedorSeguro + ' <span class="text-muted small">(' + escaparHtml(rol) + ')</span>';
        }

        function cargarHistorial() {
            var params = new URLSearchParams();
            params.set('accion', 'obtener_historial');
            if (inputFechaInicio.value) params.set('fecha_inicio', inputFechaInicio.value);
            if (inputFechaFin.value)    params.set('fecha_fin', inputFechaFin.value);
            if (inputCliente.value.trim())  params.set('cliente', inputCliente.value.trim());
            if (inputFactura.value.trim())  params.set('factura', inputFactura.value.trim());

            cuerpoTabla.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Cargando facturas...</td></tr>';
            mensajeVacio.style.display = 'none';

            fetch('historial.php?' + params.toString())
                .then(function (resp) { return resp.json(); })
                .then(function (data) {
                    // --- Tarjetas ---
                    document.getElementById('cardTotalVendido').innerText = formatearMoneda(data.totales.total_vendido);
                    document.getElementById('cardCantidadFacturas').innerText = data.totales.cantidad_facturas;
                    document.getElementById('cardTicketPromedio').innerText = formatearMoneda(data.totales.ticket_promedio);

                    // --- Tabla ---
                    cuerpoTabla.innerHTML = '';

                    if (!data.facturas.length) {
                        mensajeVacio.style.display = 'block';
                        return;
                    }

                    data.facturas.forEach(function (f) {
                        var numeroFactura = String(f.id).padStart(6, '0');
                        var esAnulada = f.estado === 'anulada';
                        var fila = document.createElement('tr');
                        fila.innerHTML =
                            '<td>#' + numeroFactura + '</td>' +
                            '<td>' + formatearFecha(f.fecha_emision) + '</td>' +
                            '<td>' + escaparHtml(f.cliente) + '</td>' +
                            '<td>' + textoVendedor(f.vendedor, f.rol_vendedor) + '</td>' +
                            '<td>' + formatearMoneda(f.total_factura) + '</td>' +
                            '<td>' + badgeEstado(f.estado) + '</td>' +
                            '<td class="text-nowrap">' +
                                '<button type="button" class="btn btn-sm btn-outline-primary btn-ver-detalle" data-id="' + f.id + '">Ver Detalles</button> ' +
                                '<a href="factura.php?id=' + f.id + '" target="_blank" class="btn btn-sm btn-outline-secondary">Reimprimir</a> ' +
                                (esAnulada
                                    ? ''
                                    : '<button type="button" class="btn btn-sm btn-outline-danger btn-anular" data-id="' + f.id + '">Anular</button>'
                                ) +
                            '</td>';
                        cuerpoTabla.appendChild(fila);
                    });

                    // Enlazar los botones recién creados
                    document.querySelectorAll('.btn-ver-detalle').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            abrirModalDetalle(btn.dataset.id);
                        });
                    });
                    document.querySelectorAll('.btn-anular').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            anularFactura(btn.dataset.id);
                        });
                    });
                })
                .catch(function () {
                    cuerpoTabla.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Error al cargar el historial</td></tr>';
                });
        }

        document.getElementById('btnFiltrar').addEventListener('click', cargarHistorial);

        document.getElementById('btnLimpiar').addEventListener('click', function () {
            inputFechaInicio.value = '';
            inputFechaFin.value = '';
            inputCliente.value = '';
            inputFactura.value = '';
            cargarHistorial();
        });

        // Enter en cualquier input de texto también dispara el filtro
        [inputCliente, inputFactura].forEach(function (el) {
            el.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') cargarHistorial();
            });
        });

        // -----------------------------------------------------------------
        // Modal: Ver Detalles
        // -----------------------------------------------------------------
        var modalDetalleEl = document.getElementById('modalDetalle');
        var modalDetalle = new bootstrap.Modal(modalDetalleEl);

        function abrirModalDetalle(ventaId) {
            fetch('historial.php?accion=ver_detalle&id=' + ventaId)
                .then(function (resp) { return resp.json(); })
                .then(function (data) {
                    if (data.error) {
                        mostrarAlerta(data.error, 'error');
                        return;
                    }

                    var v = data.venta;
                    var numeroFactura = String(v.id).padStart(6, '0');

                    document.getElementById('modalDetalleTitulo').innerText = 'Factura #' + numeroFactura;
                    document.getElementById('modalDetalleInfo').innerHTML =
                        '<p class="mb-1"><strong>Cliente:</strong> ' + escaparHtml(v.cliente) + '</p>' +
                        '<p class="mb-1"><strong>Vendedor:</strong> ' + textoVendedor(v.vendedor, v.rol_vendedor) + '</p>' +
                        '<p class="mb-1"><strong>Fecha:</strong> ' + formatearFecha(v.fecha_emision) + '</p>' +
                        '<p class="mb-1"><strong>Estado:</strong> ' + badgeEstado(v.estado) + '</p>' +
                        '<p class="mb-1"><strong>Pago:</strong> ' + formatearMoneda(v.pago) + ' &nbsp; <strong>Cambio:</strong> ' + formatearMoneda(v.cambio) + '</p>' +
                        '<p class="mb-0"><strong>Total:</strong> ' + formatearMoneda(v.total_factura) + '</p>';

                    var cuerpoDetalle = document.getElementById('cuerpoDetalleModal');
                    cuerpoDetalle.innerHTML = '';
                    data.productos.forEach(function (p) {
                        var fila = document.createElement('tr');
                        fila.innerHTML =
                            '<td>' + escaparHtml(p.nombre_producto) + '</td>' +
                            '<td class="text-center">' + p.cantidad + '</td>' +
                            '<td class="text-end">' + formatearMoneda(p.precio_congelado) + '</td>' +
                            '<td class="text-end">' + formatearMoneda(p.subtotal_linea) + '</td>';
                        cuerpoDetalle.appendChild(fila);
                    });

                    document.getElementById('btnVerPdfModal').href = 'factura.php?id=' + v.id;

                    modalDetalle.show();
                })
                .catch(function () {
                    mostrarAlerta('Error al cargar el detalle de la factura', 'error');
                });
        }

        // -----------------------------------------------------------------
        // Anular factura
        // -----------------------------------------------------------------
        function anularFactura(ventaId) {
            if (!confirm('¿Anular la factura #' + String(ventaId).padStart(6, '0') + '? Esta acción devolverá el stock de los productos vendidos y no se puede deshacer.')) {
                return;
            }

            fetch('historial.php?accion=anular_factura', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: ventaId })
            })
            .then(function (resp) { return resp.json(); })
            .then(function (res) {
                if (res.exito) {
                    mostrarAlerta('Factura anulada correctamente. El stock fue devuelto al inventario.', 'info');
                    cargarHistorial();
                } else {
                    mostrarAlerta(res.error || 'No se pudo anular la factura', 'error');
                }
            })
            .catch(function () {
                mostrarAlerta('Error al anular la factura', 'error');
            });
        }

        cargarHistorial();
    </script>
</body>
</html>