<?php
// app/ImportService.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/CatalogoModel.php';

class ImportService {
    public static function importFamiliasCsv($filePath, $dryRun = false) {
        $pdo = getDbConnection();
        $handle = self::openCsv($filePath);
        $summary = ['inserted' => 0, 'skipped' => 0, 'errors' => 0, 'mode' => $dryRun ? 'preview' : 'import'];

        try {
            if ($dryRun) {
                $pdo->beginTransaction();
            }

            self::readHeaders($handle);
            $stmt = $pdo->prepare("INSERT IGNORE INTO familias_catalogo (nombre) VALUES (?)");

            while (($row = fgetcsv($handle)) !== false) {
                $nombre = trim((string)($row[0] ?? ''));
                if ($nombre === '') {
                    continue;
                }

                $stmt->execute([$nombre]);
                if ($stmt->rowCount() > 0) {
                    $summary['inserted']++;
                } else {
                    $summary['skipped']++;
                }
            }

            if ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            if ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            fclose($handle);
        }

        return $summary;
    }

    public static function importEquiposCsv($filePath, $dryRun = false) {
        $pdo = getDbConnection();
        $handle = self::openCsv($filePath);
        $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'mode' => $dryRun ? 'preview' : 'import'];

        try {
            if ($dryRun) {
                $pdo->beginTransaction();
            }

            $headers = self::readHeaders($handle);
            $findStmt = $pdo->prepare("SELECT id, valor FROM equipos_catalogo WHERE nombre = ?");
            $insertStmt = $pdo->prepare("INSERT INTO equipos_catalogo (nombre, valor) VALUES (?, ?)");
            $updateStmt = $pdo->prepare("UPDATE equipos_catalogo SET valor = ? WHERE id = ?");

            while (($row = fgetcsv($handle)) !== false) {
                $data = self::rowToAssoc($headers, $row);
                $nombre = trim((string)($data['nombre'] ?? ''));
                $valor = trim((string)($data['valor'] ?? ''));

                if ($nombre === '') {
                    continue;
                }

                $findStmt->execute([$nombre]);
                $existing = $findStmt->fetch();
                if (!$existing) {
                    $insertStmt->execute([$nombre, $valor !== '' ? $valor : null]);
                    $summary['inserted']++;
                    continue;
                }

                if ((string)($existing['valor'] ?? '') !== $valor) {
                    $updateStmt->execute([$valor !== '' ? $valor : null, $existing['id']]);
                    $summary['updated']++;
                } else {
                    $summary['skipped']++;
                }
            }

            if ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            if ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            fclose($handle);
        }

