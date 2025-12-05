<?php
// Simple attendance API using PDO + JSON responses

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$dbHost = 'localhost';
$dbName = 'relojmarca';
$dbUser = 'root';
$dbPass = '';

$validTypes = ['Entrada', 'Salida Descanso', 'Vuelta Descanso', 'Salida'];

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de conexion: ' . $e->getMessage()]);
    exit;
}

function respond($payload, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $empleadoId = isset($input['empleado_id']) ? (int)$input['empleado_id'] : null;
    $tipo = isset($input['tipo']) ? trim($input['tipo']) : '';
    $fechaHora = !empty($input['fecha_hora']) ? $input['fecha_hora'] : null;

    if (!$empleadoId || !$tipo) {
        respond(['success' => false, 'error' => 'empleado_id y tipo son obligatorios'], 400);
    }

    if (!in_array($tipo, $validTypes, true)) {
        respond(['success' => false, 'error' => 'Tipo invalido'], 400);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO fichajes (empleado_id, tipo, fecha_hora) VALUES (:empleado_id, :tipo, :fecha_hora)'
        );
        $stmt->execute([
            ':empleado_id' => $empleadoId,
            ':tipo' => $tipo,
            ':fecha_hora' => $fechaHora ?? date('Y-m-d H:i:s'),
        ]);

        respond([
            'success' => true,
            'id' => $pdo->lastInsertId(),
            'empleado_id' => $empleadoId,
            'tipo' => $tipo,
            'fecha_hora' => $fechaHora ?? date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        respond(['success' => false, 'error' => 'No se pudo guardar el fichaje: ' . $e->getMessage()], 500);
    }
}

// GET: return history
$empleadoId = isset($_GET['empleado_id']) ? (int)$_GET['empleado_id'] : null;
$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;

try {
    $sql = 'SELECT f.id, f.empleado_id, e.nombre AS empleado, f.tipo, f.fecha_hora
            FROM fichajes f
            JOIN empleados e ON e.id = f.empleado_id';

    $params = [];
    if ($empleadoId) {
        $sql .= ' WHERE f.empleado_id = :empleado_id';
        $params[':empleado_id'] = $empleadoId;
    }

    $sql .= ' ORDER BY f.fecha_hora DESC, f.id DESC LIMIT :limit';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    respond([
        'success' => true,
        'data' => $stmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    respond(['success' => false, 'error' => 'No se pudo obtener el historial: ' . $e->getMessage()], 500);
}
