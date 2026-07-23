<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_activo'])) {
    header('Location: index.php');
    exit();
}
$usuario = $_SESSION['usuario_activo'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="frontend/css/dashboard.css">
    <style>
        /* Estilos propios de esta vista: reutilizan las variables ya
           definidas en dashboard.css (mismo criterio que pos.php e
           historial.php) para mantener coherencia visual. */
        .catalogo-content{
            margin-left: 280px;
            padding: 24px 28px 40px;
        }

        /* ---------- Barra de herramientas (buscador + acción) ---------- */
        .toolbar-catalogo{
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 18px;
        }
        .buscador-wrap{
            position: relative;
            flex: 1 1 320px;
        }
        .buscador-wrap i{
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--texto-secundario);
        }
        #buscarCatalogo{
            padding: 12px 14px 12px 42px;
            border-radius: var(--radio-md);
            border: 1px solid var(--borde-suave);
        }
        #buscarCatalogo:focus{
            box-shadow: var(--sombra-glow);
            border-color: var(--verde-claro);
        }

        /* ---------- Tarjetas resumen ---------- */
        .resumen-catalogo{
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }
        .resumen-card{
            background: #fff;
            border-radius: var(--radio-md);
            padding: 16px 18px;
            box-shadow: var(--sombra-sm);
            border-left: 5px solid var(--verde-medio);
        }
        .resumen-card.alerta{ border-left-color: #d90429; }
        .resumen-card .valor{
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: 1.6rem;
            color: var(--verde-oscuro);
        }
        .resumen-card.alerta .valor{ color: #d90429; }
        .resumen-card .etiqueta{
            font-size: 0.82rem;
            color: var(--texto-secundario);
            text-transform: uppercase;
            letter-spacing: .03em;
            font-weight: 600;
        }

        /* ---------- Grilla de productos ---------- */
        .grid-productos{
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 18px;
        }
        .producto-card{
            background: #fff;
            border-radius: var(--radio-lg);
            box-shadow: var(--sombra-sm);
            border: 1px solid var(--borde-suave);
            border-top: 4px solid var(--verde-medio);
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: var(--transicion);
            animation: fundido-arriba 0.3s ease;
        }
        .producto-card:hover{
            transform: translateY(-4px);
            box-shadow: var(--sombra-md);
        }
        .producto-card.stock-bajo{ border-top-color: #d90429; }
        .producto-card.stock-medio{ border-top-color: #f4a300; }

        .producto-codigo{
            display: inline-block;
            font-family: 'Courier New', monospace;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: .04em;
            color: var(--verde-oscuro);
            background: var(--verde-suave);
            padding: 3px 10px;
            border-radius: 20px;
            width: fit-content;
        }
        .producto-nombre{
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 1.02rem;
            color: var(--texto-principal);
            line-height: 1.3;
            min-height: 2.6em;
        }
        .producto-precio{
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: 1.5rem;
            color: var(--verde-oscuro);
        }
        .producto-footer{
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: auto;
            padding-top: 10px;
            border-top: 1px dashed var(--borde-suave);
        }
        .producto-acciones{
            display: flex;
            gap: 6px;
        }
        .producto-acciones .btn{
            padding: 5px 10px;
            font-size: .82rem;
        }

        /* Badges de stock (mismos tonos que .badge-pagada/.badge-anulada) */
        .badge-stock-bajo{
            background: linear-gradient(135deg, #e5383b 0%, #d90429 100%) !important;
        }
        .badge-stock-medio{
            background: linear-gradient(135deg, #f4a300 0%, #d98c00 100%) !important;
        }
        .badge-stock-ok{
            background: var(--degradado-verde-claro) !important;
        }
        .badge-stock{
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.78rem;
            padding: 5px 10px;
            border-radius: 20px;
            color: #fff;
            font-weight: 600;
        }

        .producto-imagen{
            width: 100%;
            height: 140px;
            border-radius: var(--radio-md);
            overflow: hidden;
            background: var(--verde-suave);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .producto-imagen img{
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .producto-imagen .sin-imagen{
            font-size: 2.2rem;
            opacity: .5;
        }

        .preview-imagen-wrap{
            width: 100%;
            height: 150px;
            border: 2px dashed var(--borde-suave);
            border-radius: var(--radio-md);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: var(--verde-suave);
            margin-bottom: 8px;
        }
        .preview-imagen-wrap img{
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .estado-vacio{
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            color: var(--texto-secundario);
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php include __DIR__ . '/backend/includes/sidebar.php'; ?>

        <div class="catalogo-content w-100" id="content">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h3 class="mb-3">Catálogo de Productos</h3>
                <button type="button" class="btn btn-filtrar" data-bs-toggle="modal" data-bs-target="#modalProducto" onclick="abrirModalNuevo()">
                    + Nuevo Producto
                </button>
            </div>

            <!-- Resumen rápido -->
            <div class="resumen-catalogo">
                <div class="resumen-card">
                    <div class="valor" id="resumenTotal">-</div>
                    <div class="etiqueta">Productos registrados</div>
                </div>
                <div class="resumen-card">
                    <div class="valor" id="resumenValorInventario">-</div>
                    <div class="etiqueta">Valor total en stock</div>
                </div>
                <div class="resumen-card alerta">
                    <div class="valor" id="resumenStockBajo">-</div>
                    <div class="etiqueta">Con stock bajo o agotado</div>
                </div>
            </div>

            <!-- Buscador -->
            <div class="toolbar-catalogo">
                <div class="buscador-wrap">
                    <i>🔎</i>
                    <input type="text" id="buscarCatalogo" class="form-control" placeholder="Buscar por nombre o código de barras..." autocomplete="off">
                </div>
            </div>

            <!-- Grilla de productos -->
            <div class="grid-productos" id="grillaCatalogo">
                <div class="estado-vacio">Cargando productos...</div>
            </div>
        </div>
    </div>

    <!-- Modal Crear / Editar Producto -->
    <div class="modal fade" id="modalProducto" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-white" id="tituloModalProducto">Nuevo Producto</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formProducto">
                        <input type="hidden" id="productoId">
                        <input type="hidden" id="imagenActual">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Foto del producto</label>
                            <div class="preview-imagen-wrap" id="previewImagenWrap">
                                <span class="sin-imagen" id="previewSinImagen">🖼️</span>
                                <img id="previewImagen" src="" alt="" style="display:none;">
                            </div>
                            <input type="file" id="imagenProducto" class="form-control" accept="image/png, image/jpeg, image/webp">
                            <div class="form-text">JPG, PNG o WEBP. Máximo 3 MB. Opcional.</div>
                            <div class="invalid-feedback" id="errorImagenProducto"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Código de barras</label>
                            <input type="text" id="codigoBarras" class="form-control" placeholder="Ej. PROD-001" required>
                            <div class="invalid-feedback" id="errorCodigoBarras"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre del producto</label>
                            <input type="text" id="nombreProducto" class="form-control" placeholder="Ej. Laptop Dell XPS 13" required>
                            <div class="invalid-feedback" id="errorNombreProducto"></div>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">Precio actual</label>
                                <input type="number" id="precioActual" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                                <div class="invalid-feedback" id="errorPrecioActual"></div>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">Stock disponible</label>
                                <input type="number" id="stockDisponible" class="form-control" step="1" min="0" placeholder="0" required>
                                <div class="invalid-feedback" id="errorStockDisponible"></div>
                            </div>
                        </div>

                        <div id="errorGeneralProducto" class="alert alert-danger py-2 d-none"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-filtrar" id="btnGuardarProducto">Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Confirmar Eliminación -->
    <div class="modal fade" id="modalEliminarProducto" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-white">Eliminar producto</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">¿Seguro que deseas eliminar <strong id="nombreProductoEliminar"></strong> del catálogo?</p>
                    <p class="text-muted small mb-0">Esta acción no se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarEliminar">Eliminar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var API_URL = 'backend/api_productos.php';
        var grillaCatalogo = document.getElementById('grillaCatalogo');
        var inputBuscar = document.getElementById('buscarCatalogo');
        var idAEliminar = null;
        var temporizadorBusqueda = null;

        var modalProducto = new bootstrap.Modal(document.getElementById('modalProducto'));
        var modalEliminar = new bootstrap.Modal(document.getElementById('modalEliminarProducto'));

        // ---------------------------------------------------------------
        // Cargar / listar productos (usa backend/api_productos.php,
        // que consulta con PDO y sentencias preparadas)
        // ---------------------------------------------------------------
        function cargarProductos(texto) {
            texto = texto || '';
            grillaCatalogo.innerHTML = '<div class="estado-vacio">Cargando productos...</div>';

            fetch(API_URL + '?q=' + encodeURIComponent(texto))
                .then(function (resp) { return resp.json(); })
                .then(function (productos) {
                    renderGrilla(productos);
                })
                .catch(function () {
                    grillaCatalogo.innerHTML = '<div class="estado-vacio text-danger">Error al cargar el catálogo</div>';
                });
        }

        function nivelStock(stock) {
            stock = parseInt(stock, 10);
            if (stock <= 0) return 'bajo';
            if (stock <= 5) return 'medio';
            return 'ok';
        }

        function badgeStock(stock) {
            var nivel = nivelStock(stock);
            var stockNum = parseInt(stock, 10);
            var clase = 'badge-stock-ok';
            var texto = stockNum + ' unid.';
            var icono = '✅';
            if (nivel === 'bajo') {
                clase = 'badge-stock-bajo';
                texto = stockNum <= 0 ? 'Agotado' : stockNum + ' unid.';
                icono = '⛔';
            } else if (nivel === 'medio') {
                clase = 'badge-stock-medio';
                icono = '⚠️';
            }
            return '<span class="badge-stock ' + clase + '">' + icono + ' ' + texto + '</span>';
        }

        function escaparHtml(texto) {
            var div = document.createElement('div');
            div.textContent = texto == null ? '' : texto;
            return div.innerHTML;
        }

        function actualizarResumen(productos) {
            var total = productos.length;
            var valorInventario = productos.reduce(function (acc, p) {
                return acc + (parseFloat(p.precio_actual) * parseInt(p.stock_disponible, 10));
            }, 0);
            var stockBajo = productos.filter(function (p) {
                return nivelStock(p.stock_disponible) !== 'ok';
            }).length;

            document.getElementById('resumenTotal').textContent = total;
            document.getElementById('resumenValorInventario').textContent =
                '$' + valorInventario.toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('resumenStockBajo').textContent = stockBajo;
        }

        function renderGrilla(productos) {
            if (!productos || productos.error) {
                grillaCatalogo.innerHTML = '<div class="estado-vacio text-danger">' +
                    (productos && productos.error ? escaparHtml(productos.error) : 'Error al cargar productos') +
                    '</div>';
                return;
            }

            if (!productos.length) {
                grillaCatalogo.innerHTML = '<div class="estado-vacio">No se encontraron productos</div>';
                document.getElementById('resumenTotal').textContent = '0';
                document.getElementById('resumenValorInventario').textContent = '$0.00';
                document.getElementById('resumenStockBajo').textContent = '0';
                return;
            }

            actualizarResumen(productos);
            grillaCatalogo.innerHTML = '';

            productos.forEach(function (p) {
                var nivel = nivelStock(p.stock_disponible);
                var claseCard = nivel === 'bajo' ? 'stock-bajo' : (nivel === 'medio' ? 'stock-medio' : '');

                var imagenHtml = p.imagen_url
                    ? '<div class="producto-imagen"><img src="' + escaparHtml(p.imagen_url) + '" alt="' + escaparHtml(p.nombre_producto) + '" loading="lazy"></div>'
                    : '<div class="producto-imagen"><span class="sin-imagen">🖼️</span></div>';

                var card = document.createElement('div');
                card.className = 'producto-card ' + claseCard;
                card.innerHTML =
                    imagenHtml +
                    '<span class="producto-codigo">' + escaparHtml(p.codigo_barras || '-') + '</span>' +
                    '<div class="producto-nombre">' + escaparHtml(p.nombre_producto) + '</div>' +
                    '<div class="producto-precio">$' + parseFloat(p.precio_actual).toFixed(2) + '</div>' +
                    badgeStock(p.stock_disponible) +
                    '<div class="producto-footer">' +
                        '<div class="producto-acciones">' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary" onclick=\'abrirModalEditar(' + JSON.stringify(p) + ')\'>✏️ Editar</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-danger" onclick=\'abrirModalEliminar(' + p.id + ', ' + JSON.stringify(p.nombre_producto) + ')\'>🗑️ Eliminar</button>' +
                        '</div>' +
                    '</div>';
                grillaCatalogo.appendChild(card);
            });
        }

        inputBuscar.addEventListener('input', function () {
            var texto = inputBuscar.value.trim();
            clearTimeout(temporizadorBusqueda);
            temporizadorBusqueda = setTimeout(function () {
                cargarProductos(texto);
            }, 250);
        });

        // ---------------------------------------------------------------
        // Modal crear / editar
        // ---------------------------------------------------------------
        function limpiarErroresProducto() {
            ['codigoBarras', 'nombreProducto', 'precioActual', 'stockDisponible', 'imagenProducto'].forEach(function (id) {
                document.getElementById(id).classList.remove('is-invalid');
            });
            document.getElementById('errorGeneralProducto').classList.add('d-none');
            document.getElementById('errorGeneralProducto').textContent = '';
        }

        function mostrarPreview(src) {
            var img = document.getElementById('previewImagen');
            var sinImagen = document.getElementById('previewSinImagen');
            if (src) {
                img.src = src;
                img.style.display = 'block';
                sinImagen.style.display = 'none';
            } else {
                img.src = '';
                img.style.display = 'none';
                sinImagen.style.display = 'block';
            }
        }

        function abrirModalNuevo() {
            limpiarErroresProducto();
            document.getElementById('tituloModalProducto').textContent = 'Nuevo Producto';
            document.getElementById('productoId').value = '';
            document.getElementById('imagenActual').value = '';
            document.getElementById('codigoBarras').value = '';
            document.getElementById('nombreProducto').value = '';
            document.getElementById('precioActual').value = '';
            document.getElementById('stockDisponible').value = '';
            document.getElementById('imagenProducto').value = '';
            mostrarPreview(null);
        }

        function abrirModalEditar(producto) {
            limpiarErroresProducto();
            document.getElementById('tituloModalProducto').textContent = 'Editar Producto';
            document.getElementById('productoId').value = producto.id;
            document.getElementById('imagenActual').value = producto.imagen || '';
            document.getElementById('codigoBarras').value = producto.codigo_barras || '';
            document.getElementById('nombreProducto').value = producto.nombre_producto;
            document.getElementById('precioActual').value = producto.precio_actual;
            document.getElementById('stockDisponible').value = producto.stock_disponible;
            document.getElementById('imagenProducto').value = '';
            mostrarPreview(producto.imagen_url || null);
            modalProducto.show();
        }

        // Vista previa instantánea al elegir un archivo nuevo
        document.getElementById('imagenProducto').addEventListener('change', function (e) {
            var archivo = e.target.files[0];
            if (!archivo) return;
            var lector = new FileReader();
            lector.onload = function (ev) { mostrarPreview(ev.target.result); };
            lector.readAsDataURL(archivo);
        });

        function validarFormularioProducto() {
            limpiarErroresProducto();
            var valido = true;

            var codigo = document.getElementById('codigoBarras').value.trim();
            var nombre = document.getElementById('nombreProducto').value.trim();
            var precio = document.getElementById('precioActual').value;
            var stock = document.getElementById('stockDisponible').value;
            var archivoImagen = document.getElementById('imagenProducto').files[0];

            if (codigo === '') {
                document.getElementById('codigoBarras').classList.add('is-invalid');
                document.getElementById('errorCodigoBarras').textContent = 'El código de barras es obligatorio';
                valido = false;
            }
            if (nombre === '') {
                document.getElementById('nombreProducto').classList.add('is-invalid');
                document.getElementById('errorNombreProducto').textContent = 'El nombre es obligatorio';
                valido = false;
            }
            if (precio === '' || parseFloat(precio) < 0) {
                document.getElementById('precioActual').classList.add('is-invalid');
                document.getElementById('errorPrecioActual').textContent = 'Precio inválido';
                valido = false;
            }
            if (stock === '' || parseInt(stock, 10) < 0 || String(parseInt(stock, 10)) !== stock) {
                document.getElementById('stockDisponible').classList.add('is-invalid');
                document.getElementById('errorStockDisponible').textContent = 'Stock inválido';
                valido = false;
            }

            if (archivoImagen) {
                if (archivoImagen.size > 3 * 1024 * 1024) {
                    document.getElementById('imagenProducto').classList.add('is-invalid');
                    document.getElementById('errorImagenProducto').textContent = 'La imagen debe pesar menos de 3 MB';
                    valido = false;
                }
                if (!['image/jpeg', 'image/png', 'image/webp'].includes(archivoImagen.type)) {
                    document.getElementById('imagenProducto').classList.add('is-invalid');
                    document.getElementById('errorImagenProducto').textContent = 'Formato de imagen no permitido';
                    valido = false;
                }
            }

            return valido;
        }

        document.getElementById('btnGuardarProducto').addEventListener('click', function () {
            if (!validarFormularioProducto()) {
                return;
            }

            var id = document.getElementById('productoId').value;
            var archivoImagen = document.getElementById('imagenProducto').files[0];

            // FormData en vez de JSON: es lo que permite enviar el archivo
            // de la imagen junto con los demás campos, en un solo request.
            var formData = new FormData();
            if (id) {
                formData.append('id', id);
            }
            formData.append('codigo_barras', document.getElementById('codigoBarras').value.trim());
            formData.append('nombre_producto', document.getElementById('nombreProducto').value.trim());
            formData.append('precio_actual', document.getElementById('precioActual').value);
            formData.append('stock_disponible', document.getElementById('stockDisponible').value);
            if (archivoImagen) {
                formData.append('imagen', archivoImagen);
            }

            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Guardando...';

            fetch(API_URL, {
                method: 'POST', // el backend distingue crear/editar según venga o no "id"
                body: formData   // sin header Content-Type: el navegador arma el boundary del multipart solo
            })
            .then(function (resp) { return resp.json(); })
            .then(function (res) {
                if (res.error) {
                    var errorGeneral = document.getElementById('errorGeneralProducto');
                    errorGeneral.textContent = res.error;
                    errorGeneral.classList.remove('d-none');
                    return;
                }
                modalProducto.hide();
                cargarProductos(inputBuscar.value.trim());
            })
            .catch(function () {
                var errorGeneral = document.getElementById('errorGeneralProducto');
                errorGeneral.textContent = 'No se pudo guardar el producto. Intenta nuevamente.';
                errorGeneral.classList.remove('d-none');
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = 'Guardar';
            });
        });

        // ---------------------------------------------------------------
        // Eliminar producto
        // ---------------------------------------------------------------
        function abrirModalEliminar(id, nombre) {
            idAEliminar = id;
            document.getElementById('nombreProductoEliminar').textContent = nombre;
            modalEliminar.show();
        }

        document.getElementById('btnConfirmarEliminar').addEventListener('click', function () {
            if (!idAEliminar) return;

            fetch(API_URL, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: idAEliminar })
            })
            .then(function (resp) { return resp.json(); })
            .then(function (res) {
                modalEliminar.hide();
                if (res.error) {
                    alert(res.error);
                } else {
                    idAEliminar = null;
                    cargarProductos(inputBuscar.value.trim());
                }
            })
            .catch(function () {
                modalEliminar.hide();
                alert('No se pudo eliminar el producto. Intenta nuevamente.');
            });
        });

        // Carga inicial
        cargarProductos();
    </script>
</body>
</html>