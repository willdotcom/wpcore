#!/usr/bin/env php
<?php
/**
 * ────────────────────────────────────────────────────────────────────────────
 *  WPCore · Reinstalador del núcleo de WordPress desde la terminal
 * ────────────────────────────────────────────────────────────────────────────
 *  Reemplaza wp-admin, wp-includes y los archivos raíz del núcleo por una copia
 *  limpia y verificada desde wordpress.org, conservando wp-config.php,
 *  wp-content/ y .htaccess.
 *
 *  Uso rápido (desde la raíz del WordPress):
 *      php wpcore.php
 *
 *  Ejecuta `php wpcore.php help` para ver comandos y opciones.
 *  Requiere PHP 7.4+ y la extensión cURL o allow_url_fopen.
 * ────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "WPCore requiere PHP 7.4 o superior (tienes " . PHP_VERSION . ").\n");
    exit(1);
}

set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('memory_limit', '512M');

const WPCORE_VERSION = '2.0.0';

/* ═══════════════════════════════════════════════════════════════════════════
 *  Terminal: colores, ancho, modo raw y lectura de teclas
 * ═══════════════════════════════════════════════════════════════════════════ */

final class Term
{
    public static $color = false;
    public static $tty = false;    // STDOUT es una terminal
    public static $input = false;  // STDIN es una terminal
    private static $stty = null;
    private static $width = null;

    public static function init(bool $noColor): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            if (function_exists('sapi_windows_vt100_support')) {
                @sapi_windows_vt100_support(STDOUT, true);
            }
            if (function_exists('sapi_windows_cp_set')) {
                @sapi_windows_cp_set(65001);
            }
        }
        self::$tty = self::isatty(STDOUT);
        self::$input = self::isatty(STDIN);
        self::$color = !$noColor && self::$tty && getenv('NO_COLOR') === false && getenv('TERM') !== 'dumb';
    }

    private static function isatty($stream): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }
        return function_exists('posix_isatty') && @posix_isatty($stream);
    }

    public static function canShell(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            $ok = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
        }
        return $ok;
    }

    public static function width(): int
    {
        if (self::$width !== null) {
            return self::$width;
        }
        $cols = (int) getenv('COLUMNS');
        if ($cols <= 0 && DIRECTORY_SEPARATOR === '/' && self::$input && self::canShell()) {
            $size = (string) @shell_exec('stty size 2>/dev/null');
            if (preg_match('/^\d+\s+(\d+)/', trim($size), $m)) {
                $cols = (int) $m[1];
            }
        }
        if ($cols <= 0 && DIRECTORY_SEPARATOR === '\\' && self::canShell()) {
            $mode = (string) @shell_exec('mode con');
            if (preg_match('/(?:Columns|Columnas)\s*:\s*(\d+)/i', $mode, $m)) {
                $cols = (int) $m[1];
            }
        }
        return self::$width = max(40, min($cols > 0 ? $cols : 80, 100));
    }

    public static function rawOn(): bool
    {
        if (self::$stty !== null) {
            return true;
        }
        if (!self::$input || !self::$tty || DIRECTORY_SEPARATOR !== '/' || !self::canShell()) {
            return false;
        }
        $state = trim((string) shell_exec('stty -g 2>/dev/null'));
        if ($state === '') {
            return false;
        }
        self::$stty = $state;
        shell_exec('stty -icanon -echo -isig min 1 time 0 2>/dev/null');
        return true;
    }

    public static function rawOff(): void
    {
        if (self::$stty !== null) {
            shell_exec('stty ' . escapeshellarg(self::$stty) . ' 2>/dev/null');
            self::$stty = null;
        }
    }

    public static function key(): string
    {
        $c = fread(STDIN, 1);
        if ($c === false || $c === '') {
            return 'ctrl-c';
        }
        if ($c === "\033") {
            $r = [STDIN];
            $w = $e = null;
            if (@stream_select($r, $w, $e, 0, 60000)) {
                $c .= (string) fread(STDIN, 2);
            }
        }
        switch ($c) {
            case "\033[A": case "\033OA": case 'k': return 'up';
            case "\033[B": case "\033OB": case 'j': return 'down';
            case "\n": case "\r": return 'enter';
            case ' ': return 'space';
            case "\x03": case "\x04": return 'ctrl-c';
            case "\033": return 'esc';
        }
        return strtolower($c);
    }

    public static function readLine(string $prompt): ?string
    {
        self::write($prompt);
        $line = fgets(STDIN);
        if (!self::$input) {
            self::write("\n");  // la entrada no se ve en eco cuando viene por tubería
        }
        return $line === false ? null : rtrim($line, "\r\n");
    }

    public static function write(string $s): void
    {
        fwrite(STDOUT, $s);
    }

    public static function line(string $s = ''): void
    {
        fwrite(STDOUT, $s . "\n");
    }

    public static function clearUp(int $lines): void
    {
        if (self::$tty && $lines > 0) {
            self::write("\033[{$lines}A\r\033[J");
        }
    }

    public static function hideCursor(): void
    {
        if (self::$tty) {
            self::write("\033[?25l");
        }
    }

    public static function showCursor(): void
    {
        if (self::$tty) {
            self::write("\033[?25h");
        }
    }
}

final class C
{
    public static function paint(string $s, string $code): string
    {
        return Term::$color && $s !== '' ? "\033[{$code}m{$s}\033[0m" : $s;
    }

    public static function bold(string $s): string   { return self::paint($s, '1'); }
    public static function dim(string $s): string    { return self::paint($s, '2'); }
    public static function white(string $s): string  { return self::paint($s, '1;97'); }
    public static function accent(string $s): string { return self::paint($s, '38;5;75'); }
    public static function brand(string $s): string  { return self::paint($s, '1;38;5;81'); }
    public static function ok(string $s): string     { return self::paint($s, '38;5;78'); }
    public static function warn(string $s): string   { return self::paint($s, '38;5;214'); }
    public static function err(string $s): string    { return self::paint($s, '38;5;203'); }
    public static function muted(string $s): string  { return self::paint($s, '38;5;244'); }

    public static function tone(string $s, ?string $tone): string
    {
        switch ($tone) {
            case 'ok':     return self::ok($s);
            case 'warn':   return self::warn($s);
            case 'err':    return self::err($s);
            case 'muted':  return self::muted($s);
            case 'accent': return self::accent($s);
            case 'white':  return self::white($s);
            case 'brand':  return self::brand($s);
        }
        return $s;
    }
}

