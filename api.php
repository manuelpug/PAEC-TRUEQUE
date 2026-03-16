<?php
$host = 'localhost';
$user = 'root'; 
$pass = ''; 
$db = 'trueque_ecologico';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

$action = $_POST['action'] ?? '';

// ---------------------------------------------------------
// 1. REGISTRAR ALUMNO Y SUS PRODUCTOS
// ---------------------------------------------------------
if ($action == 'registrar_todo') {
    $nombre = $_POST['nombre'];
    $especialidad = $_POST['especialidad'];
    $grupo = $_POST['grupo'];
    $productos = json_decode($_POST['productos'], true);
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO alumnos (nombre, especialidad, grupo, puntos_actuales) VALUES (?, ?, ?, 0)");
        $stmt->bind_param("sss", $nombre, $especialidad, $grupo);
        $stmt->execute();
        $alumno_id = $stmt->insert_id;
        $stmt->close();
        
        $puntos_totales = 0;
        
        if (is_array($productos) && count($productos) > 0) {
            $limite = min(count($productos), 10); 
            $stmt_prod = $conn->prepare("INSERT INTO productos_aportados (alumno_id, categoria, descripcion, puntos_otorgados, estado) VALUES (?, ?, ?, ?, 'disponible')");
            
            for ($i = 0; $i < $limite; $i++) {
                $cat = $productos[$i]['categoria'];
                $desc = $productos[$i]['descripcion'];
                $pts = (int)$productos[$i]['puntos'];
                
                $puntos_totales += $pts;
                
                $stmt_prod->bind_param("issi", $alumno_id, $cat, $desc, $pts);
                $stmt_prod->execute();
            }
            $stmt_prod->close();
            
            if ($puntos_totales > 0) {
                $stmt_upd = $conn->prepare("UPDATE alumnos SET puntos_actuales = ? WHERE id = ?");
                $stmt_upd->bind_param("ii", $puntos_totales, $alumno_id);
                $stmt_upd->execute();
                $stmt_upd->close();
            }
        }
        $conn->commit();
        echo "Alumno registrado. Producto(s) registrado(s).";
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
}

// ---------------------------------------------------------
// 2. BUSCAR PERFIL DEL ALUMNO (POR ID O NOMBRE)
// ---------------------------------------------------------
if ($action == 'buscar_alumno') {
    $termino = trim($_POST['termino']);
    
    if (is_numeric($termino)) {
        $stmt = $conn->prepare("SELECT id, nombre, especialidad, grupo, puntos_actuales FROM alumnos WHERE id = ?");
        $stmt->bind_param("i", $termino);
    } else {
        $termino_like = "%" . $termino . "%";
        $stmt = $conn->prepare("SELECT id, nombre, especialidad, grupo, puntos_actuales FROM alumnos WHERE nombre LIKE ? LIMIT 1");
        $stmt->bind_param("s", $termino_like);
    }
    
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        echo json_encode(["error" => "No se encontró ningún alumno con ese ID o Nombre."]);
        exit;
    }
    
    $alumno = $resultado->fetch_assoc();
    $stmt->close();
    
    $id_encontrado = $alumno['id'];
    
    $aportados = [];
    $stmt_aportados = $conn->prepare("SELECT categoria, descripcion, puntos_otorgados, estado, DATE_FORMAT(fecha, '%d/%m/%Y') as fecha FROM productos_aportados WHERE alumno_id = ?");
    $stmt_aportados->bind_param("i", $id_encontrado);
    $stmt_aportados->execute();
    $res_aportados = $stmt_aportados->get_result();
    while($row = $res_aportados->fetch_assoc()) { $aportados[] = $row; }
    $stmt_aportados->close();
    
    $canjeados = [];
    $stmt_canjeados = $conn->prepare("SELECT producto_llevado, puntos_gastados, DATE_FORMAT(fecha, '%d/%m/%Y') as fecha FROM productos_trocados WHERE alumno_id = ?");
    $stmt_canjeados->bind_param("i", $id_encontrado);
    $stmt_canjeados->execute();
    $res_canjeados = $stmt_canjeados->get_result();
    while($row = $res_canjeados->fetch_assoc()) { $canjeados[] = $row; }
    $stmt_canjeados->close();
    
    echo json_encode([
        "alumno" => $alumno,
        "aportados" => $aportados,
        "canjeados" => $canjeados
    ]);
}

// ---------------------------------------------------------
// 3. CARGAR INVENTARIO PARA CANJES
// ---------------------------------------------------------
if ($action == 'cargar_inventario') {
    $res = $conn->query("SELECT id, categoria, descripcion, puntos_otorgados, estado FROM productos_aportados ORDER BY id DESC");
    $inventario = [];
    while($row = $res->fetch_assoc()) {
        $inventario[] = $row;
    }
    echo json_encode($inventario);
}

// ---------------------------------------------------------
// 4. REALIZAR UN CANJE (TRUEQUE POR ID O NOMBRE)
// ---------------------------------------------------------
if ($action == 'realizar_canje') {
    $identificador = trim($_POST['identificador_alumno']);
    $producto_id = (int)$_POST['producto_id'];
    $costo = (int)$_POST['costo'];
    $descripcion = $_POST['descripcion'];

    if (is_numeric($identificador)) {
        $stmt_puntos = $conn->prepare("SELECT id, puntos_actuales FROM alumnos WHERE id = ?");
        $stmt_puntos->bind_param("i", $identificador);
    } else {
        $termino_like = "%" . $identificador . "%";
        $stmt_puntos = $conn->prepare("SELECT id, puntos_actuales FROM alumnos WHERE nombre LIKE ? LIMIT 1");
        $stmt_puntos->bind_param("s", $termino_like);
    }
    
    $stmt_puntos->execute();
    $res_puntos = $stmt_puntos->get_result();
    
    if($res_puntos->num_rows === 0) {
        die("Error: No se encontró ningún alumno con ese ID o Nombre.");
    }
    
    $alumno = $res_puntos->fetch_assoc();
    $alumno_id = $alumno['id'];

    if($alumno['puntos_actuales'] < $costo) {
        die("Operación denegada: El alumno solo tiene " . $alumno['puntos_actuales'] . " puntos y necesita " . $costo . ".");
    }

    $conn->begin_transaction();
    try {
        $conn->query("UPDATE alumnos SET puntos_actuales = puntos_actuales - $costo WHERE id = $alumno_id");
        $conn->query("UPDATE productos_aportados SET estado = 'agotado' WHERE id = $producto_id");
        
        $stmt_hist = $conn->prepare("INSERT INTO productos_trocados (alumno_id, producto_llevado, puntos_gastados) VALUES (?, ?, ?)");
        $stmt_hist->bind_param("isi", $alumno_id, $descripcion, $costo);
        $stmt_hist->execute();

        $conn->commit();
        echo "Producto canjeado.";
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error al realizar el canje.";
    }
}
$conn->close();
?>
