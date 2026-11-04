<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/limite.php';
require_once __DIR__ . '/cuota.php';
require_once __DIR__ . '/correo.php';

/**
 * Subida y consulta de archivos.
 * El .gz nunca vive dentro del docroot: se sirve solo por PHP tras validar token.
 */

/**
 * Guarda el CSV recibido: valida, comprime en streaming, registra y avisa por correo.
 * Devuelve los datos del archivo listo para responder a la API.
 *
 * El CSV llega ya volcado a un temporal: el contrato es cuerpo crudo, sin multipart.
 *
 * @param string $rutaCsv       ruta al CSV en disco
 * @param string $nombreOriginal nombre que se mostrará en el portal
 *
 * @throws ErrorDeCarga cuando la petición no cumple el contrato
 */
function guardarSubida(string $rutaCsv, string $nombreOriginal, array $destinatarios, ?string $ip): array
{
    if (!is_file($rutaCsv)) {
        throw new ErrorDeCarga('falta_archivo', 'el cuerpo de la petición no llegó');
    }

    $tamano = filesize($rutaCsv);
    $bytes = $tamano === false ? 0 : (int) $tamano;
    $maximo = (int) config()['api']['max_bytes'];
    if ($bytes <= 0) {
        throw new ErrorDeCarga('archivo_vacio');
    }
    if ($bytes > $maximo) {
        throw new ErrorDeCarga('archivo_muy_grande', 'máximo ' . $maximo . ' bytes');
    }

    // Primero lo barato: cuota de disco y de cargas. Después se lee el contenido.
    verificarCuota();
    verificarLimiteCarga($ip);
    $resumen = analizarCsv($rutaCsv);

    $nombre = nombreVisible($nombreOriginal);
    $dirBase = rtrim((string) config()['archivos']['dir'], '/');
    $dir = $dirBase . '/' . date('Y/m');

    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('no se pudo crear el directorio de archivos');
    }

    $ruta = $dir . '/' . bin2hex(random_bytes(16)) . '.csv.gz';
    [$bytesGz, $sha256] = comprimirConHash($rutaCsv, $ruta, $bytes);

    $token = bin2hex(random_bytes(32));
    $dias = (int) config()['archivos']['dias'];
    $diasLink = (int) config()['archivos']['dias_link'];

    $pdo = bd();
    $pdo->beginTransaction();
    try {
        // Con el lock global: dos subidas a la vez no pueden rebasar la cuota de disco.
        reservarCuota($bytesGz);

        $stmt = $pdo->prepare(
            'insert into archivo (nombre, ruta, bytes, bytes_gz, sha256, token_hash, ip_origen, expira_en, link_expira_en)
             values (:n, :r, :b, :g, :s, :t, cast(:ip as inet),
                     now() + make_interval(days => :d),
                     now() + make_interval(days => :dl))
             returning id, expira_en, link_expira_en'
        );
        $stmt->execute([
            'n' => $nombre,
            'r' => $ruta,
            'b' => $bytes,
            'g' => $bytesGz,
            's' => $sha256,
            't' => hash('sha256', $token),
            'ip' => $ip,
            'd' => $dias,
            'dl' => $diasLink,
        ]);
        $fila = $stmt->fetch();
        $archivoId = (int) $fila['id'];
        $expira = (new DateTimeImmutable((string) $fila['expira_en']))->format(DATE_ATOM);
        $expiraLink = (new DateTimeImmutable((string) $fila['link_expira_en']))->format(DATE_ATOM);

        $insertar = $pdo->prepare(
            'insert into archivo_destinatario (archivo_id, correo) values (:a, :c)'
        );
        foreach ($destinatarios as $correo) {
            $insertar->execute(['a' => $archivoId, 'c' => $correo]);
        }

        // La cuota se reserva aquí: si algo falla, el rollback no la consume.
        registrarCarga($ip);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        @unlink($ruta);
        throw $e;
    }

    $link = rtrim((string) config()['url_base'], '/') . '/d/' . $token;
    $envios = avisarDestinatarios($archivoId, $destinatarios, $nombre, $link, $expiraLink, $expira);

    return [
        'id' => $archivoId,
        'nombre' => $nombre,
        'bytes' => $bytes,
        'bytes_gz' => $bytesGz,
        'sha256' => $sha256,
        'expira_en' => $expira,
        'link_expira_en' => $expiraLink,
        'link' => $link,
        'csv' => $resumen,
        'envios' => $envios,
    ];
}

