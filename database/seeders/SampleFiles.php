<?php

namespace Database\Seeders;

use Illuminate\Http\UploadedFile;

/** Small but genuine PDF and PNG files for demo data and tests. */
class SampleFiles
{
    public static function pdf(string $name = 'document.pdf', string $text = 'Sample document'): UploadedFile
    {
        $stream = "BT /F1 18 Tf 72 720 Td ($text) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n$obj\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

        return self::wrap($pdf, $name, 'application/pdf');
    }

    public static function png(string $name = 'photo.png'): UploadedFile
    {
        $img = imagecreatetruecolor(120, 150);
        imagefill($img, 0, 0, imagecolorallocate($img, 210, 220, 235));
        imagefilledellipse($img, 60, 55, 50, 60, imagecolorallocate($img, 120, 90, 70));
        imagefilledrectangle($img, 25, 95, 95, 150, imagecolorallocate($img, 40, 60, 110));
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();

        return self::wrap($bytes, $name, 'image/png');
    }

    private static function wrap(string $bytes, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ntrec');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
