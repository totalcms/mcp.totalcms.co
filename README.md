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
php bin/build-index.php /path/to/totalcms
php -S localhost:8765 -t public/
```

### Building the Index

The documentation index is built from markdown files in the [totalcms/cms](https://github.com/totalcms/cms) repo:

```bash
bin/build.sh /path/to/totalcms
```

## Links

- [Total CMS](https://totalcms.co)
- [Documentation](https://docs.totalcms.co)
- [MCP Protocol](https://modelcontextprotocol.io)
