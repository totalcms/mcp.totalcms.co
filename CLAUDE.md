# CLAUDE.md

## Project Overview

MCP documentation server for Total CMS, hosted at `mcp.totalcms.co`. Provides AI coding agents with real-time access to Total CMS documentation via the Model Context Protocol.

## Technology Stack

- **Runtime**: PHP 8.2+ with Apache + PHP-FPM
- **MCP SDK**: `mcp/sdk` (official PHP SDK from PHP Foundation)
- **Transport**: Streamable HTTP via `StreamableHttpTransport`
- **Index**: Static JSON built at deploy time from Total CMS docs

## Project Structure

- `public/index.php` — MCP server entry point (Apache document root)
- `src/DocsTools.php` — Tool implementations (search, twig functions/filters, field types, API endpoints, schema config)
- `bin/build-index.php` — Parses Total CMS `resources/docs/` markdown into structured JSON index
- `bin/build.sh` — install, rebuild index
- `data/index.json` — Built index (eventually gitignored when T3 can be loaded via composer)
- `data/sessions/` — MCP session storage for PHP-FPM

## Common Commands

```bash
# Build/rebuild the documentation index
php bin/build-index.php [/path/to/totalcms]

# build (install + rebuild)
bin/build.sh [/path/to/totalcms]

# Local testing
https://mcp.totalcms.test
```

## MCP Tools

| Tool | Purpose |
|------|---------|
| `docs_search` | Full-text search across all docs |
| `docs_twig_function` | Look up Twig function signatures |
| `docs_twig_filter` | Look up Twig filter signatures |
| `docs_field_type` | Look up field type configuration |
| `docs_api_endpoint` | Look up REST API endpoints |
| `docs_schema_config` | Look up schema/collection config |
| `docs_cli_command` | CLI commands (stub — CLI in development) |

## Documentation Source

The index is built from markdown files in the main Total CMS repo at `/Users/joeworkman/Developer/totalcms/resources/docs/`. The source of truth is always that repo — this project only reads and indexes it.

## Apache Configuration

Document root should point to `public/`. The `.htaccess` routes all requests to `index.php`.
