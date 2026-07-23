<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_activo'])) {
    header('Location: index.php');
    exit();
}
$usuario = $_SESSION['usuario_activo'];

require_once 'backend/conexion.php';

// -----------------------------------------------------------------
// Verificación defensiva: en algunos entornos la columna "estado" de
// ventas todavía no existe (o se llama distinto). En vez de asumir que
// sí está y romper el dashboard con un Fatal Error, se detecta en
// tiempo real y se ajustan las consultas según corresponda.
// -----------------------------------------------------------------
$stmtColumnas = $pdo->query("SHOW COLUMNS FROM ventas LIKE 'estado'");
$columnaEstadoExiste = $stmtColumnas->rowCount() > 0;

// Fragmento de condición reutilizable: solo filtra por "pagada" si la
// columna existe; si no existe, se asume que toda venta registrada es válida.
$condicionEstadoPagada = $columnaEstadoExiste ? " AND estado = 'pagada'" : "";

// Columna a seleccionar en la tabla de actividad reciente
$columnaEstadoSelect = $columnaEstadoExiste ? "v.estado" : "'pagada' AS estado";

// -----------------------------------------------------------------
// KPIs: se calculan directamente en SQL para no traer filas de más.
// Nota de criterio: "Total de Ventas del Día / Mes" se dividió en dos
// tarjetas distintas (ventas de HOY e ingresos del MES) porque son dos
// métricas con utilidad distinta para el negocio.
// -----------------------------------------------------------------

// 1. Ventas de hoy (cantidad de facturas pagadas emitidas hoy)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE DATE(fecha_emision) = CURDATE()$condicionEstadoPagada");
$stmt->execute();
$ventasHoy = (int)$stmt->fetchColumn();

// 2. Ingresos del mes actual (suma de total_factura, solo ventas pagadas)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_factura), 0) FROM ventas
                        WHERE YEAR(fecha_emision) = YEAR(CURDATE())
                        AND MONTH(fecha_emision) = MONTH(CURDATE())$condicionEstadoPagada");
$stmt->execute();
$ingresosMes = (float)$stmt->fetchColumn();

// 3. Total de productos en catálogo
$stmt = $pdo->prepare("SELECT COUNT(*) FROM productos");
$stmt->execute();
$totalProductos = (int)$stmt->fetchColumn();

// 4. Total de clientes registrados
$stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes");
$stmt->execute();
$totalClientes = (int)$stmt->fetchColumn();

