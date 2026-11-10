---
title: Health endpoint
description: Let your uptime monitor know if emails can be sent.
---

Check if emails can be sent, e.g. from an uptime monitor. This is a Pro feature.

## Setup

1. Generate a token with at least 16 characters and no spaces, e.g. `openssl rand -hex 32`.
2. Store it in an environment variable, e.g. `LETTERMINT_HEALTH_TOKEN`.
3. Set **Health Token** to `$LETTERMINT_HEALTH_TOKEN`.

:::caution
Don't enter the token directly. It would be saved in the project config and end up in version control.
:::

## Usage

```bash
curl -H "Authorization: Bearer $LETTERMINT_HEALTH_TOKEN" https://example.com/actions/lettermint/health
```

The overall `status` is the worst check result: `ok`, `warning` or `error`.

| Status              | Response |
| ------------------- | :------: |
| `ok` or `warning`   |  `200`   |
| `error`             |  `503`   |
| Missing/wrong token |  `401`   |
| Endpoint turned off |  `404`   |

The endpoint is off without a **Health Token** or in the Standard edition. Results are cached for 60 seconds to stay within Lettermint's rate limits. The endpoint also works while the system is offline.

## Example response

```json
{
	"status": "warning",
	"date": "2026-11-05T10:00:00+00:00",
	"checks": [
		{ "id": "token", "status": "ok", "text": "Lettermint accepts the project API token." },
		{ "id": "teamToken", "status": "ok", "text": "Lettermint accepts the team API token." },
		{ "id": "usage", "status": "warning", "text": "The monthly volume of the plan is nearly used up, with 46000 of 50000 emails." },
		{ "id": "delivery", "status": "ok", "text": "The project is live." },
		{ "id": "sender", "status": "ok", "text": "The domain “example.com” of the system email address belongs to the project." },
		{ "id": "route", "status": "ok", "text": "Emails use the default route “outgoing” of the project." }
	],
	"usage": { "used": 46000, "included": 50000, "share": 0.92 }
}
```

`date` shows when the checks ran. Match on the check `id`, not the `text`, because the text is translated. See all [checks](/reference/health-checks/).

## Troubleshooting

- If your monitor can't send headers, use `?key=…` instead. Prefer the header when possible, because query strings end up in server logs.
- Some Apache setups drop the `Authorization` header before it reaches PHP. If you get `401` with the right token, pass the header through or use `?key=…`.
