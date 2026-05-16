<?php
// app/CatalogoModel.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class CatalogoModel {
    public static function ensureReparacionesSchema() {
        static $done = false;
        if ($done) return;
        $pdo = getDbConnection();
        if (!$pdo->query("SHOW COLUMNS FROM reparaciones LIKE 'tecnico_nombre_historico'")->fetch()) {
            $pdo->exec("ALTER TABLE reparaciones ADD COLUMN tecnico_nombre_historico VARCHAR(100) NULL AFTER tecnico_id");
        }
        if (!$pdo->query("SHOW COLUMNS FROM reparaciones LIKE 'valor_ahorrado'")->fetch()) {
            $pdo->exec("ALTER TABLE reparaciones ADD COLUMN valor_ahorrado DECIMAL(12,2) NULL AFTER fecha_reparado");
            self::backfillValorAhorrado();
        }
        $done = true;
    }

    /**
     * Populate reparaciones.valor_ahorrado from equipos_catalogo.valor for
     * already-completed repairs (estado LIKE 'REPARADO%'). Runs once when the
     * column is first added — best approximation for historical rows where
     * we never captured the catalog value at the time of completion.
     */
    private static function backfillValorAhorrado() {
        $pdo = getDbConnection();
        $rows = $pdo->query("
            SELECT r.id, ec.valor
            FROM reparaciones r
            LEFT JOIN equipos_catalogo ec ON ec.nombre = r.equipo
            WHERE r.valor_ahorrado IS NULL
              AND UPPER(r.estado) LIKE 'REPARADO%'
              AND ec.valor IS NOT NULL
              AND ec.valor <> ''
        ")->fetchAll();
        if (!$rows) return;
        $update = $pdo->prepare("UPDATE reparaciones SET valor_ahorrado = ? WHERE id = ?");
        foreach ($rows as $row) {
            $val = parseArsToFloat($row['valor']);
            if ($val !== null) {
                $update->execute([$val, $row['id']]);
            }
        }
    }

    /**
     * Apply the same partial update to several equipos at once.
     * Only whitelisted columns ('familia', 'valor') are accepted from the
     * outside. Empty string values become NULL so the admin can clear fields.
     * Returns the number of rows actually changed.
     */
    public static function bulkUpdateEquipos(array $ids, array $updates) {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (!$ids) return 0;

        $allowed = ['familia', 'valor'];
        $sets = [];
        $params = [];
        foreach ($updates as $col => $val) {
            if (!in_array($col, $allowed, true)) continue;
            $sets[] = "$col = ?";
            $params[] = ($val === '' || $val === null) ? null : trim((string)$val);
        }
        if (!$sets) return 0;

        self::ensureEquiposSchema();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE equipos_catalogo SET " . implode(', ', $sets) . " WHERE id IN ($placeholders)";
        $pdo = getDbConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $ids));
        return $stmt->rowCount();
    }

    /**
     * Returns the equipo row that already uses the given lab, or null.
     * Pass excludeId to skip a specific record (useful when editing).
     */
    public static function findEquipoByLab($lab, $excludeId = null) {
        if ($lab === null || $lab === '' || (int)$lab <= 0) return null;
        self::ensureEquiposSchema();
        $pdo = getDbConnection();
        $sql = "SELECT id, nombre, lab FROM equipos_catalogo WHERE lab = ?";
        $params = [(int)$lab];
        if ($excludeId !== null && $excludeId !== '') {
            $sql .= " AND id <> ?";
            $params[] = (int)$excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public static function ensureEquiposSchema() {
        static $done = false;
        if ($done) return;
        $pdo = getDbConnection();
        if (!$pdo->query("SHOW COLUMNS FROM equipos_catalogo LIKE 'lab'")->fetch()) {
            $pdo->exec("ALTER TABLE equipos_catalogo ADD COLUMN lab INT NULL UNIQUE AFTER id");
        }
        if (!$pdo->query("SHOW COLUMNS FROM equipos_catalogo LIKE 'familia'")->fetch()) {
            $pdo->exec("ALTER TABLE equipos_catalogo ADD COLUMN familia VARCHAR(100) NULL AFTER nombre");
        }
        self::backfillEquipoLabs();
        $done = true;
    }

    /**
     * For equipos whose name contains "(LABxxx)" but lab IS NULL (legacy rows
     * created before the lab column existed, or imported from CSV), extract
     * the number from the name and persist it into the lab column. Skips
     * conflicts so the UNIQUE constraint isn't violated.
     */
    private static function backfillEquipoLabs() {
        $pdo = getDbConnection();
        $rows = $pdo->query("
            SELECT id, nombre FROM equipos_catalogo
            WHERE lab IS NULL AND nombre LIKE '%(LAB%'
        ")->fetchAll();
        if (!$rows) return;
        foreach ($rows as $row) {
            if (preg_match('/\(LAB\s*(\d+)\)/i', $row['nombre'], $m)) {
                $lab = (int)$m[1];
                if ($lab <= 0) continue;
                try {
                    $update = $pdo->prepare("UPDATE equipos_catalogo SET lab = ? WHERE id = ? AND lab IS NULL");
                    $update->execute([$lab, $row['id']]);
                } catch (PDOException $e) {
                    // Duplicate lab — leave this row null, conflict will surface on next edit attempt
                }
            }
        }
    }

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

    /**
     * Normalize a free-text catalog name: trim, collapse internal whitespace.
     * Case is preserved — the schema's utf8mb4_unicode_ci collation handles
     * case-insensitive UNIQUE matching at the DB level.
     */
    private static function normalizeNombre($nombre) {
        $nombre = trim((string)$nombre);
        $nombre = preg_replace('/\s+/u', ' ', $nombre);
        return $nombre;
    }

    public static function getAll($tipo, $onlyActive = true) {
        $tabla = self::getTable($tipo);
        if (!$tabla) return [];

        if ($tipo === 'equipos') self::ensureEquiposSchema();

        $pdo = getDbConnection();
        $sql = "SELECT * FROM $tabla";
        if ($tipo === 'tecnicos' && $onlyActive) {
            $sql .= " WHERE activo = 1";
        }
        $sql .= " ORDER BY nombre ASC";
        return $pdo->query($sql)->fetchAll();
    }

    public static function getById($tipo, $id) {
        $tabla = self::getTable($tipo);
        if (!$tabla) return null;
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM $tabla WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * For equipos, $extra may be a string (legacy: valor) or an array:
     *   ['valor' => ..., 'lab' => ..., 'familia' => ...]
     * lab is an optional unique integer identifier.
     * familia links the equipo to a row in familias_catalogo (free-text match).
     */
    public static function agregar($tipo, $nombre, $extra = null) {
        $tabla = self::getTable($tipo);
        if (!$tabla) return false;

        $nombre = self::normalizeNombre($nombre);
        if ($nombre === '') return false;

        $pdo = getDbConnection();
        try {
            if ($tipo === 'equipos') {
                self::ensureEquiposSchema();
                [$valor, $lab, $familia] = self::unpackEquipoExtra($extra);
                $stmt = $pdo->prepare("INSERT INTO $tabla (nombre, valor, lab, familia) VALUES (?, ?, ?, ?)");
                return $stmt->execute([$nombre, $valor, $lab, $familia]);
            }
            $stmt = $pdo->prepare("INSERT INTO $tabla (nombre) VALUES (?)");
            return $stmt->execute([$nombre]);
        } catch (PDOException $e) {
            return false; // Posible duplicado
        }
    }

    public static function editar($tipo, $id, $nombre, $extra = null, $activo = null) {
        $tabla = self::getTable($tipo);
        if (!$tabla) return false;

        $nombre = self::normalizeNombre($nombre);
        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre no puede estar vacío.');
        }

        $pdo = getDbConnection();
        $fields = ["nombre = ?"];
        $params = [$nombre];

        if ($tipo === 'equipos') {
            self::ensureEquiposSchema();
            [$valor, $lab, $familia] = self::unpackEquipoExtra($extra);
            $fields[] = "valor = ?";
            $params[] = $valor;
            $fields[] = "lab = ?";
            $params[] = $lab;
            $fields[] = "familia = ?";
            $params[] = $familia;
        }

        if ($tipo === 'tecnicos' && $activo !== null) {
            $fields[] = "activo = ?";
            $params[] = $activo ? 1 : 0;
        }

        $params[] = $id;
        $sql = "UPDATE $tabla SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    private static function unpackEquipoExtra($extra) {
        if (is_array($extra)) {
            $valor = isset($extra['valor']) && trim((string)$extra['valor']) !== '' ? trim((string)$extra['valor']) : null;
            $lab = isset($extra['lab']) && $extra['lab'] !== '' ? (int)$extra['lab'] : null;
            $familia = isset($extra['familia']) && trim((string)$extra['familia']) !== '' ? trim((string)$extra['familia']) : null;
            return [$valor, $lab, $familia];
        }
        // Legacy: extra is just the "valor" string
        $valor = $extra !== null && trim((string)$extra) !== '' ? trim((string)$extra) : null;
        return [$valor, null, null];
    }

    /**
     * For tecnicos: hard delete, but first copies the technician name into
     * reparaciones.tecnico_nombre_historico so historical records keep
     * showing who handled them. The FK is ON DELETE SET NULL, so tecnico_id
     * becomes NULL automatically. Queries should COALESCE both fields.
     *
     * For other catalogs: hard delete (reparaciones store the name as a
     * free-text string, no FK relationship).
     */
    public static function eliminar($tipo, $id) {
        $tabla = self::getTable($tipo);
        if (!$tabla) return false;

        $pdo = getDbConnection();

        if ($tipo === 'tecnicos') {
            self::ensureReparacionesSchema();
            // Snapshot the name into reparaciones before the FK nulls it out.
            $pdo->prepare("
                UPDATE reparaciones r
                JOIN tecnicos t ON r.tecnico_id = t.id
                SET r.tecnico_nombre_historico = t.nombre
                WHERE t.id = ? AND r.tecnico_nombre_historico IS NULL
            ")->execute([$id]);
        }

        $stmt = $pdo->prepare("DELETE FROM $tabla WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public static function toggleTecnicoActivo($id, $activo) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE tecnicos SET activo = ? WHERE id = ?");
        return $stmt->execute([$activo ? 1 : 0, $id]);
    }

    public static function asegurarExiste($tipo, $nombre) {
        $nombre = self::normalizeNombre($nombre);
        if ($nombre === '') return null;

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

    /**
     * Returns [name => total_repair_count] for free-text catalogs whose
     * value is stored as a string column on reparaciones (sala, equipo, familia).
     * Names are matched case-insensitively and trimmed to align with the
     * normalization applied on insert/update.
     */
    public static function getUsoEnReparaciones($tipo) {
        $columns = ['salas' => 'sala', 'equipos' => 'equipo', 'familias' => 'familia'];
        if (!isset($columns[$tipo])) return [];

        $col = $columns[$tipo];
        $pdo = getDbConnection();
        $sql = "
            SELECT UPPER(TRIM($col)) AS clave, COUNT(*) AS cnt
            FROM reparaciones
            WHERE $col IS NOT NULL AND TRIM($col) <> ''
            GROUP BY UPPER(TRIM($col))
        ";
        $rows = $pdo->query($sql)->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['clave']] = (int)$r['cnt'];
        }
        return $out;
    }

    /**
     * Returns [tecnico_id => active_repair_count] for reparaciones whose
     * estado is not closed (entregado / reparado* / sin reparacion*).
     */
    public static function getReparacionesActivasPorTecnico() {
        $pdo = getDbConnection();
        $sql = "
            SELECT tecnico_id, COUNT(*) AS cnt
            FROM reparaciones
            WHERE tecnico_id IS NOT NULL
              AND UPPER(TRIM(estado)) <> 'ENTREGADO'
              AND UPPER(TRIM(estado)) NOT LIKE 'REPARADO%'
              AND UPPER(TRIM(estado)) NOT LIKE 'SIN REPARACION%'
            GROUP BY tecnico_id
        ";
        $rows = $pdo->query($sql)->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['tecnico_id']] = (int)$r['cnt'];
        }
        return $out;
    }
}
