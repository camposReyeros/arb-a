<?php
declare(strict_types=1);

/**
 * PUT|POST /api/upload — carga de un CSV.
 *
 * Cabeceras:
 *   X-Api-Key   obligatoria
 *   X-Correos   obligatoria, lista separada por comas o punto y coma
 *   X-Nombre    opcional, nombre visible en el portal (default archivo.csv)
 *
 * Cuerpo: el CSV crudo (Content-Type: text/csv). Sin multipart.
 * Respuesta: JSON con `codigo` estable en los errores, y `exit`.
 */

require_once __DIR__ . '/../../src/archivos.php';
require_once __DIR__ . '/../../src/apikey.php';

cabecerasDeSeguridad();
header('Content-Type: application/json; charset=utf-8');

/** @param array<string, mixed> $datos */
function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function responderError(ErrorDeCarga $e): never
{
    if ($e->codigo === 'limite_cargas' && isset($e->extra['reintentar_en'])) {
        header('Retry-After: ' . (int) $e->extra['reintentar_en']);
    }

    responder($e->estado, ['ok' => false, 'codigo' => $e->codigo, 'error' => $e->getMessage()] + $e->extra);
}

/**
 * Copia el cuerpo de la petición a un temporal, en bloques, sin cargarlo en memoria.
 * Corta en cuanto se pasa del tope y borra lo escrito.
 *
 * @throws ErrorDeCarga
 */
function volcarCuerpo(string $destino, int $maximo): int
{
    $entrada = fopen('php://input', 'rb');
    $salida = fopen($destino, 'wb');
    if ($entrada === false || $salida === false) {
        if (is_resource($entrada)) {
            fclose($entrada);
        }
        if (is_resource($salida)) {
            fclose($salida);
        }
        throw new RuntimeException('no se pudo abrir el cuerpo de la petición');
    }

    $total = 0;
    try {
        while (!feof($entrada)) {
            $bloque = fread($entrada, 1 << 20);
            if ($bloque === false) {
                throw new RuntimeException('la lectura del cuerpo falló');
            }
            $total += strlen($bloque);
            if ($total > $maximo) {
                throw new ErrorDeCarga('archivo_muy_grande', 'máximo ' . $maximo . ' bytes');
            }
            if ($bloque !== '' && fwrite($salida, $bloque) !== strlen($bloque)) {
                throw new RuntimeException('no se pudo escribir el temporal');
            }
        }
    } finally {
        fclose($entrada);
        fclose($salida);
    }

    return $total;
}

$tmp = null;

try {
    $metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    if ($metodo !== 'POST' && $metodo !== 'PUT') {
        header('Allow: PUT, POST');
        throw new ErrorDeCarga('metodo_no_permitido');
    }

    $estadoClave = apikeyComprobar((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($estadoClave === 'error' || $estadoClave === 'sin_configurar') {
        // Nunca se revela qué variable falta: eso es información de despliegue.
        throw new ErrorDeCarga('servicio_no_disponible');
    }
    if ($estadoClave !== 'ok') {
        throw new ErrorDeCarga('apikey_invalida');
    }

    // El contrato es cuerpo crudo: si llega un formulario, el mensaje lo dice.
    $tipo = mb_strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
    if (str_starts_with($tipo, 'multipart/')) {
        throw new ErrorDeCarga('falta_archivo', 'manda el CSV crudo en el cuerpo, sin multipart');
    }

    $destinatarios = parseDestinatarios((string) ($_SERVER['HTTP_X_CORREOS'] ?? ''));
    if (!$destinatarios['ok']) {
        throw new ErrorDeCarga(
            (string) ($destinatarios['codigo'] ?? 'destinatarios_invalidos'),
            (string) $destinatarios['error']
        );
    }

    // Freno barato antes de volcar: el cupo por IP se consulta con una sola query.
    verificarLimiteCarga(ipCliente());

    $maximo = (int) config()['api']['max_bytes'];
    $largo = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $topePost = $metodo === 'POST' ? bytesDeIni((string) ini_get('post_max_size')) : 0;
    if ($topePost > 0 && $largo > $topePost) {
        throw new ErrorDeCarga('archivo_muy_grande', 'por el límite del servidor');
    }
    if ($largo > $maximo) {
        throw new ErrorDeCarga('archivo_muy_grande', 'máximo ' . $maximo . ' bytes');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'arba-csv-');
    if ($tmp === false) {
        throw new RuntimeException('no se pudo crear el temporal de la carga');
    }

    $bytes = volcarCuerpo($tmp, $maximo);
    if ($bytes === 0) {
        throw new ErrorDeCarga('archivo_vacio');
    }

    $resultado = guardarSubida(
        $tmp,
        (string) ($_SERVER['HTTP_X_NOMBRE'] ?? ''),
        $destinatarios['correos'],
        ipCliente()
    );
} catch (ErrorDeCarga $e) {
    responderError($e);
} catch (Throwable $e) {
    error_log('arb-a upload: ' . $e->getMessage());
    responderError(new ErrorDeCarga('error_interno'));
} finally {
    if ($tmp !== null) {
        @unlink($tmp);
    }
}

$cuerpo = ['ok' => true] + $resultado;
if (($resultado['envios']['ok'] ?? 0) === 0) {
    $cuerpo['aviso'] = 'el archivo quedó guardado, pero ningún correo salió';
}

responder(201, $cuerpo);
