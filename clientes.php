<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_activo'])) {
    header('Location: index.php');
    exit();
}

require_once 'backend/conexion.php';

// Cédula fija del cliente "Consumidor Final" (igual que en pos.php).
// Este registro no se puede editar ni eliminar porque el POS depende de él.
const CEDULA_CONSUMIDOR_FINAL = '9999999999';

function esCedulaEcuatorianaValida(string $cedula): bool
{
    if (!preg_match('/^\d{10}$/', $cedula)) {
        return false;
    }

    $provincia = (int)substr($cedula, 0, 2);
    if ($provincia < 1 || $provincia > 24) {
        return false;
    }

    $tercerDigito = (int)$cedula[2];
    if ($tercerDigito > 5) {
        return false;
    }

    $coeficientes = [2, 1, 2, 1, 2, 1, 2, 1, 2];
    $suma = 0;
    for ($i = 0; $i < 9; $i++) {
        $valor = (int)$cedula[$i] * $coeficientes[$i];
        if ($valor >= 10) {
            $valor -= 9;
        }
        $suma += $valor;
    }

    $digitoVerificador = (int)$cedula[9];
    $residuo = $suma % 10;
    $resultadoEsperado = ($residuo === 0) ? 0 : 10 - $residuo;

    return $resultadoEsperado === $digitoVerificador;
}

$accion = $_GET['accion'] ?? '';

// -----------------------------------------------------------------
// Endpoint AJAX: listar clientes (con búsqueda opcional)
// -----------------------------------------------------------------
if ($accion === 'listar') {
    header('Content-Type: application/json; charset=UTF-8');

    $busqueda        = trim($_GET['q'] ?? '');
    $incluirInactivos = ($_GET['incluir_inactivos'] ?? '') === '1';
    $porPagina       = 10;
    $pagina          = max(1, (int)($_GET['pagina'] ?? 1));
    $offset          = ($pagina - 1) * $porPagina;

    $condiciones  = ['(nombre_completo LIKE ? OR cedula LIKE ? OR correo LIKE ?)'];
    $parametros   = ["%$busqueda%", "%$busqueda%", "%$busqueda%"];

    if (!$incluirInactivos) {
        $condiciones[] = 'estado = 1';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $condiciones);

    // --- Total de resultados (para saber cuántas páginas hay) ---
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM clientes $whereSql");
    $stmtTotal->execute($parametros);
    $total = (int)$stmtTotal->fetchColumn();

    // --- Página actual ---
    $sql = "SELECT id, nombre_completo, cedula, telefono, direccion, correo, estado, fecha_registro
            FROM clientes
            $whereSql
            ORDER BY (cedula = ?) DESC, nombre_completo ASC
            LIMIT $porPagina OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$parametros, CEDULA_CONSUMIDOR_FINAL]);

    echo json_encode([
        'clientes'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total'         => $total,
        'pagina'        => $pagina,
        'total_paginas' => (int)max(1, ceil($total / $porPagina)),
    ]);
    exit();
}

// -----------------------------------------------------------------
// Endpoint AJAX: historial de facturas de un cliente específico
// (para el modal "Compras" — aprovecha la relación clientes -> ventas)
// -----------------------------------------------------------------
if ($accion === 'historial_cliente') {
    header('Content-Type: application/json; charset=UTF-8');

    $clienteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$clienteId || $clienteId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de cliente inválido']);
        exit();
    }

    $stmtCliente = $pdo->prepare("SELECT id, nombre_completo, cedula FROM clientes WHERE id = ?");
    $stmtCliente->execute([$clienteId]);
    $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        http_response_code(404);
        echo json_encode(['error' => 'El cliente no existe']);
        exit();
    }

    $stmtFacturas = $pdo->prepare("
        SELECT id, fecha_emision, total_factura, estado
        FROM ventas
        WHERE cliente_id = ?
        ORDER BY fecha_emision DESC
    ");
    $stmtFacturas->execute([$clienteId]);
    $facturas = $stmtFacturas->fetchAll(PDO::FETCH_ASSOC);

    $totalGastado = 0.0;
    foreach ($facturas as $f) {
        if ($f['estado'] !== 'anulada') {
            $totalGastado += (float)$f['total_factura'];
        }
    }

    echo json_encode([
        'cliente'           => $cliente,
        'facturas'          => $facturas,
        'cantidad_facturas' => count($facturas),
        'total_gastado'     => $totalGastado,
    ]);
    exit();
}

