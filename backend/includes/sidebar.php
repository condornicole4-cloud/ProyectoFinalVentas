<nav id="sidebar" class="d-flex flex-column p-3 text-white"
    style="width:280px; height: 100vh; background-color: var(--verde-oscuro);position:fixed">
    <div class="text-center mb-4 mt-2">
        <h3 class="fw-bold m-0 text-light">🛒 SISTEMA POS </h3>
        <small style="color: var(--verde-claro);">GESTIÓN COMERCIAL</small>
    </div>
    <hr style="border-color: var(--verde-medio);">

    <ul class="nav nav-pills flwx-column mb-auto mt-2">
        <li class="nav-item mb-2">
            <a href="dashboard.php" class="nav-link text-white fw-semibold menu-item aria-current=" page">
                🏠INICIO
            </a>
        </li>
        <li class="nav-item mb-2">
            <a href="catalogo.php" class="nav-link text-white fw-semibold menu-item aria-current=" page">
                📦CATALOGO
            </a>
        </li>
        <li class="nav-item mb-2">
            <a href="pos.php" class="nav-link text-white fw-semibold menu-item aria-current=" page">
                💻PUNTO DE VENTA
            </a>
        </li>
        <li class="nav-item mb-2">
            <a href="historial.php" class="nav-link text-white fw-semibold menu-item aria-current=" page">
                📊REPORTES
            </a>
        </li>
        <li class="nav-item mb-2">
            <a href="clientes.php" class="nav-link text-white fw-semibold menu-item aria-current=" page">
                👥CLIENTES
            </a>
        </li>

    </ul>

    <hr style="boder-color: var(--verde-medio);">
    <div class="pb-2">
        <a href="backend/logout.php"
           class="nav-link text-white fw-semibold menu-item d-flex align-items-center gap-2"
           onclick="return confirm('¿Cerrar sesión?');">
            🚪 CERRAR SESIÓN
        </a>
    </div>
    <hr style="boder-color: var(--verde-medio);">
    <div class="text-centar pb-2">
      <small class="text-white-50">version 1.0.0 &copy; 2026</small>  
    </div>
</nav>