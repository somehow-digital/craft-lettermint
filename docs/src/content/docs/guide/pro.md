---
title: Pro edition
description: What the Pro edition adds to the plugin.
---

Pro adds per-site settings, insights in the Control Panel, webhooks and health checks.

| Feature                                              | Standard | Pro |
| ---------------------------------------------------- | :------: | :-: |
| Mail transport with route and tags                   |   Yes    | Yes |
| [Route and tags per site](/features/site-routes/)    |          | Yes |
| [Craft metadata](/features/metadata/) on every email |          | Yes |
| [Utility](/features/utility/)                        |          | Yes |
| [Dashboard widget](/features/widget/)                |          | Yes |
| [Webhooks](/features/webhooks/) as Craft events      |          | Yes |
| [Health endpoint](/features/health-endpoint/)        |          | Yes |
| [Health command](/features/health-command/)          |          | Yes |

## Pro settings

The Pro settings appear below the standard settings. All of them are optional, so turn on only what you need.

| Setting            | What it does                                                                                                                                                                                    |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Team API Token** | Unlocks the [Team API](https://lettermint.co/docs/platform/teams/team-api) features. Team owners create it in the [dashboard](https://app.lettermint.co/) under **Manage team** → “API Tokens”. |
| **Project**        | ID of the project your **Project API Token** belongs to. Needed for project details, statistics, domains and checks.                                                                            |
| **Webhook Secret** | Turns on the [webhook endpoint](/features/webhooks/).                                                                                                                                           |
| **Health Token**   | Turns on the [health endpoint](/features/health-endpoint/).                                                                                                                                     |
| **Sites**          | Sets a [route and tags per site](/features/site-routes/).                                                                                                                                       |

:::note
Lettermint can't tell which project a token belongs to, so set **Project** yourself.
:::
