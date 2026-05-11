<?php
// app/CatalogoModel.php
require_once __DIR__ . '/db.php';

class CatalogoModel {
    private static function getTable($tipo) {
        $tablas = [
            'estados' => 'estados_catalogo',
            'equipos' => 'equipos_catalogo',
            'salas' => 'salas_catalogo',
            'familias' => 'familias_catalogo',
            'tecnicos' => 'tecnicos'
        ];
        return $tablas[$tipo] ?? null;
    }

    public static function getAll($tipo, $onlyActive = true) {
        $tabla = self::getTable($tipo);
        if (!$tabla) return [];

        $pdo = getDbConnection();
        $sql = "SELECT * FROM $tabla";
        if ($tipo === 'tecnicos' && $onlyActive) {
            $sql .= " WHERE activo = 1";
        }
        $sql .= " ORDER BY nombre ASC";
        return $pdo->query($sql)->fetchAll();
    }
    
    public static function agregar($tipo, $nombre, $extra = null) {
        $tabla = self::getTable($tipo);
        if (!$tabla) return false;
        
        $pdo = getDbConnection();
        try {
            if ($tipo === 'equipos' && $extra !== null) {
                 $stmt = $pdo->prepare("INSERT INTO $tabla (nombre, valor) VALUES (?, ?)");
                 return $stmt->execute([trim($nombre), trim($extra)]);
            } else {
                 $stmt = $pdo->prepare("INSERT INTO $tabla (nombre) VALUES (?)");
                 return $stmt->execute([trim($nombre)]);
            }
        } catch (PDOException $e) {
            return false; // Posible duplicado
        }
    }
    
    public static function asegurarExiste($tipo, $nombre) {
        if (empty(trim($nombre))) return null;
        $nombre = trim($nombre);
        
        $tabla = self::getTable($tipo);
        if (!$tabla) return null;
        
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT id FROM $tabla WHERE nombre = ?");
        $stmt->execute([$nombre]);
        $row = $stmt->fetch();
        
        if ($row) return $row['id'];
        
        self::agregar($tipo, $nombre);
        return $pdo->lastInsertId();
    }
}