final class Str
{
    public static function strip(string $s): string
    {
        return (string) preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $s);
    }

    public static function len(string $s): int
    {
        $s = self::strip($s);
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int) preg_match_all('/./us', $s);
    }

    /** Recorta texto plano a $max caracteres visibles. */
    public static function fit(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (self::len($s) <= $max) {
            return $s;
        }
        $chars = preg_split('//u', self::strip($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, 0, max(0, $max - 1))) . '…';
    }

    /** Recorta por el inicio, útil para rutas largas. */
    public static function fitLeft(string $s, int $max): string
    {
        if (self::len($s) <= $max) {
            return $s;
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return '…' . implode('', array_slice($chars, -($max - 1)));
    }

    public static function pad(string $s, int $w): string
    {
        return $s . str_repeat(' ', max(0, $w - self::len($s)));
    }

    public static function starts(string $s, string $prefix): bool
    {
        return strncmp($s, $prefix, strlen($prefix)) === 0;
    }

    public static function num(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    public static function bytes(float $b): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($b >= 1024 && $i < 3) {
            $b /= 1024;
            $i++;
        }
        return ($i ? number_format($b, 1, ',', '.') : (string) (int) $b) . ' ' . $units[$i];
    }

    public static function plural(int $n, string $one, string $many): string
    {
        return Str::num($n) . ' ' . ($n === 1 ? $one : $many);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  UI: banner, paneles, prompts interactivos y tareas con spinner
 * ═══════════════════════════════════════════════════════════════════════════ */

final class UI
{
    const SPIN = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /** Modo desatendido (--yes): los prompts devuelven su valor por defecto. */
    public static $auto = false;

    public static function banner(): void
    {
        $art = [
            '██╗    ██╗██████╗  ██████╗ ██████╗ ██████╗ ███████╗',
            '██║    ██║██╔══██╗██╔════╝██╔═══██╗██╔══██╗██╔════╝',
            '██║ █╗ ██║██████╔╝██║     ██║   ██║██████╔╝█████╗  ',
            '██║███╗██║██╔═══╝ ██║     ██║   ██║██╔══██╗██╔══╝  ',
            '╚███╔███╔╝██║     ╚██████╗╚██████╔╝██║  ██║███████╗',
            ' ╚══╝╚══╝ ╚═╝      ╚═════╝ ╚═════╝ ╚═╝  ╚═╝╚══════╝',
        ];
        $gradient = ['38;5;87', '38;5;81', '38;5;75', '38;5;69', '38;5;63', '38;5;99'];

        Term::line();
        if (Term::width() >= 60) {
            foreach ($art as $i => $row) {
                Term::line('  ' . C::paint($row, $gradient[$i]));
            }
        } else {
            Term::line('  ' . C::brand('W P C O R E'));
        }
        Term::line('  ' . C::muted('Reinstalador del núcleo de WordPress') . C::muted('  ·  ') . C::accent('v' . WPCORE_VERSION));
    }

    public static function step(int $n, int $total, string $title): void
    {
        self::rule("PASO $n/$total", $title);
    }

    public static function title(string $title): void
    {
        self::rule('', $title);
    }

    private static function rule(string $kicker, string $title): void
    {
        $w = min(Term::width() - 4, 78);
        $plain = '━━ ' . ($kicker !== '' ? "$kicker · " : '') . "$title ";
        $line = C::accent('━━ ') . ($kicker !== '' ? C::muted("$kicker · ") : '') . C::white($title) . ' ';
        Term::line();
        Term::line('  ' . $line . C::accent(str_repeat('━', max(3, $w - Str::len($plain)))));
        Term::line();
    }

    /**
     * Panel con borde redondeado.
     * Filas: [etiqueta, valor, tono] · string (texto libre) · null (fila vacía).
     */
    public static function panel(string $title, array $rows, string $tone = 'accent'): void
    {
        $w = min(Term::width() - 4, 76);
        $inner = $w - 2;
        $labelW = 0;
        foreach ($rows as $r) {
            if (is_array($r)) {
                $labelW = max($labelW, Str::len($r[0]));
            }
        }
        $b = function (string $s) use ($tone): string {
            return C::tone($s, $tone);
        };

        $head = '╭─ ' . $title . ' ';
        Term::line('  ' . $b('╭─ ') . C::white($title) . ' ' . $b(str_repeat('─', max(1, $w - Str::len($head) - 1)) . '╮'));
        $expanded = [];
        foreach ($rows as $r) {
            if (is_string($r) && Str::len($r) > $inner - 4) {
                foreach (explode("\n", wordwrap(Str::strip($r), $inner - 4, "\n", true)) as $part) {
                    $expanded[] = $part;
                }
            } else {
                $expanded[] = $r;
            }
        }
        foreach ($expanded as $r) {
            if ($r === null) {
                $content = '';
            } elseif (is_string($r)) {
                $content = '  ' . $r;
            } else {
                $value = Str::fit((string) $r[1], $inner - 6 - $labelW);
                $content = '  ' . C::muted(Str::pad($r[0], $labelW)) . '  ' . C::tone($value, $r[2] ?? null);
            }
            Term::line('  ' . $b('│') . $content . str_repeat(' ', max(0, $inner - Str::len($content))) . $b('│'));
        }
        Term::line('  ' . $b('╰' . str_repeat('─', $w - 2) . '╯'));
    }

    public static function info(string $m): void    { Term::line('  ' . C::accent('ℹ') . ' ' . $m); }
    public static function success(string $m): void { Term::line('  ' . C::ok('✔') . ' ' . $m); }
    public static function warning(string $m): void { Term::line('  ' . C::warn('▲') . ' ' . $m); }
    public static function error(string $m): void   { Term::line('  ' . C::err('✖') . ' ' . $m); }
    public static function note(string $m): void    { Term::line('    ' . C::muted($m)); }

    /** Lista de elementos con límite visible. */
    public static function items(array $items, int $max = 12, string $tone = 'muted'): void
    {
        $w = Term::width() - 8;
        foreach (array_slice($items, 0, $max) as $item) {
            Term::line('    ' . C::muted('·') . ' ' . C::tone(Str::fitLeft($item, $w), $tone));
        }
        if (count($items) > $max) {
            Term::line('    ' . C::muted('… y ' . Str::num(count($items) - $max) . ' más'));
        }
    }

    /* ── Prompts ──────────────────────────────────────────────────────────── */

    private static function normalize(array $opts): array
    {
        $out = [];
        foreach ($opts as $key => $o) {
            $o = is_array($o) ? $o : ['label' => (string) $o];
            $out[$key] = $o + ['hint' => '', 'checked' => false, 'short' => $o['label']];
        }
        return $out;
    }

    private static function answered(string $q, string $a): void
    {
        $room = Term::width() - Str::len($q) - 9;
        Term::line('  ' . C::ok('✔') . ' ' . C::bold($q) . ' ' . C::muted('›') . ' ' . C::accent(Str::fit($a, max(10, $room))));
    }

    private static function cancelPrompt(int $lines): void
    {
        Term::clearUp($lines);
        Term::rawOff();
        Term::showCursor();
        App::abort();
    }

    /** Menú de una opción. Devuelve la clave elegida. */
    public static function select(string $q, array $opts, $default = null)
    {
        $opts = self::normalize($opts);
        $keys = array_keys($opts);
        $cur = $default !== null && in_array($default, $keys, true) ? array_search($default, $keys, true) : 0;

        if (self::$auto) {
            self::answered($q, $opts[$keys[$cur]]['short']);
            return $keys[$cur];
        }

        if (Term::rawOn()) {
            $n = count($keys);
            Term::hideCursor();
            $lines = self::drawSelect($q, $opts, $cur);
            while (true) {
                $k = Term::key();
                if ($k === 'up') {
                    $cur = ($cur - 1 + $n) % $n;
                } elseif ($k === 'down') {
                    $cur = ($cur + 1) % $n;
                } elseif (ctype_digit($k) && (int) $k >= 1 && (int) $k <= $n) {
                    $cur = (int) $k - 1;
                } elseif ($k === 'enter') {
                    break;
                } elseif (in_array($k, ['ctrl-c', 'esc', 'q'], true)) {
                    self::cancelPrompt($lines);
                } else {
                    continue;
                }
                Term::clearUp($lines);
                $lines = self::drawSelect($q, $opts, $cur);
            }
            Term::clearUp($lines);
            Term::rawOff();
            Term::showCursor();
        } else {
            Term::line('  ' . C::accent('?') . ' ' . C::white($q));
            $labelW = max(array_map(function ($o) { return Str::len($o['label']); }, $opts));
            foreach ($keys as $i => $key) {
                $o = $opts[$key];
                Term::line('    ' . C::accent((string) ($i + 1)) . C::muted(')') . ' ' . Str::pad($o['label'], $labelW) . ($o['hint'] !== '' ? '   ' . C::muted($o['hint']) : ''));
            }
            while (true) {
                $a = Term::readLine('    ' . C::muted('Opción [' . ($cur + 1) . ']: '));
                if ($a === null) {
                    App::abort();
                }
                $a = trim($a);
                if ($a === '') {
                    break;
                }
                if (ctype_digit($a) && (int) $a >= 1 && (int) $a <= count($keys)) {
                    $cur = (int) $a - 1;
                    break;
                }
                Term::line('    ' . C::err('Opción no válida.'));
            }
        }

        self::answered($q, $opts[$keys[$cur]]['short']);
        return $keys[$cur];
    }

    private static function drawSelect(string $q, array $opts, int $cur): int
    {
        $w = Term::width() - 2;
        $labelW = max(array_map(function ($o) { return Str::len($o['label']); }, $opts));
        $out = ['  ' . C::accent('?') . ' ' . C::white(Str::fit($q, $w - 4))];
        $i = 0;
        foreach ($opts as $o) {
            $room = $w - 7 - $labelW;
            $hint = $o['hint'] !== '' && $room > 8 ? '   ' . Str::fit($o['hint'], $room) : '';
            $label = Str::pad($o['label'], $labelW);
            $out[] = $i === $cur
                ? '  ' . C::accent('❯') . ' ' . C::brand($label) . C::paint($hint, '38;5;250')
                : '    ' . $label . C::muted($hint);
            $i++;
        }
        $out[] = '  ' . C::muted('↑↓ mover · enter elegir · q salir');
        Term::write(implode("\n", $out) . "\n");
        return count($out);
    }

    /** Selección múltiple. Devuelve las claves marcadas. */
    public static function multiselect(string $q, array $opts): array
    {
        $opts = self::normalize($opts);
        $keys = array_keys($opts);
        $sel = array_map(function ($k) use ($opts) { return (bool) $opts[$k]['checked']; }, $keys);

        if (!self::$auto && Term::rawOn()) {
            $n = count($keys);
            $cur = 0;
            Term::hideCursor();
            $lines = self::drawMulti($q, $opts, $sel, $cur);
            while (true) {
                $k = Term::key();
                if ($k === 'up') {
                    $cur = ($cur - 1 + $n) % $n;
                } elseif ($k === 'down') {
                    $cur = ($cur + 1) % $n;
                } elseif ($k === 'space') {
                    $sel[$cur] = !$sel[$cur];
                } elseif ($k === 'a') {
                    $sel = array_fill(0, $n, in_array(false, $sel, true));
                } elseif ($k === 'enter') {
                    break;
                } elseif (in_array($k, ['ctrl-c', 'esc', 'q'], true)) {
                    self::cancelPrompt($lines);
                } else {
                    continue;
                }
                Term::clearUp($lines);
                $lines = self::drawMulti($q, $opts, $sel, $cur);
            }
            Term::clearUp($lines);
            Term::rawOff();
            Term::showCursor();
        } elseif (!self::$auto) {
            while (true) {
                Term::line('  ' . C::accent('?') . ' ' . C::white($q));
                foreach ($keys as $i => $key) {
                    $box = $sel[$i] ? C::ok('[x]') : C::muted('[ ]');
                    Term::line('    ' . C::accent((string) ($i + 1)) . C::muted(')') . " $box " . $opts[$key]['label']);
                }
                $a = Term::readLine('    ' . C::muted('Números para marcar/desmarcar (ej. 2,4) · enter para continuar: '));
                if ($a === null) {
                    App::abort();
                }
                if (trim($a) === '') {
                    break;
                }
                foreach (preg_split('/[\s,]+/', trim($a)) as $num) {
                    if (ctype_digit($num) && isset($sel[(int) $num - 1])) {
                        $sel[(int) $num - 1] = !$sel[(int) $num - 1];
                    }
                }
            }
        }

        $chosen = [];
        foreach ($keys as $i => $key) {
            if ($sel[$i]) {
                $chosen[] = $key;
            }
        }
        $summary = array_map(function ($k) use ($opts) { return $opts[$k]['short']; }, $chosen);
        self::answered($q, $summary ? implode(', ', $summary) : 'ninguna');
        return $chosen;
    }

    private static function drawMulti(string $q, array $opts, array $sel, int $cur): int
    {
        $w = Term::width() - 2;
        $labelW = max(array_map(function ($o) { return Str::len($o['label']); }, $opts));
        $out = ['  ' . C::accent('?') . ' ' . C::white(Str::fit($q, $w - 4))];
        $i = 0;
        foreach ($opts as $o) {
            $room = $w - 9 - $labelW;
            $hint = $o['hint'] !== '' && $room > 8 ? '   ' . Str::fit($o['hint'], $room) : '';
            $box = $sel[$i] ? C::ok('◉') : C::muted('◯');
            $label = Str::pad($o['label'], $labelW);
            $out[] = $i === $cur
                ? '  ' . C::accent('❯') . " $box " . C::brand($label) . C::paint($hint, '38;5;250')
                : "    $box " . $label . C::muted($hint);
            $i++;
        }
        $out[] = '  ' . C::muted('↑↓ mover · espacio marcar · a todas · enter confirmar');
        Term::write(implode("\n", $out) . "\n");
        return count($out);
    }

    public static function confirm(string $q, bool $default = true): bool
    {
        if (self::$auto) {
            self::answered($q, $default ? 'Sí' : 'No');
            return $default;
        }
        $hint = C::muted($default ? '(S/n)' : '(s/N)');
        if (Term::rawOn()) {
            Term::write('  ' . C::accent('?') . ' ' . C::white($q) . " $hint ");
            while (true) {
                $k = Term::key();
                if ($k === 's' || $k === 'y') { $v = true; break; }
                if ($k === 'n') { $v = false; break; }
                if ($k === 'enter') { $v = $default; break; }
                if ($k === 'ctrl-c' || $k === 'esc') {
                    Term::rawOff();
                    Term::line();
                    App::abort();
                }
            }
            Term::rawOff();
            Term::write("\r\033[K");
        } else {
            while (true) {
                $a = Term::readLine('  ' . C::accent('?') . ' ' . C::white($q) . " $hint ");
                if ($a === null) {
                    App::abort();
                }
                $a = strtolower(trim($a));
                if ($a === '') { $v = $default; break; }
                if (in_array($a, ['s', 'si', 'sí', 'y', 'yes'], true)) { $v = true; break; }
                if (in_array($a, ['n', 'no'], true)) { $v = false; break; }
            }
            if (Term::$tty) {
                Term::write("\033[1A\r\033[K");
            }
        }
        self::answered($q, $v ? 'Sí' : 'No');
        return $v;
    }

    public static function ask(string $q, string $default = '', ?callable $validate = null): string
    {
        if (self::$auto && $default !== '') {
            self::answered($q, $default);
            return $default;
        }
        while (true) {
            $suffix = $default !== '' ? ' ' . C::muted("($default)") : '';
            $a = Term::readLine('  ' . C::accent('?') . ' ' . C::white($q) . $suffix . ' ');
            if ($a === null) {
                App::abort();
            }
            $a = trim($a) === '' ? $default : trim($a);
            $error = $validate ? $validate($a) : null;
            if ($error === null && $a !== '') {
                if (Term::$tty) {
                    Term::write("\033[1A\r\033[K");
                }
                self::answered($q, $a);
                return $a;
            }
            Term::line('    ' . C::err($error ?: 'Este valor es obligatorio.'));
        }
    }
}

/** Línea de progreso con spinner, barra opcional y resultado final. */
final class Task
{
    private $label;
    private $start;
    private $frame = 0;
    private $last = 0.0;
    private $ratio = null;
    private $detail = '';
    private $closed = false;

    public function __construct(string $label)
    {
        $this->label = $label;
        $this->start = microtime(true);
        if (Term::$tty) {
            Term::hideCursor();
            $this->draw();
        }
    }

    /** Ejecuta $fn mostrando la tarea; marca ✔ al terminar o ✖ si lanza excepción. */
    public static function run(string $label, callable $fn)
    {
        $t = new self($label);
        try {
            $result = $fn($t);
        } catch (Throwable $e) {
            $t->fail($e->getMessage());
            throw $e;
        }
        $t->done();
        return $result;
    }

    public function update(?float $ratio = null, string $detail = ''): void
    {
        $this->ratio = $ratio;
        $this->detail = $detail;
        $now = microtime(true);
        if (!Term::$tty || $now - $this->last < 0.08) {
            return;
        }
        $this->last = $now;
        $this->frame++;
        $this->draw();
    }

    private function draw(): void
    {
        $w = Term::width() - 1;
        $line = '  ' . C::accent(UI::SPIN[$this->frame % count(UI::SPIN)]) . ' ' . $this->label;
        $used = 4 + Str::len($this->label);
        if ($this->ratio !== null) {
            $barW = max(10, min(26, $w - $used - 30));
            $ratio = max(0.0, min(1.0, $this->ratio));
            $filled = (int) round($barW * $ratio);
            $pct = str_pad((string) (int) floor($ratio * 100), 3, ' ', STR_PAD_LEFT) . '%';
            $line .= '  ' . C::accent(str_repeat('━', $filled)) . C::paint(str_repeat('━', $barW - $filled), '38;5;238') . ' ' . C::white($pct);
            $used += 3 + $barW + 4;
        }
        $room = $w - $used - 2;
        if ($this->detail !== '' && $room > 6) {
            $line .= '  ' . C::muted(Str::fitLeft($this->detail, $room));
        }
        Term::write("\r\033[K" . $line);
    }

    public function done(string $note = ''): void { $this->finish(C::ok('✔'), $note !== '' ? C::muted($note) : ''); }
    public function warn(string $note): void      { $this->finish(C::warn('▲'), C::warn($note)); }
    public function fail(string $note): void      { $this->finish(C::err('✖'), C::err($note)); }

    private function finish(string $icon, string $note): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $elapsed = microtime(true) - $this->start;
        $time = $elapsed >= 0.1 ? '  ' . C::paint(sprintf('%.1fs', $elapsed), '38;5;240') : '';
        $line = '  ' . $icon . ' ' . $this->label . ($note !== '' ? C::muted(' · ') . $note : '') . $time;
        Term::write((Term::$tty ? "\r\033[K" : '') . $line . "\n");
        Term::showCursor();
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  Red y sistema de archivos
 * ═══════════════════════════════════════════════════════════════════════════ */

final class Http
{
    private static function ua(): string
    {
        return 'WPCore/' . WPCORE_VERSION . ' (+https://github.com/willdotcom/wpcore)';
    }

    public static function get(string $url, int $timeout = 30): string
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => self::ua(),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === false) {
                throw new RuntimeException('Error de red: ' . curl_error($ch));
            }
            if ($code >= 400) {
                throw new RuntimeException("HTTP $code en $url");
            }
            return (string) $body;
        }

        self::requireFopen();
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => self::ua(), 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = self::status(isset($http_response_header) ? $http_response_header : []);
        if ($body === false) {
            throw new RuntimeException("No se pudo conectar con $url");
        }
        if ($code >= 400) {
            throw new RuntimeException("HTTP $code en $url");
        }
        return $body;
    }

    public static function json(string $url): array
    {
        $data = json_decode(self::get($url), true);
        if (!is_array($data)) {
            throw new RuntimeException("Respuesta inválida de $url");
        }
        return $data;
    }

    /** Descarga a disco. $progress(int $hechos, int $total). */
    public static function download(string $url, string $dest, callable $progress): void
    {
        $fh = @fopen($dest, 'wb');
        if (!$fh) {
            throw new RuntimeException("No se puede escribir $dest");
        }

        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE             => $fh,
                CURLOPT_FOLLOWLOCATION   => true,
                CURLOPT_CONNECTTIMEOUT   => 15,
                CURLOPT_TIMEOUT          => 600,
                CURLOPT_USERAGENT        => self::ua(),
                CURLOPT_FAILONERROR      => true,
                CURLOPT_NOPROGRESS       => false,
                defined('CURLOPT_XFERINFOFUNCTION') ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION =>
                    function ($ch, $total, $now) use ($progress) {
                        $progress((int) $now, (int) $total);
                        return 0;
                    },
            ]);
            $ok = curl_exec($ch);
            $error = curl_error($ch);
            fclose($fh);
            if (!$ok) {
                @unlink($dest);
                throw new RuntimeException("Descarga fallida: $error");
            }
            return;
        }

        self::requireFopen();
        $total = 0;
        $ctx = stream_context_create(['http' => ['user_agent' => self::ua()]], [
            'notification' => function ($code, $sev, $msg, $msgCode, $bytes, $max) use (&$total, $progress) {
                if ($code === STREAM_NOTIFY_FILE_SIZE_IS) {
                    $total = (int) $max;
                } elseif ($code === STREAM_NOTIFY_PROGRESS) {
                    $progress((int) $bytes, $total ?: (int) $max);
                }
            },
        ]);
        $in = @fopen($url, 'rb', false, $ctx);
        if (!$in) {
            fclose($fh);
            @unlink($dest);
            throw new RuntimeException("Descarga fallida: $url");
        }
        stream_copy_to_stream($in, $fh);
        fclose($in);
        fclose($fh);
    }

    private static function requireFopen(): void
    {
        if (!ini_get('allow_url_fopen')) {
            throw new RuntimeException('Se necesita la extensión cURL o allow_url_fopen=On en PHP.');
        }
    }

    private static function status(array $headers): int
    {
        $code = 0;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return $code;
    }
}

