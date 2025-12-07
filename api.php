<?php
// Attendance API with auth, roles, validations, and admin tools

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
$kioskToken = getenv('KIOSK_TOKEN') ?: 'changeme';

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

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// --- POST routes ---
if ($method === 'POST') {
    $input = getJsonInput();
    if (isset($input['action']) && $action === '') {
        $action = trim($input['action']);
    }

    switch ($action) {
        case 'login':
            // Inicia sesión del usuario y actualiza hash heredado si aplica
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
            break;

        case 'logout':
            // Cierra la sesión actual
            session_unset();
            session_destroy();
            respond(['success' => true]);
            break;

        case 'create_employee':
            // Alta de empleado por administrador
            requireLogin('admin');

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
            break;

        case 'admin_punch':
            // Crea fichaje manual por un administrador
            requireLogin('admin');

            $empleadoId = isset($input['empleado_id']) ? (int)$input['empleado_id'] : 0;
            $tipo = isset($input['tipo']) ? trim($input['tipo']) : '';
            $fechaHora = !empty($input['fecha_hora']) ? trim($input['fecha_hora']) : date('Y-m-d H:i:s');

            if (!$empleadoId || !in_array($tipo, $validTypes, true)) {
                respond(['success' => false, 'error' => 'Empleado y tipo son requeridos'], 400);
            }

            $pdo->prepare('INSERT INTO fichajes (empleado_id, tipo, fecha_hora) VALUES (:empleado_id, :tipo, :fecha_hora)')->execute([
                ':empleado_id' => $empleadoId,
                ':tipo' => $tipo,
                ':fecha_hora' => $fechaHora,
            ]);

            respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        case 'edit_punch':
            // Edita fichaje existente (admin)
            requireLogin('admin');
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            $tipo = isset($input['tipo']) ? trim($input['tipo']) : '';
            $fechaHora = isset($input['fecha_hora']) ? trim($input['fecha_hora']) : '';

            if (!$id || (!$tipo && !$fechaHora)) {
                respond(['success' => false, 'error' => 'Datos insuficientes'], 400);
            }
            if ($tipo && !in_array($tipo, $validTypes, true)) {
                respond(['success' => false, 'error' => 'Tipo invalido'], 400);
            }

            $fields = [];
            $params = [':id' => $id];
            if ($tipo) {
                $fields[] = 'tipo = :tipo';
                $params[':tipo'] = $tipo;
            }
            if ($fechaHora) {
                $fields[] = 'fecha_hora = :fecha_hora';
                $params[':fecha_hora'] = $fechaHora;
            }

            $sql = 'UPDATE fichajes SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            respond(['success' => true]);
            break;

        case 'delete_punch':
            // Borra fichaje (admin)
            requireLogin('admin');
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            if (!$id) {
                respond(['success' => false, 'error' => 'ID requerido'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM fichajes WHERE id = :id');
            $stmt->execute([':id' => $id]);
            respond(['success' => true]);
            break;

        case 'save_face':
            // Guarda descriptor facial para un empleado (admin)
            requireLogin('admin');
            $empleadoId = isset($input['empleado_id']) ? (int)$input['empleado_id'] : 0;
            $descriptor = isset($input['descriptor']) ? $input['descriptor'] : null;

            $parsedDescriptor = parseDescriptor($descriptor);
            if (!$empleadoId || $parsedDescriptor === null) {
                respond(['success' => false, 'error' => 'Empleado y descriptor válidos son requeridos'], 400);
            }

            $stmt = $pdo->prepare('UPDATE empleados SET face_descriptor = :descriptor WHERE id = :id');
            $stmt->execute([
                ':descriptor' => json_encode($parsedDescriptor),
                ':id' => $empleadoId,
            ]);

            respond(['success' => true]);
            break;

        case 'kiosk_punch':
            // Fichaje simplificado para kiosco por reconocimiento facial
            $providedToken = '';
            if (!empty($_SERVER['HTTP_X_KIOSK_TOKEN'])) {
                $providedToken = $_SERVER['HTTP_X_KIOSK_TOKEN'];
            } elseif (!empty($input['token'])) {
                $providedToken = $input['token'];
            }

            if (!validateKioskToken($providedToken, $kioskToken)) {
                respond(['success' => false, 'error' => 'Token kiosco inválido'], 401);
            }

            $empleadoId = isset($input['empleado_id']) ? (int)$input['empleado_id'] : 0;
            $tipo = isset($input['tipo']) ? trim($input['tipo']) : '';

            if (!$empleadoId || $tipo === '') {
                respond(['success' => false, 'error' => 'Empleado y tipo son requeridos'], 400);
            }

            $error = validatePunch($pdo, $empleadoId, $tipo);
            if ($error) {
                respond(['success' => false, 'error' => $error], 400);
            }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO fichajes (empleado_id, tipo, fecha_hora) VALUES (:empleado_id, :tipo, :fecha_hora)'
                );
                $stmt->execute([
                    ':empleado_id' => $empleadoId,
                    ':tipo' => $tipo,
                    ':fecha_hora' => date('Y-m-d H:i:s'),
                ]);

                respond([
                    'success' => true,
                    'id' => (int)$pdo->lastInsertId(),
                    'empleado_id' => $empleadoId,
                    'tipo' => $tipo,
                ]);
            } catch (Throwable $e) {
                respond(['success' => false, 'error' => 'No se pudo guardar el fichaje: ' . $e->getMessage()], 500);
            }
            break;

        default:
            // Fichaje de empleado autenticado con validaciones de negocio
            $user = requireLogin();

            $tipo = isset($input['tipo']) ? trim($input['tipo']) : '';
            $fechaHora = !empty($input['fecha_hora']) ? trim($input['fecha_hora']) : null;

            $error = validatePunch($pdo, $user['id'], $tipo);
            if ($error) {
                respond(['success' => false, 'error' => $error], 400);
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
            break;
    }
}

// --- GET routes ---
if ($method === 'GET') {
    switch ($action) {
        case 'session':
            // Devuelve la sesión activa si existe
            $user = currentUser();
            respond(['success' => true, 'user' => $user]);
            break;

        case 'stats':
            // Dashboard admin: contadores y últimos fichajes
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
                 LIMIT 20'
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
            break;

        case 'get_kiosk_data':
            // Datos de empleados con descriptor facial para kiosco
            $providedToken = '';
            if (!empty($_SERVER['HTTP_X_KIOSK_TOKEN'])) {
                $providedToken = $_SERVER['HTTP_X_KIOSK_TOKEN'];
            } elseif (!empty($_GET['token'])) {
                $providedToken = $_GET['token'];
            }
            if (!validateKioskToken($providedToken, $kioskToken)) {
                respond(['success' => false, 'error' => 'Token kiosco inválido'], 401);
            }

            $rows = $pdo->query('SELECT id, nombre, cedula, rol, face_descriptor FROM empleados WHERE face_descriptor IS NOT NULL')->fetchAll();
            $empleados = [];
            foreach ($rows as $row) {
                $empleados[] = [
                    'id' => (int)$row['id'],
                    'nombre' => $row['nombre'],
                    'cedula' => $row['cedula'],
                    'rol' => $row['rol'],
                    'face_descriptor' => decodeDescriptor($row['face_descriptor']),
                ];
            }
            respond(['success' => true, 'empleados' => $empleados]);
            break;

        case 'export_csv':
            // Exporta historial filtrado en CSV (solo admin)
            requireLogin('admin');

            $empleadoId = isset($_GET['empleado_id']) ? (int)$_GET['empleado_id'] : 0;
            if (!$empleadoId) {
                respond(['success' => false, 'error' => 'Empleado requerido'], 400);
            }

            $filters = [
                'empleado_id' => $empleadoId,
                'limit' => isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 500,
                'start_date' => isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '',
                'end_date' => isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : '',
            ];

            $rows = fetchLogs($pdo, $filters);

            $filename = 'reporte_fichajes_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM para compatibilidad Excel
            fputcsv($output, ['ID Empleado', 'Cédula', 'Nombre', 'Tipo', 'Fecha', 'Hora']);

            foreach ($rows as $row) {
                $ts = strtotime($row['fecha_hora']);
                fputcsv($output, [
                    $row['empleado_id'],
                    $row['cedula'],
                    $row['empleado'],
                    $row['tipo'],
                    date('Y-m-d', $ts),
                    date('H:i:s', $ts),
                ]);
            }
            fclose($output);
            exit;

        default:
            // Historial: del usuario o del empleado indicado por admin
            $user = requireLogin();
            $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;

            $empleadoId = $user['id'];
            if ($user['rol'] === 'admin' && isset($_GET['empleado_id'])) {
                $empleadoId = (int)$_GET['empleado_id'];
            }

            $filters = [
                'empleado_id' => $empleadoId,
                'limit' => $limit,
                'start_date' => isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '',
                'end_date' => isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : '',
            ];

            $rows = fetchLogs($pdo, $filters);

            respond([
                'success' => true,
                'data' => $rows,
            ]);
            break;
    }
}

respond(['success' => false, 'error' => 'Metodo no permitido'], 405);

// --- Helpers ---
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

    if (hash_equals($stored, $plain) || hash_equals($stored, hash('sha256', $plain))) {
        $needsRehash = true;
        return true;
    }

    return false;
}

function getTodayStats(PDO $pdo, $empleadoId)
{
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT tipo, fecha_hora FROM fichajes WHERE empleado_id = :id AND DATE(fecha_hora) = :today ORDER BY fecha_hora DESC, id DESC'
    );
    $stmt->execute([':id' => $empleadoId, ':today' => $today]);
    $rows = $stmt->fetchAll();

    $last = count($rows) ? $rows[0] : null;
    $count = count($rows);

    return [$count, $last];
}

