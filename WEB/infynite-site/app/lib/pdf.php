<?php
/** Minimal dependency-free PDF writer: A4, Helvetica / Helvetica-Bold, flowing text, rules and tables. Text is converted to Windows-1252; the rupee sign prints as "Rs.". */
class Pdf
{
    const W = 595.28, H = 841.89, M = 46;
    private array $pages = [];
    private string $buf = '';
    public float $y = 0;
    private string $title;
    private static array $reg = [32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,64=>1015,65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,92=>278,93=>278,94=>469,95=>556,96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584];
    private static array $bold = [32=>278,33=>333,34=>474,35=>556,36=>556,37=>889,38=>722,39=>238,40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,58=>333,59=>333,60=>584,61=>584,62=>584,63=>611,64=>975,65=>722,66=>722,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>556,75=>722,76=>611,77=>833,78=>722,79=>778,80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>333,92=>278,93=>333,94=>584,95=>556,96=>333,97=>556,98=>611,99=>556,100=>611,101=>556,102=>333,103=>611,104=>611,105=>278,106=>278,107=>556,108=>278,109=>889,110=>611,111=>611,112=>611,113=>611,114=>389,115=>556,116=>333,117=>611,118=>556,119=>778,120=>556,121=>556,122=>500,123=>389,124=>280,125=>389,126=>584];

    function __construct(string $title = '') { $this->title = $title; $this->newPage(); }

    static function enc(string $s): string
    {
        $s = str_replace(['₹', '–', '—', '‘', '’', '“', '”', '…', '•', "\t"], ['Rs.', '-', '-', "'", "'", '"', '"', '...', '-', '    '], $s);
        $o = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $o === false ? preg_replace('/[^\x20-\x7e]/', '?', $s) : $o;
    }
    function width(string $s, float $size, bool $b = false): float
    {
        $t = $b ? self::$bold : self::$reg; $w = 0;
        foreach (str_split(self::enc($s)) as $c) { $w += $t[ord($c)] ?? 556; }
        return $w * $size / 1000;
    }
    private static function esc(string $s): string { return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::enc($s)); }
    private function newPage(): void
    {
        if ($this->buf !== '') { $this->pages[] = $this->buf; }
        $this->buf = ''; $this->y = self::H - self::M;
    }
    function ensure(float $h): void { if ($this->y - $h < self::M + 24) { $this->newPage(); } }
    function gap(float $h): void { $this->y -= $h; }
    /** Draws text with its baseline at the current y (does not advance). align: L, R (x = right edge), C. */
    function put(string $s, float $x, float $size = 10, bool $bold = false, string $align = 'L', ?array $rgb = null): void
    {
        if ($align === 'R') { $x -= $this->width($s, $size, $bold); } elseif ($align === 'C') { $x -= $this->width($s, $size, $bold) / 2; }
        $c = $rgb ? vsprintf('%.3F %.3F %.3F rg ', $rgb) : '0 g ';
        $this->buf .= sprintf("BT /%s %.2F Tf %s%.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $c, $x, $this->y, self::esc($s));
    }
    function wrap(string $s, float $maxW, float $size, bool $bold = false): array
    {
        $out = [];
        foreach (preg_split('/\R/', $s) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) ?: [] as $w) {
                while ($this->width($w, $size, $bold) > $maxW && mb_strlen($w) > 1) {   // very long word: hard break
                    $cut = mb_strlen($w); while ($cut > 1 && $this->width(mb_substr($w, 0, $cut), $size, $bold) > $maxW) { $cut--; }
                    if ($line !== '') { $out[] = $line; $line = ''; }
                    $out[] = mb_substr($w, 0, $cut); $w = mb_substr($w, $cut);
                }
                $try = $line === '' ? $w : "$line $w";
                if ($this->width($try, $size, $bold) <= $maxW) { $line = $try; } else { $out[] = $line; $line = $w; }
            }
            $out[] = $line;
        }
        return $out;
    }
    /** Flowing paragraph at left margin $x (default margin), advancing y. */
    function para(string $s, float $size = 10, bool $bold = false, ?float $x = null, ?float $w = null, float $lead = 1.35): void
    {
        $x = $x ?? self::M; $w = $w ?? (self::W - self::M - $x);
        foreach ($this->wrap($s, $w, $size, $bold) as $l) { $this->ensure($size * $lead); $this->y -= $size * $lead; $this->put($l, $x, $size, $bold); }
    }
    function rule(float $gapBefore = 4, float $gapAfter = 6): void
    {
        $this->y -= $gapBefore; $this->buf .= sprintf("0.7 G 0.5 w %.2F %.2F m %.2F %.2F l S\n", self::M, $this->y, self::W - self::M, $this->y); $this->y -= $gapAfter;
    }
    /** $cols: [[header, width, align L|R], ...]; $rows: array of arrays of strings. */
    function table(array $cols, array $rows, float $size = 9): void
    {
        $x0 = self::M; $draw = function (array $cells, bool $head) use ($cols, $size, $x0): void {
            $lines = []; $max = 1;
            foreach ($cols as $i => $c) { $lines[$i] = $this->wrap((string) ($cells[$i] ?? ''), $c[1] - 6, $size, $head); $max = max($max, count($lines[$i])); }
            $h = $max * $size * 1.3 + 5; $this->ensure($h);
            if ($head) { $this->buf .= sprintf("0.93 g %.2F %.2F %.2F %.2F re f\n", $x0, $this->y - $h + 2, array_sum(array_column($cols, 1)), $h); }
            $x = $x0;
            foreach ($cols as $i => $c) {
                $yy = $this->y - $size * 1.1 - 2;
                foreach ($lines[$i] as $l) { $save = $this->y; $this->y = $yy; $this->put($l, $c[2] === 'R' ? $x + $c[1] - 3 : $x + 3, $size, $head, $c[2]); $this->y = $save; $yy -= $size * 1.3; }
                $x += $c[1];
            }
            $this->y -= $h; $this->buf .= sprintf("0.85 G 0.4 w %.2F %.2F m %.2F %.2F l S\n", $x0, $this->y + 2, $x0 + array_sum(array_column($cols, 1)), $this->y + 2);
        };
        $draw(array_column($cols, 0), true);
        foreach ($rows as $r) { $draw($r, false); }
    }
    function output(): string
    {
        $pages = $this->pages; if ($this->buf !== '' || !$pages) { $pages[] = $this->buf; }
        $n = count($pages); $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = []; for ($i = 0; $i < $n; $i++) { $kids[] = (6 + $i * 2) . ' 0 R'; }
        $objs[2] = '<< /Type /Pages /Count ' . $n . ' /Kids [' . implode(' ', $kids) . '] >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[5] = '<< /Title (' . self::esc($this->title) . ') /Producer (Infynite portal) >>';
        foreach ($pages as $i => $content) {
            $footer = sprintf("BT /F1 8 Tf 0.45 g %.2F 24 Td (%s) Tj ET\n", self::W / 2 - 20, 'Page ' . ($i + 1) . ' of ' . $n);
            $z = gzcompress($content . $footer);
            $objs[6 + $i * 2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . (7 + $i * 2) . ' 0 R >>';
            $objs[7 + $i * 2] = "<< /Length " . strlen($z) . " /Filter /FlateDecode >>\nstream\n$z\nendstream";
        }
        $out = "%PDF-1.4\n"; $off = [];
        foreach ($objs as $k => $o) { $off[$k] = strlen($out); $out .= "$k 0 obj\n$o\nendobj\n"; }
        $x = strlen($out); $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) { $out .= sprintf("%010d 00000 n \n", $o); }
        return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n$x\n%%EOF\n";
    }
    /** Sends the PDF as a download and ends the request. */
    function download(string $filename): void
    {
        $d = $this->output();
        header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($d)); header('Cache-Control: private, no-store'); echo $d; exit;
    }
}

