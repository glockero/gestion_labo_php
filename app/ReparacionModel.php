<?php
// app/ReparacionModel.php
require_once __DIR__ . '/db.php';

class ReparacionModel {
    public static function getList($filtros = [], $limit = 50, $offset = 0) {
        $pdo = getDbConnection();
        $where = [];
        $params = [];

        $sql = "SELECT r.*, t.nombre as tecnico_nombre 
                FROM reparaciones r 
                LEFT JOIN tecnicos t ON r.tecnico_id = t.id";

        if (!empty($filtros['estado']) && $filtros['estado'] !== 'TODAS') {
            if ($filtros['estado'] === 'MIS_REPARACIONES') {
                 $where[] = "r.tecnico_id = ?";
                 $params[] = $_SESSION['tecnico_id'] ?? 0;
            } elseif ($filtros['estado'] === 'URGENTES') {
                 $where[] = "r.urgente = 'SI' AND r.estado NOT IN ('REPARADO', 'SIN REPARACION', 'ENTREGADO')";
            } else {
                 $where[] = "r.estado = ?";
                 $params[] = $filtros['estado'];
            }
        }

        if (!empty($filtros['busqueda'])) {
            $b = '%' . $filtros['busqueda'] . '%';
            $where[] = "(r.npu LIKE ? OR r.uid LIKE ? OR r.equipo LIKE ? OR r.sala LIKE ? OR r.parte LIKE ?)";
            array_push($params, $b, $b, $b, $b, $b);
        }
        
        if (!empty($filtros['sala'])) {
            $where[] = "r.sala = ?";
            $params[] = $filtros['sala'];
        }
        
        if (!empty($filtros['tecnico_id'])) {
            $where[] = "r.tecnico_id = ?";
            $params[] = $filtros['tecnico_id'];
        }
        
        if (!empty($filtros['f_anio'])) {
            $where[] = "YEAR(r.fecha) = ?";
            $params[] = $filtros['f_anio'];
        }
        
        if (!empty($filtros['f_mes'])) {
            $where[] = "MONTH(r.fecha) = ?";
            $params[] = $filtros['f_mes'];
        }
        
        if (!empty($filtros['f_dia'])) {
            $where[] = "DAY(r.fecha) = ?";
            $params[] = $filtros['f_dia'];
        }
        
        if ($filtros['f_diasemana'] !== '') {
            // MySQL DAYOFWEEK: 1=Sun, 2=Mon... 7=Sat. The prompt assumed 0=Sun, 1=Mon...6=Sat or similar. 
            // We will map WEEKDAY() which is 0=Mon, 1=Tue...6=Sun, or we can use whatever the prompt used.
            // The template uses: 1=Lunes, 2=Martes... 0=Domingo.
            // Let's use a custom mapping or raw comparison if it's passed directly.
            // A simple way is to use WEEKDAY() + 1 for Mon-Sat, and for Sunday it's 0.
            $where[] = "(WEEKDAY(r.fecha) + 1) % 7 = ?";
            $params[] = (int)$filtros['f_diasemana'];
        }

        if (count($where) > 0) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY r.fecha DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public static function getCount($filtros = []) {
        $pdo = getDbConnection();
        $where = [];
        $params = [];

        $sql = "SELECT COUNT(*) as total FROM reparaciones r";

        if (!empty($filtros['estado']) && $filtros['estado'] !== 'TODAS') {
             if ($filtros['estado'] === 'MIS_REPARACIONES') {
                 $where[] = "r.tecnico_id = ?";
                 $params[] = $_SESSION['tecnico_id'] ?? 0;
            } elseif ($filtros['estado'] === 'URGENTES') {
                 $where[] = "r.urgente = 'SI' AND r.estado NOT IN ('REPARADO', 'SIN REPARACION', 'ENTREGADO')";
            } else {
                 $where[] = "r.estado = ?";
                 $params[] = $filtros['estado'];
            }
        }

        if (!empty($filtros['busqueda'])) {
            $b = '%' . $filtros['busqueda'] . '%';
            $where[] = "(r.npu LIKE ? OR r.uid LIKE ? OR r.equipo LIKE ? OR r.sala LIKE ? OR r.parte LIKE ?)";
            array_push($params, $b, $b, $b, $b, $b);
        }
        
        if (!empty($filtros['sala'])) {
            $where[] = "r.sala = ?";
            $params[] = $filtros['sala'];
        }
        
        if (!empty($filtros['tecnico_id'])) {
            $where[] = "r.tecnico_id = ?";
            $params[] = $filtros['tecnico_id'];
        }
        
        if (!empty($filtros['f_anio'])) {
            $where[] = "YEAR(r.fecha) = ?";
            $params[] = $filtros['f_anio'];
        }
        
        if (!empty($filtros['f_mes'])) {
            $where[] = "MONTH(r.fecha) = ?";
            $params[] = $filtros['f_mes'];
        }
        
        if (!empty($filtros['f_dia'])) {
            $where[] = "DAY(r.fecha) = ?";
            $params[] = $filtros['f_dia'];
        }
        
        if (isset($filtros['f_diasemana']) && $filtros['f_diasemana'] !== '') {
            $where[] = "(WEEKDAY(r.fecha) + 1) % 7 = ?";
            $params[] = (int)$filtros['f_diasemana'];
        }

        if (count($where) > 0) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    public static function getById($id) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT r.*, t.nombre as tecnico_nombre 
            FROM reparaciones r 
            LEFT JOIN tecnicos t ON r.tecnico_id = t.id 
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function getHistorialReparacion($id) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT h.*, u.username 
            FROM historial h 
            LEFT JOIN usuarios u ON h.usuario_id = u.id 
            WHERE h.reparacion_id = ? 
            ORDER BY h.fecha DESC
        ");
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }
    
    public static function checkNpuRepetido($npu, $exclude_id = null) {
        if (empty($npu)) return false;
        $pdo = getDbConnection();
        $sql = "SELECT COUNT(*) as count FROM reparaciones WHERE npu = ?";
        $params = [$npu];
        if ($exclude_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['count'] > 0;
    }
}
