<?php
declare(strict_types=1);

//cabeceras necesarias para el intercambio de JSON
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

//Incluir la conexión
require_once 'conexion.php';

//Capturar el metodo GET, POST, PUT, DELETE
$method = $_SERVER['REQUEST_METHOD'];

// Carpeta donde se guardan las imágenes de productos (relativa a este archivo).
// Ajusta esta ruta si tu backend/ no está al mismo nivel que frontend/.
const CARPETA_IMAGENES = __DIR__ . '/../frontend/img/';
const RUTA_PUBLICA_IMAGENES = 'frontend/img/';
const TAMANO_MAX_IMAGEN = 3 * 1024 * 1024; // 3 MB
const TIPOS_IMAGEN_PERMITIDOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/**
 * Valida los campos de texto/numéricos del producto.
 * Devuelve un array de errores; vacío si todo está OK.
 *
 * @param array $datos
 * @return array<string,string>
 */
function validarDatosProducto(array $datos): array
{
    $errores = [];

    if (empty(trim((string)($datos['codigo_barras'] ?? '')))) {
        $errores['codigo_barras'] = 'El código de barras es obligatorio';
    }

    if (empty(trim((string)($datos['nombre_producto'] ?? '')))) {
        $errores['nombre_producto'] = 'El nombre del producto es obligatorio';
    }

    if (!isset($datos['precio_actual']) || $datos['precio_actual'] === '' || !is_numeric($datos['precio_actual']) || (float)$datos['precio_actual'] < 0) {
        $errores['precio_actual'] = 'El precio debe ser un número mayor o igual a 0';
    }

    if (
        !isset($datos['stock_disponible']) ||
        $datos['stock_disponible'] === '' ||
        filter_var($datos['stock_disponible'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
    ) {
        $errores['stock_disponible'] = 'El stock debe ser un número entero mayor o igual a 0';
    }

    return $errores;
}

/**
 * Procesa la imagen subida (si existe) y devuelve el nombre de archivo
 * guardado en el servidor, o null si no se subió ninguna imagen.
 *
 * Lanza RuntimeException con un mensaje amigable si algo falla.
 */
function procesarImagenSubida(): ?string
{
    if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // no se envió imagen, es opcional
    }

    $archivo = $_FILES['imagen'];

    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Ocurrió un error al subir la imagen');
    }

    if ($archivo['size'] > TAMANO_MAX_IMAGEN) {
        throw new RuntimeException('La imagen supera el tamaño máximo de 3 MB');
    }

    // Detectar el tipo MIME real (no confiar en la extensión ni en el
    // Content-Type que manda el navegador, que se pueden falsificar).
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $archivo['tmp_name']);
    finfo_close($finfo);

    if (!isset(TIPOS_IMAGEN_PERMITIDOS[$mime])) {
        throw new RuntimeException('Formato de imagen no permitido. Usa JPG, PNG o WEBP');
    }

    if (!is_dir(CARPETA_IMAGENES) && !mkdir(CARPETA_IMAGENES, 0755, true) && !is_dir(CARPETA_IMAGENES)) {
        throw new RuntimeException('No se pudo preparar la carpeta de imágenes');
    }

    $extension = TIPOS_IMAGEN_PERMITIDOS[$mime];
    $nombreArchivo = bin2hex(random_bytes(16)) . '.' . $extension;
    $rutaDestino = CARPETA_IMAGENES . $nombreArchivo;

    if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
        throw new RuntimeException('No se pudo guardar la imagen en el servidor');
    }

    return $nombreArchivo;
}

/** Borra una imagen de producto del disco, ignorando si no existe. */
function borrarImagenAnterior(?string $nombreArchivo): void
{
    if (!$nombreArchivo) {
        return;
    }
    $ruta = CARPETA_IMAGENES . $nombreArchivo;
    if (is_file($ruta)) {
        @unlink($ruta);
    }
}

