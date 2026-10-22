<?php
/**
 * Minimal Dolibarr REST client (server-side only; never writes to Dolibarr tables directly).
 * UNTESTED against a live instance: endpoint payloads must be verified against the installed Dolibarr version in Phase 2.
 */
function dol_enabled(): bool { return cfg('dolibarr.url') && cfg('dolibarr.api_key'); }

function dol_call(string $method, string $path, ?array $data = null): array
{
    $ch = curl_init(rtrim(cfg('dolibarr.url'), '/') . '/api/index.php' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['DOLAPIKEY: ' . cfg('dolibarr.api_key'), 'Accept: application/json', 'Content-Type: application/json'],
    ]);
    if ($data !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $body, true)];
}

/** Idempotent: creates the Dolibarr customer and draft proposal once, and stores the mapping. */
function dol_sync_request(array $req): string
{
    if (!dol_enabled()) { return 'Dolibarr integration is not configured.'; }
    if (!empty($req['dolibarr_proposal_id'])) { return 'Already synced (proposal #' . $req['dolibarr_proposal_id'] . ').'; }
    $cust = one('SELECT * FROM is_users WHERE id=?', [$req['user_id']]);
    $socid = $cust['dolibarr_id'];
    if (!$socid) {
        [$code, $res] = dol_call('POST', '/thirdparties', ['name' => $cust['name'], 'email' => $cust['email'], 'client' => 1, 'code_client' => -1]);
        if ($code !== 200 || !is_numeric($res)) { return "Dolibarr customer creation failed (HTTP $code)."; }
        $socid = (int) $res;
        q('UPDATE is_users SET dolibarr_id=? WHERE id=?', [$socid, $cust['id']]);
    }
    $qty = max(1, (int) $req['quantity']);
    [$code, $res] = dol_call('POST', '/proposals', [
        'socid' => $socid,
        'date' => time(),
        'ref_client' => $req['ref'],
        'note_private' => 'Portal order ' . $req['ref'],
        'lines' => [[
            'desc' => "3D printing - {$req['material']} / {$req['color']} ({$req['ref']})",
            'qty' => $qty,
            'subprice' => round((float) $req['quote_amount'] / $qty, 2),
            'tva_tx' => (float) cfg('dolibarr.vat_rate', 0),
        ]],
    ]);
    if ($code !== 200 || !is_numeric($res)) { return "Dolibarr proposal creation failed (HTTP $code)."; }
    q('UPDATE is_requests SET dolibarr_proposal_id=?, updated_at=? WHERE id=?', [(int) $res, now(), $req['id']]);
    audit('dolibarr.sync', $req['ref'] . ' -> proposal ' . (int) $res);
    return 'Synced to Dolibarr as proposal #' . (int) $res . '.';
}