// -----------------------------------------------------------------
// Últimas ventas (actividad reciente), con JOIN a clientes
// -----------------------------------------------------------------
$stmt = $pdo->prepare("SELECT v.id, v.fecha_emision, $columnaEstadoSelect, v.total_factura,
                               c.nombre_completo AS cliente_nombre
                        FROM ventas v
                        LEFT JOIN clientes c ON c.id = v.cliente_id
                        ORDER BY v.fecha_emision DESC
                        LIMIT 10");
$stmt->execute();
$ultimasVentas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="frontend/css/dashboard.css">
    <style>
        /* Estilos propios de esta vista: reutilizan las variables ya
           definidas en dashboard.css (mismo criterio que pos.php,
           historial.php y catalogo.php) */
        .dashboard-content{
            margin-left: 280px;
            padding: 20px;
        }
        .saludo-usuario{
            color: var(--texto-secundario, #5c6b62);
            font-weight: 500;
        }

        /* ---------- Tarjetas KPI ---------- */
        .kpi-tarjeta{
            border-radius: var(--radio-lg, 16px) !important;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            color: #fff;
            box-shadow: var(--sombra-md, 0 8px 24px rgba(27,67,50,0.12));
            position: relative;
            overflow: hidden;
        }
        .kpi-tarjeta::after{
            content: "";
            position: absolute;
            top: -40%;
            right: -20%;
            width: 140px;
            height: 140px;
            background: rgba(255,255,255,0.10);
            border-radius: 50%;
        }
        .kpi-tarjeta:hover{
            transform: translateY(-3px);
            box-shadow: var(--sombra-lg, 0 16px 40px rgba(27,67,50,0.2));
        }
        .kpi-icono{
            width: 54px;
            height: 54px;
            min-width: 54px;
            border-radius: 14px;
            background: rgba(255,255,255,0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
        }
        .kpi-etiqueta{
            font-size: 0.82rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            opacity: 0.9;
        }
        .kpi-valor{
            font-family: 'Poppins', sans-serif;
            font-size: 1.7rem;
            font-weight: 800;
            line-height: 1.2;
        }
        .kpi-1{ background: var(--degradado-verde); }
        .kpi-2{ background: var(--degradado-verde-claro); }
        .kpi-3{ background: linear-gradient(135deg, var(--verde-oscuro-2, #0f261c) 0%, var(--verde-oscuro) 100%); }
        .kpi-4{ background: linear-gradient(135deg, var(--verde-claro) 0%, #1b4332 120%); }

        /* ---------- Accesos rápidos ---------- */
        .acceso-rapido{
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            background: #fff;
            border-radius: var(--radio-lg, 16px);
            padding: 28px 16px;
            box-shadow: var(--sombra-sm);
            border-top: 4px solid var(--verde-medio);
            transition: all var(--transicion, .22s ease);
            height: 100%;
        }
        .acceso-rapido:hover{
            transform: translateY(-4px);
            box-shadow: var(--sombra-md);
            border-top-color: var(--verde-claro);
        }
        .acceso-rapido .acceso-icono{
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--verde-fondo, #eef4ef);
            color: var(--verde-oscuro);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            transition: all var(--transicion, .22s ease);
        }
        .acceso-rapido:hover .acceso-icono{
            background: var(--degradado-verde-claro);
            color: #fff;
        }
        .acceso-rapido .acceso-titulo{
            font-weight: 700;
            color: var(--texto-principal, #1f2a24);
            text-align: center;
        }

        /* ---------- Tabla actividad reciente ---------- */
        .titulo-seccion{
            font-weight: 700;
            color: var(--verde-oscuro);
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php include __DIR__ . '/backend/includes/sidebar.php'; ?>

        <div class="dashboard-content w-100">
            <h3 class="mb-1">Panel Principal</h3>
            <p class="saludo-usuario mb-4">Bienvenido, <?= htmlspecialchars($usuario['nombre']) ?> 👋</p>

            <!-- ============== KPIs ============== -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-xl-3">
                    <div class="kpi-tarjeta kpi-1">
                        <div class="kpi-icono"><i class="bi bi-cart-check-fill"></i></div>
                        <div>
                            <div class="kpi-etiqueta">Ventas de hoy</div>
                            <div class="kpi-valor"><?= $ventasHoy ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="kpi-tarjeta kpi-2">
                        <div class="kpi-icono"><i class="bi bi-cash-coin"></i></div>
                        <div>
                            <div class="kpi-etiqueta">Ingresos del mes</div>
                            <div class="kpi-valor">$<?= number_format($ingresosMes, 2) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="kpi-tarjeta kpi-3">
                        <div class="kpi-icono"><i class="bi bi-box-seam-fill"></i></div>
                        <div>
                            <div class="kpi-etiqueta">Productos en catálogo</div>
                            <div class="kpi-valor"><?= $totalProductos ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="kpi-tarjeta kpi-4">
                        <div class="kpi-icono"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-etiqueta">Clientes registrados</div>
                            <div class="kpi-valor"><?= $totalClientes ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============== Accesos rápidos ============== -->
            <h5 class="titulo-seccion">Accesos rápidos</h5>
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <a href="pos.php" class="acceso-rapido">
                        <div class="acceso-icono"><i class="bi bi-display"></i></div>
                        <span class="acceso-titulo">Nuevo Punto de Venta</span>
                    </a>
                </div>
                <div class="col-6 col-lg-3">
                    <a href="catalogo.php" class="acceso-rapido">
                        <div class="acceso-icono"><i class="bi bi-grid-3x3-gap-fill"></i></div>
                        <span class="acceso-titulo">Ver Catálogo</span>
                    </a>
                </div>
                <div class="col-6 col-lg-3">
                    <a href="clientes.php" class="acceso-rapido">
                        <div class="acceso-icono"><i class="bi bi-person-plus-fill"></i></div>
                        <span class="acceso-titulo">Registrar Cliente</span>
                    </a>
                </div>
                <div class="col-6 col-lg-3">
                    <a href="historial.php" class="acceso-rapido">
                        <div class="acceso-icono"><i class="bi bi-bar-chart-line-fill"></i></div>
                        <span class="acceso-titulo">Ver Reportes</span>
                    </a>
                </div>
            </div>

            <!-- ============== Actividad reciente ============== -->
            <h5 class="titulo-seccion">Últimas ventas</h5>
            <div class="card p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>N° Venta</th>
                            <th>Cliente</th>
                            <th>Fecha de emisión</th>
                            <th>Estado</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$ultimasVentas): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">Aún no se han registrado ventas</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ultimasVentas as $venta): ?>
                                <tr>
                                    <td>#<?= str_pad((string)$venta['id'], 5, '0', STR_PAD_LEFT) ?></td>
                                    <td><?= htmlspecialchars($venta['cliente_nombre'] ?? 'Consumidor Final') ?></td>
                                    <td><?= (new DateTime($venta['fecha_emision']))->format('d/m/Y H:i') ?></td>
                                    <td>
                                        <?php if ($venta['estado'] === 'pagada'): ?>
                                            <span class="badge badge-pagada">Pagada</span>
                                        <?php else: ?>
                                            <span class="badge badge-anulada">Anulada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>$<?= number_format((float)$venta['total_factura'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>