final class Fs
{
    public static function norm(string $p): string
    {
        return rtrim(str_replace('\\', '/', $p), '/');
    }

    public static function isPhp(string $name): bool
    {
        return (bool) preg_match('/\.(php\d?|phtml|phar|pht|phps|suspected)$/i', $name);
    }

    /** Lista recursiva de archivos, rutas relativas a $dir con "/". */
    public static function listFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $base = strlen(self::norm($dir)) + 1;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        $out = [];
        foreach ($it as $f) {
            if (!$f->isDir()) {
                $out[] = substr(self::norm($f->getPathname()), $base);
            }
        }
        sort($out);
        return $out;
    }

    /** Elimina un archivo forzando permisos si hace falta. */
    public static function unlink(string $p, array &$errors): void
    {
        if (@unlink($p)) {
            return;
        }
        @chmod(dirname($p), 0755);
        @chmod($p, 0644);
        if (!@unlink($p) && file_exists($p)) {
            $errors[] = $p;
        }
    }

    public static function rrmdir(string $dir, ?callable $tick, array &$errors): void
    {
        if (is_link($dir) || is_file($dir)) {
            self::unlink($dir, $errors);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        @chmod($dir, 0755);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            if ($f->isDir() && !$f->isLink()) {
                @chmod($p, 0755);
                if (!@rmdir($p)) {
                    $errors[] = $p;
                }
            } else {
                self::unlink($p, $errors);
                if ($tick) {
                    $tick($p);
                }
            }
        }
        if (!@rmdir($dir)) {
            $errors[] = $dir;
        }
    }

    public static function copyTree(string $src, string $dst): void
    {
        if (is_file($src)) {
            @chmod($dst, 0644);
            if (!@copy($src, $dst)) {
                throw new RuntimeException("No se pudo copiar a $dst");
            }
            return;
        }
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
            throw new RuntimeException("No se pudo crear $dst");
        }
        foreach (scandir($src) as $e) {
            if ($e !== '.' && $e !== '..') {
                self::copyTree("$src/$e", "$dst/$e");
            }
        }
    }

    public static function walk(string $path, callable $fn): void
    {
        $fn($path);
        if (is_dir($path) && !is_link($path)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $f) {
                $fn($f->getPathname());
            }
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  WordPress: detección del sitio y operaciones sobre el núcleo
 * ═══════════════════════════════════════════════════════════════════════════ */

final class WordPress
{
    /** Archivos raíz del núcleo (respaldo por si la API de checksums no responde). */
    const ROOT_FILES = [
        'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-blog-header.php',
        'wp-comments-post.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php',
        'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php',
        'wp-trackback.php', 'xmlrpc.php',
    ];

    /** Archivos de la raíz que nunca se consideran extraños. */
    const KEEP = [
        'wp-config.php', '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config',
        'robots.txt', 'favicon.ico', 'ads.txt', 'app-ads.txt', 'error_log',
    ];

    const SIGNATURES = [
        ['eval + decodificación', '/\beval\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|rawurldecode|hex2bin)\s*\(/i', 'alta'],
        ['ejecuta datos del usuario', '/\b(?:eval|assert|system|shell_exec|passthru|exec|popen|proc_open)\s*\(\s*(?:stripslashes\s*\(\s*)?\$_(?:POST|GET|REQUEST|COOKIE|SERVER)\b/i', 'alta'],
        ['include de archivo no PHP', '/\b(?:include|require)(?:_once)?\s*\(?\s*[\'"][^\'"\n]+\.(?:ico|png|jpe?g|gif|txt|log|css)[\'"]/i', 'alta'],
        ['web shell conocida', '/(?:FilesMan|WSO\s?shell|b374k|r57shell|c99shell|IndoXploit|alfa-?shell|Tiny\s?File\s?Manager)/i', 'alta'],
        ['preg_replace con /e', '/preg_replace\s*\(\s*[\'"]([^\w\s])(?:(?!\1).)+\1[imsxuU]*e[imsxuU]*[\'"]/i', 'alta'],
        ['$GLOBALS ofuscado', '/\$GLOBALS\s*\[\s*[\'"]\w{1,8}[\'"]\s*\]\s*\[\s*\d+\s*\]\s*\.\s*\$GLOBALS/i', 'alta'],
        ['cadena hex ofuscada', '/(?:\\\\x[0-9a-f]{2}){16,}/i', 'media'],
        ['bloque base64 extenso', '/[\'"][A-Za-z0-9+\/]{2000,}={0,2}[\'"]/', 'media'],
        ['create_function', '/\bcreate_function\s*\(/i', 'media'],
    ];

    public $path;

    public function __construct(string $path)
    {
        $this->path = Fs::norm(realpath($path) ?: $path);
    }

    /** Carpetas públicas habituales en hostings (cPanel, Debian/Ubuntu, XAMPP). */
    const PUBLIC_DIRS = ['public_html', 'www', 'htdocs'];

    /**
     * Busca la raíz de WordPress desde $dir hacia arriba y, si no la encuentra,
     * dentro de las carpetas públicas habituales de $dir (p. ej. /home/usuario/public_html).
     */
    public static function detect(string $dir): ?string
    {
        $start = realpath($dir);
        $dir = $start;
        for ($i = 0; $dir && $i < 6; $i++) {
            if (self::isRoot($dir)) {
                return Fs::norm($dir);
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        foreach ($start ? self::PUBLIC_DIRS : [] as $sub) {
            if (self::isRoot("$start/$sub")) {
                return Fs::norm((string) realpath("$start/$sub"));
            }
        }
        return null;
    }

    private static function isRoot(string $dir): bool
    {
        return is_file("$dir/wp-includes/version.php")
            || (is_dir("$dir/wp-content") && (is_file("$dir/wp-config.php") || is_file(dirname($dir) . '/wp-config.php')));
    }

    private function versionFile(): string
    {
        $file = "{$this->path}/wp-includes/version.php";
        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    public function version(): ?string
    {
        return preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $this->versionFile(), $m) ? $m[1] : null;
    }

    public function locale(): string
    {
        return preg_match('/\$wp_local_package\s*=\s*[\'"]([^\'"]+)[\'"]/', $this->versionFile(), $m) ? $m[1] : 'en_US';
    }

    public function configFile(): ?string
    {
        foreach (["{$this->path}/wp-config.php", dirname($this->path) . '/wp-config.php'] as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }

    public function config(): array
    {
        $file = $this->configFile();
        $src = $file ? (string) file_get_contents($file) : '';
        $out = [];
        preg_match_all('/define\s*\(\s*[\'"](DB_NAME|DB_HOST|DB_USER|WP_DEBUG|WP_HOME|WP_SITEURL)[\'"]\s*,\s*[\'"]?([^\'")]*)[\'"]?\s*\)/', $src, $m, PREG_SET_ORDER);
        foreach ($m as $d) {
            $out[$d[1]] = trim($d[2]);
        }
        if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $p)) {
            $out['table_prefix'] = $p[1];
        }
        return $out;
    }

    /* ── API de wordpress.org ─────────────────────────────────────────────── */

    public static function latest(): string
    {
        $data = Http::json('https://api.wordpress.org/core/version-check/1.7/');
        $offer = $data['offers'][0] ?? null;
        if (!$offer || empty($offer['current'])) {
            throw new RuntimeException('Respuesta inesperada de la API de versiones');
        }
        return (string) $offer['current'];
    }

    /** Checksums MD5 del núcleo, sin wp-content/. */
    public static function checksums(string $version, string $locale = 'en_US'): array
    {
        $url = 'https://api.wordpress.org/core/checksums/1.0/?' . http_build_query(['version' => $version, 'locale' => $locale]);
        $sums = Http::json($url)['checksums'] ?? false;
        if (is_array($sums) && isset($sums[$version]) && is_array($sums[$version])) {
            $sums = $sums[$version];
        }
        if (!is_array($sums) || !$sums) {
            throw new RuntimeException("WordPress $version no existe o no tiene checksums publicados");
        }
        return array_filter($sums, function ($file) {
            return !Str::starts($file, 'wp-content/');
        }, ARRAY_FILTER_USE_KEY);
    }

    public static function checksumsFor(string $version, string $locale): array
    {
        try {
            return self::checksums($version, $locale);
        } catch (RuntimeException $e) {
            if ($locale === 'en_US') {
                throw $e;
            }
            return self::checksums($version, 'en_US');
        }
    }

    public static function rootCore(array $sums): array
    {
        $root = array_filter(array_keys($sums), function ($f) { return strpos($f, '/') === false; });
        return array_values(array_unique(array_merge($root, self::ROOT_FILES)));
    }

    /* ── Análisis ─────────────────────────────────────────────────────────── */

    public function integrity(array $sums, ?Task $t = null): array
    {
        $r = ['ok' => 0, 'modified' => [], 'missing' => [], 'unknown' => []];
        $total = count($sums);
        $i = 0;
        foreach ($sums as $file => $md5) {
            $abs = "{$this->path}/$file";
            if (!is_file($abs)) {
                $r['missing'][] = $file;
            } elseif (md5_file($abs) !== $md5) {
                $r['modified'][] = $file;
            } else {
                $r['ok']++;
            }
            if ($t && ++$i % 15 === 0) {
                $t->update($i / $total, $file);
            }
        }
        foreach (['wp-admin', 'wp-includes'] as $dir) {
            foreach (Fs::listFiles("{$this->path}/$dir") as $rel) {
                if (!isset($sums["$dir/$rel"])) {
                    $r['unknown'][] = "$dir/$rel";
                }
            }
        }
        return $r;
    }

    /** Archivos de la raíz que no pertenecen al núcleo. */
    public function rootUnknown(array $sums): array
    {
        $known = array_flip(self::rootCore($sums));
        $self = Fs::norm((string) realpath(__FILE__));
        $out = [];
        foreach (scandir($this->path) ?: [] as $e) {
            $abs = "{$this->path}/$e";
            if (!is_file($abs) || isset($known[$e]) || in_array(strtolower($e), self::KEEP, true)
                || Fs::norm((string) realpath($abs)) === $self || preg_match('/^wpcore-(backup|report)-/', $e)) {
                continue;
            }
            $out[] = ['name' => $e, 'php' => Fs::isPhp($e), 'size' => (int) @filesize($abs)];
        }
        return $out;
    }

    public function uploadsPhp(): array
    {
        $dir = "{$this->path}/wp-content/uploads";
        $out = [];
        foreach (Fs::listFiles($dir) as $rel) {
            if (!Fs::isPhp($rel)) {
                continue;
            }
            $abs = "$dir/$rel";
            if (basename($rel) === 'index.php' && filesize($abs) < 80 && stripos((string) file_get_contents($abs), 'silence') !== false) {
                continue;
            }
            $out[] = "wp-content/uploads/$rel";
        }
        return $out;
    }

    /** Escaneo heurístico de firmas de malware. */
    public function scan(?Task $t = null): array
    {
        $files = [];
        foreach (Fs::listFiles("{$this->path}/wp-content") as $rel) {
            if (Fs::isPhp($rel) || preg_match('/\.(ico|js)$/i', $rel) && strpos($rel, 'uploads/') === 0) {
                $files[] = "wp-content/$rel";
            }
        }
        foreach (scandir($this->path) ?: [] as $e) {
            if (is_file("{$this->path}/$e") && Fs::isPhp($e)) {
                $files[] = $e;
            }
        }

        $hits = [];
        $total = count($files);
        foreach ($files as $i => $rel) {
            $abs = "{$this->path}/$rel";
            if ($t && $i % 10 === 0) {
                $t->update($total ? $i / $total : 1, $rel);
            }
            $size = (int) @filesize($abs);
            if ($size === 0 || $size > 3 * 1024 * 1024) {
                continue;
            }
            $src = (string) @file_get_contents($abs);
            $found = [];
            $level = 'media';
            foreach (self::SIGNATURES as [$name, $regex, $sev]) {
                if (@preg_match($regex, $src)) {
                    $found[] = $name;
                    if ($sev === 'alta') {
                        $level = 'alta';
                    }
                }
            }
            if (Str::starts($rel, 'wp-content/uploads/') && Fs::isPhp($rel)) {
                array_unshift($found, 'PHP dentro de uploads');
                $level = 'alta';
            }
            if ($found) {
                $hits[] = ['file' => $rel, 'level' => $level, 'why' => $found];
            }
        }
        usort($hits, function ($a, $b) { return strcmp($a['level'], $b['level']) ?: strcmp($a['file'], $b['file']); });
        return ['files' => $total, 'hits' => $hits];
    }

    /* ── Operaciones ──────────────────────────────────────────────────────── */

    public function backup(array $sums, Task $t): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Falta la extensión zip de PHP (usa --no-backup para omitir)');
        }
        $parent = dirname($this->path);
        $dir = is_writable($parent) ? $parent : $this->path;
        $file = sprintf('%s/wpcore-backup-%s-%s.zip', $dir, date('Ymd-His'), bin2hex(random_bytes(3)));

        $entries = [];
        foreach (['wp-admin', 'wp-includes'] as $d) {
            foreach (Fs::listFiles("{$this->path}/$d") as $rel) {
                $entries[] = "$d/$rel";
            }
        }
        foreach (scandir($this->path) ?: [] as $e) {
            $abs = "{$this->path}/$e";
            if (is_file($abs) && !preg_match('/^wpcore-backup-/', $e) && filesize($abs) < 25 * 1024 * 1024) {
                $entries[] = $e;
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("No se pudo crear $file");
        }
        $total = count($entries);
        foreach ($entries as $i => $rel) {
            // Un antivirus o permisos rotos pueden bloquear archivos: se omiten en vez de abortar.
            $fh = @fopen("{$this->path}/$rel", 'rb');
            if ($fh) {
                fclose($fh);
                $zip->addFile("{$this->path}/$rel", $rel);
            }
            if ($i % 25 === 0) {
                $t->update(0.4 * $i / max(1, $total), $rel);
            }
        }
        if (method_exists($zip, 'registerProgressCallback')) {
            $zip->registerProgressCallback(0.01, function ($r) use ($t) {
                $t->update(0.4 + 0.6 * $r, 'comprimiendo');
            });
        }
        if (!@$zip->close()) {
            @unlink($file);
            throw new RuntimeException('Error al comprimir el respaldo: ' . $zip->getStatusString());
        }
        return $file;
    }

    public function extract(string $zipFile, string $to, Task $t): string
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('El archivo descargado no es un ZIP válido');
            }
            $n = $zip->numFiles;
            for ($i = 0; $i < $n; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (strpos($name, '..') !== false || $name[0] === '/') {
                    continue;
                }
                $zip->extractTo($to, $name);
                if ($i % 20 === 0) {
                    $t->update($i / $n, $name);
                }
            }
            $zip->close();
        } elseif (Term::canShell() && trim((string) shell_exec('command -v unzip 2>/dev/null')) !== '') {
            shell_exec('unzip -q -o ' . escapeshellarg($zipFile) . ' -d ' . escapeshellarg($to) . ' 2>&1');
        } else {
            throw new RuntimeException('Se necesita la extensión zip de PHP o el comando unzip');
        }
        if (!is_dir("$to/wordpress/wp-includes")) {
            throw new RuntimeException('El paquete no contiene una instalación de WordPress válida');
        }
        return "$to/wordpress";
    }

    /** Comprueba que el paquete extraído coincide con los checksums oficiales. */
    public static function verifyPackage(string $src, array $sums, Task $t): void
    {
        $bad = [];
        $total = count($sums);
        $i = 0;
        foreach ($sums as $file => $md5) {
            if (!is_file("$src/$file") || md5_file("$src/$file") !== $md5) {
                $bad[] = $file;
            }
            if (++$i % 25 === 0) {
                $t->update($i / $total, $file);
            }
        }
        if ($bad) {
            throw new RuntimeException('El paquete descargado no coincide con los checksums (' . count($bad) . ' archivos). Abortado sin tocar el sitio.');
        }
    }

    /** Elimina wp-admin, wp-includes, archivos raíz del núcleo y los extras indicados. */
    public function removeCore(array $sums, array $extra, Task $t): array
    {
        $errors = [];
        $total = max(1, count($sums) + count($extra));
        $done = 0;
        $tick = function ($p) use ($t, &$done, $total) {
            $done++;
            if ($done % 20 === 0) {
                $t->update(min(1, $done / $total), Fs::norm($p));
            }
        };
        foreach (['wp-admin', 'wp-includes'] as $d) {
            Fs::rrmdir("{$this->path}/$d", $tick, $errors);
        }
        foreach (array_merge(self::rootCore($sums), $extra) as $f) {
            if ($f === 'wp-config.php' || strpos($f, '/') !== false) {
                continue;
            }
            if (is_file("{$this->path}/$f")) {
                Fs::unlink("{$this->path}/$f", $errors);
                $tick($f);
            }
        }
        return $errors;
    }

    /** Mueve el núcleo limpio a su lugar (wp-content del paquete se ignora). */
    public function place(string $src): void
    {
        foreach (scandir($src) as $e) {
            if ($e === '.' || $e === '..' || $e === 'wp-content') {
                continue;
            }
            $dest = "{$this->path}/$e";
            if (file_exists($dest) || !@rename("$src/$e", $dest)) {
                Fs::copyTree("$src/$e", $dest);
            }
        }
        if (!is_file("{$this->path}/wp-content/index.php") && is_file("$src/wp-content/index.php")) {
            @copy("$src/wp-content/index.php", "{$this->path}/wp-content/index.php");
        }
    }

    public function corePaths(array $sums): array
    {
        $paths = ["{$this->path}/wp-admin", "{$this->path}/wp-includes"];
        foreach (self::rootCore($sums) as $f) {
            if (file_exists("{$this->path}/$f")) {
                $paths[] = "{$this->path}/$f";
            }
        }
        return $paths;
    }

    /** Si se ejecuta como root, devuelve el núcleo al dueño de wp-content. */
    public function fixOwner(array $sums): ?string
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return null;
        }
        $uid = @fileowner("{$this->path}/wp-content");
        $gid = @filegroup("{$this->path}/wp-content");
        if (!$uid) {
            return null;
        }
        foreach ($this->corePaths($sums) as $p) {
            Fs::walk($p, function ($f) use ($uid, $gid) {
                @chown($f, $uid);
                @chgrp($f, $gid);
            });
        }
        $info = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : null;
        return $info['name'] ?? "uid $uid";
    }

    public function fixPermissions(array $sums): int
    {
        $n = 0;
        $paths = array_merge($this->corePaths($sums), ["{$this->path}/wp-content"]);
        foreach ($paths as $p) {
            Fs::walk($p, function ($f) use (&$n) {
                if (!is_link($f)) {
                    @chmod($f, is_dir($f) ? 0755 : 0644);
                    $n++;
                }
            });
        }
        return $n;
    }

    public function regenerateSalts(): int
    {
        $file = $this->configFile();
        if (!$file || !is_writable($file)) {
            throw new RuntimeException('wp-config.php no existe o no tiene permisos de escritura');
        }
        $remote = Http::get('https://api.wordpress.org/secret-key/1.1/salt/');
        preg_match_all("/define\(\s*'([A-Z_]+)',\s*'(.*)'\s*\);/", $remote, $m, PREG_SET_ORDER);
        if (count($m) < 8) {
            throw new RuntimeException('La API de claves devolvió una respuesta inesperada');
        }

        $src = (string) file_get_contents($file);
        $missing = '';
        foreach ($m as [$line, $name]) {
            $regex = '/define\s*\(\s*([\'"])' . $name . '\1\s*,\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\)\s*;/';
            $count = 0;
            $src = preg_replace_callback($regex, function () use ($line) { return $line; }, $src, 1, $count);
            if (!$count) {
                $missing .= $line . "\n";
            }
        }
        if ($missing !== '') {
            $anchor = strpos($src, '/* That\'s all') ?: strpos($src, '$table_prefix');
            $src = $anchor !== false
                ? substr($src, 0, $anchor) . $missing . "\n" . substr($src, $anchor)
                : preg_replace('/<\?php\s*/', "<?php\n" . $missing, $src, 1);
        }

        $tmp = $file . '.wpcore-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $src) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo guardar wp-config.php');
        }
        return count($m);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  Aplicación: argumentos, asistente y comandos
 * ═══════════════════════════════════════════════════════════════════════════ */

