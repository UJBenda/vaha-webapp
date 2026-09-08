<?php
// Klient pro MediaMTX Control API - přidávání/mazání "paths" (kamer) za běhu,
// bez restartu kontejneru a bez úprav mediamtx.yml.
// Dokumentace: POST /v3/config/paths/add/{name}, PATCH /v3/config/paths/patch/{name},
// DELETE /v3/config/paths/delete/{name}, GET /v3/config/paths/list.

function mediamtxRequest(string $method, string $path, ?array $body = null): array {
    global $config;

    $ch = curl_init($config['mediamtx_api'] . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    if (!empty($config['mediamtx_api_user'])) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $config['mediamtx_api_user'] . ':' . $config['mediamtx_api_pass']);
    }

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['code' => $httpCode, 'body' => $response, 'error' => $error];
}

// Vytvoří path, pokud neexistuje, jinak ho upraví (upsert).
function mediamtxUpsertPath(string $name, string $source): array {
    $add = mediamtxRequest('POST', "/v3/config/paths/add/$name", ['source' => $source]);
    if ($add['code'] >= 200 && $add['code'] < 300) {
        return $add;
    }
    // Path už existuje (nebo jiná chyba) -> zkusíme patch.
    return mediamtxRequest('PATCH', "/v3/config/paths/patch/$name", ['source' => $source]);
}

function mediamtxDeletePath(string $name): array {
    return mediamtxRequest('DELETE', "/v3/config/paths/delete/$name");
}

// Vrací seznam aktuálně nakonfigurovaných paths (jména).
function mediamtxListPathNames(): array {
    $res = mediamtxRequest('GET', '/v3/config/paths/list');
    if ($res['code'] !== 200 || $res['body'] === false) {
        return [];
    }
    $data = json_decode($res['body'], true);
    $items = $data['items'] ?? [];
    return array_map(fn($item) => $item['name'] ?? '', $items);
}
