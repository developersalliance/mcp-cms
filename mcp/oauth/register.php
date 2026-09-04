<?php
/**
 * RFC 7591 dynamic client registration. Public: any MCP client may register
 * (that is how ChatGPT and Claude.ai work); registration grants nothing by
 * itself — a CMS user still has to approve the client on the consent page.
 */
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    oauthError('invalid_request', 'POST a JSON client metadata document', 405);
}

$req = oauthRequestBody();
if ($req === []) {
    oauthError('invalid_client_metadata', 'Body must be a JSON object with redirect_uris');
}

try {
    $client = $oauth->registerClient($req);
} catch (InvalidArgumentException $e) {
    oauthError($e->getMessage(), $e->getMessage() === 'invalid_redirect_uri'
        ? 'redirect_uris must be https URLs or http://localhost / http://127.0.0.1 URLs'
        : 'Unsupported client metadata');
} catch (Throwable $e) {
    oauthError('server_error', 'Could not store the client registration', 500);
}

unset($client['kind']);
oauthJson($client, 201);