/** Plain-text rendering of a Markdown knowledge-base article into a Pdf. */
function pdf_markdown(Pdf $p, string $md): void
{
    foreach (preg_split('/\R/', $md) as $l) {
        $l = rtrim($l);
        if ($l === '') { $p->gap(4); continue; }
        $l = preg_replace(['/\*\*(.+?)\*\*/', '/`([^`]*)`/', '/\[([^\]]+)\]\([^)]*\)/', '/_(.+?)_/'], ['$1', '$1', '$1', '$1'], $l);
        if (preg_match('/^(#{1,4})\s+(.*)/', $l, $m)) { $p->gap(5); $p->para($m[2], [1 => 15, 2 => 13, 3 => 11.5, 4 => 10.5][strlen($m[1])], true); }
        elseif (preg_match('/^\s*[-*]\s+\[( |x)\]\s+(.*)/i', $l, $m)) { $p->para(($m[1] === ' ' ? '[  ] ' : '[x] ') . $m[2], 10, false, Pdf::M + 8, Pdf::W - 2 * Pdf::M - 8); }
        elseif (preg_match('/^\s*[-*]\s+(.*)/', $l, $m)) { $p->para('- ' . $m[1], 10, false, Pdf::M + 8, Pdf::W - 2 * Pdf::M - 8); }
        elseif (preg_match('/^\s*(\d+)\.\s+(.*)/', $l, $m)) { $p->para($m[1] . '. ' . $m[2], 10, false, Pdf::M + 8, Pdf::W - 2 * Pdf::M - 8); }
        elseif (preg_match('/^>\s?(.*)/', $l, $m)) { $p->para($m[1], 10, false, Pdf::M + 14, Pdf::W - 2 * Pdf::M - 14); }
        else { $p->para($l); }
    }
}
