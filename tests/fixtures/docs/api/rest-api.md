---
title: "REST API"
description: "Total CMS REST API reference."
---

# REST API

### Get collection objects

Returns objects in the named collection.

```http
GET /collections/{name}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | string | Collection identifier |

### Create object

Create a new object in a collection. Pro edition feature.

```http
POST /collections/{name}/objects
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | string | Collection identifier |
