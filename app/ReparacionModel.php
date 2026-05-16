<?php
// app/ReparacionModel.php
require_once __DIR__ . '/db.php';

class ReparacionModel {
    public static function getList($filtros = [], $limit = 50, $offset = 0) {
        $pdo = getDbConnection();
        $params = [];

        $sql = "SELECT r.*, COALESCE(t.nombre, r.tecnico_nombre_historico) AS tecnico_nombre
                FROM reparaciones r
                LEFT JOIN tecnicos t ON r.tecnico_id = t.id";

        $where = self::buildWhereClauses($filtros, $params);

        if (count($where) > 0) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $orderColumn = self::resolveOrderColumn($filtros['estado'] ?? 'TODAS');
        $sql .= " ORDER BY {$orderColumn} DESC, r.id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Returns counts for every tab in one query. Replaces 7 sequential
     * getCount() calls with a single SELECT that uses SUM(CASE WHEN ...)
     * per tab. The non-estado filters (search, sala, tecnico, dates) are
     * applied once in the WHERE; each tab's estado predicate is encoded
     * as a CASE WHEN branch.
     *
     * Returns: [URGENTES=>int, MIS_REPARACIONES=>int, PEND_REPARACION=>int,
     *           EN_REPARACION=>int, REPARADOS=>int, SIN_REPARACION=>int,
     *           PENDIENTES=>int, TODAS=>int]
     */
    public static function getTabCounts($filtros = []) {
        $pdo = getDbConnection();
        $params = [];

        // Strip estado-specific filters so the WHERE only contains
        // cross-tab filters (search, sala, tecnico, dates).
        $f = $filtros;
        $f['estado'] = 'TODAS';
        unset($f['estado_exacto']);
        $where = self::buildWhereClauses($f, $params);

        $tecnicoSesion = (int)($_SESSION['tecnico_id'] ?? 0);

        $sql = "SELECT
            SUM(CASE WHEN r.urgente = 'SI'
                AND UPPER(r.estado) NOT LIKE 'REPARADO%'
                AND UPPER(r.estado) NOT LIKE 'SIN REPARACION%'
                AND UPPER(r.estado) <> 'ENTREGADO'
            THEN 1 ELSE 0 END) AS URGENTES,
            SUM(CASE WHEN r.tecnico_id = ?
                AND UPPER(r.estado) NOT LIKE 'REPARADO%'
                AND UPPER(r.estado) NOT LIKE 'SIN REPARACION%'
                AND UPPER(r.estado) <> 'ENTREGADO'
            THEN 1 ELSE 0 END) AS MIS_REPARACIONES,
            SUM(CASE WHEN UPPER(r.estado) = 'PEND. DE REVISION' THEN 1 ELSE 0 END) AS PEND_REPARACION,
            SUM(CASE WHEN UPPER(r.estado) = 'EN REPARACION' OR UPPER(r.estado) LIKE 'EN PRUEBA%' THEN 1 ELSE 0 END) AS EN_REPARACION,
            SUM(CASE WHEN UPPER(r.estado) LIKE 'REPARADO%' THEN 1 ELSE 0 END) AS REPARADOS,
            SUM(CASE WHEN UPPER(r.estado) LIKE 'SIN REPARACION%' THEN 1 ELSE 0 END) AS SIN_REPARACION,
            SUM(CASE WHEN UPPER(r.estado) NOT LIKE 'REPARADO%'
                AND UPPER(r.estado) NOT LIKE 'SIN REPARACION%'
                AND UPPER(r.estado) <> 'PEND. DE REVISION'
                AND UPPER(r.estado) <> 'EN REPARACION'
                AND UPPER(r.estado) NOT LIKE 'EN PRUEBA%'
                AND UPPER(r.estado) <> 'ENTREGADO'
            THEN 1 ELSE 0 END) AS PENDIENTES,
            COUNT(*) AS TODAS
        FROM reparaciones r
        LEFT JOIN tecnicos t ON r.tecnico_id = t.id";

        // The session tecnico_id is the first placeholder in the SELECT
        // (MIS_REPARACIONES CASE), so it goes before the WHERE params.
        $allParams = array_merge([$tecnicoSesion], $params);

        if (count($where) > 0) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);
        $row = $stmt->fetch();

        return $row ? array_map('intval', $row) : [
            'URGENTES' => 0, 'MIS_REPARACIONES' => 0, 'PEND_REPARACION' => 0,
            'EN_REPARACION' => 0, 'REPARADOS' => 0, 'SIN_REPARACION' => 0,
            'PENDIENTES' => 0, 'TODAS' => 0,
        ];
    }

