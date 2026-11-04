<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/usuarios.php';
require_once __DIR__ . '/errores.php';

/**
 * Reglas del CSV y de sus cabeceras. Todo aquí es puro: sin BD, sin HTTP.
 */

const ARB_A_MAX_DESTINATARIOS = 50;
const ARB_A_MAX_COLUMNAS = 500;
/** Tope de bytes que se leen por fila: acota la memoria ante una línea gigante o mal entrecomillada. */
const ARB_A_MAX_LINEA = 1 << 20;

/**
 * Nombre visible: base del original en MAYÚSCULAS, sin rutas ni caracteres raros.
 * La extensión la decide el tipo real del contenido, no el nombre recibido.
 */
function nombreVisible(string $original, string $tipo = 'csv'): string
{
    $base = basename(str_replace('\\', '/', trim($original)));
    $base = preg_replace('/\.(csv|txt|xlsx|xls)$/i', '', $base) ?? $base;
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? $base;
    $base = trim($base, '._-');

    if ($base === '') {
        $base = 'ARCHIVO';
    }

    return mb_strtoupper(mb_substr($base, 0, 120)) . ($tipo === 'xlsx' ? '.XLSX' : '.CSV');
}

/**
 * Revisa que el archivo sea un CSV de verdad, en una sola pasada de lectura.
 *
 * `fgetcsv` es C y respeta comillas, comas y saltos de línea dentro de un campo, así que
 * la validación cuesta una lectura secuencial (el archivo recién subido está en caché).
 * No impone codificación: también hay CSV en latin1.
 *
 * @return array{lineas: int, columnas: int, separador: string}
 * @throws ErrorDeCarga
 */
function analizarCsv(string $ruta): array
{
    $fh = @fopen($ruta, 'rb');
    if ($fh === false) {
        throw new ErrorDeCarga('csv_ilegible');
    }

    $primera = (string) fgets($fh, 1 << 16);
    if (str_starts_with($primera, "\x1f\x8b")) {
        fclose($fh);
        throw new ErrorDeCarga('csv_ya_comprimido');
    }
    if (trim($primera) === '') {
        fclose($fh);
        throw new ErrorDeCarga('csv_vacio');
    }

    // Los extremos bastan para descartar un binario: un .gz o un .xlsx traen NUL en cuanto
    // se salen de la cabecera. La ventana queda documentada en AGENTS.md.
    $muestra = $primera . (string) fread($fh, 1 << 16);
    if (str_contains($muestra, "\0")) {
        fclose($fh);
        throw new ErrorDeCarga('csv_binario');
    }

    $peso = @filesize($ruta);
    if (is_int($peso) && $peso > (1 << 17)) {
        fseek($fh, -1 * (1 << 16), SEEK_END);
        if (str_contains((string) fread($fh, 1 << 16), "\0")) {
            fclose($fh);
            throw new ErrorDeCarga('csv_binario');
        }
    }

    $separador = detectarSeparador($primera);
    $columnas = 0;
    $lineas = 0;

    rewind($fh);
    // La longitud acota la memoria por fila: una línea de 30 MB no se carga entera.
    while (($fila = fgetcsv($fh, ARB_A_MAX_LINEA, $separador, '"', '')) !== false) {
        if ($fila === [null] || (count($fila) === 1 && trim((string) $fila[0]) === '')) {
            continue; // línea en blanco: no cuenta ni rompe la consistencia
        }

        $cuantas = count($fila);
        if ($cuantas > ARB_A_MAX_COLUMNAS) {
            fclose($fh);
            throw new ErrorDeCarga('csv_demasiadas_columnas', 'máximo ' . ARB_A_MAX_COLUMNAS);
        }
        if ($columnas === 0) {
            $columnas = $cuantas;
        } elseif ($cuantas !== $columnas) {
            fclose($fh);
            throw new ErrorDeCarga(
                'csv_columnas_inconsistentes',
                sprintf('línea %d: %d columnas; la primera trae %d', $lineas + 1, $cuantas, $columnas)
            );
        }

        $lineas++;
    }

    fclose($fh);

    if ($lineas === 0) {
        throw new ErrorDeCarga('csv_vacio');
    }

    return ['lineas' => $lineas, 'columnas' => $columnas, 'separador' => $separador];
}

/** Separador del archivo: el más frecuente en la primera línea, ignorando lo entrecomillado. */
function detectarSeparador(string $linea): string
{
    $sinComillas = preg_replace('/"(?:[^"]|"")*"/', '', $linea) ?? $linea;
    $elegido = ',';
    $tope = 0;

    foreach ([',', ';', "\t", '|'] as $candidato) {
        $cuenta = substr_count($sinComillas, $candidato);
        if ($cuenta > $tope) {
            $tope = $cuenta;
            $elegido = $candidato;
        }
    }

    return $elegido;
}

/**
 * Dominio autorizado a recibir avisos (`correo.dominios`).
 * Comparación exacta y sin subdominios; lista vacía = nadie (no es un relay abierto).
 */
function dominioPermitido(string $correo): bool
{
    $arroba = strrpos($correo, '@');
    if ($arroba === false) {
        return false;
    }

    $dominio = strtolower(substr($correo, $arroba + 1));
    $permitidos = array_map('strtolower', (array) (config()['correo']['dominios'] ?? []));

    return $permitidos !== [] && in_array($dominio, $permitidos, true);
}

/** `X-Correos`: lista separada por coma o punto y coma. Deduplica y valida. */
function parseDestinatarios(string $cabecera): array
{
    $partes = preg_split('/[,;]+/', $cabecera) ?: [];
    $correos = [];
    $posicion = 0;

    foreach ($partes as $parte) {
        $posicion++;
        $correo = normalizarCorreo($parte);
        if ($correo === '') {
            continue;
        }
        if (!correoValido($correo)) {
            // Se dice la posición, no el valor: el error no devuelve lo que mandó el cliente.
            return ['ok' => false, 'codigo' => 'destinatarios_invalidos', 'correos' => [], 'error' => 'X-Correos trae un correo inválido (posición ' . $posicion . ')'];
        }
        if (!dominioPermitido($correo)) {
            return ['ok' => false, 'codigo' => 'destinatario_no_permitido', 'correos' => [], 'error' => 'dominio no autorizado (posición ' . $posicion . ')'];
        }
        $correos[$correo] = true;
    }

    $lista = array_keys($correos);
    if ($lista === []) {
        return ['ok' => false, 'codigo' => 'destinatarios_invalidos', 'correos' => [], 'error' => 'X-Correos no trae destinatarios'];
    }
    if (count($lista) > ARB_A_MAX_DESTINATARIOS) {
        return ['ok' => false, 'codigo' => 'destinatarios_invalidos', 'correos' => [], 'error' => 'demasiados destinatarios (máximo ' . ARB_A_MAX_DESTINATARIOS . ')'];
    }

    return ['ok' => true, 'codigo' => null, 'correos' => $lista, 'error' => null];
}