/**
 * Comprime en streaming y calcula el sha256 del original en el mismo recorrido.
 * Si algo falla a la mitad, el .gz parcial se borra: nunca se registra un archivo truncado.
 *
 * @return array{0: int, 1: string} [bytes comprimidos, sha256 del original]
 */
function comprimirConHash(string $origen, string $destino, int $bytesEsperados): array
{
    $entrada = @fopen($origen, 'rb');
    if ($entrada === false) {
        throw new RuntimeException('no se pudo leer el archivo a comprimir');
    }

    $salida = @gzopen($destino, 'wb6');
    if ($salida === false) {
        fclose($entrada);
        throw new RuntimeException('no se pudo crear el archivo comprimido');
    }

    $hash = hash_init('sha256');
    $total = 0;
    $fallo = null;

    while (!feof($entrada)) {
        $bloque = fread($entrada, 1 << 20);
        if ($bloque === false) {
            $fallo = 'falló la lectura del archivo';
            break;
        }
        if ($bloque === '') {
            break;
        }
        hash_update($hash, $bloque);
        if (gzwrite($salida, $bloque) !== strlen($bloque)) {
            $fallo = 'falló la escritura del archivo comprimido';
            break;
        }
        $total += strlen($bloque);
    }

    fclose($entrada);
    if (!gzclose($salida) && $fallo === null) {
        $fallo = 'no se pudo cerrar el archivo comprimido';
    }

    if ($fallo !== null) {
        @unlink($destino);
        throw new RuntimeException($fallo);
    }
    if ($total !== $bytesEsperados) {
        @unlink($destino);
        throw new ErrorDeCarga('archivo_incompleto', $total . ' de ' . $bytesEsperados . ' bytes');
    }

    $bytesGz = @filesize($destino);
    if ($bytesGz === false || $bytesGz <= 0) {
        @unlink($destino);
        throw new RuntimeException('el archivo comprimido quedó vacío');
    }
    if (!chmod($destino, 0600)) {
        @unlink($destino);
        throw new RuntimeException('no se pudieron fijar los permisos del archivo comprimido');
    }

    return [$bytesGz, hash_final($hash)];
}

/** Archivo vigente por token (el token viaja en claro solo en el link). */
function archivoPorToken(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = bd()->prepare(
        'select * from archivo
          where token_hash = :t and borrado_en is null and expira_en > now() and link_expira_en > now()'
    );
    $stmt->execute(['t' => hash('sha256', $token)]);
    $fila = $stmt->fetch();

    return $fila !== false ? $fila : null;
}

/** Archivo visible para un correo (regla de acceso del portal, anti-IDOR). */
function archivoParaCorreo(int $id, string $correo): ?array
{
    $stmt = bd()->prepare(
        'select a.* from archivo a
           join archivo_destinatario d on d.archivo_id = a.id
          where a.id = :i and lower(d.correo) = :c
            and a.borrado_en is null and a.expira_en > now()'
    );
    $stmt->execute(['i' => $id, 'c' => normalizarCorreo($correo)]);
    $fila = $stmt->fetch();

    return $fila !== false ? $fila : null;
}

/** Archivos que le corresponden a un correo (la regla de acceso de la UI). */
function archivosDeUsuario(string $correo): array
{
    $stmt = bd()->prepare(
        'select a.* from archivo a
           join archivo_destinatario d on d.archivo_id = a.id
          where lower(d.correo) = :c and a.borrado_en is null and a.expira_en > now()
          order by a.subido_en desc'
    );
    $stmt->execute(['c' => normalizarCorreo($correo)]);

    return $stmt->fetchAll();
}

function registrarAcceso(int $archivoId, ?int $usuarioId, string $via, ?string $correo, ?string $ip, ?string $userAgent): void
{
    bd()->prepare(
        'insert into evento_acceso (archivo_id, usuario_id, via, correo, ip, user_agent)
         values (:a, :u, :v, :c, cast(:ip as inet), :ua)'
    )->execute([
        'a' => $archivoId,
        'u' => $usuarioId,
        'v' => $via,
        'c' => $correo,
        'ip' => $ip,
        'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 300) : null,
    ]);
}
