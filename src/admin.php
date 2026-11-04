<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/usuarios.php';
require_once __DIR__ . '/cuota.php';

/**
 * Panel de administración: salud del sistema, uso de los enlaces y purga manual.
 *
 * La descarga directa es anónima (no hay sesión ni correo), así que solo se puede contar
 * por archivo. Los accesos por correo se cuentan únicamente para `via = 'ui'`.
 */

/** Números del tablero de salud. */
function resumenSalud(): array
{
    $fila = bd()->query(
        "select
            (select count(*) from archivo
              where borrado_en is null and expira_en > now()) as activos,
            (select coalesce(sum(bytes_gz), 0) from archivo
              where borrado_en is null and expira_en > now()) as bytes,
            (select count(*) from archivo
              where borrado_en is null and expira_en <= now()) as vencidos,
            (select count(*) from envio
              where creado_en > now() - interval '7 days' and estado = 'ok') as envios_ok,
            (select count(*) from envio
              where creado_en > now() - interval '7 days' and estado = 'error') as envios_error,
            (select count(*) from evento_acceso
              where creado_en > now() - interval '30 days' and via = 'ui') as accesos_ui,
            (select count(*) from evento_acceso
              where creado_en > now() - interval '30 days' and via = 'directo') as accesos_directo"
    )->fetch();

    $d = is_array($fila) ? $fila : [];

    return [
        'activos' => (int) ($d['activos'] ?? 0),
        'bytes' => (int) ($d['bytes'] ?? 0),
        'vencidos' => (int) ($d['vencidos'] ?? 0),
        'envios_ok' => (int) ($d['envios_ok'] ?? 0),
        'envios_error' => (int) ($d['envios_error'] ?? 0),
        'accesos_ui' => (int) ($d['accesos_ui'] ?? 0),
        'accesos_directo' => (int) ($d['accesos_directo'] ?? 0),
        'cuota' => cuotaDeDisco(),
    ];
}

/**
 * Condiciones de filtro compartidas. Cada marcador aparece una sola vez: el driver
 * nativo de pgsql no admite repetir el mismo nombre en una consulta.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function condicionesAdmin(array $filtros): array
{
    $nombre = mb_substr(trim((string) ($filtros['nombre'] ?? '')), 0, 80);
    $correo = mb_substr(trim((string) ($filtros['correo'] ?? '')), 0, 120);
    $plaza = mb_strtoupper(mb_substr(trim((string) ($filtros['plaza'] ?? '')), 0, 40));

    $condiciones = [
        "(:nombre = '' or a.nombre ilike :nombre_like escape '\\')",
        "(:plaza = '' or :plaza = any(a.plazas))",
        "(:correo = '' or exists (select 1 from archivo_destinatario x
                                   where x.archivo_id = a.id and lower(x.correo) like :correo_like escape '\\'))",
    ];

    // Los comodines del usuario no deben ampliar la búsqueda: se escapan.
    $escapa = static fn (string $valor): string
        => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valor);

    $parametros = [
        'nombre' => $nombre,
        'nombre_like' => '%' . $escapa($nombre) . '%',
        'plaza' => $plaza,
        'correo' => $correo,
        'correo_like' => '%' . $escapa($correo) . '%',
    ];

    return [implode(' and ', $condiciones), $parametros];
}

/** Tabla por archivo: UI y directo son comparables porque el link es uno por archivo. */
function estadisticasArchivos(array $filtros): array
{
    [$where, $parametros] = condicionesAdmin($filtros);
    $nunca = !empty($filtros['nunca']) ? ' having count(e.id) = 0' : '';

    $sql = "select a.id, a.nombre, a.subido_en, a.expira_en, a.plazas,
                   (select count(*) from archivo_destinatario d
                     where d.archivo_id = a.id) as destinatarios,
                   count(e.id) filter (where e.via = 'ui') as ui,
                   count(e.id) filter (where e.via = 'directo') as directo,
                   max(e.creado_en) as ultimo
              from archivo a
              left join evento_acceso e on e.archivo_id = a.id
             where a.borrado_en is null and {$where}
             group by a.id{$nunca}
             order by a.subido_en desc
             limit 200";

    $stmt = bd()->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->fetchAll();
}

