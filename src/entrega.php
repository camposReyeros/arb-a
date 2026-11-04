<?php
declare(strict_types=1);

require_once __DIR__ . '/errores.php';

/**
 * Escritura del archivo en el depósito y cabeceras de descarga.
 *
 * Todo lo que se guarda queda fuera del docroot, modo 0600, y se sirve solo por PHP.
 */

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

/**
 * Copia sin recomprimir (el xlsx ya es un ZIP deflate) y calcula el sha256 en el mismo recorrido.
 *
 * @return array{0: int, 1: string} [bytes en disco, sha256]
 */
function copiarConHash(string $origen, string $destino, int $bytesEsperados): array
{
    $entrada = @fopen($origen, 'rb');
    if ($entrada === false) {
        throw new RuntimeException('no se pudo leer el archivo a copiar');
    }

    $salida = @fopen($destino, 'xb');
    if ($salida === false) {
        fclose($entrada);
        throw new RuntimeException('no se pudo crear el archivo de destino');
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
        if (fwrite($salida, $bloque) !== strlen($bloque)) {
            $fallo = 'falló la escritura del archivo';
            break;
        }
        $total += strlen($bloque);
    }

    fclose($entrada);
    if (!fclose($salida) && $fallo === null) {
        $fallo = 'no se pudo cerrar el archivo';
    }

    if ($fallo !== null) {
        @unlink($destino);
        throw new RuntimeException($fallo);
    }
    if ($total !== $bytesEsperados) {
        @unlink($destino);
        throw new ErrorDeCarga('archivo_incompleto', $total . ' de ' . $bytesEsperados . ' bytes');
    }
    if (!chmod($destino, 0600)) {
        @unlink($destino);
        throw new RuntimeException('no se pudieron fijar los permisos del archivo');
    }

    return [$total, hash_final($hash)];
}

/** Tipo MIME y nombre de descarga según cómo se guardó el archivo. */
function descargaDe(array $archivo): array
{
    $esXlsx = ($archivo['tipo'] ?? 'csv') === 'xlsx';

    // El nombre viene saneado en origen; se limpia otra vez en el sumidero (cabecera HTTP).
    $nombre = preg_replace('/[\r\n"\\\\\x00-\x1f\x7f]/', '', (string) ($archivo['nombre'] ?? ''));
    $nombre = is_string($nombre) && $nombre !== '' ? $nombre : ($esXlsx ? 'ARCHIVO.XLSX' : 'ARCHIVO.CSV');

    if ($esXlsx) {
        return [
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'nombre' => $nombre,
        ];
    }

    return ['mime' => 'application/gzip', 'nombre' => $nombre . '.gz'];
}
