# Total CMS Docs MCP Server

An [MCP (Model Context Protocol)](https://modelcontextprotocol.io) server that gives AI coding agents real-time access to Total CMS documentation. Hosted at [mcp.totalcms.co](https://mcp.totalcms.co).

Use it with Claude Code, Cursor, Windsurf, or any MCP-compatible AI tool to get accurate, up-to-date answers about Total CMS while you build.

## Setup

Add to your AI tool's MCP config:

```json
{
  "mcpServers": {
    "totalcms-docs": {
      "url": "https://mcp.totalcms.co/"
    }
  }
}
```

No authentication required.

## Available Tools

| Tool | Description |
|------|-------------|
| `docs_search` | Full-text search across all documentation |
| `docs_twig_function` | Twig function signatures and examples |
| `docs_twig_filter` | Twig filter signatures and examples |
| `docs_field_type` | Field type configuration options |
| `docs_api_endpoint` | REST API endpoint details |
| `docs_schema_config` | Schema/collection config options |
| `docs_cli_command` | CLI command reference |
| `docs_extension` | Extension API: context methods, events, permissions, manifest fields |
| `docs_builder` | Site Builder reference: page schema, twig functions, asset pipeline, starters |

## Self-Hosting

### Requirements

- PHP 8.2+
- Apache with mod_rewrite
- Composer

### Development

```bash
composer install
bin/build.sh /path/to/totalcms     # or just `bin/build.sh` to pull from Packagist
php -S localhost:8765 -t public/
```

### Building the Index

`bin/build.sh` reads markdown from a Total CMS source tree and writes `data/index.json`:

```bash
bin/build.sh /path/to/totalcms     # build from a local T3 checkout
bin/build.sh                       # shallow-clone totalcms/cms from GitHub and build from there
```

The no-arg path defaults to `https://github.com/totalcms/cms.git` on the `develop` branch — override with `TOTALCMS_REPO` and `TOTALCMS_BRANCH` env vars if needed (e.g. `TOTALCMS_BRANCH=main bin/build.sh`).

`data/index.json` is a build artifact and is not committed to the repo.

### Deploying

On the server:

```bash
bin/deploy.sh
```

This runs `composer install --no-dev`, rebuilds the index from the latest `totalcms/cms` on Packagist, and ensures `data/sessions` exists.

### Tests

```bash
composer test
```

### Cron

To keep stale session files under control on production, add this crontab entry:

```cron
*/15 * * * * /path/to/mcp.totalcms.co/bin/cleanup-sessions.sh >/dev/null
```

### Health Check

`GET /health` returns JSON:

```json
{
  "ok": true,
  "index_built_at": "2026-05-12T15:00:00+00:00",
  "index_pages_count": 114,
  "sdk_version": "v0.5.0",
  "php_version": "8.4.x"
}
```

Returns `503` if `data/index.json` is missing.

## Links

- [Total CMS](https://totalcms.co)
- [Documentation](https://docs.totalcms.co)
- [MCP Protocol](https://modelcontextprotocol.io)
