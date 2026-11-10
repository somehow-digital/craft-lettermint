---
title: Tags
description: Label your emails with Lettermint tags.
---

[Tags](https://lettermint.co/docs/platform/emails/tags) label your emails in Lettermint. Set **Tags** in **Settings** → **Email** to add them to all emails.

Write them as comma-separated `name=value` pairs:

```
environment=production, campaign=welcome
```

- Names have 1 to 32 characters and values 1 to 64. Use only letters, digits, `_` and `-`.
- Each name can be used once. Names starting with `__lettermint` are reserved.
- An email can have up to 20 tags.

:::tip
The setting supports [environment variables](https://craftcms.com/docs/5.x/configure.html#env).
:::

With the Pro edition, each [site](/features/sites/) can add its own tags.
