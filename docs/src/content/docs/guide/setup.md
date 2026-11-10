---
title: Setup
description: Configure Craft to send emails through Lettermint.
---

Three steps and your emails go out through Lettermint:

1. Go to **Settings** → **Email**.
2. Set **Transport Type** to **Lettermint**.
3. Fill in the settings below and save.

| Setting               | Required | Description                                                                                                                                                     |
| --------------------- | :------: | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Project API Token** |   Yes    | Found on your [projects](https://app.lettermint.co/projects) page under “API Token”.                                                                            |
| **Route Slug**        |          | A custom [route](https://docs.lettermint.co/platform/projects-and-routes/routes). Leave blank for the default transactional route.                              |
| **Tags**              |          | [Tags](https://lettermint.co/docs/platform/emails/tags) for all emails, e.g. `environment=production, campaign=welcome`. Use only letters, digits, `_` and `-`. |

:::caution
The **System Email Address** must use a [domain](https://app.lettermint.co/domains) you set up in Lettermint.
:::

:::tip
All settings support [environment variables](https://craftcms.com/docs/5.x/configure.html#env).
:::