final class App
{
    /** Durante el reemplazo del núcleo Ctrl+C se pospone para no dejar el sitio a medias. */
    public static $critical = false;
    public static $cancelled = false;

    private $opts = [];
    /** @var WordPress */
    private $wp;

    public static function abort(): void
    {
        if (self::$critical) {
            self::$cancelled = true;
            return;
        }
        Term::rawOff();
        Term::showCursor();
        Term::line();
        Term::line('  ' . C::muted('✕ Operación cancelada.'));
        Term::line();
        exit(130);
    }

    public function run(array $argv): int
    {
        $cmd = null;
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '-y') {
                $this->opts['yes'] = true;
            } elseif ($arg === '-h') {
                $cmd = 'help';
            } elseif (Str::starts($arg, '--')) {
                $kv = explode('=', substr($arg, 2), 2);
                $this->opts[$kv[0]] = $kv[1] ?? true;
            } elseif ($cmd === null) {
                $cmd = strtolower($arg);
            }
        }
        if (isset($this->opts['help'])) {
            $cmd = 'help';
        }

        Term::init(isset($this->opts['no-color']));
        UI::$auto = isset($this->opts['yes']);
        $this->signals();

        if ($cmd === 'help') {
            $this->help();
            return 0;
        }
        if (!in_array($cmd, [null, 'reinstall', 'verify', 'scan', 'salts'], true)) {
            UI::error("Comando desconocido: $cmd");
            UI::note('Ejecuta `php ' . basename(__FILE__) . ' help` para ver los comandos disponibles.');
            return 1;
        }

        UI::banner();
        try {
            $this->locate();
            $this->summary();
            if ($cmd !== null) {
                $this->dispatch($cmd);
            } else {
                $this->menu();
            }
        } catch (Throwable $e) {
            Term::line();
            UI::panel('Error', [C::err($e->getMessage())], 'err');
            Term::line();
            return 1;
        }
        return 0;
    }

    private function signals(): void
    {
        register_shutdown_function(function () {
            Term::rawOff();
            Term::showCursor();
        });
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $handler = function () {
                if (App::$critical) {
                    App::$cancelled = true;
                    Term::write("\n  " . C::warn('▲ Terminando el paso crítico antes de salir…') . "\n");
                    return;
                }
                App::abort();
            };
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGTERM, $handler);
        }
    }

    private function locate(): void
    {
        $start = is_string($this->opts['path'] ?? null) ? $this->opts['path'] : getcwd();
        $root = WordPress::detect($start);
        while ($root === null) {
            Term::line();
            UI::warning('No se encontró WordPress en ' . C::white(Fs::norm((string) $start)));
            if (UI::$auto || !Term::$input) {
                throw new RuntimeException('Indica la ruta con --path=/ruta/a/wordpress');
            }
            $start = UI::ask('Ruta de la instalación de WordPress', '', function ($p) {
                return is_dir($p) ? null : 'La carpeta no existe.';
            });
            $root = WordPress::detect($start);
        }
        $this->wp = new WordPress($root);
    }

    private function summary(): void
    {
        $wp = $this->wp;
        $version = $wp->version();
        $cfg = $wp->config();
        $ext = [];
        foreach (['curl' => 'curl', 'zip' => 'zip', 'mbstring' => 'mbstring'] as $label => $name) {
            $ext[] = (extension_loaded($name) ? '✔ ' : '✖ ') . $label;
        }
        $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user())
            : get_current_user();

        Term::line();
        UI::panel('Sitio detectado', [
            ['Ruta', Str::fitLeft($wp->path, 52), 'white'],
            ['WordPress', $version ? "$version  ·  {$wp->locale()}" : 'no detectado (núcleo dañado o eliminado)', $version ? 'accent' : 'warn'],
            ['Base de datos', isset($cfg['DB_NAME']) ? $cfg['DB_NAME'] . ' @ ' . ($cfg['DB_HOST'] ?? 'localhost') : 'wp-config.php no encontrado', isset($cfg['DB_NAME']) ? null : 'warn'],
            ['Prefijo', $cfg['table_prefix'] ?? '—', null],
            ['PHP', PHP_VERSION . '  ·  ' . implode('  ', $ext), null],
            ['Usuario', $user . ($user === 'root' ? '  (los archivos se devolverán al dueño del sitio)' : ''), $user === 'root' ? 'warn' : null],
        ]);
    }

    private function menu(): void
    {
        while (true) {
            UI::title('¿Qué quieres hacer?');
            $choice = UI::select('Elige una acción', [
                'reinstall' => ['label' => 'Reinstalar núcleo', 'hint' => 'Reemplaza wp-admin, wp-includes y archivos raíz por una copia limpia'],
                'verify'    => ['label' => 'Verificar integridad', 'hint' => 'Compara cada archivo con los checksums oficiales'],
                'scan'      => ['label' => 'Buscar código malicioso', 'hint' => 'Escaneo heurístico de wp-content, uploads y raíz'],
                'salts'     => ['label' => 'Regenerar claves SALT', 'hint' => 'Invalida todas las sesiones abiertas'],
                'exit'      => ['label' => 'Salir', 'hint' => ''],
            ], 'reinstall');
            if ($choice === 'exit') {
                Term::line();
                return;
            }
            $this->dispatch($choice);
            if (UI::$auto || !UI::confirm('¿Volver al menú principal?', false)) {
                Term::line();
                return;
            }
        }
    }

    private function dispatch(string $cmd): void
    {
        switch ($cmd) {
            case 'reinstall': $this->reinstall(); break;
            case 'verify':    $this->verify(); break;
            case 'scan':      $this->scan(); break;
            case 'salts':     $this->salts(); break;
        }
    }

    /* ── Reinstalar ───────────────────────────────────────────────────────── */

    private function reinstall(): void
    {
        $wp = $this->wp;
        $current = $wp->version();
        $began = microtime(true);
        $windows = DIRECTORY_SEPARATOR === '\\';

        // 1 · Versión
        UI::step(1, 4, 'Versión de WordPress');
        $latest = null;
        $t = new Task('Consultando versiones en wordpress.org');
        try {
            $latest = WordPress::latest();
            $t->done("última estable: $latest");
        } catch (Throwable $e) {
            $t->warn('sin respuesta, podrás escribir la versión');
        }

        $flag = $this->opts['version'] ?? null;
        if (is_string($flag)) {
            $target = $flag === 'latest' ? $latest : ($flag === 'current' ? $current : $flag);
            if (!$target) {
                throw new RuntimeException("No se pudo resolver --version=$flag");
            }
            UI::success("Versión indicada: " . C::accent($target));
        } else {
            $choices = [];
            if ($current) {
                $choices['current'] = ['label' => "Misma versión ($current)", 'short' => $current, 'hint' => 'Recomendado: limpia sin cambiar de versión'];
            }
            if ($latest && $latest !== $current) {
                $choices['latest'] = ['label' => "Última versión ($latest)", 'short' => $latest, 'hint' => 'Limpia y actualiza en un solo paso'];
            }
            $choices['custom'] = ['label' => 'Otra versión…', 'short' => 'otra', 'hint' => 'Escribe el número, ej. 6.4.3'];
            $pick = UI::select('¿Qué versión quieres instalar?', $choices, $current ? 'current' : 'latest');
            $target = $pick === 'current' ? $current : ($pick === 'latest' ? $latest : null);
        }

        $sums = null;
        while ($sums === null) {
            if ($target === null) {
                $target = UI::ask('Versión de WordPress', $current ?? (string) $latest, function ($v) {
                    return preg_match('/^\d+\.\d+(\.\d+)?$/', $v) ? null : 'Formato no válido (ej. 6.4.3).';
                });
            }
            try {
                $sums = Task::run("Obteniendo checksums oficiales de $target", function () use ($target) {
                    return WordPress::checksums($target);
                });
            } catch (RuntimeException $e) {
                if (UI::$auto || is_string($flag)) {
                    throw $e;
                }
                $target = null;
            }
        }

        // 2 · Análisis y opciones
        UI::step(2, 4, 'Análisis y opciones');
        $defs = [
            'scan'    => ['label' => 'Analizar el núcleo actual', 'short' => 'análisis', 'hint' => 'detecta archivos modificados o inyectados', 'checked' => true],
            'backup'  => ['label' => 'Respaldo .zip del núcleo', 'short' => 'respaldo', 'hint' => 'se guarda fuera de la carpeta pública si es posible', 'checked' => !isset($this->opts['no-backup'])],
            'unknown' => ['label' => 'Limpiar archivos extraños', 'short' => 'limpieza raíz', 'hint' => 'archivos PHP en la raíz que no son del núcleo', 'checked' => !isset($this->opts['keep-unknown'])],
            'salts'   => ['label' => 'Regenerar claves SALT', 'short' => 'SALT', 'hint' => 'cierra todas las sesiones activas', 'checked' => !isset($this->opts['no-salts'])],
            'perms'   => ['label' => 'Corregir permisos', 'short' => 'permisos', 'hint' => $windows ? 'no aplica en Windows' : 'carpetas 755 · archivos 644', 'checked' => isset($this->opts['fix-perms']) && !$windows],
            'verify'  => ['label' => 'Verificación final', 'short' => 'verificación', 'hint' => 'confirma con checksums que todo quedó limpio', 'checked' => true],
        ];
        $opts = array_flip(UI::multiselect('¿Qué incluir en el proceso?', $defs));

        if (isset($opts['scan']) && $current) {
            Term::line();
            try {
                $currentSums = $current === $target ? $sums : WordPress::checksumsFor($current, $wp->locale());
                $r = Task::run('Analizando el núcleo actual', function (Task $t) use ($wp, $currentSums) {
                    return $wp->integrity($currentSums, $t);
                });
                $issues = count($r['modified']) + count($r['missing']) + count($r['unknown']);
                if ($issues === 0) {
                    UI::success('Núcleo sin alteraciones detectadas ' . C::muted('(' . Str::num($r['ok']) . ' archivos íntegros)'));
                } else {
                    Report::issueLine('Modificados', $r['modified'], 'err');
                    Report::issueLine('Inyectados en wp-admin / wp-includes', $r['unknown'], 'err');
                    Report::issueLine('Faltantes', $r['missing'], 'warn');
                }
            } catch (Throwable $e) {
                UI::warning('No se pudo analizar: ' . $e->getMessage());
            }
            $uploads = $wp->uploadsPhp();
            if ($uploads) {
                Report::issueLine('PHP en wp-content/uploads (revísalos a mano)', $uploads, 'warn', 5);
            }
        }

        $extra = [];
        if (isset($opts['unknown'])) {
            $found = $wp->rootUnknown($sums);
            if (!$found) {
                Term::line();
                UI::success('La raíz no contiene archivos extraños');
            } else {
                Term::line();
                $choices = [];
                foreach ($found as $f) {
                    $choices[$f['name']] = [
                        'label'   => $f['name'],
                        'hint'    => Str::bytes($f['size']) . ($f['php'] ? '  · PHP fuera del núcleo' : ''),
                        'checked' => $f['php'],
                    ];
                }
                $extra = UI::multiselect('Archivos extraños en la raíz · ¿cuáles eliminar?', $choices);
            }
        }

        // 3 · Confirmación
        UI::step(3, 4, 'Confirmación');
        $keep = array_values(array_filter(['wp-config.php', 'wp-content/', '.htaccess'], function ($f) use ($wp) {
            return file_exists("{$wp->path}/" . rtrim($f, '/'));
        }));
        UI::panel('Resumen', [
            ['Sitio', Str::fitLeft($wp->path, 50), 'white'],
            ['Versión', ($current ?: '¿?') . '  →  ' . $target, 'accent'],
            ['Se reemplaza', 'wp-admin/, wp-includes/ y ' . count(WordPress::rootCore($sums)) . ' archivos raíz', null],
            ['Se elimina', $extra ? Str::plural(count($extra), 'archivo extraño', 'archivos extraños') : 'ningún archivo extra', $extra ? 'warn' : 'muted'],
            ['Se conserva', implode(', ', $keep), 'ok'],
            ['Respaldo', isset($opts['backup']) ? 'sí, en .zip' : 'no', isset($opts['backup']) ? null : 'warn'],
            ['Extras', implode(', ', array_filter([isset($opts['salts']) ? 'SALT' : '', isset($opts['perms']) ? 'permisos' : '', isset($opts['verify']) ? 'verificación' : ''])) ?: '—', null],
        ], 'warn');
        Term::line();
        if (!UI::confirm('¿Iniciar la reinstalación?', true)) {
            UI::note('No se realizó ningún cambio.');
            return;
        }

        // 4 · Ejecución
        UI::step(4, 4, 'Reinstalación');
        if (!is_writable($wp->path)) {
            throw new RuntimeException("Sin permisos de escritura en {$wp->path}");
        }
        $free = @disk_free_space($wp->path);
        if ($free !== false && $free < 80 * 1024 * 1024) {
            throw new RuntimeException('Espacio en disco insuficiente (' . Str::bytes($free) . ' libres)');
        }

        $backup = null;
        if (isset($opts['backup'])) {
            $t = new Task('Creando respaldo del núcleo actual');
            try {
                $backup = $wp->backup($sums, $t);
                $t->done(Str::bytes((float) filesize($backup)));
            } catch (Throwable $e) {
                $t->fail($e->getMessage());
                if (UI::$auto) {
                    throw new RuntimeException('Reinstalación cancelada: no se pudo crear el respaldo. Usa --no-backup para omitirlo.');
                }
                if (!UI::confirm('¿Continuar sin respaldo?', false)) {
                    UI::note('No se realizó ningún cambio.');
                    return;
                }
            }
        }

        $tmp = "{$wp->path}/.wpcore-" . bin2hex(random_bytes(4));
        $zip = "$tmp.zip";
        try {
            @mkdir($tmp, 0755);
            $url = "https://downloads.wordpress.org/release/wordpress-$target-no-content.zip";
            $t = new Task("Descargando WordPress $target");
            $last = [microtime(true), 0, 0.0];
            Http::download($url, $zip, function ($now, $total) use ($t, &$last) {
                $elapsed = microtime(true) - $last[0];
                $speed = $elapsed > 0.3 ? $now / $elapsed : 0;
                $t->update($total > 0 ? $now / $total : null, Str::bytes($now) . ($total ? ' / ' . Str::bytes($total) : '') . ($speed ? '  ' . Str::bytes($speed) . '/s' : ''));
            });
            $t->done(Str::bytes((float) filesize($zip)));

            $t = new Task('Extrayendo paquete');
            $src = $wp->extract($zip, $tmp, $t);
            $t->done();

            Task::run('Verificando paquete con checksums oficiales', function (Task $t) use ($src, $sums) {
                WordPress::verifyPackage($src, $sums, $t);
                $t->done(Str::num(count($sums)) . ' archivos auténticos');
            });

            self::$critical = true;
            $t = new Task('Eliminando núcleo anterior');
            $errors = $wp->removeCore($sums, $extra, $t);
            if ($errors) {
                $t->warn(count($errors) . ' elementos no se pudieron borrar (se sobrescribirán)');
            } else {
                $t->done($extra ? 'incluye ' . Str::plural(count($extra), 'archivo extraño', 'archivos extraños') : '');
            }

            Task::run('Instalando núcleo limpio', function (Task $t) use ($wp, $src, $sums) {
                $wp->place($src);
                $t->done(Str::num(count($sums)) . ' archivos');
            });
            self::$critical = false;
        } finally {
            self::$critical = false;
            $ignored = [];
            Fs::rrmdir($tmp, null, $ignored);
            @unlink($zip);
        }
        if (self::$cancelled) {
            App::abort();
        }

        $owner = $wp->fixOwner($sums);
        if ($owner) {
            UI::success("Propietario devuelto a " . C::accent($owner));
        }
        if (isset($opts['perms'])) {
            Task::run('Corrigiendo permisos', function (Task $t) use ($wp, $sums) {
                $t->done(Str::num($wp->fixPermissions($sums)) . ' elementos');
            });
        }
        if (isset($opts['salts'])) {
            try {
                Task::run('Regenerando claves SALT', function (Task $t) use ($wp) {
                    $t->done($wp->regenerateSalts() . ' claves nuevas');
                });
            } catch (Throwable $e) {
                // ya se mostró en la tarea; no es fatal
            }
        }
        $clean = null;
        if (isset($opts['verify'])) {
            $t = new Task('Verificación final');
            $r = $wp->integrity($sums, $t);
            $clean = !$r['modified'] && !$r['missing'] && !$r['unknown'];
            $clean ? $t->done('núcleo 100% íntegro') : $t->warn(count($r['modified']) + count($r['missing']) + count($r['unknown']) . ' diferencias, ejecuta "verify"');
        }

        Term::line();
        UI::panel('✔ Reinstalación completada', [
            ['Versión', $target . ($current && $current !== $target ? "  (antes $current)" : ''), 'accent'],
            ['Núcleo', $clean === null ? 'sin verificar' : ($clean ? 'íntegro según checksums oficiales' : 'con diferencias'), $clean === false ? 'warn' : 'ok'],
            ['Eliminados', Str::plural(count($extra), 'archivo extraño', 'archivos extraños'), null],
            ['Respaldo', $backup ? Str::fitLeft(Fs::norm($backup), 50) : 'no', $backup ? null : 'muted'],
            ['Tiempo', sprintf('%.1f s', microtime(true) - $began), null],
        ], 'ok');

        Term::line();
        Term::line('  ' . C::white('Próximos pasos recomendados'));
        $steps = [
            'Cambia las contraseñas de los administradores, FTP/SFTP y base de datos.',
            'Busca código malicioso en plugins y temas:  ' . C::accent('php ' . basename(__FILE__) . ' scan'),
            'Revisa .htaccess y los usuarios administradores en wp-admin › Usuarios.',
            'Elimina plugins o temas abandonados o "nulled" y actualiza el resto.',
        ];
        foreach ($steps as $i => $s) {
            Term::line('   ' . C::accent((string) ($i + 1) . '.') . ' ' . $s);
        }
        if ($backup) {
            Term::line();
            UI::note('Borra el respaldo cuando ya no lo necesites: contiene el núcleo infectado.');
        }
    }

    /* ── Verificar ────────────────────────────────────────────────────────── */

    private function verify(): void
    {
        $wp = $this->wp;
        UI::title('Verificación de integridad');
        $version = $wp->version();
        if (!$version) {
            throw new RuntimeException('No existe wp-includes/version.php: el núcleo está eliminado o dañado. Usa "reinstall".');
        }
        $sums = Task::run("Obteniendo checksums de WordPress $version", function () use ($wp, $version) {
            return WordPress::checksumsFor($version, $wp->locale());
        });
        $r = Task::run('Comparando archivos del núcleo', function (Task $t) use ($wp, $sums) {
            return $wp->integrity($sums, $t);
        });
        $r['uploads'] = Task::run('Buscando PHP en wp-content/uploads', function () use ($wp) {
            return $wp->uploadsPhp();
        });
        $r['root'] = Task::run('Revisando la raíz del sitio', function () use ($wp, $sums) {
            return array_map(function ($f) { return $f['name']; }, $wp->rootUnknown($sums));
        });

        Term::line();
        $clean = Report::integrity($r, $version);
        $this->saveReport(Report::integrityText($r, $version, $wp->path), $clean);
        if (!$clean) {
            Term::line();
            UI::info('Para limpiar el núcleo ejecuta: ' . C::accent('php ' . basename(__FILE__) . ' reinstall'));
        }
    }

    /* ── Escanear ─────────────────────────────────────────────────────────── */

    private function scan(): void
    {
        $wp = $this->wp;
        UI::title('Búsqueda de código malicioso');
        $r = Task::run('Analizando wp-content y raíz', function (Task $t) use ($wp) {
            $r = $wp->scan($t);
            $t->done(Str::plural($r['files'], 'archivo', 'archivos') . ' analizados');
            return $r;
        });
        Term::line();
        $high = count(array_filter($r['hits'], function ($h) { return $h['level'] === 'alta'; }));
        UI::panel('Resultado del escaneo', [
            ['Analizados', Str::num($r['files']) . ' archivos', null],
            ['Riesgo alto', (string) $high, $high ? 'err' : 'ok'],
            ['Riesgo medio', (string) (count($r['hits']) - $high), count($r['hits']) - $high ? 'warn' : 'ok'],
        ], $high ? 'err' : (count($r['hits']) ? 'warn' : 'ok'));

        if ($r['hits']) {
            Term::line();
            $w = Term::width() - 12;
            foreach (array_slice($r['hits'], 0, 25) as $h) {
                $icon = $h['level'] === 'alta' ? C::err('✖') : C::warn('▲');
                Term::line("  $icon " . Str::fitLeft($h['file'], $w));
                Term::line('    ' . C::muted(Str::fit(implode(' · ', $h['why']), $w)));
            }
            if (count($r['hits']) > 25) {
                UI::note('… y ' . (count($r['hits']) - 25) . ' más (guarda el reporte para verlos todos)');
            }
            Term::line();
            UI::note('Es un análisis heurístico: revisa cada archivo antes de borrarlo.');
        }

        $lines = ["WPCore · Escaneo heurístico", 'Sitio: ' . $wp->path, 'Fecha: ' . date('Y-m-d H:i:s'), ''];
        foreach ($r['hits'] as $h) {
            $lines[] = sprintf('[%s] %s — %s', strtoupper($h['level']), $h['file'], implode(', ', $h['why']));
        }
        $this->saveReport(implode("\n", $lines) . "\n", !$r['hits']);
    }

    /* ── SALT ─────────────────────────────────────────────────────────────── */

    private function salts(): void
    {
        UI::title('Regenerar claves SALT');
        UI::note('Todas las sesiones abiertas se cerrarán y los usuarios deberán iniciar sesión de nuevo.');
        Term::line();
        if (!UI::confirm('¿Regenerar las claves de seguridad?', true)) {
            return;
        }
        $wp = $this->wp;
        Task::run('Regenerando claves SALT', function (Task $t) use ($wp) {
            $t->done($wp->regenerateSalts() . ' claves nuevas en wp-config.php');
        });
    }

    private function saveReport(string $text, bool $clean): void
    {
        $file = $this->opts['report'] ?? null;
        if (!is_string($file)) {
            if ($clean || UI::$auto) {
                return;
            }
            Term::line();
            if (!UI::confirm('¿Guardar el reporte completo en un archivo?', false)) {
                return;
            }
            $parent = dirname($this->wp->path);
            $file = (is_writable($parent) ? $parent : $this->wp->path) . '/wpcore-report-' . date('Ymd-His') . '.txt';
        }
        if (@file_put_contents($file, $text) === false) {
            UI::error("No se pudo escribir $file");
            return;
        }
        UI::success('Reporte guardado en ' . C::accent(Fs::norm($file)));
    }

    private function help(): void
    {
        $me = 'php ' . basename(__FILE__);
        UI::banner();
        $section = function (string $t) { Term::line(); Term::line('  ' . C::white($t)); };
        $row = function (string $a, string $b) { Term::line('    ' . C::accent(Str::pad($a, 22)) . ' ' . $b); };

        $section('USO');
        Term::line("    $me " . C::muted('[comando] [opciones]'));
        $section('COMANDOS');
        $row('(ninguno)', 'Abre el asistente interactivo');
        $row('reinstall', 'Reinstala el núcleo de WordPress');
        $row('verify', 'Verifica la integridad del núcleo con checksums oficiales');
        $row('scan', 'Busca código malicioso en wp-content y la raíz');
        $row('salts', 'Regenera las claves SALT de wp-config.php');
        $row('help', 'Muestra esta ayuda');
        $section('OPCIONES');
        $row('--path=<dir>', 'Ruta de WordPress (por defecto: directorio actual)');
        $row('--version=<v>', 'Versión a instalar: current, latest o x.y.z');
        $row('-y, --yes', 'Modo desatendido: acepta los valores por defecto');
        $row('--no-backup', 'No crear respaldo .zip del núcleo actual');
        $row('--no-salts', 'No regenerar las claves SALT');
        $row('--keep-unknown', 'No eliminar archivos extraños de la raíz');
        $row('--fix-perms', 'Corregir permisos (carpetas 755 / archivos 644)');
        $row('--report=<archivo>', 'Guardar el reporte de verify/scan');
        $row('--no-color', 'Desactivar colores');
        $section('EJEMPLOS');
        Term::line("    $me");
        Term::line("    $me reinstall --version=current -y");
        Term::line("    $me verify --path=/home/cliente/public_html");
        Term::line();
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  Reportes
 * ═══════════════════════════════════════════════════════════════════════════ */

final class Report
{
    public static function issueLine(string $label, array $items, string $tone, int $max = 8): void
    {
        if (!$items) {
            return;
        }
        $icon = $tone === 'err' ? C::err('✖') : C::warn('▲');
        Term::line("  $icon $label " . C::tone('(' . count($items) . ')', $tone));
        UI::items($items, $max);
    }

    public static function integrity(array $r, string $version): bool
    {
        $rootPhp = array_filter($r['root'], [Fs::class, 'isPhp']);
        $clean = !$r['modified'] && !$r['missing'] && !$r['unknown'] && !$r['uploads'] && !$rootPhp;
        $n = function (array $a, string $bad) { return [(string) count($a), count($a) ? $bad : 'ok']; };

        $rows = [['Íntegros', Str::num($r['ok']), 'ok']];
        foreach ([
            'Modificados'        => [$r['modified'], 'err'],
            'Inyectados'         => [$r['unknown'], 'err'],
            'Faltantes'          => [$r['missing'], 'warn'],
            'PHP en uploads'     => [$r['uploads'], 'err'],
            'Extraños en raíz'   => [$r['root'], 'warn'],
        ] as $label => [$list, $bad]) {
            [$count, $tone] = $n($list, $bad);
            $rows[] = [$label, $count, $tone];
        }
        UI::panel("Integridad · WordPress $version", $rows, $clean ? 'ok' : 'err');

        if ($clean) {
            Term::line();
            UI::success('El núcleo coincide con la copia oficial de wordpress.org');
            return true;
        }
        Term::line();
        self::issueLine('Archivos del núcleo modificados', $r['modified'], 'err');
        self::issueLine('Archivos inyectados en wp-admin / wp-includes', $r['unknown'], 'err');
        self::issueLine('PHP dentro de wp-content/uploads', $r['uploads'], 'err');
        self::issueLine('Archivos faltantes', $r['missing'], 'warn');
        self::issueLine('Archivos extraños en la raíz', $r['root'], 'warn');
        return false;
    }

    public static function integrityText(array $r, string $version, string $path): string
    {
        $out = ['WPCore · Verificación de integridad', "Sitio: $path", "WordPress: $version", 'Fecha: ' . date('Y-m-d H:i:s'), ''];
        foreach ([
            'MODIFICADOS' => $r['modified'],
            'INYECTADOS EN WP-ADMIN / WP-INCLUDES' => $r['unknown'],
            'PHP EN UPLOADS' => $r['uploads'],
            'FALTANTES' => $r['missing'],
            'EXTRAÑOS EN LA RAÍZ' => $r['root'],
        ] as $title => $list) {
            $out[] = "## $title (" . count($list) . ')';
            foreach ($list as $f) {
                $out[] = "  $f";
            }
            $out[] = '';
        }
        return implode("\n", $out);
    }
}

exit((new App())->run($argv));
