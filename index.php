<?php

session_start();
if (isset($_SESSION['usuario_activo'])) {
    header('Location: dashboard.php');
    exit;
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="frontend/css/dashboard.css">
    <style>
        html, body{
            height: 100%;
        }
        body.login-body{
            background: linear-gradient(160deg, var(--verde-oscuro) 0%, var(--verde-medio) 55%, var(--verde-claro) 100%);
            position: relative;
            overflow: hidden;
        }

        /* Formas decorativas de fondo, sutiles, mismos verdes */
        body.login-body::before,
        body.login-body::after{
            content: "";
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.06);
            z-index: 0;
        }
        body.login-body::before{
            width: 480px;
            height: 480px;
            top: -180px;
            left: -160px;
        }
        body.login-body::after{
            width: 380px;
            height: 380px;
            bottom: -160px;
            right: -120px;
            background: rgba(27,67,50,0.18);
        }

        /* Tarjeta principal: dos columnas (marca + formulario) */
        .login-wrap{
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 820px;
            min-height: 480px;
            display: flex;
            border-radius: var(--radio-lg);
            overflow: hidden;
            box-shadow: var(--sombra-lg);
            animation: aparecer 0.5s ease;
        }
        @keyframes aparecer{
            from{ opacity: 0; transform: translateY(14px); }
            to{ opacity: 1; transform: translateY(0); }
        }

        /* Panel izquierdo: marca */
        .login-panel-marca{
            flex: 1 1 42%;
            background:
                radial-gradient(circle at 20% 20%, rgba(255,255,255,0.10) 0, transparent 45%),
                linear-gradient(155deg, var(--verde-oscuro-2) 0%, var(--verde-oscuro) 60%, var(--verde-medio) 100%);
            color: #fff;
            padding: 3rem 2.2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 18px;
        }
        .login-panel-marca .marca-icono{
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }
        .login-panel-marca h2{
            color: #fff;
            font-size: 1.6rem;
            margin: 0;
            line-height: 1.25;
        }
        .login-panel-marca p{
            color: var(--verde-suave);
            font-size: 0.92rem;
            margin: 0;
            max-width: 260px;
        }
        .login-panel-marca .marca-lista{
            list-style: none;
            padding: 0;
            margin: 6px 0 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .login-panel-marca .marca-lista li{
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: #eaf3ec;
        }
        .login-panel-marca .marca-lista li span{
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        /* Panel derecho: formulario */
        .login-panel-form{
            flex: 1 1 58%;
            background: #fff;
            padding: 3rem 2.6rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .login-panel-form h3{
            font-size: 1.35rem;
            margin-bottom: 4px;
        }
        .login-panel-form .subtitulo{
            color: var(--texto-secundario);
            font-size: 0.9rem;
            margin-bottom: 24px;
        }
        .login-panel-form .form-label{
            font-weight: 600;
            color: var(--texto-secundario);
            font-size: 0.85rem;
        }

        /* Inputs con icono */
        .input-icono{
            position: relative;
        }
        .input-icono .icono{
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.95rem;
            opacity: 0.55;
            pointer-events: none;
        }
        .input-icono .form-control{
            padding-left: 40px;
        }
        .form-control{
            border-radius: var(--radio-sm);
            padding: 10px 14px;
            border: 1px solid var(--borde-suave);
        }

        .login-panel-form .btn-primary{
            background-color: var(--verde-oscuro);
            border-color: var(--verde-oscuro);
            padding: 11px;
            font-weight: 600;
            border-radius: var(--radio-sm);
            transition: all var(--transicion);
        }
        .login-panel-form .btn-primary:hover{
            background-color: var(--verde-medio);
            border-color: var(--verde-medio);
            transform: translateY(-1px);
            box-shadow: var(--sombra-sm);
        }

        .login-footer-nota{
            margin-top: 22px;
            text-align: center;
            font-size: 0.78rem;
            color: #9aa8a0;
        }

        /* En pantallas chicas, apilar el panel de marca sobre el formulario */
        @media (max-width: 767px){
            .login-wrap{
                flex-direction: column;
                max-width: 420px;
            }
            .login-panel-marca{
                padding: 2rem 1.8rem;
            }
            .login-panel-marca .marca-lista{
                display: none;
            }
            .login-panel-form{
                padding: 2.2rem 1.8rem;
            }
        }
    </style>
</head>

<body class="login-body d-flex align-items-center justify-content-center vh-100">
    <div class="login-wrap">

        <!-- Panel izquierdo: identidad del sistema -->
        <div class="login-panel-marca">
            <div class="marca-icono">🛒</div>
            <h2>Sistema POS<br>Gestión Comercial</h2>
            <p>Controla ventas, inventario y clientes desde un solo lugar, en tiempo real.</p>
            <ul class="marca-lista">
                <li><span>💳</span> Punto de venta ágil</li>
                <li><span>📦</span> Control de inventario</li>
                <li><span>📊</span> Reportes al instante</li>
            </ul>
        </div>

        <!-- Panel derecho: formulario -->
        <div class="login-panel-form">
            <h3>Iniciar sesión</h3>
            <p class="subtitulo">Ingresa tus credenciales para acceder al sistema</p>

            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-danger py-2" role="alert">
                   Usuario o contraseña incorrectos.
                </div>
            <?php endif; ?>

            <form method="POST" action="backend/procesar_login.php">
                <div class="mb-3">
                    <label for="usuario" class="form-label">Usuario</label>
                    <div class="input-icono">
                        <span class="icono">👤</span>
                        <input type="text" class="form-control" id="usuario" name="usuario" placeholder="Ej: jperez" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Contraseña</label>
                    <div class="input-icono">
                        <span class="icono">🔒</span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Iniciar Sesión</button>
            </form>

            <p class="login-footer-nota">Sistema POS &middot; versión 1.0.0</p>
        </div>

    </div>

</body>

</html>