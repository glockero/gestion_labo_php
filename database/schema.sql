CREATE DATABASE IF NOT EXISTS laboratorio_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE laboratorio_db;

CREATE TABLE tecnicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    activo BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('admin', 'tecnico') NOT NULL DEFAULT 'tecnico',
    tecnico_id INT NULL,
    activo BOOLEAN DEFAULT TRUE,
    last_login DATETIME NULL,
    last_ip VARCHAR(45) NULL,
    session_id VARCHAR(128) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tecnico_id) REFERENCES tecnicos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estados_catalogo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipos_catalogo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    valor VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE salas_catalogo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE familias_catalogo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reparaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATETIME NOT NULL,
    sala VARCHAR(100) NOT NULL,
    uid VARCHAR(100) NULL,
    npu VARCHAR(100) NULL,
    parte VARCHAR(255) NULL,
    familia VARCHAR(100) NULL,
    equipo VARCHAR(100) NOT NULL,
    urgente ENUM('SI', 'NO') DEFAULT 'NO',
    tecnico_id INT NULL,
    estado VARCHAR(100) DEFAULT 'PEND. DE REVISION',
    observaciones TEXT NULL,
    dia_semana VARCHAR(20) NULL,
    fecha_en_reparacion DATETIME NULL,
    fecha_reparado DATETIME NULL,
    fecha_pendiente DATETIME NULL,
    fecha_sin_reparacion DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tecnico_id) REFERENCES tecnicos(id) ON DELETE SET NULL,
    INDEX idx_fecha (fecha),
    INDEX idx_sala (sala),
    INDEX idx_estado (estado),
    INDEX idx_npu (npu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE historial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reparacion_id INT NULL,
    usuario_id INT NULL,
    accion VARCHAR(255) NOT NULL,
    detalle TEXT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reparacion_id) REFERENCES reparaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
