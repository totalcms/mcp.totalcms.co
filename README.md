# Total CMS Docs MCP Server

MCP server that gives AI coding agents real-time access to Total CMS documentation. Hosted at `mcp.totalcms.co`.

## Setup

Add to your AI tool's MCP config (Claude Code, Cursor, Windsurf, etc.):

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

## Tools

| Tool | Description |
|------|-------------|
| `docs_search` | Full-text search across all documentation |
| `docs_twig_function` | Twig function signatures and examples |
| `docs_twig_filter` | Twig filter signatures and examples |
| `docs_field_type` | Field type configuration options |
| `docs_api_endpoint` | REST API endpoint details |
| `docs_schema_config` | Schema/collection config options |
| `docs_cli_command` | CLI commands (stub — in development) |

## Requirements

- PHP 8.2+
- Apache with mod_rewrite + PHP-FPM
- Composer

## Development

```bash
composer install
php bin/build-index.php /path/to/totalcms
php -S localhost:8765 -t public/
```

## Deployment

```bash
bin/build.sh /path/to/totalcms
```

## Maintenance

```bash
# Clean up expired MCP sessions
bin/cleanup-sessions.sh
```

Connection logs are written to `logs/connections.log`.
