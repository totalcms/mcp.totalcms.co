---
title: "Twig Functions"
description: "Built-in Twig functions for Total CMS templates."
---

# Twig Functions

### `cmsConfig(key: string): mixed`

Fetch a configuration value by dotted key.

```twig
{% if cmsConfig('debug') %}…{% endif %}
```

### `imageUrl(id: string, size: string = 'medium'): string`

Build a CDN URL for an image.
