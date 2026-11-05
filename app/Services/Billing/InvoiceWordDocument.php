<?php

namespace App\Services\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * The invoice as a Word file (.docx) with the same content as the PDF, drawn from a frozen invoice
 * snapshot. The body comes from the `billing.invoice-docx` view; the other parts of the package are
 * fixed WordprocessingML written here.
 */
final class InvoiceWordDocument
{
    private const PROLOG = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PKG_RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** Width of the logo in EMU (about 3.5 cm). */
    private const LOGO_WIDTH = 1249529;

    /** Characters XML 1.0 cannot hold. */
    private const ILLEGAL = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    public function render(array $snapshot, int $version, string $path): void
    {
        $logo = public_path('images/logo-telkom.png');
        $size = is_file($logo) ? getimagesize($logo) : false;
        if ($size === false) {
            throw new \RuntimeException('Logo Telkom untuk dokumen Word tidak ditemukan.');
        }
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $body = view('billing.invoice-docx', ['snapshot' => $snapshot, 'version' => $version])->render();

        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Dokumen Word gagal dibuat.');
        }
        $zip->addFromString('[Content_Types].xml', self::PROLOG.$this->contentTypes());
        $zip->addFromString('_rels/.rels', self::PROLOG.$this->rootRels());
        $zip->addFromString('docProps/core.xml', self::PROLOG.$this->core($snapshot, $version));
        $zip->addFromString('word/document.xml', self::PROLOG.ltrim($body));
        $zip->addFromString('word/_rels/document.xml.rels', self::PROLOG.$this->documentRels());
        $zip->addFromString('word/styles.xml', self::PROLOG.$this->styles());
        $zip->addFromString('word/settings.xml', self::PROLOG.$this->settings());
        $zip->addFromString('word/header1.xml', self::PROLOG.$this->header((int) round(self::LOGO_WIDTH * $size[1] / $size[0])));
        $zip->addFromString('word/_rels/header1.xml.rels', self::PROLOG.$this->headerRels());
        $zip->addFromString('word/footer1.xml', self::PROLOG.$this->footer($snapshot, $version));
        $zip->addFile($logo, 'word/media/logo.png');
        if (! $zip->close()) {
            throw new \RuntimeException('Dokumen Word gagal disimpan.');
        }

