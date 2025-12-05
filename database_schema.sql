-- CloudTimes Evolution - Base de Datos (MySQL 8.0 / InnoDB)
-- Ejecuta este script dentro de la base de datos deseada en cPanel.

DROP TABLE IF EXISTS attendance_logs;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS shifts;

CREATE TABLE shifts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  tolerance_minutes INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shift_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL,
  role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  shift_id INT NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_shift FOREIGN KEY (shift_id) REFERENCES shifts (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type ENUM('check_in', 'break_out', 'break_in', 'check_out') NOT NULL,
  timestamp DATETIME NOT NULL,
  location_data JSON NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  KEY idx_logs_user_ts (user_id, timestamp),
  KEY idx_logs_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seeds: turnos
INSERT INTO shifts (name, start_time, end_time, tolerance_minutes) VALUES
('Turno Manana', '09:00:00', '17:00:00', 15),
('Turno Tarde', '13:00:00', '21:00:00', 10);

-- Seeds: usuarios (password_hash corresponde a "password123")
INSERT INTO users (full_name, email, role, shift_id, password_hash) VALUES
('Ana Martinez', 'ana@cloudtimes.test', 'admin', 1, '$2y$10$uX8Y7R6pujISiFDUFxIrEOyYqS/fWznuaCG2lLBVmOAXoJ1i8LJiW'),
('Bruno Perez', 'bruno@cloudtimes.test', 'user', 1, '$2y$10$uX8Y7R6pujISiFDUFxIrEOyYqS/fWznuaCG2lLBVmOAXoJ1i8LJiW'),
('Carmen Diaz', 'carmen@cloudtimes.test', 'user', 2, '$2y$10$uX8Y7R6pujISiFDUFxIrEOyYqS/fWznuaCG2lLBVmOAXoJ1i8LJiW');

-- Seeds: logs de asistencia (muestra de un dia)
INSERT INTO attendance_logs (user_id, type, timestamp, location_data) VALUES
(1, 'check_in', '2024-07-15 08:58:00', JSON_OBJECT('ip', '10.0.0.12')),
(1, 'break_out', '2024-07-15 13:05:00', NULL),
(1, 'break_in', '2024-07-15 13:38:00', NULL),
(1, 'check_out', '2024-07-15 17:02:00', JSON_OBJECT('device', 'web')),
(2, 'check_in', '2024-07-15 09:16:00', JSON_OBJECT('ip', '10.0.0.21')),
(2, 'break_out', '2024-07-15 12:59:00', NULL),
(2, 'break_in', '2024-07-15 13:45:00', NULL),
(2, 'check_out', '2024-07-15 17:20:00', JSON_OBJECT('device', 'mobile')),
(3, 'check_in', '2024-07-15 13:04:00', JSON_OBJECT('ip', '10.0.0.33'));