// -----------------------------------------------------------------
// Endpoint AJAX: crear o actualizar un cliente (POST)
// -----------------------------------------------------------------
if ($accion === 'guardar') {
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $id        = (int)($input['id'] ?? 0);
    $nombre    = trim((string)($input['nombre_completo'] ?? ''));
    $cedula    = trim((string)($input['cedula'] ?? ''));
    $correo    = trim((string)($input['correo'] ?? ''));
    $telefono  = trim((string)($input['telefono'] ?? ''));
    $direccion = trim((string)($input['direccion'] ?? ''));

    if ($nombre === '' || !preg_match('/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s]+$/', $nombre)) {
        http_response_code(400);
        echo json_encode(['error' => 'El nombre es obligatorio y solo puede contener letras']);
        exit();
    }
    if ($cedula === '') {
        http_response_code(400);
        echo json_encode(['error' => 'La cédula es obligatoria']);
        exit();
    }
    if (!esCedulaEcuatorianaValida($cedula)) {
        http_response_code(400);
        echo json_encode(['error' => 'La cédula ingresada no es válida (verifica los 10 dígitos)']);
        exit();
    }
    // El correo sigue siendo opcional, pero si se escribe algo, debe tener
    // arroba + dominio obligatorios (FILTER_VALIDATE_EMAIL lo exige).
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'El correo debe tener un formato válido, con @ y dominio (ej: nombre@dominio.com)']);
        exit();
    }
    if ($telefono !== '' && !preg_match('/^\d{7,10}$/', $telefono)) {
        http_response_code(400);
        echo json_encode(['error' => 'El teléfono debe tener entre 7 y 10 dígitos numéricos']);
        exit();
    }

    try {
        if ($id > 0) {
            // --- Actualizar ---
            $stmtActual = $pdo->prepare("SELECT cedula FROM clientes WHERE id = ?");
            $stmtActual->execute([$id]);
            $clienteActual = $stmtActual->fetch(PDO::FETCH_ASSOC);

            if (!$clienteActual) {
                http_response_code(404);
                echo json_encode(['error' => 'El cliente no existe']);
                exit();
            }
            if ($clienteActual['cedula'] === CEDULA_CONSUMIDOR_FINAL) {
                http_response_code(400);
                echo json_encode(['error' => 'El cliente "Consumidor Final" no se puede modificar']);
                exit();
            }

            $stmtDup = $pdo->prepare("SELECT id FROM clientes WHERE cedula = ? AND id != ?");
            $stmtDup->execute([$cedula, $id]);
            if ($stmtDup->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Ya existe otro cliente registrado con esa cédula']);
                exit();
            }

            $stmt = $pdo->prepare("UPDATE clientes SET nombre_completo = ?, cedula = ?, correo = ?, telefono = ?, direccion = ? WHERE id = ?");
            $stmt->execute([
                $nombre,
                $cedula,
                $correo !== '' ? $correo : null,
                $telefono !== '' ? $telefono : null,
                $direccion !== '' ? $direccion : null,
                $id,
            ]);
            echo json_encode(['message' => 'Cliente actualizado con éxito']);
        } else {
            // --- Crear ---
            $stmtDup = $pdo->prepare("SELECT id FROM clientes WHERE cedula = ?");
            $stmtDup->execute([$cedula]);
            if ($stmtDup->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Ya existe un cliente registrado con esa cédula']);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO clientes (nombre_completo, cedula, correo, telefono, direccion) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $nombre,
                $cedula,
                $correo !== '' ? $correo : null,
                $telefono !== '' ? $telefono : null,
                $direccion !== '' ? $direccion : null,
            ]);
            echo json_encode(['message' => 'Cliente creado con éxito', 'id' => $pdo->lastInsertId()]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en la base de datos: ' . $e->getMessage()]);
    }
    exit();
}