        $this->validate($path, $snapshot);
    }

    /**
     * Reads the saved file back: it must open, hold well-formed XML, and show every line and, when the
     * invoice has a grand total, the same sum and grand total as the snapshot.
     */
    public function validate(string $path, array $snapshot): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Dokumen Word tidak valid.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException('Dokumen Word tidak valid.');
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new \RuntimeException('Dokumen Word tidak valid.');
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('w', self::W);
        $tables = $xpath->query('/w:document/w:body/w:tbl');
        if ($tables->length < 3) {
            throw new \RuntimeException('Dokumen Word tidak valid.');
        }
        // Text of a table cell: its runs are the only text nodes.
        $text = fn (\DOMNode $cell): string => $cell->textContent;

        // The lines table follows the 2 header rows with one row per line.
        $rows = $xpath->query('./w:tr', $tables->item(1));
        if ($rows->length - 2 !== count($snapshot['lines'])) {
            throw new \RuntimeException('Jumlah baris dokumen Word tidak sama dengan snapshot.');
        }

        $totals = $snapshot['totals'];
        if ($totals['grand_total'] === null) {
            return;
        }
        $money = fn (string $value): BigDecimal => BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
        $sum = BigDecimal::of(0);
        try {
            for ($i = 2; $i < $rows->length; $i++) {
                $sum = $sum->plus($money(str_replace(',', '', $text($xpath->query('./w:tc[last()]', $rows->item($i))->item(0)))));
            }
        } catch (MathException) {
            throw new \RuntimeException('Nilai baris dokumen Word tidak valid.');
        }
        if (! $sum->isEqualTo($totals['net_exact'])) {
            throw new \RuntimeException('Jumlah baris dokumen Word tidak sama dengan total snapshot.');
        }

        $summary = $xpath->query('./w:tr[last()]/w:tc[last()]', $tables->item(2));
        if ($summary->length !== 1 || $text($summary->item(0)) !== $this->money($totals['grand_total'])) {
            throw new \RuntimeException('Grand total dokumen Word tidak sama dengan snapshot.');
        }
    }

    /**
     * An amount as the PDF prints it: whole rupiah, or two decimals when there are cents.
     */
    private function money(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        $value = (float) $value;

        return number_format($value, fmod($value, 1) == 0 ? 0 : 2, '.', ',');
    }

    /**
     * A dynamic value as XML text: illegal characters dropped, markup characters escaped.
     */
    private function xml(mixed $value): string
    {
        return htmlspecialchars((string) preg_replace(self::ILLEGAL, '', (string) $value), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypes(): string
    {
        $main = 'application/vnd.openxmlformats-officedocument.wordprocessingml.';

        return '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .'<Override PartName="/word/document.xml" ContentType="'.$main.'document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="'.$main.'styles+xml"/>'
            .'<Override PartName="/word/settings.xml" ContentType="'.$main.'settings+xml"/>'
            .'<Override PartName="/word/header1.xml" ContentType="'.$main.'header+xml"/>'
            .'<Override PartName="/word/footer1.xml" ContentType="'.$main.'footer+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<Relationships xmlns="'.self::PKG_RELS.'">'
            .'<Relationship Id="rId1" Type="'.self::R.'/officeDocument" Target="word/document.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'</Relationships>';
    }

    private function core(array $snapshot, int $version): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$this->xml('Invoice '.$snapshot['period']['name'].' / v'.$version).'</dc:title>'
            .'<dc:creator>SIJATIM</dc:creator>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function documentRels(): string
    {
        return '<Relationships xmlns="'.self::PKG_RELS.'">'
            .'<Relationship Id="rIdStyles" Type="'.self::R.'/styles" Target="styles.xml"/>'
            .'<Relationship Id="rIdSettings" Type="'.self::R.'/settings" Target="settings.xml"/>'
            .'<Relationship Id="rIdHeader" Type="'.self::R.'/header" Target="header1.xml"/>'
            .'<Relationship Id="rIdFooter" Type="'.self::R.'/footer" Target="footer1.xml"/>'
            .'</Relationships>';
    }

    private function settings(): string
    {
        return '<w:settings xmlns:w="'.self::W.'">'
            .'<w:zoom w:percent="89"/>'
            .'<w:defaultTabStop w:val="720"/>'
            .'<w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>'
            .'</w:settings>';
    }

    private function styles(): string
    {
        // A paragraph style: name, alignment, indent, and run size/bold; all are based on Normal with no spacing.
        $style = function (string $id, string $jc, int $size, bool $bold = false, int $indent = 0): string {
            return '<w:style w:type="paragraph" w:styleId="'.$id.'"><w:name w:val="'.$id.'"/><w:basedOn w:val="Normal"/><w:qFormat/>'
                .'<w:pPr><w:spacing w:before="0" w:after="0"/>'.($indent ? '<w:ind w:left="'.$indent.'"/>' : '').'<w:jc w:val="'.$jc.'"/></w:pPr>'
                .'<w:rPr>'.($bold ? '<w:b/><w:bCs/>' : '').'<w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/></w:rPr></w:style>';
        };

        return '<w:styles xmlns:w="'.self::W.'">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="id-ID" w:eastAsia="en-US" w:bidi="ar-SA"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr></w:style>'
            .$style('Cell', 'center', 16)
            .$style('CellLeft', 'left', 16)
            .$style('CellNum', 'right', 16)
            .$style('HeadMain', 'center', 20, true)
            .$style('HeadSub', 'center', 18, true)
            .$style('Meta', 'left', 24, true)
            .$style('Sum', 'left', 24, true, 642)
            .$style('SumNum', 'right', 24, true)
            .'</w:styles>';
    }

    /**
     * The logo, right aligned, at a fixed width with the height of the picture's own proportions.
     */
    private function header(int $height): string
    {
        $cx = self::LOGO_WIDTH;
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';

        return '<w:hdr xmlns:w="'.self::W.'" xmlns:r="'.self::R.'" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="'.$a.'" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<w:p><w:pPr><w:ind w:right="120"/><w:jc w:val="right"/></w:pPr><w:r><w:drawing>'
            .'<wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$cx.'" cy="'.$height.'"/><wp:effectExtent l="0" t="0" r="0" b="0"/>'
            .'<wp:docPr id="1" name="Logo Telkom" descr="Telkom Indonesia"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
            .'<pic:nvPicPr><pic:cNvPr id="1" name="logo.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$height.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            .'</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p></w:hdr>';
    }

    private function headerRels(): string
    {
        return '<Relationships xmlns="'.self::PKG_RELS.'"><Relationship Id="rId1" Type="'.self::R.'/image" Target="media/logo.png"/></Relationships>';
    }

    /**
     * The page identity on the left (as in the PDF) and the page number on the right.
     */
    private function footer(array $snapshot, int $version): string
    {
        $identity = ($snapshot['metadata']['invoice_number'] ?? '').' · '.$snapshot['period']['name'].' · v'.$version;
        $number = '<w:rPr><w:b/><w:bCs/><w:sz w:val="40"/><w:szCs w:val="40"/></w:rPr>';

        return '<w:ftr xmlns:w="'.self::W.'">'
            .'<w:p><w:pPr><w:tabs><w:tab w:val="right" w:pos="20400"/></w:tabs></w:pPr>'
            .'<w:r><w:rPr><w:color w:val="888888"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr><w:t xml:space="preserve">'.$this->xml($identity).'</w:t></w:r>'
            .'<w:r><w:tab/></w:r>'
            .'<w:r>'.$number.'<w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r>'.$number.'<w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
            .'<w:r>'.$number.'<w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r>'.$number.'<w:t>1</w:t></w:r>'
            .'<w:r>'.$number.'<w:fldChar w:fldCharType="end"/></w:r>'
            .'</w:p></w:ftr>';
    }
}
