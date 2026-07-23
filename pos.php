<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_activo'])) {
    header('Location: index.php');
    exit();
}
$usuario = $_SESSION['usuario_activo'];

require_once 'backend/conexion.php';

// ID real del cliente "Consumidor Final" (creado previamente en la BD).
// Se busca por cédula porque es el dato más estable para no depender
// de que el nombre esté escrito exactamente igual.
$stmtCF = $pdo->prepare("SELECT id FROM clientes WHERE cedula = ? LIMIT 1");
$stmtCF->execute(['9999999999']);
$consumidorFinalId = (int)($stmtCF->fetchColumn() ?: 0);

if ($consumidorFinalId === 0) {
    error_log('ADVERTENCIA: no se encontró el cliente Consumidor Final (cedula 9999999999)');
}

$accion = $_GET['accion'] ?? '';

// Búsqueda de cliente (AJAX)
if ($accion === 'buscar_cliente') {
    header('Content-Type: application/json; charset=UTF-8');
    $busqueda = $_GET['q'] ?? '';
    $stmt = $pdo->prepare("SELECT id, nombre_completo AS nombre, cedula FROM clientes WHERE nombre_completo LIKE ? OR cedula LIKE ? LIMIT 10");
    $stmt->execute(["%$busqueda%", "%$busqueda%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// Procesar venta (AJAX)
if ($accion === 'procesar_venta') {
    header('Content-Type: application/json; charset=UTF-8');
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['productos'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No hay productos en la venta']);
        exit();
    }

    $clienteIdRecibido = (int)($input['cliente_id'] ?? 0);
    $clienteId = $clienteIdRecibido > 0 ? $clienteIdRecibido : $consumidorFinalId;

    try {
        $pdo->beginTransaction();

        $stmtCheckStock = $pdo->prepare("SELECT nombre_producto, stock_disponible FROM productos WHERE id = ? FOR UPDATE");
        foreach ($input['productos'] as $p) {
            $stmtCheckStock->execute([$p['id']]);
            $prodDb = $stmtCheckStock->fetch();

            if (!$prodDb) {
                throw new Exception('El producto con id ' . $p['id'] . ' ya no existe');
            }
            if ((int)$prodDb['stock_disponible'] < (int)$p['cantidad']) {
                throw new Exception('Stock insuficiente para "' . $prodDb['nombre_producto'] . '". Disponible: ' . $prodDb['stock_disponible']);
            }
        }

        $stmt = $pdo->prepare("INSERT INTO ventas (cliente_id, usuario_id, total_factura, pago, cambio, estado, fecha_emision) VALUES (?, ?, ?, ?, ?, 'pagada', NOW())");
        $stmt->execute([
            $clienteId,
            $usuario['id'],
            $input['total'],
            $input['pago'],
            $input['cambio']
        ]);
        $ventaId = $pdo->lastInsertId();

        $stmtDetalle = $pdo->prepare("INSERT INTO detalles_venta (venta_id, producto_id, cantidad, precio_congelado) VALUES (?, ?, ?, ?)");
        $stmtStock = $pdo->prepare("UPDATE productos SET stock_disponible = stock_disponible - ? WHERE id = ?");

        foreach ($input['productos'] as $p) {
            $stmtDetalle->execute([$ventaId, $p['id'], $p['cantidad'], $p['precio']]);
            $stmtStock->execute([$p['cantidad'], $p['id']]);
        }

        $pdo->commit();
        echo json_encode(['venta_id' => $ventaId]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Punto de Venta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="frontend/css/dashboard.css">
    <style>
        .pos-content{
            margin-left: 280px;
            padding: 20px;
        }
        #buscarProducto{
            font-size: 1.4rem;
            padding: 14px;
        }
        .btn-procesar{
            background-color: var(--verde-oscuro);
            color: #fff;
            font-size: 1.3rem;
            font-weight: bold;
            padding: 14px;
        }
        .btn-procesar:hover{
            background-color: var(--verde-medio);
            color: #fff;
        }
        .totales p{
            font-size: 1.1rem;
            margin-bottom: 6px;
        }
        .totales .total-final{
            font-size: 1.6rem;
            font-weight: bold;
            color: var(--verde-oscuro);
        }
        .btn-cant{
            width: 28px;
        }

        .autocomplete-dropdown{
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1050;
            background: #fff;
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid #ddd;
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
            display: none;
        }
        .autocomplete-item{
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        .autocomplete-item:last-child{
            border-bottom: none;
        }
        .autocomplete-item small{
            display: block;
            color: #666;
        }
        .autocomplete-item:hover,
        .autocomplete-item.activo{
            background-color: var(--fondo-gris, #eef4ef);
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php include __DIR__ . '/backend/includes/sidebar.php'; ?>

        <div class="pos-content w-100">
            <h3 class="mb-3">Punto de Venta</h3>

            <div class="row">
                <div class="col-lg-8">
                    <div class="position-relative mb-3">
                        <input type="text" id="buscarProducto" class="form-control" placeholder="Escanee o escriba el código / nombre del producto" autocomplete="off" autofocus>
                        <div id="resultadosProducto" class="autocomplete-dropdown"></div>
                    </div>

                    <button type="button" id="btnEscanearCodigo" class="btn btn-outline-primary mb-3">
                        📷 Escanear Código
                    </button>

                    <div id="contenedorLectorCamara" style="display:none;" class="mb-3">
                        <div id="lector-camara-video" style="width:100%; max-width:400px; margin:0 auto;"></div>
                        <button type="button" id="btnCerrarLector" class="btn btn-sm btn-outline-danger mt-2">Cerrar cámara</button>
                    </div>

                    <table class="table table-hover bg-white">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Precio</th>
                                <th>Cantidad</th>
                                <th>Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoCarrito">
                            <tr>
                                <td colspan="5" class="text-center text-muted">El carrito está vacío</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="col-lg-4">
                    <div class="card p-3 mb-3">
                        <label class="form-label fw-bold">Cliente</label>
                        <div class="position-relative">
                            <input type="text" id="buscarCliente" class="form-control" placeholder="Buscar cliente por nombre o cédula..." autocomplete="off">
                            <div id="resultadosCliente" class="autocomplete-dropdown"></div>
                        </div>
                        <div id="errorCliente" class="text-danger small mt-1" style="display:none;"></div>
                        <button type="button" id="btnConsumidorFinal" class="btn btn-outline-secondary btn-sm mt-2">Consumidor Final</button>
                        <p class="mt-2 mb-0"><strong>Seleccionado:</strong> <span id="nombreClienteSel">Consumidor Final</span></p>
                        <input type="hidden" id="clienteId" value="<?= $consumidorFinalId ?>">
                    </div>

                    <div class="card p-3 mb-3 totales">
                        <p>Subtotal: <span id="txtSubtotal">$0.00</span></p>
                        <p>IVA (15%): <span id="txtIva">$0.00</span></p>
                        <p class="total-final">Total: <span id="txtTotal">$0.00</span></p>
                    </div>

                    <div class="card p-3 mb-3">
                        <label class="form-label fw-bold">Paga con</label>
                        <input type="number" id="montoPago" class="form-control mb-2" placeholder="0.00" step="0.01">
                        <p class="mb-0">Cambio: <span id="txtCambio">$0.00</span></p>
                    </div>

                    <button type="button" id="btnProcesar" class="btn btn-procesar w-100">PROCESAR VENTA</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script>
        var carrito = [];
        var clienteSeleccionado = { id: <?= $consumidorFinalId ?>, nombre: "Consumidor Final" };
        var IVA_PORC = 0.15;

        var inputBuscar = document.getElementById('buscarProducto');
        var cuerpoCarrito = document.getElementById('cuerpoCarrito');

        // ---------------------------------------------------------------
        // Alertas propias (reemplazan a alert() nativo del navegador,
        // que no se puede estilizar). tipo: 'error' (rojo) o 'info' (verde).
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

        // ---------------------------------------------------------------
        // Autocompletado genérico: dropdown con flechas ↑/↓, Enter, clic
        // ---------------------------------------------------------------
        function crearAutocomplete(opciones) {
            var inputEl = opciones.inputEl;
            var dropdownEl = opciones.dropdownEl;
            var indiceActivo = -1;
            var itemsActuales = [];
            var temporizador = null;

            function cerrar() {
                dropdownEl.style.display = 'none';
                dropdownEl.innerHTML = '';
                indiceActivo = -1;
                itemsActuales = [];
            }

            function resaltar() {
                var hijos = dropdownEl.children;
                for (var i = 0; i < hijos.length; i++) {
                    hijos[i].classList.toggle('activo', i === indiceActivo);
                }
                if (indiceActivo >= 0 && hijos[indiceActivo]) {
                    hijos[indiceActivo].scrollIntoView({ block: 'nearest' });
                }
            }

            function seleccionar(item) {
                opciones.onSelect(item);
                cerrar();
            }

            function render(items) {
                itemsActuales = items || [];
                indiceActivo = -1;
                dropdownEl.innerHTML = '';

                if (!itemsActuales.length) {
                    dropdownEl.style.display = 'none';
                    return;
                }

                itemsActuales.forEach(function (item, i) {
                    var div = document.createElement('div');
                    div.className = 'autocomplete-item';
                    div.innerHTML = opciones.renderItem(item);
                    div.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        seleccionar(item);
                    });
                    dropdownEl.appendChild(div);
                });

                dropdownEl.style.display = 'block';
            }

            inputEl.addEventListener('input', function () {
                var texto = inputEl.value.trim();
                clearTimeout(temporizador);

                if (texto.length < (opciones.minChars || 1)) {
                    cerrar();
                    return;
                }

                temporizador = setTimeout(function () {
                    opciones.fetchItems(texto).then(render);
                }, opciones.debounceMs || 200);
            });

            inputEl.addEventListener('keydown', function (e) {
                if (dropdownEl.style.display === 'none' || !itemsActuales.length) {
                    return;
                }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    indiceActivo = Math.min(indiceActivo + 1, itemsActuales.length - 1);
                    resaltar();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    indiceActivo = Math.max(indiceActivo - 1, 0);
                    resaltar();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var elegido = indiceActivo >= 0 ? itemsActuales[indiceActivo] : itemsActuales[0];
                    if (elegido) seleccionar(elegido);
                } else if (e.key === 'Escape') {
                    cerrar();
                }
            });

            document.addEventListener('click', function (e) {
                if (e.target !== inputEl && !dropdownEl.contains(e.target)) {
                    cerrar();
                }
            });

            return {
                cerrar: cerrar,
                estaAbierto: function () {
                    return dropdownEl.style.display !== 'none' && itemsActuales.length > 0;
                }
            };
        }

        var acProducto = crearAutocomplete({
            inputEl: inputBuscar,
            dropdownEl: document.getElementById('resultadosProducto'),
            minChars: 1,
            debounceMs: 150,
            fetchItems: function (texto) {
                return fetch('backend/api_productos.php?q=' + encodeURIComponent(texto))
                    .then(function (resp) { return resp.json(); })
                    .catch(function () { return []; });
            },
            renderItem: function (p) {
                return p.nombre_producto +
                    ' <small>Código: ' + (p.codigo_barras || '-') +
                    ' · $' + parseFloat(p.precio_actual).toFixed(2) +
                    ' · Stock: ' + p.stock_disponible + '</small>';
            },
            onSelect: function (p) {
                agregarAlCarrito(p);
                inputBuscar.value = '';
                inputBuscar.focus();
            }
        });

        inputBuscar.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                if (acProducto.estaAbierto()) {
                    return;
                }
                e.preventDefault();
                var texto = inputBuscar.value.trim();
                if (texto === '') return;
                buscarYAgregarProducto(texto);
            }
        });

        function buscarYAgregarProducto(texto) {
            fetch('backend/api_productos.php?q=' + encodeURIComponent(texto))
                .then(function (resp) { return resp.text(); })
                .then(function (texto2) {
                    var productos;
                    try {
                        productos = JSON.parse(texto2);
                    } catch (err) {
                        console.error('Respuesta no es JSON válido:', texto2);
                        mostrarAlerta('Error al buscar producto, revisa la consola (F12)', 'error');
                        return;
                    }

                    if (!productos || !productos.length || productos.error) {
                        mostrarAlerta(productos && productos.error ? productos.error : 'Producto no encontrado', 'error');
                        inputBuscar.value = '';
                        inputBuscar.focus();
                        return;
                    }
                    agregarAlCarrito(productos[0]);
                    inputBuscar.value = '';
                    inputBuscar.focus();
                })
                .catch(function (err) {
                    console.error('Error de red al buscar producto:', err);
                    mostrarAlerta('Error al buscar el producto', 'error');
                });
        }

        var btnEscanear = document.getElementById('btnEscanearCodigo');
        var btnCerrarLector = document.getElementById('btnCerrarLector');
        var contenedorLector = document.getElementById('contenedorLectorCamara');
        var html5QrCode = null;

        var formatosCodigoBarras = [
            Html5QrcodeSupportedFormats.EAN_13,
            Html5QrcodeSupportedFormats.EAN_8,
            Html5QrcodeSupportedFormats.UPC_A,
            Html5QrcodeSupportedFormats.UPC_E,
            Html5QrcodeSupportedFormats.CODE_128,
            Html5QrcodeSupportedFormats.CODE_39
        ];

        btnEscanear.addEventListener('click', function () {
            contenedorLector.style.display = 'block';

            html5QrCode = new Html5Qrcode('lector-camara-video', {
                formatsToSupport: formatosCodigoBarras
            });

            Html5Qrcode.getCameras().then(function (camaras) {
                if (!camaras || !camaras.length) {
                    mostrarAlerta('No se encontró ninguna cámara disponible', 'error');
                    contenedorLector.style.display = 'none';
                    return;
                }

                var camaraElegida = camaras.length === 1
                    ? camaras[0].id
                    : (camaras.find(function (c) { return /back|rear|trasera|environment/i.test(c.label); }) || camaras[camaras.length - 1]).id;

                html5QrCode.start(
                    camaraElegida,
                    { fps: 10, qrbox: { width: 250, height: 150 } },
                    function onCodigoDetectado(codigoDecodificado) {
                        detenerLector();
                        codigoDecodificado = codigoDecodificado.trim();
                        mostrarAlerta('La cámara leyó: [' + codigoDecodificado + ']', 'info');
                        buscarYAgregarProducto(codigoDecodificado);
                    },
                    function onErrorEscaneo(mensajeError) {
                        // Se ignora: ocurre en cada frame donde todavía no hay código legible
                    }
                ).catch(function (err) {
                    mostrarAlerta('No se pudo acceder a la cámara: ' + err, 'error');
                    contenedorLector.style.display = 'none';
                });
            }).catch(function (err) {
                mostrarAlerta('No se pudo obtener acceso a la cámara: ' + err, 'error');
                contenedorLector.style.display = 'none';
            });
        });

        function detenerLector() {
            if (!html5QrCode) {
                contenedorLector.style.display = 'none';
                return;
            }
            html5QrCode.stop().then(function () {
                html5QrCode.clear();
                contenedorLector.style.display = 'none';
            }).catch(function () {
                contenedorLector.style.display = 'none';
            });
        }

        btnCerrarLector.addEventListener('click', detenerLector);

        function agregarAlCarrito(producto) {
            var stockDisponible = parseInt(producto.stock_disponible, 10);

            if (stockDisponible <= 0) {
                mostrarAlerta('"' + producto.nombre_producto + '" no tiene stock disponible', 'error');
                return;
            }

            var existente = carrito.find(function (item) { return item.id == producto.id; });

            if (existente) {
                if (existente.cantidad + 1 > existente.stock) {
                    mostrarAlerta('No hay más stock disponible de "' + existente.nombre + '" (máximo: ' + existente.stock + ')', 'error');
                    return;
                }
                existente.cantidad++;
            } else {
                carrito.push({
                    id: producto.id,
                    nombre: producto.nombre_producto,
                    precio: parseFloat(producto.precio_actual),
                    stock: stockDisponible,
                    cantidad: 1
                });
            }
            renderCarrito();
        }

        function cambiarCantidad(id, delta) {
            var item = carrito.find(function (i) { return i.id == id; });
            if (!item) return;

            if (delta > 0 && item.cantidad + 1 > item.stock) {
                mostrarAlerta('No hay más stock disponible de "' + item.nombre + '" (máximo: ' + item.stock + ')', 'error');
                return;
            }

            item.cantidad += delta;
            if (item.cantidad <= 0) {
                carrito = carrito.filter(function (i) { return i.id != id; });
            }
            renderCarrito();
        }

        function quitarDelCarrito(id) {
            carrito = carrito.filter(function (i) { return i.id != id; });
            renderCarrito();
        }

        function renderCarrito() {
            cuerpoCarrito.innerHTML = '';

            if (carrito.length === 0) {
                cuerpoCarrito.innerHTML = '<tr><td colspan="5" class="text-center text-muted">El carrito está vacío</td></tr>';
                calcularTotales();
                return;
            }

            carrito.forEach(function (item) {
                var subtotal = item.precio * item.cantidad;
                var fila = document.createElement('tr');
                fila.innerHTML =
                    '<td>' + item.nombre + '</td>' +
                    '<td>$' + item.precio.toFixed(2) + '</td>' +
                    '<td>' +
                        '<button class="btn btn-sm btn-outline-secondary btn-cant" onclick="cambiarCantidad(' + item.id + ', -1)">-</button> ' +
                        item.cantidad +
                        ' <button class="btn btn-sm btn-outline-secondary btn-cant" onclick="cambiarCantidad(' + item.id + ', 1)">+</button>' +
                    '</td>' +
                    '<td>$' + subtotal.toFixed(2) + '</td>' +
                    '<td><button class="btn btn-sm btn-danger" onclick="quitarDelCarrito(' + item.id + ')">x</button></td>';
                cuerpoCarrito.appendChild(fila);
            });

            calcularTotales();
        }

        function calcularTotales() {
            var subtotal = carrito.reduce(function (sum, item) {
                return sum + item.precio * item.cantidad;
            }, 0);
            var iva = subtotal * IVA_PORC;
            var total = subtotal + iva;

            document.getElementById('txtSubtotal').innerText = '$' + subtotal.toFixed(2);
            document.getElementById('txtIva').innerText = '$' + iva.toFixed(2);
            document.getElementById('txtTotal').innerText = '$' + total.toFixed(2);

            calcularCambio();
        }

        function validarClienteInput(texto) {
            var soloDigitos = /^\d+$/.test(texto);
            var soloLetras = /^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s]+$/.test(texto);

            if (soloDigitos) {
                if (texto.length > 10) {
                    return { valido: false, mensaje: 'Cédula no válida (máximo 10 dígitos)' };
                }
                return { valido: true, mensaje: null };
            }

            if (soloLetras) {
                return { valido: true, mensaje: null };
            }

            return { valido: false, mensaje: 'Caracteres inválidos' };
        }

        var inputCliente = document.getElementById('buscarCliente');
        var errorCliente = document.getElementById('errorCliente');

        function mostrarErrorCliente(mensaje) {
            errorCliente.textContent = mensaje;
            errorCliente.style.display = 'block';
            inputCliente.classList.add('is-invalid');
        }

        function ocultarErrorCliente() {
            errorCliente.style.display = 'none';
            errorCliente.textContent = '';
            inputCliente.classList.remove('is-invalid');
        }

        var acCliente = crearAutocomplete({
            inputEl: inputCliente,
            dropdownEl: document.getElementById('resultadosCliente'),
            minChars: 1,
            debounceMs: 200,
            fetchItems: function (texto) {
                var validacion = validarClienteInput(texto);

                if (!validacion.valido) {
                    mostrarErrorCliente(validacion.mensaje);
                    return Promise.resolve([]);
                }

                ocultarErrorCliente();

                return fetch('pos.php?accion=buscar_cliente&q=' + encodeURIComponent(texto))
                    .then(function (resp) { return resp.json(); })
                    .catch(function () { return []; });
            },
            renderItem: function (c) {
                return c.nombre + (c.cedula ? ' <small>' + c.cedula + '</small>' : '');
            },
            onSelect: function (c) {
                seleccionarCliente(c.id, c.nombre);
            }
        });

        inputCliente.addEventListener('input', function () {
            if (inputCliente.value.trim() === '') {
                ocultarErrorCliente();
            }
        });

        function seleccionarCliente(id, nombre) {
            clienteSeleccionado = { id: id, nombre: nombre };
            document.getElementById('clienteId').value = id;
            document.getElementById('nombreClienteSel').innerText = nombre;
            inputCliente.value = '';
            ocultarErrorCliente();
            inputBuscar.focus();
        }

        document.getElementById('btnConsumidorFinal').addEventListener('click', function () {
            seleccionarCliente(<?= $consumidorFinalId ?>, 'Consumidor Final');
        });

        var inputPago = document.getElementById('montoPago');
        inputPago.addEventListener('input', calcularCambio);

        function calcularCambio() {
            var total = parseFloat(document.getElementById('txtTotal').innerText.replace('$', '')) || 0;
            var pago = parseFloat(inputPago.value) || 0;
            var cambio = pago - total;
            document.getElementById('txtCambio').innerText = '$' + (cambio > 0 ? cambio.toFixed(2) : '0.00');
        }

        document.getElementById('btnProcesar').addEventListener('click', function () {
            if (carrito.length === 0) {
                mostrarAlerta('Agregue al menos un producto', 'error');
                return;
            }

            var total = parseFloat(document.getElementById('txtTotal').innerText.replace('$', ''));
            var pago = parseFloat(inputPago.value) || 0;

            if (pago < total) {
                mostrarAlerta('El monto pagado es menor al total', 'error');
                return;
            }

            var subtotal = parseFloat(document.getElementById('txtSubtotal').innerText.replace('$', ''));
            var iva = parseFloat(document.getElementById('txtIva').innerText.replace('$', ''));
            var cambio = pago - total;

            var datos = {
                cliente_id: clienteSeleccionado.id,
                subtotal: subtotal,
                iva: iva,
                total: total,
                pago: pago,
                cambio: cambio,
                productos: carrito.map(function (item) {
                    return { id: item.id, cantidad: item.cantidad, precio: item.precio };
                })
            };

            fetch('pos.php?accion=procesar_venta', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(datos)
            })
            .then(function (resp) { return resp.json(); })
            .then(function (res) {
                if (res.venta_id) {
                    window.open('factura.php?id=' + res.venta_id, '_blank');
                    carrito = [];
                    renderCarrito();
                    inputPago.value = '';
                    document.getElementById('txtCambio').innerText = '$0.00';
                    seleccionarCliente(0, 'Consumidor Final');
                    inputBuscar.focus();
                } else {
                    mostrarAlerta(res.error || 'No se pudo procesar la venta', 'error');
                }
            })
            .catch(function () {
                mostrarAlerta('Error al procesar la venta', 'error');
            });
        });

        renderCarrito();

        var camposQueConservanFoco = ['buscarCliente', 'montoPago'];

        document.addEventListener('click', function (e) {
            if (camposQueConservanFoco.indexOf(e.target.id) !== -1) {
                return;
            }
            setTimeout(function () {
                var activo = document.activeElement.id;
                if (camposQueConservanFoco.indexOf(activo) === -1) {
                    inputBuscar.focus();
                }
            }, 50);
        });
    </script>
</body>
</html>