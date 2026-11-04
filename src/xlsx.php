<?php
declare(strict_types=1);

require_once __DIR__ . '/errores.php';

/**
 * Validación de libros de Excel (.xlsx) sin extraer hojas.
 *
 * Un xlsx es un ZIP (OOXML). Solo se leen el índice de partes y dos XML pequeños
 * (`[Content_Types].xml` y `xl/workbook.xml`), con tope de tamaño **antes** de leerlos:
 * así una bomba de expansión no se materializa en memoria. El riesgo residual es el
 * libro que abra el destinatario en su Excel, ajeno al servidor.
 */

const ARB_A_MAX_ENTRADAS_XLSX = 5000;
/** Tope de lectura del workbook: el real ronda 5-20 KB. */
const ARB_A_MAX_WORKBOOK = 2 << 20;
/** Tope de `[Content_Types].xml`: el real ronda 1-3 KB. */
const ARB_A_MAX_TIPOS = 1 << 20;
/** Partes que delatan macros, hojas XLM u objetos incrustados. */
const ARB_A_PARTES_MACRO = 'vbaProject|activeX|oleObject|macrosheet|excel4|embeddings|ctrlProp|vbaData';
/** Marcas de macro en `[Content_Types].xml` (el tipo `macroEnabled` no es un nombre de parte). */
const ARB_A_TIPOS_MACRO = ARB_A_PARTES_MACRO . '|macroenabled';

/**
 * Firma de un ZIP (xlsx es un ZIP). Se aceptan las tres variantes: normal, vacío y
 * *spanned* (`PK\x07\x08`).
 */
function esXlsx(string $ruta): bool
{
    $fh = @fopen($ruta, 'rb');
    if ($fh === false) {
        throw new ErrorDeCarga('archivo_ilegible');
    }
    $firma = (string) fread($fh, 4);
    fclose($fh);

    return in_array($firma, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
}

/** Firma de un .xls binario (OLE2): formato viejo, no soportado. */
function esXlsBinario(string $ruta): bool
{
    $fh = @fopen($ruta, 'rb');
    if ($fh === false) {
        throw new ErrorDeCarga('archivo_ilegible');
    }
    $firma = (string) fread($fh, 8);
    fclose($fh);

    return $firma === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
}

/**
 * Lee una parte del ZIP comprobando su tamaño declarado antes de materializarla.
 *
 * @throws ErrorDeCarga
 */
function parteXlsx(ZipArchive $zip, int $indice, int $tope, string $que): string
{
    $stat = $zip->statIndex($indice);
    if ($stat === false || !isset($stat['size'])) {
        throw new ErrorDeCarga('xlsx_invalido', "el libro trae $que ilegible");
    }
    if ((int) $stat['size'] > $tope) {
        throw new ErrorDeCarga('xlsx_invalido', "el libro trae $que demasiado grande");
    }

    $contenido = $zip->getFromIndex($indice);
    if ($contenido === false) {
        throw new ErrorDeCarga('xlsx_invalido', "el libro trae $que ilegible");
    }

    return $contenido;
}

/**
 * @return array{hojas: int, entradas: int}
 * @throws ErrorDeCarga
 */
function analizarXlsx(string $ruta): array
{
    $zip = new ZipArchive();
    if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
        throw new ErrorDeCarga('xlsx_invalido', 'el libro no se pudo abrir');
    }

    try {
        $entradas = $zip->numFiles;
        if ($entradas < 1) {
            throw new ErrorDeCarga('xlsx_invalido', 'el libro está vacío');
        }
        if ($entradas > ARB_A_MAX_ENTRADAS_XLSX) {
            throw new ErrorDeCarga('xlsx_invalido', 'el libro trae demasiadas partes');
        }

        $indiceTipos = null;
        $indiceWorkbook = null;
        for ($i = 0; $i < $entradas; $i++) {
            $nombre = $zip->getNameIndex($i);
            if ($nombre === false) {
                throw new ErrorDeCarga('xlsx_invalido', 'el libro trae una parte ilegible');
            }
            if (preg_match('~(^|/)(' . ARB_A_PARTES_MACRO . ')~i', $nombre) === 1) {
                throw new ErrorDeCarga('xlsx_con_macros', 'el libro trae macros u objetos incrustados');
            }
            if ($nombre === '[Content_Types].xml') {
                $indiceTipos = $i;
            }
            if ($nombre === 'xl/workbook.xml') {
                $indiceWorkbook = $i;
            }
        }

        if ($indiceTipos === null || $indiceWorkbook === null) {
            throw new ErrorDeCarga('xlsx_invalido', 'no es un libro de Excel');
        }

        $tipos = parteXlsx($zip, $indiceTipos, ARB_A_MAX_TIPOS, 'el índice de tipos');
        $workbook = parteXlsx($zip, $indiceWorkbook, ARB_A_MAX_WORKBOOK, 'el índice del libro');

        // XML en UTF-16 (legal) esconde las cadenas tras bytes NUL: se normaliza antes
        // de buscar, para que el filtro de macros no se eluda solo cambiando el encoding.
        $tiposPlano = strtolower(str_replace("\x00", '', $tipos));
        if (preg_match('~(' . ARB_A_TIPOS_MACRO . ')~i', $tiposPlano) === 1) {
            throw new ErrorDeCarga('xlsx_con_macros', 'el libro está guardado como libro con macros');
        }

        $hojas = preg_match_all('~<(?:\w+:)?sheet\b~', $workbook);
        if ($hojas === false || $hojas < 1) {
            throw new ErrorDeCarga('xlsx_invalido', 'el libro no trae hojas');
        }

        return ['hojas' => $hojas, 'entradas' => $entradas];
    } finally {
        $zip->close();
    }
}