/** Tabla por destinatario: solo los accesos con sesión se pueden atribuir a un correo. */
function estadisticasDestinatarios(array $filtros): array
{
    [$where, $parametros] = condicionesAdmin($filtros);
    $nunca = !empty($filtros['nunca']) ? ' having count(e.id) = 0' : '';

    $sql = "select a.id as archivo_id, a.nombre, d.correo,
                   count(e.id) as accesos,
                   min(e.creado_en) as primero,
                   max(e.creado_en) as ultimo
              from archivo a
              join archivo_destinatario d on d.archivo_id = a.id
              left join evento_acceso e
                     on e.archivo_id = a.id and lower(e.correo) = lower(d.correo)
             where a.borrado_en is null and {$where}
             group by a.id, d.correo{$nunca}
             order by a.subido_en desc, d.correo
             limit 300";

    $stmt = bd()->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->fetchAll();
}

/** Archivos vivos que la purga manual puede borrar, con los filtros del panel. */
function archivosPurgables(array $filtros): array
{
    $estado = (string) ($filtros['estado'] ?? 'todos');
    $where = ['a.borrado_en is null'];
    $parametros = [];

    if ($estado === 'expirado') {
        $where[] = 'a.expira_en <= now()';
    } elseif ($estado === 'vigente') {
        $where[] = 'a.expira_en > now()';
    }

    [$filtro, $parametros] = condicionesAdmin($filtros);
    $where[] = $filtro;

    $sql = 'select a.id, a.nombre, a.subido_en, a.expira_en, a.bytes_gz, a.plazas,
                   (select count(*) from archivo_destinatario d
                     where d.archivo_id = a.id) as destinatarios
              from archivo a
             where ' . implode(' and ', $where) . '
             order by a.expira_en asc, a.subido_en desc
             limit 200';

    $stmt = bd()->prepare($sql);
    $stmt->execute($parametros);

    return $stmt->fetchAll();
}

/** Ruta real dentro del depósito, o null si se sale o ya no existe. */
function rutaEnDeposito(string $ruta): ?string
{
    $base = realpath((string) config()['archivos']['dir']);
    $real = $ruta !== '' ? realpath($ruta) : false;

    if ($base === false || $real === false) {
        return null;
    }

    return str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
}

/**
 * Purga manual: borra el .gz y marca `borrado_en`. Nunca borra la fila (la estadística queda).
 * Un archivo cuyo fichero ya no está se marca igual: si no, se reintenta para siempre.
 *
 * @return array{borrados: int, sin_fichero: int}
 */
function purgarArchivos(array $ids, int $usuarioId): array
{
    $limpios = [];
    foreach ($ids as $id) {
        $n = (int) $id;
        if ($n > 0) {
            $limpios[$n] = $n;
        }
    }

    if ($limpios === []) {
        return ['borrados' => 0, 'sin_fichero' => 0];
    }

    $ids = array_values($limpios);
    $marcas = implode(',', array_fill(0, count($ids), '?'));

    $stmt = bd()->prepare(
        "select id, ruta, nombre from archivo where id in ({$marcas}) and borrado_en is null"
    );
    $stmt->execute($ids);
    $filas = $stmt->fetchAll();

    if ($filas === []) {
        return ['borrados' => 0, 'sin_fichero' => 0];
    }

    $sinFichero = 0;
    $borrados = [];
    foreach ($filas as $fila) {
        $real = rutaEnDeposito((string) $fila['ruta']);
        if ($real === null) {
            $sinFichero++;
        } elseif (!@unlink($real)) {
            // No se pudo borrar: se deja vivo para que el cron lo reintente.
            continue;
        }
        $borrados[] = (int) $fila['id'];
    }

    if ($borrados === []) {
        return ['borrados' => 0, 'sin_fichero' => $sinFichero];
    }

    $marcas = implode(',', array_fill(0, count($borrados), '?'));
    bd()->prepare(
        "update archivo set borrado_en = now() where id in ({$marcas}) and borrado_en is null"
    )->execute($borrados);

    bitacora($usuarioId, 'purga_manual', [
        'archivos' => $borrados,
        'sin_fichero' => $sinFichero,
    ]);

    return ['borrados' => count($borrados), 'sin_fichero' => $sinFichero];
}
