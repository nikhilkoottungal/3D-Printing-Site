<?php
/** When the encrypted backup runs (times are IST). Settings: backup_freq (off|hours|daily|weekly), backup_every_h, backup_time (HH:MM), backup_weekday (1=Mon..7=Sun). The systemd timer wakes every 15 minutes; backup_due() decides. */
const BACKUP_FREQS = ['daily' => 'Every day', 'weekly' => 'Every week', 'hours' => 'Every few hours', 'off' => 'Never (manual only)'];
const BACKUP_EVERY_HOURS = [1, 2, 3, 4, 6, 8, 12];
const BACKUP_WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

function backup_schedule_get(): array
{
    $f = (string) setting('backup_freq', 'daily');
    $h = (int) setting('backup_every_h', 6);
    $t = (string) setting('backup_time', '22:30');
    $d = (int) setting('backup_weekday', 1);
    return [
        'freq' => isset(BACKUP_FREQS[$f]) ? $f : 'daily',
        'every_h' => in_array($h, BACKUP_EVERY_HOURS, true) ? $h : 6,
        'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '22:30',
        'weekday' => $d >= 1 && $d <= 7 ? $d : 1,
    ];
}

/** Backup times (the schedule and everything shown on the Backups page) are in IST by default, whatever time zone PHP runs in. */
function backup_tz(): DateTimeZone { return new DateTimeZone((string) (function_exists('cfg') ? cfg('timezone', 'Asia/Kolkata') : 'Asia/Kolkata')); }
function backup_fmt_ts(?int $ts): string { return $ts ? (new DateTimeImmutable('@' . $ts))->setTimezone(backup_tz())->format('d M Y, H:i T') : '—'; }
/** Any date string the app stores (PHP default zone, or ISO with an offset) to IST text. */
function backup_fmt(?string $d): string { return $d && ($t = strtotime($d)) ? backup_fmt_ts($t) : '—'; }
/** Run folders are named in UTC (20261022-165500). */
function backup_run_ts(string $id): ?int { $d = DateTimeImmutable::createFromFormat('Ymd-His', $id, new DateTimeZone('UTC')); return $d ? $d->getTimestamp() : null; }
function backup_run_fmt(string $id): string { return backup_fmt_ts(backup_run_ts($id)); }

/** The scheduled moment (unix time) on the IST calendar day $daysBack/$ahead days from $now. */
function backup_slot_on(array $s, int $now, int $offsetDays): array
{
    [$hh, $mm] = array_map('intval', explode(':', $s['time']));
    $d = (new DateTimeImmutable('@' . $now))->setTimezone(backup_tz())->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' day')->setTime($hh, $mm, 0);
    return [$d->getTimestamp(), (int) $d->format('N')];
}

/** Latest scheduled moment (unix time) at or before $now for daily/weekly, null for the other modes. */
function backup_last_slot(array $s, int $now): ?int
{
    if (!in_array($s['freq'], ['daily', 'weekly'], true)) { return null; }
    for ($i = 0; $i < 8; $i++) {
        [$slot, $dow] = backup_slot_on($s, $now, -$i);
        if ($s['freq'] === 'weekly' && $dow !== $s['weekday']) { continue; }
        if ($slot <= $now) { return $slot; }
    }
    return null;
}

/** Is a backup due? $last = unix time the previous backup finished (null = never). Pure: easy to test. */
function backup_due(array $s, ?int $last, int $now): bool
{
    if ($s['freq'] === 'off') { return false; }
    if ($last === null) { return true; }
    if ($s['freq'] === 'hours') { return $now - $last >= $s['every_h'] * 3600 - 60; }
    $slot = backup_last_slot($s, $now);
    return $slot !== null && $last < $slot;
}

/** Next planned run (unix time) for display, or null. */
function backup_next_run(array $s, ?int $last, int $now): ?int
{
    if ($s['freq'] === 'off') { return null; }
    if ($s['freq'] === 'hours') { return $last === null ? $now : max($now, $last + $s['every_h'] * 3600); }
    $slot = backup_last_slot($s, $now);
    if ($slot !== null && ($last === null || $last < $slot)) { return $now; }
    for ($i = 0; $i < 9; $i++) {
        [$c, $dow] = backup_slot_on($s, $now, $i);
        if ($s['freq'] === 'weekly' && $dow !== $s['weekday']) { continue; }
        if ($c > $now) { return $c; }
    }
    return null;
}

function backup_schedule_text(array $s): string
{
    return match ($s['freq']) {
        'off' => 'manual only',
        'hours' => 'every ' . $s['every_h'] . ' hour' . ($s['every_h'] > 1 ? 's' : ''),
        'weekly' => 'every ' . BACKUP_WEEKDAYS[$s['weekday']] . ' at ' . $s['time'] . ' IST',
        default => 'every day at ' . $s['time'] . ' IST',
    };
}

/** Where backups go. Both can be on. Older installs stored one choice in backup_dest ('ftp' used to mean local + FTP). */
function backup_targets(): array
{
    $l = setting('backup_local'); $f = setting('backup_ftp');
    if ($l === null && $f === null) { return ['local' => true, 'ftp' => setting('backup_dest', 'local') === 'ftp']; }
    return ['local' => $l !== '0', 'ftp' => $f === '1'];
}
function backup_targets_text(array $t): string { return $t['local'] && $t['ftp'] ? 'Local + FTP' : ($t['ftp'] ? 'FTP only' : 'Local'); }
/** With FTP only there is no permanent local copy: backups are staged here until the FTP upload succeeds. */
function backup_staging_dir(): string { return cfg('storage') . '/backup-staging'; }