    public static function getCount($filtros = []) {
        $pdo = getDbConnection();
        $params = [];

        $sql = "SELECT COUNT(*) as total FROM reparaciones r LEFT JOIN tecnicos t ON r.tecnico_id = t.id";
        $where = self::buildWhereClauses($filtros, $params);

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
            SELECT r.*, COALESCE(t.nombre, r.tecnico_nombre_historico) AS tecnico_nombre
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

    private static function buildWhereClauses($filtros, &$params) {
        $where = [];
        $estado = strtoupper((string)($filtros['estado'] ?? 'TODAS'));

        if (!empty($filtros['busqueda'])) {
            $b = '%' . $filtros['busqueda'] . '%';
            $where[] = "(r.npu LIKE ? OR r.uid LIKE ? OR r.equipo LIKE ? OR r.sala LIKE ? OR r.parte LIKE ? OR r.estado LIKE ? OR COALESCE(t.nombre, r.tecnico_nombre_historico) LIKE ?)";
            array_push($params, $b, $b, $b, $b, $b, $b, $b);
        }

        if (!empty($filtros['sala'])) {
            $where[] = "r.sala = ?";
            $params[] = $filtros['sala'];
        }

        if (!empty($filtros['tecnico_id'])) {
            $where[] = "r.tecnico_id = ?";
            $params[] = $filtros['tecnico_id'];
        } elseif (!empty($filtros['tecnico_sin_asignar'])) {
            $where[] = "r.tecnico_id IS NULL";
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

        if (!empty($filtros['solo_urgentes'])) {
            $where[] = "r.urgente = 'SI'";
        }

        if (!empty($filtros['estado_exacto'])) {
            $where[] = "r.estado = ?";
            $params[] = $filtros['estado_exacto'];
            return $where;
        }

        switch ($estado) {
            case 'URGENTES':
                $where[] = "r.urgente = 'SI'";
                $where[] = "UPPER(r.estado) NOT LIKE 'REPARADO%'";
                $where[] = "UPPER(r.estado) NOT LIKE 'SIN REPARACION%'";
                $where[] = "UPPER(r.estado) != 'ENTREGADO'";
                break;
            case 'MIS_REPARACIONES':
                $where[] = "r.tecnico_id = ?";
                $params[] = $_SESSION['tecnico_id'] ?? 0;
                $where[] = "UPPER(r.estado) NOT LIKE 'REPARADO%'";
                $where[] = "UPPER(r.estado) NOT LIKE 'SIN REPARACION%'";
                $where[] = "UPPER(r.estado) != 'ENTREGADO'";
                break;
            case 'PEND_REPARACION':
                $where[] = "UPPER(r.estado) = 'PEND. DE REVISION'";
                break;
            case 'EN_REPARACION':
                $where[] = "(UPPER(r.estado) = 'EN REPARACION' OR UPPER(r.estado) LIKE 'EN PRUEBA%')";
                break;
            case 'REPARADOS':
                $where[] = "UPPER(r.estado) LIKE 'REPARADO%'";
                break;
            case 'SIN_REPARACION':
                $where[] = "UPPER(r.estado) LIKE 'SIN REPARACION%'";
                break;
            case 'PENDIENTES':
                $where[] = "UPPER(r.estado) NOT LIKE 'REPARADO%'";
                $where[] = "UPPER(r.estado) NOT LIKE 'SIN REPARACION%'";
                $where[] = "UPPER(r.estado) != 'PEND. DE REVISION'";
                $where[] = "UPPER(r.estado) != 'EN REPARACION'";
                $where[] = "UPPER(r.estado) NOT LIKE 'EN PRUEBA%'";
                $where[] = "UPPER(r.estado) != 'ENTREGADO'";
                break;
        }

        return $where;
    }

    private static function resolveOrderColumn($estado) {
        $estado = strtoupper((string)$estado);
        if ($estado === 'REPARADOS') {
            return 'COALESCE(r.fecha_reparado, r.fecha)';
        }
        if ($estado === 'EN_REPARACION') {
            return 'COALESCE(r.fecha_en_reparacion, r.fecha)';
        }
        return 'r.fecha';
    }
}
