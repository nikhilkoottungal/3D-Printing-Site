<?php
/** QR code generator (byte mode, error correction M, versions 1-10). Returns a matrix of 0/1 rows, or an SVG string. */
final class Qr
{
    // [data codewords per block group list [count, dataPerBlock]], ec codewords per block  (level M)
    private const BLOCKS = [1 => [[[1, 16]], 10], 2 => [[[1, 28]], 16], 3 => [[[1, 44]], 26], 4 => [[[2, 32]], 18], 5 => [[[2, 43]], 24], 6 => [[[4, 27]], 16],
        7 => [[[4, 31]], 18], 8 => [[[2, 38], [2, 39]], 22], 9 => [[[3, 36], [2, 37]], 22], 10 => [[[4, 43], [1, 44]], 26]];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        for ($v = 1; $v <= 10; $v++) {
            $cap = 0; foreach (self::BLOCKS[$v][0] as [$n, $d]) { $cap += $n * $d; }
            $cc = $v < 10 ? 8 : 16;
            if (4 + $cc + 8 * count($bytes) <= $cap * 8) { break; }
            if ($v === 10) { throw new RuntimeException('QR text too long'); }
        }
        $bits = [];
        $push = function (int $val, int $len) use (&$bits) { for ($i = $len - 1; $i >= 0; $i--) { $bits[] = ($val >> $i) & 1; } };
        $push(4, 4); $push(count($bytes), $cc); foreach ($bytes as $b) { $push($b, 8); }
        $cap = 0; foreach (self::BLOCKS[$v][0] as [$n, $d]) { $cap += $n * $d; }
        for ($i = 0; $i < 4 && count($bits) < $cap * 8; $i++) { $bits[] = 0; }
        while (count($bits) % 8) { $bits[] = 0; }
        $data = []; foreach (array_chunk($bits, 8) as $c) { $data[] = bindec(implode('', $c)); }
        for ($pad = 0xEC; count($data) < $cap; $pad ^= 0xEC ^ 0x11) { $data[] = $pad; }
        // blocks + reed-solomon
        $ecLen = self::BLOCKS[$v][1]; $blocks = []; $pos = 0;
        foreach (self::BLOCKS[$v][0] as [$n, $d]) { for ($i = 0; $i < $n; $i++) { $blocks[] = array_slice($data, $pos, $d); $pos += $d; } }
        $gen = self::rsGen($ecLen); $ecs = array_map(fn($b) => self::rsRem($b, $gen), $blocks);
        $out = []; $maxD = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxD; $i++) { foreach ($blocks as $b) { if (isset($b[$i])) { $out[] = $b[$i]; } } }
        for ($i = 0; $i < $ecLen; $i++) { foreach ($ecs as $e) { $out[] = $e[$i]; } }
        // matrix
        $size = 17 + 4 * $v; $m = array_fill(0, $size, array_fill(0, $size, 0)); $fn = array_fill(0, $size, array_fill(0, $size, false));
        $set = function (int $x, int $y, bool $dark) use (&$m, &$fn, $size) { if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) { $m[$y][$x] = $dark ? 1 : 0; $fn[$y][$x] = true; } };
        for ($i = 0; $i < $size; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) { for ($dx = -4; $dx <= 4; $dx++) { $d = max(abs($dx), abs($dy)); $set($cx + $dx, $cy + $dy, $d !== 2 && $d !== 4); } }
        }
        $al = self::ALIGN[$v]; $na = count($al);
        for ($i = 0; $i < $na; $i++) { for ($j = 0; $j < $na; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) { continue; }
            for ($dy = -2; $dy <= 2; $dy++) { for ($dx = -2; $dx <= 2; $dx++) { $set($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) !== 1); } }
        } }
        self::drawFormat($set, $size, 0);   // reserve
        if ($v >= 7) { $rem = $v; for ($i = 0; $i < 12; $i++) { $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25); } $vb = ($v << 12) | $rem;
            for ($i = 0; $i < 18; $i++) { $c = (($vb >> $i) & 1) === 1; $a = $size - 11 + $i % 3; $b = intdiv($i, 3); $set($a, $b, $c); $set($b, $a, $c); } }
        // data
        $i = 0; $total = count($out) * 8;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) { $right = 5; }
            for ($vert = 0; $vert < $size; $vert++) { for ($j = 0; $j < 2; $j++) {
                $x = $right - $j; $y = ((($right + 1) & 2) === 0) ? $size - 1 - $vert : $vert;
                if (!$fn[$y][$x] && $i < $total) { $m[$y][$x] = ($out[$i >> 3] >> (7 - ($i & 7))) & 1; $i++; }
            } }
        }
        $best = null; $bestPen = PHP_INT_MAX; $bestMask = 0;
        for ($mask = 0; $mask < 8; $mask++) {
            $t = $m; self::applyMask($t, $fn, $size, $mask);
            $fm = $fn; $set2 = function (int $x, int $y, bool $dark) use (&$t, &$fm, $size) { if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) { $t[$y][$x] = $dark ? 1 : 0; } };
            self::drawFormat($set2, $size, $mask);
            $pen = self::penalty($t, $size);
            if ($pen < $bestPen) { $bestPen = $pen; $best = $t; $bestMask = $mask; }
        }
        return $best;
    }
    private static function drawFormat(callable $set, int $size, int $mask): void
    {
        $data = (0 << 3) | $mask; $rem = $data;               // error correction level M = bits 00
        for ($i = 0; $i < 10; $i++) { $rem = ($rem << 1) ^ (($rem >> 9) * 0x537); }
        $bits = (($data << 10) | $rem) ^ 0x5412; $b = fn($i) => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) { $set(8, $i, $b($i)); }
        $set(8, 7, $b(6)); $set(8, 8, $b(7)); $set(7, 8, $b(8));
        for ($i = 9; $i < 15; $i++) { $set(14 - $i, 8, $b($i)); }
        for ($i = 0; $i < 8; $i++) { $set($size - 1 - $i, 8, $b($i)); }
        for ($i = 8; $i < 15; $i++) { $set(8, $size - 15 + $i, $b($i)); }
        $set(8, $size - 8, true);
    }
    private static function applyMask(array &$m, array $fn, int $size, int $mask): void
    {
        for ($y = 0; $y < $size; $y++) { for ($x = 0; $x < $size; $x++) {
            if ($fn[$y][$x]) { continue; }
            $inv = match ($mask) { 0 => ($x + $y) % 2 === 0, 1 => $y % 2 === 0, 2 => $x % 3 === 0, 3 => ($x + $y) % 3 === 0, 4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                5 => $x * $y % 2 + $x * $y % 3 === 0, 6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0, default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0 };
            if ($inv) { $m[$y][$x] ^= 1; }
        } }
    }
    private static function penalty(array $m, int $size): int
    {
        $p = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1; for ($b = 1; $b < $size; $b++) {
                    $cur = $pass ? $m[$b][$a] : $m[$a][$b]; $prev = $pass ? $m[$b - 1][$a] : $m[$a][$b - 1];
                    if ($cur === $prev) { $run++; if ($run === 5) { $p += 3; } elseif ($run > 5) { $p++; } } else { $run = 1; }
                }
            }
        }
        for ($y = 0; $y < $size - 1; $y++) { for ($x = 0; $x < $size - 1; $x++) { $c = $m[$y][$x]; if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) { $p += 3; } } }
        $dark = 0; foreach ($m as $r) { $dark += array_sum($r); }
        return $p + (int) (abs($dark * 20 - $size * $size * 10) / ($size * $size)) * 10;
    }
    private static function mul(int $a, int $b): int
    {
        $r = 0; while ($b) { if ($b & 1) { $r ^= $a; } $a <<= 1; if ($a & 0x100) { $a ^= 0x11D; } $b >>= 1; }
        return $r;
    }
    private static function rsGen(int $n): array
    {
        $r = array_fill(0, $n, 0); $r[$n - 1] = 1; $root = 1;
        for ($i = 0; $i < $n; $i++) { for ($j = 0; $j < $n; $j++) { $r[$j] = self::mul($r[$j], $root); if ($j + 1 < $n) { $r[$j] ^= $r[$j + 1]; } } $root = self::mul($root, 2); }
        return $r;
    }
    private static function rsRem(array $data, array $gen): array
    {
        $r = array_fill(0, count($gen), 0);
        foreach ($data as $b) { $f = $b ^ array_shift($r); $r[] = 0; foreach ($gen as $i => $g) { $r[$i] ^= self::mul($g, $f); } }
        return $r;
    }
    /** SVG markup, $px = module size in pixels (quiet zone of 4 modules included). */
    static function svg(string $text, int $px = 4): string
    {
        $m = self::matrix($text); $n = count($m); $q = 4; $d = '';
        foreach ($m as $y => $row) { $x = 0; while ($x < $n) { if ($row[$x]) { $s = $x; while ($x < $n && $row[$x]) { $x++; } $d .= 'M' . ($s + $q) . ' ' . ($y + $q) . 'h' . ($x - $s) . 'v1h-' . ($x - $s) . 'z'; } else { $x++; } } }
        $t = ($n + 2 * $q);
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $t . ' ' . $t . '" width="' . ($t * $px) . '" height="' . ($t * $px) . '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="100%" height="100%" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
    }
}
