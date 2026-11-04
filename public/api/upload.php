<?php
declare(strict_types=1);

/**
 * POST /api/upload — carga de un CSV.
 *
 * Cabeceras:
 *   X-Api-Key   obligatoria
 *   X-Correos   obligatoria, lista separada por comas
 *
 * Cuerpo: multipart/form-data con el campo `archivo`.
 * Respuesta: JSON con `codigo` estable en los errores, y `exit`.
 */

require_once __DIR__ . '/../../src/archivos.php';

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

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new ErrorDeCarga('metodo_no_permitido');
    }

    $claveApi = (string) config()['api']['key'];
    if ($claveApi === '') {
        // Nunca se revela qué variable falta: eso es información de despliegue.
        throw new ErrorDeCarga('servicio_no_disponible');
    }
    if (!hash_equals($claveApi, (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''))) {
        throw new ErrorDeCarga('apikey_invalida');
    }

    $destinatarios = parseDestinatarios((string) ($_SERVER['HTTP_X_CORREOS'] ?? ''));
    if (!$destinatarios['ok']) {
        throw new ErrorDeCarga(
            (string) ($destinatarios['codigo'] ?? 'destinatarios_invalidos'),
            (string) $destinatarios['error']
        );
    }

    $archivo = $_FILES['archivo'] ?? null;

    if (!is_array($archivo)) {
        // Solo es 413 si la petición superó post_max_size; si no, el campo viene mal.
        $largo = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $tope = bytesDeIni((string) ini_get('post_max_size'));
        if ($largo > 0 && $tope > 0 && $largo > $tope) {
            throw new ErrorDeCarga('archivo_muy_grande', 'por el límite del servidor');
        }

        throw new ErrorDeCarga('falta_archivo');
    }

    $resultado = guardarSubida(
        $archivo,
        $destinatarios['correos'],
        ipCliente()
    );
} catch (ErrorDeCarga $e) {
    responderError($e);
} catch (Throwable $e) {
    error_log('arb-a upload: ' . $e->getMessage());
    responderError(new ErrorDeCarga('error_interno'));
}

$cuerpo = ['ok' => true] + $resultado;
if (($resultado['envios']['ok'] ?? 0) === 0) {
    $cuerpo['aviso'] = 'el archivo quedó guardado, pero ningún correo salió';
}

responder(201, $cuerpo);