        return $summary;
    }

    public static function importReparacionesCsv($filePath, $dryRun = false) {
        set_time_limit(0);

        $pdo = getDbConnection();
        $handle = self::openCsv($filePath);
        $summary = [
            'inserted' => 0,
            'skipped' => 0,
            'errors' => 0,
            'states_mapped' => 0,
            'mode' => $dryRun ? 'preview' : 'import',
        ];
        $tecnicosCache = [];
        $estadosCache = [];
        $stateMapUsage = [];

        $findStmt = $pdo->prepare(
            "SELECT id FROM reparaciones
             WHERE fecha = ?
               AND sala = ?
               AND COALESCE(uid, '') = ?
               AND COALESCE(npu, '') = ?
               AND COALESCE(familia, '') = ?
               AND equipo = ?
               AND COALESCE(tecnico_id, 0) = ?
               AND estado = ?
             LIMIT 1"
        );

        $insertStmt = $pdo->prepare(
            "INSERT INTO reparaciones (
                fecha, sala, uid, npu, parte, familia, equipo, urgente,
                tecnico_id, estado, observaciones, dia_semana,
                fecha_en_reparacion, fecha_reparado, fecha_pendiente, fecha_sin_reparacion
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        try {
            $headers = self::readHeaders($handle);
            $pdo->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                try {
                    $data = self::rowToAssoc($headers, $row);

                    $sala = self::normalizeNullableText($data['sala'] ?? '') ?: 'SIN SALA';
                    $uid = self::normalizeNullableText($data['uid'] ?? '');
                    $npu = self::normalizeNullableText($data['npu'] ?? '');
                    if ($npu === '0') {
                        $npu = null;
                    }

                    $familia = self::normalizeNullableText($data['familia'] ?? '');
                    $equipo = self::normalizeNullableText($data['equipo'] ?? '') ?: 'SIN EQUIPO';
                    $urgente = self::normalizeUrgente($data['urgente'] ?? '');
                    $estadoOriginal = self::normalizeNullableText($data['estados de reparacion'] ?? '') ?: 'PEND. DE REVISION';
                    $estado = self::normalizeEstado($estadoOriginal);
                    if ($estado !== $estadoOriginal) {
                        $summary['states_mapped']++;
                        $stateMapUsage[] = "$estadoOriginal -> $estado";
                    }

                    $observaciones = self::normalizeNullableText($data['observaciones'] ?? '') ?? '';
                    if ($estado !== $estadoOriginal) {
                        $observaciones = trim("[IMPORTACION] Estado original: $estadoOriginal\n" . $observaciones);
                    }

                    $fecha = self::parseCsvDateTime($data['fecha'] ?? '', '00:00:00');
                    $fechaPendiente = self::parseCsvDateTime($data['f pendiente'] ?? '');
                    $fechaEnReparacion = self::parseCsvDateTime($data['f en rep'] ?? '');
                    $fechaReparado = self::parseCsvDateTime($data['f reparado'] ?? '');
                    $fechaSinReparacion = self::parseCsvDateTime($data['f sin rep'] ?? '');

                    self::appendIfInvalidDate($observaciones, $data['f pendiente'] ?? '', $fechaPendiente, 'F. Pendiente');
                    self::appendIfInvalidDate($observaciones, $data['f en rep'] ?? '', $fechaEnReparacion, 'F. En Rep.');
                    self::appendIfInvalidDate($observaciones, $data['f reparado'] ?? '', $fechaReparado, 'F. Reparado');
                    self::appendIfInvalidDate($observaciones, $data['f sin rep'] ?? '', $fechaSinReparacion, 'F. Sin Rep.');

                    if (!$fecha) {
                        $fecha = $fechaEnReparacion ?: $fechaPendiente ?: $fechaReparado ?: $fechaSinReparacion ?: date('Y-m-d H:i:s');
                    }

                    CatalogoModel::asegurarExiste('salas', $sala);
                    CatalogoModel::asegurarExiste('equipos', $equipo);
                    if ($familia) {
                        CatalogoModel::asegurarExiste('familias', $familia);
                    }
                    self::ensureEstado($pdo, $estado, $estadosCache);

                    $tecnicoId = self::resolveTecnicoId($pdo, $data['tecnico'] ?? '', $tecnicosCache);

                    $findStmt->execute([
                        $fecha,
                        $sala,
                        $uid ?? '',
                        $npu ?? '',
                        $familia ?? '',
                        $equipo,
                        $tecnicoId ?: 0,
                        $estado,
                    ]);

                    if ($findStmt->fetch()) {
                        $summary['skipped']++;
                        continue;
                    }

                    $diaSemana = (new DateTime($fecha))->format('l');

                    $insertStmt->execute([
                        $fecha,
                        $sala,
                        $uid,
                        $npu,
                        null,
                        $familia,
                        $equipo,
                        $urgente,
                        $tecnicoId,
                        $estado,
                        $observaciones !== '' ? $observaciones : null,
                        $diaSemana,
                        $fechaEnReparacion,
                        $fechaReparado,
                        $fechaPendiente,
                        $fechaSinReparacion,
                    ]);
                    $summary['inserted']++;
                } catch (Throwable $e) {
                    $summary['errors']++;
                }
            }

            if ($dryRun) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            fclose($handle);
        }

        if (!empty($stateMapUsage)) {
            $summary['state_map_detail'] = implode(', ', array_values(array_unique($stateMapUsage)));
        }

        return $summary;
    }

    public static function resetReparaciones() {
        $pdo = getDbConnection();
        $deleted = 0;

        $pdo->beginTransaction();
        try {
            $deleted = (int)$pdo->query("SELECT COUNT(*) FROM reparaciones")->fetchColumn();
            $pdo->exec("DELETE FROM reparaciones");
            $pdo->exec("ALTER TABLE reparaciones AUTO_INCREMENT = 1");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['deleted_reparaciones' => $deleted, 'mode' => 'reset'];
    }

    public static function getEstadoMappings() {
        return [
            'PEND. DE REVISION' => 'PEND. DE REVISION',
            'EN REPARACION' => 'EN REPARACION',
            'EN PRUEBA EN LAB' => 'EN REPARACION',
            'EN PRUEBA EN SALA' => 'EN REPARACION',
            'ESPERANDO REPUESTO' => 'PENDIENTE DE REPUESTO',
            'PENDIENTE DE REPUESTO' => 'PENDIENTE DE REPUESTO',
            'REPARADO' => 'REPARADO',
            'SIN REPARACION' => 'SIN REPARACION',
            'ENTREGADO' => 'ENTREGADO',
        ];
    }

    /**
     * Returns the absolute paths of directories from which CSVs may be loaded.
     * Anything outside these directories is rejected (defense against path
     * traversal via POSTed file_path). The list intentionally only contains
     * the project root and an optional uploads/ subdir.
     */
    /**
     * Returns a small sample of the CSV (first N rows) plus total row count.
     * Used to show the admin what they're about to import before committing.
     */
    public static function previewRows($filePath, $maxRows = 10) {
        $handle = self::openCsv($filePath);
        try {
            $headers = self::readHeaders($handle);
            $rows = [];
            $total = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($rows) < $maxRows) {
                    $rows[] = $row;
                }
                $total++;
            }
            return [
                'headers' => $headers,
                'rows' => $rows,
                'total' => $total,
                'displayed' => count($rows),
            ];
        } finally {
            fclose($handle);
        }
    }

    private static function getAllowedImportDirs() {
        $project = realpath(dirname(__DIR__));
        if ($project === false) {
            return [];
        }
        $dirs = [$project];
        $uploads = $project . DIRECTORY_SEPARATOR . 'uploads';
        if (is_dir($uploads)) {
            $dirs[] = realpath($uploads);
        }
        return array_filter($dirs);
    }

    private static function isPathAllowed($filePath) {
        $real = realpath($filePath);
        if ($real === false) {
            return false;
        }
        foreach (self::getAllowedImportDirs() as $base) {
            $baseWithSep = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($real === $base || strpos($real, $baseWithSep) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function openCsv($filePath) {
        if (!is_file($filePath)) {
            // Don't leak the resolved absolute path back to the user — only echo
            // the basename so the filesystem layout stays hidden.
            throw new RuntimeException("No existe el archivo: " . basename($filePath));
        }

        if (!self::isPathAllowed($filePath)) {
            throw new RuntimeException(
                "Ruta no permitida. Solo se pueden importar archivos ubicados dentro del directorio del proyecto."
            );
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new RuntimeException("Solo se permiten archivos con extensión .csv");
        }

        if (!is_readable($filePath)) {
            throw new RuntimeException("No se puede leer el archivo: " . basename($filePath));
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el archivo: " . basename($filePath));
        }

        return $handle;
    }

    private static function readHeaders($handle) {
        $headers = fgetcsv($handle);
        if ($headers === false) {
            throw new RuntimeException('El CSV está vacío.');
        }

        return array_map([self::class, 'normalizeHeader'], $headers);
    }

    private static function rowToAssoc($headers, $row) {
        $headerCount = count($headers);
        $rowCount = count($row);

        if ($rowCount < $headerCount) {
            $row = array_pad($row, $headerCount, '');
        } elseif ($rowCount > $headerCount) {
            $row = array_merge(
                array_slice($row, 0, $headerCount - 1),
                [implode(',', array_slice($row, $headerCount - 1))]
            );
        }

        return array_combine($headers, $row);
    }

    private static function normalizeHeader($value) {
        $value = str_replace("\xEF\xBB\xBF", '', (string)$value);
        $value = strtolower(trim($value));
        $value = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim($value);
    }

    private static function normalizeNullableText($value) {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private static function normalizeUrgente($value) {
        $value = strtoupper(trim((string)$value));
        return $value === 'SI' ? 'SI' : 'NO';
    }

    private static function normalizeEstado($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return 'PEND. DE REVISION';
        }

        $map = self::getEstadoMappings();
        return $map[$value] ?? $value;
    }

    private static function parseCsvDateTime($value, $defaultTime = null) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $formats = ['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt instanceof DateTime) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        $dateFormats = ['d/m/Y', 'Y-m-d'];
        foreach ($dateFormats as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt instanceof DateTime) {
                if ($defaultTime) {
                    [$hour, $minute, $second] = array_map('intval', explode(':', $defaultTime));
                    $dt->setTime($hour, $minute, $second);
                }
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private static function appendIfInvalidDate(&$observaciones, $rawValue, $parsedValue, $label) {
        $rawValue = trim((string)$rawValue);
        if ($rawValue !== '' && $parsedValue === null) {
            $observaciones = trim($observaciones . "\n[IMPORTACION] $label original no parseable: $rawValue");
        }
    }

    private static function resolveTecnicoId($pdo, $nombre, array &$cache) {
        $nombre = trim((string)$nombre);
        if ($nombre === '' || strtoupper($nombre) === 'SIN ASIGNAR') {
            return null;
        }

        if (isset($cache[$nombre])) {
            return $cache[$nombre];
        }

        $selectStmt = $pdo->prepare("SELECT id FROM tecnicos WHERE nombre = ? LIMIT 1");
        $selectStmt->execute([$nombre]);
        $id = $selectStmt->fetchColumn();
        if ($id) {
            $cache[$nombre] = (int)$id;
            return (int)$id;
        }

        $insertStmt = $pdo->prepare("INSERT INTO tecnicos (nombre, activo) VALUES (?, 1)");
        $insertStmt->execute([$nombre]);
        $cache[$nombre] = (int)$pdo->lastInsertId();
        return $cache[$nombre];
    }

    private static function ensureEstado($pdo, $nombre, array &$cache) {
        $nombre = trim((string)$nombre);
        if ($nombre === '') {
            return;
        }

        if (isset($cache[$nombre])) {
            return;
        }

        $selectStmt = $pdo->prepare("SELECT id FROM estados_catalogo WHERE nombre = ? LIMIT 1");
        $selectStmt->execute([$nombre]);
        if ($selectStmt->fetchColumn()) {
            $cache[$nombre] = true;
            return;
        }

        $insertStmt = $pdo->prepare("INSERT INTO estados_catalogo (nombre) VALUES (?)");
        $insertStmt->execute([$nombre]);
        $cache[$nombre] = true;
    }
}
