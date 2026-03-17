<?php
include 'config.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// 1. REGISTRAR ALUMNO (PASO 1)
if ($action == 'registrar_alumno') {
    $nombre = $_POST['nombre'] ?? '';
    $especialidad = $_POST['especialidad'] ?? '';
    $grupo = $_POST['grupo'] ?? '';
    
    if(!$nombre || !$especialidad || !$grupo) {
        echo json_encode(["error" => "Todos los campos son obligatorios"]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO alumnos (nombre, especialidad, grupo, puntos_actuales, puntos_totales_ganados) VALUES (?, ?, ?, 0, 0)");
    $stmt->bind_param("sss", $nombre, $especialidad, $grupo);
    
    if($stmt->execute()) {
        echo json_encode(["success" => "Alumno registrado correctamente", "id" => $stmt->insert_id]);
    } else {
        echo json_encode(["error" => "No se pudo registrar al alumno"]);
    }
}

// 2. REGISTRAR PRODUCTO (PASO 2 - LÍMITE 10)
if ($action == 'registrar_producto') {
    $alumno_id = (int)$_POST['alumno_id'];
    $cat = $_POST['categoria'];
    $desc = $_POST['descripcion'];
    $pts = (int)$_POST['puntos'];

    // Validar límite de 10 productos
    $res_count = $conn->query("SELECT COUNT(*) as total FROM productos_aportados WHERE alumno_id = $alumno_id");
    $count = $res_count->fetch_assoc()['total'];

    if($count >= 10) {
        echo json_encode(["error" => "Límite de 10 productos alcanzado para este alumno."]);
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO productos_aportados (alumno_id, categoria, descripcion, puntos_otorgados, estado) VALUES (?, ?, ?, ?, 'disponible')");
        $stmt->bind_param("issi", $alumno_id, $cat, $desc, $pts);
        $stmt->execute();

        $conn->query("UPDATE alumnos SET puntos_actuales = puntos_actuales + $pts, puntos_totales_ganados = puntos_totales_ganados + $pts WHERE id = $alumno_id");
        $conn->commit();
        echo json_encode(["success" => "Producto #".($count + 1)." agregado", "nuevo_total" => $count + 1]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["error" => "Error al guardar el producto"]);
    }
}

// 3. BUSCAR ALUMNO (PARA CANJE)
if ($action == 'buscar_alumno') {
    $t = trim($_POST['termino']);
    $sql = is_numeric($t) ? "id = ?" : "nombre = ?";
    $stmt = $conn->prepare("SELECT * FROM alumnos WHERE $sql");
    $stmt->bind_param(is_numeric($t) ? "i" : "s", $t);
    $stmt->execute();
    $alumno = $stmt->get_result()->fetch_assoc();

    if (!$alumno) { echo json_encode(["error" => "Alumno no encontrado"]); exit; }
    echo json_encode($alumno);
}

// 4. REALIZAR CANJE
if ($action == 'realizar_canje') {
    $aid = (int)$_POST['alumno_id'];
    $pid = (int)$_POST['producto_id'];
    $costo = (int)$_POST['costo'];
    $desc = $_POST['descripcion'];

    $conn->begin_transaction();
    $conn->query("UPDATE alumnos SET puntos_actuales = puntos_actuales - $costo WHERE id = $aid");
    $conn->query("UPDATE productos_aportados SET estado = 'agotado' WHERE id = $pid");
    $stmt = $conn->prepare("INSERT INTO productos_trocados (alumno_id, producto_llevado, puntos_gastados) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $aid, $desc, $costo);
    $stmt->execute();
    $conn->commit();
    echo json_encode(["success" => "Canje exitoso"]);
}

// 5. ADMIN REPORTE COMPLETO
if ($action == 'admin_full') {
    if ($_POST['password'] !== 'admin123') { echo json_encode(["error" => "Clave incorrecta"]); exit; }
    
    $res = $conn->query("SELECT * FROM alumnos ORDER BY nombre ASC");
    $data = [];
    while($al = $res->fetch_assoc()) {
        $id = $al['id'];
        $al['aportes'] = $conn->query("SELECT descripcion, puntos_otorgados FROM productos_aportados WHERE alumno_id = $id")->fetch_all(MYSQLI_ASSOC);
        $al['canjes'] = $conn->query("SELECT producto_llevado, puntos_gastados FROM productos_trocados WHERE alumno_id = $id")->fetch_all(MYSQLI_ASSOC);
        $data[] = $al;
    }
    echo json_encode($data);
}

// EXTRAS
if ($action == 'ranking') {
    echo json_encode($conn->query("SELECT nombre, puntos_totales_ganados FROM alumnos WHERE puntos_totales_ganados > 0 ORDER BY puntos_totales_ganados DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC));
}
if ($action == 'inventario') {
    echo json_encode($conn->query("SELECT * FROM productos_aportados WHERE estado = 'disponible'")->fetch_all(MYSQLI_ASSOC));
}
?>
