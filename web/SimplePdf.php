<?php

/**
 * Minimal PDF writer for Janus hour reports (no external deps).
 */
class SimplePdf
{
    /** @var list<string> */
    private array $pages = [];
    /** @var list<string> */
    private array $ops = [];
    private float $width = 595.28;
    private float $height = 841.89;
    /** @var array{data: string, w: string, h: string}|null */
    private ?array $image = null;
    /** @var array{0:int,1:int,2:int} */
    private array $textColor = [0, 0, 0];

    public function addPage(): void
    {
        if ($this->ops !== []) {
            $this->pages[] = implode("\n", $this->ops);
            $this->ops = [];
        }
    }

    public function setTextColor(int $r, int $g, int $b): void
    {
        $this->textColor = [$r, $g, $b];
    }

    public function text(float $x, float $yFromTop, string $text, int $size = 12, bool $bold = false): void
    {
        $y = $this->height - $yFromTop;
        $escaped = $this->escape($text);
        $font = $bold ? 'F2' : 'F1';
        $this->ops[] = sprintf(
            '%.3F %.3F %.3F rg',
            $this->textColor[0] / 255,
            $this->textColor[1] / 255,
            $this->textColor[2] / 255
        );
        $this->ops[] = 'BT';
        $this->ops[] = sprintf('/%s %d Tf', $font, $size);
        $this->ops[] = sprintf('1 0 0 1 %.2F %.2F Tm', $x, $y);
        $this->ops[] = sprintf('(%s) Tj', $escaped);
        $this->ops[] = 'ET';
    }

    public function setFillColor(int $r, int $g, int $b): void
    {
        $this->ops[] = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
    }

    public function rect(float $x, float $yFromTop, float $w, float $h, string $style = 'F'): void
    {
        $y = $this->height - $yFromTop - $h;
        $this->ops[] = sprintf('%.2F %.2F %.2F %.2F re', $x, $y, $w, $h);
        if ($style === 'F') {
            $this->ops[] = 'f';
        } elseif ($style === 'S') {
            $this->ops[] = 'S';
        } else {
            $this->ops[] = 'B';
        }
    }

    public function setImageJpeg(string $path, float $x, float $yFromTop, float $w, float $h): void
    {
        if (!is_file($path)) {
            return;
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return;
        }

        $jpeg = null;
        if (($info[2] ?? 0) === IMAGETYPE_JPEG) {
            $jpeg = file_get_contents($path);
        } elseif (function_exists('imagecreatefrompng') && ($info[2] ?? 0) === IMAGETYPE_PNG) {
            $im = @imagecreatefrompng($path);
            if ($im === false) {
                return;
            }
            $bg = imagecreatetruecolor(imagesx($im), imagesy($im));
            if ($bg === false) {
                imagedestroy($im);
                return;
            }
            $white = imagecolorallocate($bg, 255, 255, 255);
            imagefilledrectangle($bg, 0, 0, imagesx($im), imagesy($im), $white);
            imagecopy($bg, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
            ob_start();
            imagejpeg($bg, null, 90);
            $jpeg = ob_get_clean();
            imagedestroy($im);
            imagedestroy($bg);
        }

        if (!is_string($jpeg) || $jpeg === '') {
            return;
        }

        $this->image = [
            'data' => $jpeg,
            'w' => (string) ($info[0] ?? 1),
            'h' => (string) ($info[1] ?? 1),
        ];

        $y = $this->height - $yFromTop - $h;
        $this->ops[] = 'q';
        $this->ops[] = sprintf('%.2F 0 0 %.2F %.2F %.2F cm', $w, $h, $x, $y);
        $this->ops[] = '/Im1 Do';
        $this->ops[] = 'Q';
    }

    public function output(): string
    {
        if ($this->ops !== []) {
            $this->pages[] = implode("\n", $this->ops);
            $this->ops = [];
        }
        if ($this->pages === []) {
            $this->pages[] = '';
        }

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];
        $pageCount = count($this->pages);
        $pageObjStart = 3;
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($pageObjStart + $i) . ' 0 R';
        }
        $objects[] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            implode(' ', $kids),
            $pageCount
        );

        $contentObjStart = $pageObjStart + $pageCount;
        $fontRegularObj = $contentObjStart + $pageCount;
        $fontBoldObj = $fontRegularObj + 1;
        $imageObj = $fontBoldObj + 1;
        $hasImage = is_array($this->image);

        for ($i = 0; $i < $pageCount; $i++) {
            $contentRef = ($contentObjStart + $i) . ' 0 R';
            $resources = sprintf(
                '/Font << /F1 %d 0 R /F2 %d 0 R >>',
                $fontRegularObj,
                $fontBoldObj
            );
            if ($hasImage) {
                $resources .= sprintf(' /XObject << /Im1 %d 0 R >>', $imageObj);
            }
            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents %s /Resources << %s >> >>',
                $this->width,
                $this->height,
                $contentRef,
                $resources
            );
        }

        for ($i = 0; $i < $pageCount; $i++) {
            $stream = $this->pages[$i];
            $objects[] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($stream),
                $stream
            );
        }

        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        if ($hasImage) {
            $img = $this->image;
            $objects[] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %s /Height %s /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $img['w'],
                $img['h'],
                strlen($img['data']),
                $img['data']
            );
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    private function escape(string $text): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ç' => 'c', 'ñ' => 'n',
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
            '–' => '-', '—' => '-', '∕' => '/', '€' => 'EUR',
        ];
        $text = strtr($text, $map);
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
