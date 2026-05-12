---
title: "CLI Commands"
description: "Total CMS CLI reference."
since: "3.3.0"
---

# CLI Commands

### `collection:list`

List all collections in the data directory.

```bash
tcms collection:list
tcms collection:list --json
```

| Option | Description |
|--------|-------------|
| `--json` | Output JSON instead of a table |

### `object:get`

Get a single object by ID.

```bash
tcms object:get blog my-post
```

| Argument | Required | Description |
|----------|----------|-------------|
| `collection` | Yes | Collection identifier |
| `id` | Yes | Object identifier |
