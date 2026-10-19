<?php

namespace App\Support;

/**
 * One sheet as an Excel workbook (.xlsx), written by hand: a spreadsheet the
 * reader's Excel opens in columns whatever their list separator — a CSV in a
 * comma lands in one column under Arabic regional settings — and reads right
 * to left, its header row bold and kept in view.
 *
 * Text goes in as inline strings, never as formulas, and numbers as numbers.
 * The package is zipped here too, so the server needs no zip extension.
 */
final class Xlsx
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * The workbook's bytes: the rows in order, the first a bold header, and
     * any other rows named bold too.
     *
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<int>  $boldRows  indexes into $rows beside the header
     */
    public static function make(array $rows, string $sheetName, array $boldRows = []): string
    {
        return self::zip([
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels' => self::xml('<Relationships xmlns="'.self::PACKAGE_RELATIONSHIPS.'">'
                .'<Relationship Id="rId1" Type="'.self::RELATIONSHIPS.'/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>'),
            'xl/workbook.xml' => self::xml('<workbook xmlns="'.self::MAIN.'" xmlns:r="'.self::RELATIONSHIPS.'">'
                .'<sheets><sheet name="'.self::escape(self::sheetName($sheetName)).'" sheetId="1" r:id="rId1"/></sheets>'
                .'</workbook>'),
            'xl/_rels/workbook.xml.rels' => self::xml('<Relationships xmlns="'.self::PACKAGE_RELATIONSHIPS.'">'
                .'<Relationship Id="rId1" Type="'.self::RELATIONSHIPS.'/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="'.self::RELATIONSHIPS.'/styles" Target="styles.xml"/>'
                .'</Relationships>'),
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => self::sheet($rows, [0, ...$boldRows]),
        ]);
    }

    private static function contentTypes(): string
    {
        $part = 'application/vnd.openxmlformats-officedocument.spreadsheetml';

        return self::xml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="'.$part.'.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="'.$part.'.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="'.$part.'.styles+xml"/>'
            .'</Types>');
    }

    /** Two cell styles: plain (0), and bold on a pale fill (1). */
    private static function styles(): string
    {
        return self::xml('<styleSheet xmlns="'.self::MAIN.'">'
            .'<fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>');
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<int>  $bold
     */
    private static function sheet(array $rows, array $bold): string
    {
        $widths = [];
        $data = '';

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $style = in_array($index, $bold, true) ? ' s="1"' : '';
            $data .= '<row r="'.$number.'">';

            foreach (array_values($row) as $column => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $ref = self::column($column).$number;
                $text = (string) $value;
                $widths[$column] = max($widths[$column] ?? 0, mb_strlen($text));

                $data .= is_int($value) || is_float($value)
                    ? '<c r="'.$ref.'"'.$style.'><v>'.$text.'</v></c>'
                    : '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.self::escape($text).'</t></is></c>';
            }

            $data .= '</row>';
        }

        $columns = '';
        foreach ($widths as $column => $length) {
            $width = min(max($length + 3, 8), 60);
            $columns .= '<col min="'.($column + 1).'" max="'.($column + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        return self::xml('<worksheet xmlns="'.self::MAIN.'">'
            .'<sheetViews><sheetView rightToLeft="1" workbookViewId="0">'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            .'<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .($columns === '' ? '' : '<cols>'.$columns.'</cols>')
            .'<sheetData>'.$data.'</sheetData>'
            .'</worksheet>');
    }

    /** A, B, … Z, AA, AB… for a column counted from 0. */
    private static function column(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    /** At most 31 characters, none of those a sheet name may not hold. */
    private static function sheetName(string $name): string
    {
        return mb_substr(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)), 0, 31) ?: 'Sheet1';
    }

    /** Text as XML holds it: escaped, the control characters it cannot hold dropped. */
    private static function escape(string $text): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function xml(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".$body;
    }

    /**
     * A zip archive of the parts, each deflated where zlib is at hand.
     *
     * @param  array<string, string>  $files
     */
    private static function zip(array $files): string
    {
        $now = getdate();
        $time = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
        $date = ((max($now['year'], 1980) - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];
        $deflate = function_exists('gzdeflate');

        $body = '';
        $directory = '';

        foreach ($files as $name => $contents) {
            $packed = $deflate ? gzdeflate($contents, 6) : $contents;
            $method = $deflate ? 8 : 0;
            $crc = crc32($contents);
            $offset = strlen($body);

            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, $method, $time, $date, $crc, strlen($packed), strlen($contents), strlen($name), 0)
                .$name.$packed;

            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, $method, $time, $date, $crc, strlen($packed), strlen($contents), strlen($name), 0, 0, 0, 0, 0, $offset)
                .$name;
        }

        return $body.$directory
            .pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
    }
}
