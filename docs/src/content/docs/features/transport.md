---
title: Transport
description: Send emails to Lettermint through its API or SMTP.
---

Choose how Craft hands your emails to Lettermint. Set **Transport** in **Settings** → **Email**.

| Transport           | How it works                                                               |
| ------------------- | -------------------------------------------------------------------------- |
| **`api`** (default) | Sends each email over HTTPS to the Lettermint API.                         |
| **`smtp`**          | Sends each email to `smtp.lettermint.co` over TLS, on the configured port. |

## SMTP port

Set **Port** to change the SMTP port. It only applies to `smtp`.

| Port                | TLS                              |
| ------------------- | -------------------------------- |
| **`465`** (default) | Implicit TLS                     |
| **`587`**           | STARTTLS                         |
| **`2465`**          | Implicit TLS, alternative to 465 |
| **`2587`**          | STARTTLS, alternative to 587     |

Use `2465` or `2587` if your host blocks the standard ports. Port 25 isn't supported: Lettermint advises against it and many providers block it. Emails are always sent encrypted. Unknown values use `465`.

## Credentials

Both transports use your **Project API Token**: as the API token, and as the SMTP password. The SMTP username is always `lettermint`.

Everything else works the same with either transport: the [route](/features/route/), the [tags](/features/tags/), the [per-site](/features/sites/) settings, [metadata](/features/metadata/) and all Pro features. SMTP sends the route and tags as the `X-Lettermint-Route` and `X-LM-Tags` headers.

## Differences

- Craft's `httpProxy` setting only applies to the API.
- The `X-LM-Options` header on a message only applies to the API. With SMTP, set the `X-Lettermint-Route` and `X-LM-Tags` headers on the message instead. They take precedence over the settings.
- Lettermint reports errors in more detail over the API.

:::tip
The settings support [environment variables](https://craftcms.com/docs/5.x/configure.html#env). An unknown transport uses the API.
:::