try {
    switch ($method) {
        case 'GET':
            //Obtener todos los productos (o filtrar por búsqueda)
            $search = $_GET['q'] ?? '';
            // Escapamos los comodines de LIKE para que una búsqueda como
            // "100%" o "a_b" no se interprete como patrón SQL.
            $searchEscapado = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);

            $sql = "SELECT * FROM productos WHERE nombre_producto LIKE ? ESCAPE '\\\\' OR codigo_barras LIKE ? ESCAPE '\\\\'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(["%$searchEscapado%", "%$searchEscapado%"]);
            $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // El frontend (catalogo.php) espera un campo 'imagen_url' con la ruta
            // completa para el <img src="...">. La columna 'imagen' en la BD solo
            // guarda el nombre de archivo (ej: 'laptop.jpg'), así que se arma aquí.
            foreach ($productos as &$producto) {
                $producto['imagen_url'] = !empty($producto['imagen'])
                    ? RUTA_PUBLICA_IMAGENES . $producto['imagen']
                    : null;
            }
            unset($producto);

            echo json_encode($productos);
            break;

        case 'POST':
            // El frontend envía FormData (multipart/form-data), no JSON,
            // porque necesita mandar el archivo de imagen junto con los
            // demás campos. Por eso aquí se lee $_POST y $_FILES, y NO
            // php://input (que estaría vacío para multipart).
            $datos = $_POST;

            $errores = validarDatosProducto($datos);
            if (!empty($errores)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Datos inválidos: ' . implode(', ', $errores),
                    'errores_campo' => $errores,
                ]);
                break;
            }

            $id = isset($datos['id']) && $datos['id'] !== '' ? (int)$datos['id'] : null;

            try {
                $nombreImagen = procesarImagenSubida();
            } catch (RuntimeException $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
                break;
            }

            if ($id === null) {
                // ---- Crear producto ----
                $sql = 'INSERT INTO productos (codigo_barras, nombre_producto, precio_actual, stock_disponible, imagen)
                        VALUES (:codigo_barras, :nombre_producto, :precio_actual, :stock_disponible, :imagen)';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':codigo_barras'    => trim($datos['codigo_barras']),
                    ':nombre_producto'  => trim($datos['nombre_producto']),
                    ':precio_actual'    => (float)$datos['precio_actual'],
                    ':stock_disponible' => (int)$datos['stock_disponible'],
                    ':imagen'           => $nombreImagen,
                ]);
                echo json_encode(['message' => 'Producto creado con éxito', 'id' => $pdo->lastInsertId()]);
            } else {
                // ---- Editar producto ----
                // Si se subió una imagen nueva, hay que reemplazar la anterior;
                // si no, se conserva la que ya tenía (columna 'imagen' no se toca).
                if ($nombreImagen !== null) {
                    $stmtImagenActual = $pdo->prepare('SELECT imagen FROM productos WHERE id = :id');
                    $stmtImagenActual->execute([':id' => $id]);
                    $imagenPrevia = $stmtImagenActual->fetchColumn();

                    $sql = 'UPDATE productos
                            SET codigo_barras = :codigo_barras,
                                nombre_producto = :nombre_producto,
                                precio_actual = :precio_actual,
                                stock_disponible = :stock_disponible,
                                imagen = :imagen
                            WHERE id = :id';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':id'               => $id,
                        ':codigo_barras'    => trim($datos['codigo_barras']),
                        ':nombre_producto'  => trim($datos['nombre_producto']),
                        ':precio_actual'    => (float)$datos['precio_actual'],
                        ':stock_disponible' => (int)$datos['stock_disponible'],
                        ':imagen'           => $nombreImagen,
                    ]);

                    if ($imagenPrevia) {
                        borrarImagenAnterior($imagenPrevia);
                    }
                } else {
                    $sql = 'UPDATE productos
                            SET codigo_barras = :codigo_barras,
                                nombre_producto = :nombre_producto,
                                precio_actual = :precio_actual,
                                stock_disponible = :stock_disponible
                            WHERE id = :id';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':id'               => $id,
                        ':codigo_barras'    => trim($datos['codigo_barras']),
                        ':nombre_producto'  => trim($datos['nombre_producto']),
                        ':precio_actual'    => (float)$datos['precio_actual'],
                        ':stock_disponible' => (int)$datos['stock_disponible'],
                    ]);
                }

                if ($stmt->rowCount() === 0) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Producto no encontrado']);
                    break;
                }

                echo json_encode(['message' => 'Producto actualizado con éxito']);
            }
            break;

        case 'PUT':
            // Se conserva por compatibilidad (por si otro cliente la usa),
            // pero solo acepta JSON puro, sin imagen.
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                http_response_code(400);
                echo json_encode(['error' => 'Cuerpo de la petición inválido']);
                break;
            }

            $errores = validarDatosProducto($input);
            if (!empty($errores) || empty($input['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Datos inválidos: ' . implode(', ', $errores)]);
                break;
            }

            $sql = 'UPDATE productos
                    SET codigo_barras = :codigo_barras,
                        nombre_producto = :nombre_producto,
                        precio_actual = :precio_actual,
                        stock_disponible = :stock_disponible
                    WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id'               => (int)$input['id'],
                ':codigo_barras'    => trim($input['codigo_barras']),
                ':nombre_producto'  => trim($input['nombre_producto']),
                ':precio_actual'    => (float)$input['precio_actual'],
                ':stock_disponible' => (int)$input['stock_disponible'],
            ]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Producto no encontrado']);
                break;
            }

            echo json_encode(['message' => 'Producto actualizado con éxito']);
            break;

        case 'DELETE':
            //Eliminar un producto existente (el frontend sí manda JSON aquí)
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input) || empty($input['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Debes indicar el id del producto a eliminar']);
                break;
            }

            $id = (int)$input['id'];

            // Recuperamos el nombre de la imagen antes de borrar el registro,
            // para poder eliminar también el archivo del disco. Esto es un
            // "extra" (limpieza de archivos): si falla por lo que sea
            // (columna inexistente, etc.) NO debe impedir el borrado del
            // producto en sí.
            $imagen = null;
            try {
                $stmtImagen = $pdo->prepare('SELECT imagen FROM productos WHERE id = :id');
                $stmtImagen->execute([':id' => $id]);
                $imagen = $stmtImagen->fetchColumn();
            } catch (PDOException $e) {
                error_log('No se pudo leer la columna imagen (revisa que exista en la tabla productos): ' . $e->getMessage());
            }

            try {
                $sql = 'DELETE FROM productos WHERE id = :id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $id]);
            } catch (PDOException $e) {
                // Código SQLSTATE 23000 = violación de integridad referencial.
                // Pasa cuando el producto ya está referenciado en ventas,
                // detalle_venta, etc. y la FK no permite borrarlo directo.
                if ($e->getCode() === '23000') {
                    http_response_code(409);
                    echo json_encode(['error' => 'No se puede eliminar: este producto ya tiene ventas u otros registros asociados.']);
                    break;
                }
                throw $e; // otros errores de BD los maneja el catch general de abajo
            }

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Producto no encontrado']);
                break;
            }

            if ($imagen) {
                borrarImagenAnterior($imagen);
            }

            echo json_encode(['message' => 'Producto eliminado con éxito']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Método no permitido']);
    }
} catch (PDOException $e) {
    // Nunca se expone el mensaje real de la BD al cliente (podría filtrar
    // nombres de tablas/columnas). Se registra internamente para depurar.
    error_log('Error PDO en api_productos.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error en la base de datos']);
}