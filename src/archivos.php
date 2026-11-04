<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/entrega.php';
require_once __DIR__ . '/limite.php';
require_once __DIR__ . '/cuota.php';
require_once __DIR__ . '/correo.php';

/**
 * Subida y consulta de archivos.
 * El .gz nunca vive dentro del docroot: se sirve solo por PHP tras validar token.
 */

/**
 * Guarda el archivo recibido (CSV o .xlsx): valida, escribe en el depósito, registra
 * y avisa por correo. Devuelve los datos listos para responder a la API.
 *
 * El archivo llega ya volcado a un temporal: el contrato es cuerpo crudo, sin multipart.
 *
 * @param string $rutaTemporal   ruta al archivo temporal en disco
 * @param string $nombreOriginal nombre que se mostrará en el portal
 *
 * @throws ErrorDeCarga cuando la petición no cumple el contrato
 */
function guardarSubida(string $rutaTemporal, string $nombreOriginal, array $destinatarios, ?string $ip): array
{
    if (!is_file($rutaTemporal)) {
        throw new ErrorDeCarga('falta_archivo', 'el cuerpo de la petición no llegó');
    }

    $tamano = filesize($rutaTemporal);
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

    // El tipo lo decide el contenido: un .xlsx es un ZIP; un .xls viejo es OLE2.
    if (esXlsBinario($rutaTemporal)) {
        throw new ErrorDeCarga('formato_no_soportado', 'el .xls antiguo no se acepta; guarde el libro como .xlsx');
    }
    $tipo = esXlsx($rutaTemporal) ? 'xlsx' : 'csv';
    $resumen = $tipo === 'xlsx' ? ['xlsx' => analizarXlsx($rutaTemporal)] : ['csv' => analizarCsv($rutaTemporal)];

    $nombre = nombreVisible($nombreOriginal, $tipo);
    $dirBase = rtrim((string) config()['archivos']['dir'], '/');
    $dir = $dirBase . '/' . date('Y/m');

    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('no se pudo crear el directorio de archivos');
    }

    // El xlsx ya viene comprimido (deflate): se guarda tal cual. El CSV se comprime a .gz.
    $ruta = $dir . '/' . bin2hex(random_bytes(16)) . ($tipo === 'xlsx' ? '.xlsx' : '.csv.gz');
    [$bytesGz, $sha256] = $tipo === 'xlsx'
        ? copiarConHash($rutaTemporal, $ruta, $bytes)
        : comprimirConHash($rutaTemporal, $ruta, $bytes);

    $token = bin2hex(random_bytes(32));
    $dias = (int) config()['archivos']['dias'];
    $diasLink = (int) config()['archivos']['dias_link'];

    $pdo = bd();
    $pdo->beginTransaction();
    try {
        // Con el lock global: dos subidas a la vez no pueden rebasar la cuota de disco.
        reservarCuota($bytesGz);

        $stmt = $pdo->prepare(
            'insert into archivo (nombre, ruta, bytes, bytes_gz, sha256, tipo, token_hash, ip_origen, expira_en, link_expira_en)
             values (:n, :r, :b, :g, :s, :tipo, :t, cast(:ip as inet),
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
            'tipo' => $tipo,
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
    $envios = avisarDestinatarios($archivoId, $destinatarios, $nombre, $bytes, $tipo, $link, $expiraLink, $expira);

    return [
        'id' => $archivoId,
        'nombre' => $nombre,
        'tipo' => $tipo,
        'bytes' => $bytes,
        'bytes_gz' => $bytesGz,
        'sha256' => $sha256,
        'expira_en' => $expira,
        'link_expira_en' => $expiraLink,
        'link' => $link,
        ...$resumen,
        'envios' => $envios,
    ];
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
