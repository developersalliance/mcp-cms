# OAuth discovery for the MCP endpoint

The MCP endpoint (`/cms/mcp/index.php`) is an OAuth 2.1 protected resource.
Clients such as ChatGPT, Claude.ai, Gemini Spark and the MCP SDKs discover
the login flow in two steps:

1. A `401` from the endpoint carries
   `WWW-Authenticate: Bearer resource_metadata="https://<site>/cms/mcp/oauth/protected-resource.php"`
   (RFC 9728). That document names the authorization server (issuer)
   `https://<site>/cms/mcp/oauth`.
2. The client fetches the issuer's metadata (RFC 8414). The engine serves it
   without any web-server configuration at the **path-appended** locations:

   - `https://<site>/cms/mcp/oauth/.well-known/oauth-authorization-server`
   - `https://<site>/cms/mcp/oauth/.well-known/openid-configuration`

Some clients only try the **root-level** forms defined by RFC 8414 / RFC 9728:

- `https://<site>/.well-known/oauth-authorization-server/cms/mcp/oauth`
- `https://<site>/.well-known/oauth-authorization-server`
- `https://<site>/.well-known/oauth-protected-resource/cms/mcp/index.php`
- `https://<site>/.well-known/oauth-protected-resource`

Add the snippet for your web server so those root-level URLs also resolve.

## Apache (`.htaccess` in the site root, or the vhost)

```apache
RewriteEngine On
RewriteRule ^\.well-known/oauth-authorization-server(/.*)?$ /cms/mcp/oauth/.well-known/oauth-authorization-server/index.php [L,QSA]
RewriteRule ^\.well-known/openid-configuration(/.*)?$        /cms/mcp/oauth/.well-known/openid-configuration/index.php   [L,QSA]
RewriteRule ^\.well-known/oauth-protected-resource(/.*)?$    /cms/mcp/oauth/protected-resource.php                       [L,QSA]
```

If the site's `.htaccess` already has `RewriteEngine On`, add only the three
`RewriteRule` lines above the existing rules.

## nginx (inside the `server {}` block)

```nginx
location ~ ^/\.well-known/oauth-authorization-server(/.*)?$ {
    rewrite ^ /cms/mcp/oauth/.well-known/oauth-authorization-server/index.php last;
}
location ~ ^/\.well-known/openid-configuration(/.*)?$ {
    rewrite ^ /cms/mcp/oauth/.well-known/openid-configuration/index.php last;
}
location ~ ^/\.well-known/oauth-protected-resource(/.*)?$ {
    rewrite ^ /cms/mcp/oauth/protected-resource.php last;
}
```

Reload nginx afterwards (`nginx -t && systemctl reload nginx`).

## Checking

```bash
curl -s https://<site>/cms/mcp/oauth/.well-known/oauth-authorization-server | jq .issuer
curl -s https://<site>/.well-known/oauth-authorization-server/cms/mcp/oauth | jq .issuer   # after the rewrite
curl -si -X POST https://<site>/cms/mcp/index.php -d '{}' | grep -i www-authenticate
php tests/oauth-flow.php https://<site> <admin-user> <admin-password>              # full flow
```

## State on disk

Everything lives under `{cms_dir}/oauth/` (git-ignored): `clients/` (dynamic
registrations and cached Client ID Metadata Documents), `codes/` (10-minute
single-use authorization codes) and `tokens/` (access tokens, 1 h by default,
and refresh tokens, 30 days). Only SHA-256 hashes of credentials are stored.
Tune with `mcp_oauth_access_ttl` / `mcp_oauth_refresh_ttl` in `config/config.php`.
Revoke a connected app under **Settings → MCP Config → Connected apps**.