function validatePunch(PDO $pdo, $empleadoId, $tipo)
{
    global $validTypes;
    if (!in_array($tipo, $validTypes, true)) {
        return 'Tipo de fichaje invalido.';
    }

    [$count, $last] = getTodayStats($pdo, $empleadoId);

    if ($count >= 4) {
        return 'Límite de fichajes del día alcanzado (4).';
    }

    if ($last) {
        $lastTs = strtotime($last['fecha_hora']);
        if (time() - $lastTs < 120) {
            return 'Debes esperar 2 minutos entre fichajes.';
        }
    }

    $lastType = $last ? $last['tipo'] : null;

    if ($count === 0 && $tipo !== 'Entrada') {
        return 'Debes marcar Entrada para iniciar el turno.';
    }

    if ($lastType === 'Entrada' && !in_array($tipo, ['Salida Descanso', 'Salida'], true)) {
        return 'Después de Entrada solo puedes marcar Salida Descanso o Salida.';
    }

    if ($lastType === 'Salida Descanso' && $tipo !== 'Vuelta Descanso') {
        return 'Debes marcar Vuelta Descanso después de Salida Descanso.';
    }

    if ($lastType === 'Vuelta Descanso' && $tipo !== 'Salida') {
        return 'Después de Vuelta Descanso solo puedes marcar Salida.';
    }

    if ($lastType === 'Salida') {
        return 'El turno ya fue cerrado con Salida. Inicia uno nuevo mañana.';
    }

    return null;
}