// -----------------------------------------------------------------
// Endpoint AJAX: desactivar un cliente = borrado lógico (POST)
// En vez de DELETE, se marca estado = 0. El cliente deja de aparecer
// en el listado normal, pero sus facturas en 'ventas' quedan intactas.
// -----------------------------------------------------------------
if ($accion === 'eliminar') {
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de cliente inválido']);
        exit();
    }

    $stmtActual = $pdo->prepare("SELECT cedula FROM clientes WHERE id = ?");
    $stmtActual->execute([$id]);
    $clienteActual = $stmtActual->fetch(PDO::FETCH_ASSOC);

    if (!$clienteActual) {
        http_response_code(404);
        echo json_encode(['error' => 'El cliente no existe']);
        exit();
    }
    if ($clienteActual['cedula'] === CEDULA_CONSUMIDOR_FINAL) {
        http_response_code(400);
        echo json_encode(['error' => 'El cliente "Consumidor Final" no se puede desactivar']);
        exit();
    }

    $stmt = $pdo->prepare("UPDATE clientes SET estado = 0 WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['message' => 'Cliente desactivado con éxito']);
    exit();
}

// -----------------------------------------------------------------
// Endpoint AJAX: reactivar un cliente previamente desactivado (POST)
// -----------------------------------------------------------------
if ($accion === 'reactivar') {
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de cliente inválido']);
        exit();
    }

    $stmt = $pdo->prepare("UPDATE clientes SET estado = 1 WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['message' => 'Cliente reactivado con éxito']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="frontend/css/dashboard.css">
    <style>
        .clientes-content {
            margin-left: 280px;
            padding: 20px;
        }
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
        .btn-nuevo-cliente {
            background-color: var(--verde-oscuro);
            color: #fff;
        }
        .btn-nuevo-cliente:hover {
            background-color: var(--verde-medio);
            color: #fff;
        }
        table thead th {
            background-color: var(--verde-oscuro);
            color: #fff;
            font-size: 0.85rem;
        }
        .badge-protegido {
            background-color: var(--verde-medio);
            color: #fff;
            font-size: 0.72rem;
        }
        .badge-activo {
            background-color: #2e7d32;
            color: #fff;
            font-size: 0.72rem;
        }
        .badge-inactivo {
            background-color: #9e9e9e;
            color: #fff;
            font-size: 0.72rem;
        }
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
        .banner-vacio {
            text-align: center;
            padding: 40px 20px;
        }
        .banner-vacio .banner-icono {
            font-size: 2.2rem;
            display: block;
            margin-bottom: 8px;
        }
        .banner-vacio .banner-titulo {
            font-weight: 600;
            color: var(--verde-oscuro);
            margin-bottom: 4px;
        }
        .modal-header {
            background-color: var(--verde-oscuro);
            color: #fff;
        }
        .modal-header .btn-close {
            filter: invert(1);
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php include __DIR__ . '/backend/includes/sidebar.php'; ?>

        <div class="clientes-content w-100">
            <h3 class="mb-3">Clientes</h3>

            <!-- Búsqueda + acción de crear -->
            <div class="card-filtros">
                <div class="row g-3 align-items-end">
                    <div class="col-md-7">
                        <label class="form-label">Buscar por nombre, cédula o correo</label>
                        <input type="text" id="buscarCliente" class="form-control" placeholder="Ej: María Gómez o 1700000002" autocomplete="off">
                    </div>
                    <div class="col-md-2 d-flex align-items-center">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="checkIncluirInactivos">
                            <label class="form-check-label small" for="checkIncluirInactivos">Mostrar inactivos</label>
                        </div>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="button" id="btnNuevoCliente" class="btn btn-nuevo-cliente flex-fill">+ Nuevo Cliente</button>
                    </div>
                </div>
            </div>

            <!-- Tabla principal -->
            <div class="card p-0" style="border:none; border-radius:10px; overflow:hidden;">
                <table class="table table-hover mb-0 bg-white">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Cédula</th>
                            <th>Teléfono</th>
                            <th>Correo</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoTabla">
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Cargando clientes...</td>
                        </tr>
                    </tbody>
                </table>
                <div id="mensajeVacio" class="banner-vacio">
                    <span class="banner-icono">🔍</span>
                    <div class="banner-titulo">No se encontraron clientes</div>
                    <div class="text-muted small" id="mensajeVacioTexto">Prueba con otro nombre, cédula o correo</div>
                </div>
            </div>

            <!-- Paginación -->
            <div class="d-flex justify-content-between align-items-center mt-3" id="bloquePaginacion">
                <span class="text-muted small" id="textoPaginacion"></span>
                <div class="btn-group">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPaginaAnterior">&laquo; Anterior</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPaginaSiguiente">Siguiente &raquo;</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Nuevo / Editar cliente -->
    <div class="modal fade" id="modalCliente" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalClienteTitulo">Nuevo Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="clienteId" value="0">
                    <div class="mb-3">
                        <label class="form-label">Nombre completo</label>
                        <input type="text" id="clienteNombre" class="form-control" placeholder="Ej: María Gómez">
                        <div id="errorNombre" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cédula</label>
                        <input type="text" id="clienteCedula" class="form-control" placeholder="Ej: 1700000002" maxlength="10" inputmode="numeric">
                        <div id="errorCedula" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Correo (opcional)</label>
                        <input type="email" id="clienteCorreo" class="form-control" placeholder="Ej: cliente@correo.com">
                        <div id="errorCorreo" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Teléfono (opcional)</label>
                        <input type="text" id="clienteTelefono" class="form-control" placeholder="Ej: 0991234567" maxlength="10" inputmode="numeric">
                        <div id="errorTelefono" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Dirección (opcional)</label>
                        <input type="text" id="clienteDireccion" class="form-control" placeholder="Ej: Av. Amazonas y Naciones Unidas">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" id="btnGuardarCliente" class="btn btn-nuevo-cliente">Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Historial de compras de un cliente -->
    <div class="modal fade" id="modalHistorial" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Historial de compras — <span id="tituloClienteHistorial"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row text-center mb-3">
                        <div class="col-6">
                            <div class="text-muted small">Facturas registradas</div>
                            <div class="fs-4 fw-bold" id="historialCantidadFacturas">—</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Total comprado</div>
                            <div class="fs-4 fw-bold" id="historialTotalGastado">—</div>
                        </div>
                    </div>
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>N° Factura</th>
                                <th>Fecha</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoHistorialCliente">
                            <tr><td colspan="5" class="text-center text-muted py-3">Cargando...</td></tr>
                        </tbody>
                    </table>
                    <p id="historialVacio" class="text-center text-muted py-3" style="display:none;">Este cliente todavía no tiene facturas registradas</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var cuerpoTabla    = document.getElementById('cuerpoTabla');
        var mensajeVacio   = document.getElementById('mensajeVacio');
        var mensajeVacioTexto = document.getElementById('mensajeVacioTexto');
        var inputBuscar    = document.getElementById('buscarCliente');
        var checkIncluirInactivos = document.getElementById('checkIncluirInactivos');
        var temporizadorBusqueda = null;

        // Paginación
        var paginaActual   = 1;
        var totalPaginas   = 1;
        var textoPaginacion = document.getElementById('textoPaginacion');
        var btnPaginaAnterior  = document.getElementById('btnPaginaAnterior');
        var btnPaginaSiguiente = document.getElementById('btnPaginaSiguiente');

        var modalClienteEl = document.getElementById('modalCliente');
        var modalCliente    = new bootstrap.Modal(modalClienteEl);
        var modalClienteTitulo = document.getElementById('modalClienteTitulo');

        var modalHistorialEl = document.getElementById('modalHistorial');
        var modalHistorial    = new bootstrap.Modal(modalHistorialEl);

        var CEDULA_CONSUMIDOR_FINAL = '<?= CEDULA_CONSUMIDOR_FINAL ?>';

        // ---------------------------------------------------------------
        // Alertas propias (mismo componente que usa pos.php).
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

        function escaparHtml(texto) {
            var div = document.createElement('div');
            div.textContent = texto == null ? '' : texto;
            return div.innerHTML;
        }

        function formatearFecha(fechaSql) {
            if (!fechaSql) return '—';
            // fechaSql viene como "YYYY-MM-DD" o "YYYY-MM-DD HH:MM:SS"
            var fecha = fechaSql.split(' ')[0].split('-');
            return fecha[2] + '/' + fecha[1] + '/' + fecha[0];
        }

        // -----------------------------------------------------------------
        // Listar / buscar
        // -----------------------------------------------------------------
        function cargarClientes(pagina) {
            paginaActual = pagina || 1;
            var q = inputBuscar.value.trim();
            var incluirInactivos = checkIncluirInactivos.checked ? '1' : '0';

            cuerpoTabla.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Cargando clientes...</td></tr>';
            mensajeVacio.style.display = 'none';

            fetch('clientes.php?accion=listar&q=' + encodeURIComponent(q) + '&pagina=' + paginaActual + '&incluir_inactivos=' + incluirInactivos)
                .then(function (resp) { return resp.json(); })
                .then(function (data) {
                    var clientes = data.clientes;
                    totalPaginas = data.total_paginas;
                    cuerpoTabla.innerHTML = '';

                    if (!clientes.length) {
                        mensajeVacioTexto.textContent = q
                            ? 'No hay clientes que coincidan con "' + q + '". Prueba con otro nombre, cédula o correo.'
                            : 'Todavía no hay clientes registrados.';
                        mensajeVacio.style.display = 'block';
                        actualizarPaginacion(data);
                        return;
                    }

                    clientes.forEach(function (c) {
                        var esProtegido = c.cedula === CEDULA_CONSUMIDOR_FINAL;
                        var esActivo = String(c.estado) === '1';
                        var fila = document.createElement('tr');
                        if (!esActivo) fila.classList.add('table-secondary');
                        fila.innerHTML =
                            '<td>' + escaparHtml(c.nombre_completo) + (esProtegido ? ' <span class="badge badge-protegido">Fijo</span>' : '') + '</td>' +
                            '<td>' + escaparHtml(c.cedula) + '</td>' +
                            '<td>' + escaparHtml(c.telefono || '—') + '</td>' +
                            '<td>' + escaparHtml(c.correo || '—') + '</td>' +
                            '<td>' + (esActivo ? '<span class="badge badge-activo">Activo</span>' : '<span class="badge badge-inactivo">Inactivo</span>') + '</td>' +
                            '<td class="text-nowrap">' +
                                '<button type="button" class="btn btn-sm btn-outline-success btn-compras" data-id="' + c.id + '" title="Ver historial de compras">Compras</button> ' +
                                (esProtegido
                                    ? '<span class="text-muted small">No editable</span>'
                                    : ('<button type="button" class="btn btn-sm btn-outline-primary btn-editar" data-id="' + c.id + '">Editar</button> ' +
                                       (esActivo
                                            ? '<button type="button" class="btn btn-sm btn-outline-danger btn-eliminar" data-id="' + c.id + '">Eliminar</button>'
                                            : '<button type="button" class="btn btn-sm btn-outline-success btn-reactivar" data-id="' + c.id + '">Reactivar</button>'
                                       ))
                                ) +
                            '</td>';
                        cuerpoTabla.appendChild(fila);
                    });

                    document.querySelectorAll('.btn-editar').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var c = clientes.find(function (item) { return item.id == btn.dataset.id; });
                            if (c) abrirModalEditar(c);
                        });
                    });
                    document.querySelectorAll('.btn-eliminar').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            eliminarCliente(btn.dataset.id);
                        });
                    });
                    document.querySelectorAll('.btn-reactivar').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            reactivarCliente(btn.dataset.id);
                        });
                    });
                    document.querySelectorAll('.btn-compras').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            abrirHistorialCliente(btn.dataset.id);
                        });
                    });

                    actualizarPaginacion(data);
                })
                .catch(function () {
                    cuerpoTabla.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Error al cargar los clientes</td></tr>';
                });
        }

        function actualizarPaginacion(data) {
            textoPaginacion.textContent = data.total
                ? ('Página ' + data.pagina + ' de ' + data.total_paginas + ' — ' + data.total + ' cliente(s)')
                : '';
            btnPaginaAnterior.disabled = data.pagina <= 1;
            btnPaginaSiguiente.disabled = data.pagina >= data.total_paginas;
        }

        btnPaginaAnterior.addEventListener('click', function () {
            if (paginaActual > 1) cargarClientes(paginaActual - 1);
        });
        btnPaginaSiguiente.addEventListener('click', function () {
            if (paginaActual < totalPaginas) cargarClientes(paginaActual + 1);
        });

        inputBuscar.addEventListener('input', function () {
            clearTimeout(temporizadorBusqueda);
            temporizadorBusqueda = setTimeout(function () { cargarClientes(1); }, 250);
        });
        checkIncluirInactivos.addEventListener('change', function () {
            cargarClientes(1);
        });

        // -----------------------------------------------------------------
        // Modal: historial de compras de un cliente
        // -----------------------------------------------------------------
        function formatearMoneda(valor) {
            return '$' + Number(valor || 0).toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function badgeEstadoFactura(estado) {
            return (estado === 'anulada')
                ? '<span class="badge badge-anulada">Anulada</span>'
                : '<span class="badge badge-pagada">Pagada</span>';
        }

        function abrirHistorialCliente(idCliente) {
            document.getElementById('tituloClienteHistorial').textContent = '';
            document.getElementById('historialCantidadFacturas').textContent = '—';
            document.getElementById('historialTotalGastado').textContent = '—';
            document.getElementById('cuerpoHistorialCliente').innerHTML =
                '<tr><td colspan="5" class="text-center text-muted py-3">Cargando...</td></tr>';
            document.getElementById('historialVacio').style.display = 'none';

            modalHistorial.show();

            fetch('clientes.php?accion=historial_cliente&id=' + idCliente)
                .then(function (resp) { return resp.json(); })
                .then(function (data) {
                    if (data.error) {
                        mostrarAlerta(data.error, 'error');
                        modalHistorial.hide();
                        return;
                    }

                    document.getElementById('tituloClienteHistorial').textContent = data.cliente.nombre_completo;
                    document.getElementById('historialCantidadFacturas').textContent = data.cantidad_facturas;
                    document.getElementById('historialTotalGastado').textContent = formatearMoneda(data.total_gastado);

                    var cuerpo = document.getElementById('cuerpoHistorialCliente');
                    cuerpo.innerHTML = '';

                    if (!data.facturas.length) {
                        document.getElementById('historialVacio').style.display = 'block';
                        return;
                    }

                    data.facturas.forEach(function (f) {
                        var numeroFactura = String(f.id).padStart(6, '0');
                        var fila = document.createElement('tr');
                        fila.innerHTML =
                            '<td>#' + numeroFactura + '</td>' +
                            '<td>' + formatearFecha(f.fecha_emision) + '</td>' +
                            '<td>' + formatearMoneda(f.total_factura) + '</td>' +
                            '<td>' + badgeEstadoFactura(f.estado) + '</td>' +
                            '<td><a class="btn btn-sm btn-outline-secondary" href="factura.php?id=' + f.id + '" target="_blank">Ver factura</a></td>';
                        cuerpo.appendChild(fila);
                    });
                })
                .catch(function () {
                    mostrarAlerta('Error al cargar el historial de compras', 'error');
                    modalHistorial.hide();
                });
        }

        // -----------------------------------------------------------------
        // Modal: nuevo / editar
        // -----------------------------------------------------------------
        function abrirModalNuevo() {
            modalClienteTitulo.innerText = 'Nuevo Cliente';
            document.getElementById('clienteId').value = 0;
            document.getElementById('clienteNombre').value = '';
            document.getElementById('clienteCedula').value = '';
            document.getElementById('clienteCorreo').value = '';
            document.getElementById('clienteTelefono').value = '';
            document.getElementById('clienteDireccion').value = '';
            limpiarValidaciones();
            modalCliente.show();
        }

        function abrirModalEditar(c) {
            modalClienteTitulo.innerText = 'Editar Cliente';
            document.getElementById('clienteId').value = c.id;
            document.getElementById('clienteNombre').value = c.nombre_completo;
            document.getElementById('clienteCedula').value = c.cedula;
            document.getElementById('clienteCorreo').value = c.correo || '';
            document.getElementById('clienteTelefono').value = c.telefono || '';
            document.getElementById('clienteDireccion').value = c.direccion || '';
            limpiarValidaciones();
            modalCliente.show();
        }

        document.getElementById('btnNuevoCliente').addEventListener('click', abrirModalNuevo);

        // -----------------------------------------------------------------
        // Validación en tiempo real del formulario (mismo criterio que
        // usa pos.php al buscar cliente: cédula solo dígitos ≤10,
        // nombre solo letras, correo con formato válido / arroba obligatoria).
        // -----------------------------------------------------------------
        var campoNombre   = document.getElementById('clienteNombre');
        var campoCedula   = document.getElementById('clienteCedula');
        var campoCorreo   = document.getElementById('clienteCorreo');
        var campoTelefono = document.getElementById('clienteTelefono');

        function marcarError(campo, elError, mensaje) {
            campo.classList.add('is-invalid');
            elError.textContent = mensaje;
        }

        function marcarValido(campo, elError) {
            campo.classList.remove('is-invalid');
            elError.textContent = '';
        }

        function limpiarValidaciones() {
            [campoNombre, campoCedula, campoCorreo, campoTelefono].forEach(function (campo) {
                campo.classList.remove('is-invalid');
            });
            ['errorNombre', 'errorCedula', 'errorCorreo', 'errorTelefono'].forEach(function (id) {
                document.getElementById(id).textContent = '';
            });
        }

        function validarNombre() {
            var texto = campoNombre.value.trim();
            var elError = document.getElementById('errorNombre');

            if (texto === '') {
                marcarError(campoNombre, elError, 'El nombre es obligatorio');
                return false;
            }
            if (!/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s]+$/.test(texto)) {
                marcarError(campoNombre, elError, 'El nombre solo puede contener letras');
                return false;
            }
            marcarValido(campoNombre, elError);
            return true;
        }

        // Algoritmo oficial de validación de cédula ecuatoriana (módulo 10).
        // Reglas: 10 dígitos, código de provincia 01-24, tercer dígito 0-5,
        // y el último dígito debe coincidir con el dígito verificador calculado.
        function esCedulaEcuatorianaValida(cedula) {
            if (!/^\d{10}$/.test(cedula)) return false;

            var provincia = parseInt(cedula.substring(0, 2), 10);
            if (provincia < 1 || provincia > 24) return false;

            var tercerDigito = parseInt(cedula.charAt(2), 10);
            if (tercerDigito > 5) return false;

            var coeficientes = [2, 1, 2, 1, 2, 1, 2, 1, 2];
            var suma = 0;
            for (var i = 0; i < 9; i++) {
                var valor = parseInt(cedula.charAt(i), 10) * coeficientes[i];
                if (valor >= 10) valor -= 9;
                suma += valor;
            }

            var digitoVerificador = parseInt(cedula.charAt(9), 10);
            var residuo = suma % 10;
            var esperado = (residuo === 0) ? 0 : 10 - residuo;
            return esperado === digitoVerificador;
        }

        function validarCedula() {
            var texto = campoCedula.value.trim();
            var elError = document.getElementById('errorCedula');

            if (texto === '') {
                marcarError(campoCedula, elError, 'La cédula es obligatoria');
                return false;
            }
            if (!/^\d+$/.test(texto)) {
                marcarError(campoCedula, elError, 'La cédula solo puede contener números');
                return false;
            }
            if (texto.length !== 10) {
                marcarError(campoCedula, elError, 'La cédula debe tener exactamente 10 dígitos');
                return false;
            }
            if (!esCedulaEcuatorianaValida(texto)) {
                marcarError(campoCedula, elError, 'La cédula ingresada no es válida');
                return false;
            }
            marcarValido(campoCedula, elError);
            return true;
        }

        function validarCorreo() {
            var texto = campoCorreo.value.trim();
            var elError = document.getElementById('errorCorreo');

            // El correo es opcional: si está vacío, no hay error.
            if (texto === '') {
                marcarValido(campoCorreo, elError);
                return true;
            }

            // Formato básico obligatorio: algo@algo.dominio (arroba + punto después)
            var formatoValido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(texto);
            if (!formatoValido) {
                marcarError(campoCorreo, elError, 'El correo debe tener un formato válido (ej: nombre@dominio.com)');
                return false;
            }
            marcarValido(campoCorreo, elError);
            return true;
        }

        function validarTelefono() {
            var texto = campoTelefono.value.trim();
            var elError = document.getElementById('errorTelefono');

            // El teléfono es opcional.
            if (texto === '') {
                marcarValido(campoTelefono, elError);
                return true;
            }
            if (!/^\d{7,10}$/.test(texto)) {
                marcarError(campoTelefono, elError, 'El teléfono debe tener entre 7 y 10 dígitos');
                return false;
            }
            marcarValido(campoTelefono, elError);
            return true;
        }

        // Cédula: solo deja escribir dígitos y corta en 10 caracteres,
        // igual que se controla en el input del POS.
        campoCedula.addEventListener('input', function () {
            campoCedula.value = campoCedula.value.replace(/\D/g, '').slice(0, 10);
            validarCedula();
        });

        // Teléfono: mismo criterio, solo dígitos y máximo 10.
        campoTelefono.addEventListener('input', function () {
            campoTelefono.value = campoTelefono.value.replace(/\D/g, '').slice(0, 10);
            validarTelefono();
        });

        campoNombre.addEventListener('input', validarNombre);
        campoNombre.addEventListener('blur', validarNombre);
        campoCedula.addEventListener('blur', validarCedula);
        campoCorreo.addEventListener('input', validarCorreo);
        campoCorreo.addEventListener('blur', validarCorreo);
        campoTelefono.addEventListener('blur', validarTelefono);

        document.getElementById('btnGuardarCliente').addEventListener('click', function () {
            var nombreOk   = validarNombre();
            var cedulaOk   = validarCedula();
            var correoOk   = validarCorreo();
            var telefonoOk = validarTelefono();

            if (!nombreOk || !cedulaOk || !correoOk || !telefonoOk) {
                mostrarAlerta('Revisa los campos marcados en rojo', 'error');
                return;
            }

            var datos = {
                id: parseInt(document.getElementById('clienteId').value, 10) || 0,
                nombre_completo: campoNombre.value.trim(),
                cedula: campoCedula.value.trim(),
                correo: campoCorreo.value.trim(),
                telefono: campoTelefono.value.trim(),
                direccion: document.getElementById('clienteDireccion').value.trim()
            };

            fetch('clientes.php?accion=guardar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(datos)
            })
            .then(function (resp) { return resp.json(); })
            .then(function (res) {
                if (res.error) {
                    mostrarAlerta(res.error, 'error');
                    return;
                }
                mostrarAlerta(res.message, 'info');
                modalCliente.hide();
                cargarClientes(paginaActual);
            })
            .catch(function () {
                mostrarAlerta('Error al guardar el cliente', 'error');
            });
        });

        // -----------------------------------------------------------------
        // Eliminar (borrado lógico) / Reactivar
        // -----------------------------------------------------------------
        function eliminarCliente(id) {
            if (!confirm('¿Desactivar este cliente? No podrá usarse en nuevas ventas, pero sus facturas anteriores se conservan. Puedes reactivarlo cuando quieras.')) {
                return;
            }

            fetch('clientes.php?accion=eliminar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(function (resp) { return resp.json(); })
            .then(function (res) {
                if (res.error) {
                    mostrarAlerta(res.error, 'error');
                    return;
                }
                mostrarAlerta(res.message, 'info');
                cargarClientes(paginaActual);
            })
            .catch(function () {
                mostrarAlerta('Error al desactivar el cliente', 'error');
            });
        }

        function reactivarCliente(id) {
            if (!confirm('¿Reactivar este cliente para que vuelva a aparecer disponible en el sistema?')) {
                return;
            }

            fetch('clientes.php?accion=reactivar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(function (resp) { return resp.json(); })
            .then(function (res) {
                if (res.error) {
                    mostrarAlerta(res.error, 'error');
                    return;
                }
                mostrarAlerta(res.message, 'info');
                cargarClientes(paginaActual);
            })
            .catch(function () {
                mostrarAlerta('Error al reactivar el cliente', 'error');
            });
        }

        cargarClientes();
    </script>
</body>
</html>