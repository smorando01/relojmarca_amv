<?php
// Attendance API with auth, roles, and admin endpoints

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function loadEnv($path)
{
    if (!is_readable($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';' || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
    }
}

loadEnv(__DIR__ . '/.env');

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'amvstore_cloudamv';
$dbUser = getenv('DB_USER') ?: 'amvstore_cloudamv';
$dbPass = getenv('DB_PASS') ?: 'u!d=wa@487is3IlY';

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

function getJsonInput()
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    return $input;
}

function currentUser()
{
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function requireLogin($role = null)
{
    $user = currentUser();
    if (!$user) {
        respond(['success' => false, 'error' => 'No autenticado'], 401);
    }
    if ($role && $user['rol'] !== $role) {
        respond(['success' => false, 'error' => 'No autorizado'], 403);
    }
    return $user;
}

function verifyPassword($plain, $stored, &$needsRehash)
{
    $needsRehash = false;

    if (strpos($stored, '$2y$') === 0 || strpos($stored, '$2b$') === 0) {
        if (password_verify($plain, $stored)) {
            $needsRehash = password_needs_rehash($stored, PASSWORD_DEFAULT);
            return true;
        }
        return false;
    }

    // Legacy/plain fallback: accept and mark for rehash
    if (hash_equals($stored, $plain) || hash_equals($stored, hash('sha256', $plain))) {
        $needsRehash = true;
        return true;
    }

    return false;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// --- POST routes ---
if ($method === 'POST') {
    $input = getJsonInput();
    if (isset($input['action']) && $action === '') {
        $action = trim($input['action']);
    }

    if ($action === 'login') {
        $cedula = isset($input['cedula']) ? trim($input['cedula']) : '';
        $password = isset($input['password']) ? (string)$input['password'] : '';

        if ($cedula === '' || $password === '') {
            respond(['success' => false, 'error' => 'Cedula y password son requeridos'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, nombre, cedula, password, rol FROM empleados WHERE cedula = :cedula LIMIT 1');
        $stmt->execute([':cedula' => $cedula]);
        $user = $stmt->fetch();
        if (!$user) {
            respond(['success' => false, 'error' => 'Usuario o contraseña inválidos'], 401);
        }

        $needsRehash = false;
        if (!verifyPassword($password, $user['password'], $needsRehash)) {
            respond(['success' => false, 'error' => 'Usuario o contraseña inválidos'], 401);
        }

        if ($needsRehash) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE empleados SET password = :pwd WHERE id = :id')->execute([
                ':pwd' => $newHash,
                ':id' => $user['id'],
            ]);
            $user['password'] = $newHash;
        }

        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'nombre' => $user['nombre'],
            'cedula' => $user['cedula'],
            'rol' => $user['rol'],
        ];

        respond(['success' => true, 'user' => $_SESSION['user']]);
    }

    if ($action === 'logout') {
        session_unset();
        session_destroy();
        respond(['success' => true]);
    }

    if ($action === 'create_employee') {
        $admin = requireLogin('admin');

        $nombre = isset($input['nombre']) ? trim($input['nombre']) : '';
        $cedula = isset($input['cedula']) ? trim($input['cedula']) : '';
        $password = isset($input['password']) ? (string)$input['password'] : '';
        $rol = isset($input['rol']) ? trim($input['rol']) : 'empleado';

        if ($nombre === '' || $cedula === '' || $password === '') {
            respond(['success' => false, 'error' => 'Nombre, cedula y password son requeridos'], 400);
        }
        if (!in_array($rol, ['admin', 'empleado'], true)) {
            $rol = 'empleado';
        }

        $stmt = $pdo->prepare('SELECT id FROM empleados WHERE cedula = :cedula LIMIT 1');
        $stmt->execute([':cedula' => $cedula]);
        if ($stmt->fetch()) {
            respond(['success' => false, 'error' => 'Cedula ya registrada'], 409);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO empleados (nombre, cedula, password, rol, turno_id) VALUES (:nombre, :cedula, :password, :rol, :turno_id)');
        $stmt->execute([
            ':nombre' => $nombre,
            ':cedula' => $cedula,
            ':password' => $hash,
            ':rol' => $rol,
            ':turno_id' => 1,
        ]);

        respond([
            'success' => true,
            'id' => (int)$pdo->lastInsertId(),
            'nombre' => $nombre,
            'cedula' => $cedula,
            'rol' => $rol,
        ], 201);
    }

    // Default POST: punch/attendance for current user
    $user = requireLogin();

    $tipo = isset($input['tipo']) ? trim($input['tipo']) : '';
    $fechaHora = !empty($input['fecha_hora']) ? trim($input['fecha_hora']) : null;

    if ($tipo === '' || !in_array($tipo, $validTypes, true)) {
        respond(['success' => false, 'error' => 'Tipo de fichaje invalido'], 400);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO fichajes (empleado_id, tipo, fecha_hora) VALUES (:empleado_id, :tipo, :fecha_hora)'
        );
        $stmt->execute([
            ':empleado_id' => $user['id'],
            ':tipo' => $tipo,
            ':fecha_hora' => $fechaHora ?: date('Y-m-d H:i:s'),
        ]);

        respond([
            'success' => true,
            'id' => (int)$pdo->lastInsertId(),
            'empleado_id' => $user['id'],
            'tipo' => $tipo,
            'fecha_hora' => $fechaHora ?: date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        respond(['success' => false, 'error' => 'No se pudo guardar el fichaje: ' . $e->getMessage()], 500);
    }
}

// --- GET routes ---
if ($method === 'GET') {
    if ($action === 'session') {
        $user = currentUser();
        respond(['success' => true, 'user' => $user]);
    }

    if ($action === 'stats') {
        requireLogin('admin');

        $today = date('Y-m-d');

        $totalEmp = $pdo->query('SELECT COUNT(*) AS total FROM empleados')->fetchColumn();
        $stmtToday = $pdo->prepare('SELECT COUNT(*) FROM fichajes WHERE DATE(fecha_hora) = :today');
        $stmtToday->execute([':today' => $today]);
        $totalFichajesHoy = (int)$stmtToday->fetchColumn();

        $lastFichajes = $pdo->query(
            'SELECT f.id, f.empleado_id, e.nombre AS empleado, e.rol, f.tipo, f.fecha_hora
             FROM fichajes f
             JOIN empleados e ON e.id = f.empleado_id
             ORDER BY f.fecha_hora DESC, f.id DESC
             LIMIT 10'
        )->fetchAll();

        $empleados = $pdo->query('SELECT id, nombre, cedula, rol FROM empleados ORDER BY nombre ASC')->fetchAll();

        respond([
            'success' => true,
            'stats' => [
                'total_empleados' => (int)$totalEmp,
                'fichajes_hoy' => $totalFichajesHoy,
                'ultimos_fichajes' => $lastFichajes,
                'empleados' => $empleados,
            ],
        ]);
    }

    // GET logs for current user or specific employee if admin
    $user = requireLogin();
    $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;

    $empleadoId = $user['id'];
    if ($user['rol'] === 'admin' && isset($_GET['empleado_id'])) {
        $empleadoId = (int)$_GET['empleado_id'];
    }

    $sql = 'SELECT f.id, f.empleado_id, e.nombre AS empleado, f.tipo, f.fecha_hora
            FROM fichajes f
            JOIN empleados e ON e.id = f.empleado_id
            WHERE f.empleado_id = :empleado_id
            ORDER BY f.fecha_hora DESC, f.id DESC
            LIMIT :limit';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':empleado_id', $empleadoId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    respond([
        'success' => true,
        'data' => $stmt->fetchAll(),
    ]);
}

respond(['success' => false, 'error' => 'Metodo no permitido'], 405);
