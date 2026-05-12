---
title: "Twig Filters"
description: "Built-in Twig filters for Total CMS templates."
---

# Twig Filters

#### `humanize(): string`

Converts a machine-style identifier (snake_case, kebab-case) into a human-readable string.

```twig
{{ "hello_world" | humanize }}
```

#### `dateFormat(format: string): string`

Format a date using PHP's date() syntax.

```twig
{{ post.date | dateFormat("Y-m-d") }}
```