function validateKioskToken($provided, $expected)
{
    if ($expected === '') {
        return true;
    }
    return hash_equals((string)$expected, (string)$provided);
}

function parseDescriptor($input)
{
    if (!is_array($input)) {
        return null;
    }

    $clean = [];
    foreach ($input as $value) {
        if (!is_numeric($value)) {
            return null;
        }
        $clean[] = (float)$value;
    }

    if (count($clean) !== 128) {
        return null;
    }

    return $clean;
}

function decodeDescriptor($raw)
{
    if ($raw === null) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    return array_map('floatval', $decoded);
}

function fetchLogs(PDO $pdo, array $filters)
{
    $limit = isset($filters['limit']) ? max(1, min(500, (int)$filters['limit'])) : 200;

    $conditions = [];
    $params = [];

    if (!empty($filters['empleado_id'])) {
        $conditions[] = 'f.empleado_id = :empleado_id';
        $params[':empleado_id'] = (int)$filters['empleado_id'];
    }

    if (!empty($filters['start_date'])) {
        $conditions[] = 'DATE(f.fecha_hora) >= :start_date';
        $params[':start_date'] = $filters['start_date'];
    }

    if (!empty($filters['end_date'])) {
        $conditions[] = 'DATE(f.fecha_hora) <= :end_date';
        $params[':end_date'] = $filters['end_date'];
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $sql = 'SELECT f.id, f.empleado_id, e.cedula, e.nombre AS empleado, e.rol, f.tipo, f.fecha_hora
            FROM fichajes f
            JOIN empleados e ON e.id = f.empleado_id
            ' . $where . '
            ORDER BY f.fecha_hora DESC, f.id DESC
            LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
