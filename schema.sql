-- Database schema for attendance control with auth and roles
-- Run on a clean database. Drops existing tables to avoid column mismatches.

DROP TABLE IF EXISTS fichajes;
DROP TABLE IF EXISTS empleados;

CREATE TABLE empleados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  cedula VARCHAR(150) NOT NULL,
  password VARCHAR(255) NOT NULL,
  face_descriptor JSON NULL,
  rol ENUM('admin', 'empleado') NOT NULL DEFAULT 'empleado',
  turno_id INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cedula (cedula)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fichajes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empleado_id INT NOT NULL,
  tipo ENUM('Entrada', 'Salida Descanso', 'Vuelta Descanso', 'Salida') NOT NULL,
  fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed admin user and sample employees
-- Las contraseñas pueden ser texto plano; el API rehashea al primer login.
INSERT INTO empleados (nombre, cedula, password, rol, turno_id) VALUES
  ('Usuario Maestro', 'administracion@amvstore.com.uy', 'AmVadmin123', 'admin', 1),
  ('Ana Torres', 'ana.torres', '1234.', 'empleado', 1),
  ('Luis Perez', 'luis.perez', '1234.', 'empleado', 2);
