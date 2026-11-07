<img src="src/icon.svg" width="96" height="96" alt="Lettermint icon" />

# `Lettermint` for Craft CMS
> Provides a [Lettermint](https://www.lettermint.co/) integration for [Craft CMS](https://craftcms.com/).

| Feature                                                | Standard | Pro |
|--------------------------------------------------------|:--------:|:---:|
| Mail transport with route and tags                     |    ✓     |  ✓  |
| [Route and tags per site](#per-site-routes-and-tags)   |          |  ✓  |
| [Craft metadata](#craft-metadata) on every email       |          |  ✓  |
| [Utility](#utility) with usage and checks              |          |  ✓  |
| [Dashboard widget](#dashboard-widget)                  |          |  ✓  |
| [Webhooks](#webhooks) as Craft events                  |          |  ✓  |
| [Health endpoint](#health-endpoint) for monitoring     |          |  ✓  |
| [Health command](#health-command) for deployments      |          |  ✓  |

## Requirements

* Craft CMS 5.8.0+
* PHP 8.2+

## Installation

Install it from the **Plugin Store** in the Control Panel, or with Composer:

```sh
composer require somehow-digital/craft-lettermint
./craft plugin/install lettermint
```

## Setup

1. Go to **Settings** → **Email**.
2. Set **Transport Type** to **Lettermint**.
3. Fill in the settings and click **Save**.

| Setting               | Required | Description                                                                                                  |
|-----------------------|:--------:|--------------------------------------------------------------------------------------------------------------|
| **Project API Token** |    ✓     | Found on your [projects](https://app.lettermint.co/projects) page under “API Token”.                         |
| **Route Slug**        |          | A custom [route](https://docs.lettermint.co/platform/projects-and-routes/routes). Leave blank for the default transactional route. |
| **Tags**              |          | [Tags](https://lettermint.co/docs/platform/emails/tags) for all emails, e.g. `environment=production, campaign=welcome`. Use only letters, digits, `_` and `-`. |

> [!IMPORTANT]
> The **System Email Address** must use a [domain](https://app.lettermint.co/domains) you set up in Lettermint.

> [!TIP]
> All settings support [environment variables](https://craftcms.com/docs/5.x/configure.html#env).

## Pro Edition

### Pro settings

These appear below the standard settings. All are optional.

| Setting            | What it does                                                                                                   |
|--------------------|----------------------------------------------------------------------------------------------------------------|
| **Team API Token** | Unlocks the [Team API](https://lettermint.co/docs/platform/teams/team-api) features. Team owners create it in the [dashboard](https://app.lettermint.co/) under **Manage team** → “API Tokens”. |
| **Project**        | ID of the project your **Project API Token** belongs to. Needed for project details, statistics, domains and checks. |
| **Webhook Secret** | Turns on the [webhook endpoint](#webhooks).                                                                     |
| **Health Token**   | Turns on the [health endpoint](#health-endpoint).                                                               |
| **Sites**          | Sets a [route and tags per site](#per-site-routes-and-tags).                                                    |

> [!NOTE]
> Lettermint can't tell which project a token belongs to, so set **Project** yourself.

### Per-site routes and tags

Set a **Route Slug** and **Tags** for each site in the **Sites** table. Both support environment variables.

The site comes from the message (e.g. a user's affiliated site) or the current request.

* **Route Slug** — the site's route replaces the adapter's route.
* **Tags** — the site's tags are merged with the adapter's tags. Tags with the same name are replaced.

Example: adapter `environment=production, site=default` + site `site=shop` → `environment=production, site=shop`.

<details>
<summary>Details</summary>

| | Route Slug | Tags |
|---|---|---|
| **Site value set** | Replaces the adapter's route | Merged with the adapter's tags |
| **Site value empty** | Adapter's route is used | Adapter's tags are used |
| **`X-LM-Options` header on the message** | Takes precedence | Replaces site and adapter tags |
| **Limit** | — | Max. 20 tags in total |

</details>

### Craft metadata

Every email is sent with Craft context as Lettermint metadata. You can see it with each message in Lettermint.

| Key                 | Value                                                                                                           |
|---------------------|-----------------------------------------------------------------------------------------------------------------|
| `craft_email`       | Key of the [system message](https://craftcms.com/docs/5.x/system/mail.html), e.g. `forgot_password`             |
| `craft_site`        | Handle of the site                                                                                              |
| `craft_user`        | ID of the recipient user, e.g. with `setTo($user)`                                                              |
| `craft_language`    | Language of the email, e.g. `de-AT`                                                                             |
| `craft_environment` | Craft [environment](https://craftcms.com/docs/5.x/configure.html#craft-environment), e.g. `production`          |

Empty keys are skipped. For example, `craft_email` is missing for emails that aren't system messages.

<details>
<summary>Add your own metadata</summary>

Metadata set on the message wins over the Craft metadata:

```php
use Symfony\Component\Mailer\Header\MetadataHeader;

$message->getSymfonyEmail()->getHeaders()->add(new MetadataHeader('order_id', '1234'));
```

</details>

### Utility

Go to **Utilities** → **Lettermint** for an overview of your team and project.

| Section    | Shows                                                                          |
|------------|--------------------------------------------------------------------------------|
| Team       | Name, plan and verification                                                    |
| Usage      | Emails sent this billing period vs. your plan                                  |
| Tokens     | Whether Lettermint accepts your API tokens                                     |
| Project    | Delivery mode, default route, token last used, and [checks](#project-checks)   |
| Statistics | Sent, delivered, hard bounces, spam complaints, opens and clicks over 28 days, with a chart |
| Domains    | Verification status of the project's domains                                   |

<a id="project-checks"></a>**Project checks** make sure that:

* the project is live,
* the System Email Address domain belongs to the project and is verified,
* all route slugs (adapter and sites) are outgoing routes of the project.

<details>
<summary>Good to know</summary>

* Only the configured project is loaded, never other projects of the team.
* The **Project API Token** status works without a Team API Token.
* Sections the token can't read are hidden and show a notice.
* Data is cached for 5 minutes and shared with the [dashboard widget](#dashboard-widget). Click **Refresh** or save the settings to reload it.
* Access requires the **Utilities** → **Lettermint** permission.

</details>

### Dashboard widget

Add the **Lettermint** widget to your dashboard. It's a compact version of the [utility](#utility) and shows:

* team and plan,
* usage this billing period,
* sent, delivered, hard bounces and spam complaints over 28 days, with a daily chart,
* failing [project checks](#project-checks).

It shares its data with the utility. Only users with the **Utilities** → **Lettermint** permission can add or see it.

### Webhooks

Receive Lettermint [webhook](https://lettermint.co/docs/platform/webhooks/introduction) events (deliveries, bounces, complaints, …) as Craft events.

**Setup**

1. In the [Lettermint dashboard](https://app.lettermint.co/), create a webhook and choose its events. Use the endpoint URL shown below the **Webhook Secret** setting, e.g. `https://example.com/actions/lettermint/webhook`.
2. Copy its signing secret (`whsec_…`) into **Webhook Secret**.

**Usage**

```php
use SomehowDigital\Craft\Lettermint\services\WebhooksService;
use SomehowDigital\Craft\Lettermint\events\WebhookEvent;
use yii\base\Event;

Event::on(WebhooksService::class, WebhooksService::EVENT_RECEIVE_WEBHOOK, function (WebhookEvent $event) {
    if ($event->type === WebhooksService::TYPE_MESSAGE_HARD_BOUNCED) {
        $event->payload->id;   // delivery ID
        $event->payload->data; // event data, e.g. `recipient`
    }
});
```

Every event type has a `TYPE_*` constant, e.g. `TYPE_MESSAGE_DELIVERED` or `TYPE_SUPPRESSION_ADDED`. Test deliveries from the dashboard use `TYPE_WEBHOOK_TEST`. New types from Lettermint fire the event too.

> [!TIP]
> * The same delivery can arrive more than once. Use `$event->payload->id` to skip duplicates — it stays the same across retries.
> * Keep handlers fast. Push slow work to the queue.

<details>
<summary>Responses and retries</summary>

| Situation                                 | Response | Lettermint retries?      |
|-------------------------------------------|:--------:|:------------------------:|
| Valid signature, handlers succeed         |  `200`   | —                        |
| Invalid signature or older than 5 minutes |  `401`   | No                       |
| A handler throws an exception             |  `500`   | Yes, for up to ~14 hours |
| No response within 30 seconds             |    —     | Yes                      |

Webhooks are also received while the system is offline.

</details>

### Health endpoint

Check if emails can be sent, e.g. from an uptime monitor.

**Setup**

1. Generate a token with at least 16 characters and no spaces, e.g. `openssl rand -hex 32`.
2. Store it in an environment variable, e.g. `LETTERMINT_HEALTH_TOKEN`.
3. Set **Health Token** to `$LETTERMINT_HEALTH_TOKEN`.

> [!WARNING]
> Don't enter the token directly. It would be saved in the project config and end up in version control.

**Usage**

```sh
curl -H "Authorization: Bearer $LETTERMINT_HEALTH_TOKEN" https://example.com/actions/lettermint/health
```

The overall `status` is the worst check result: `ok`, `warning` or `error`.

| Status               | Response |
|----------------------|:--------:|
| `ok` or `warning`    |  `200`   |
| `error`              |  `503`   |
| Missing/wrong token  |  `401`   |
| Endpoint turned off  |  `404`   |

The endpoint is off without a **Health Token** or in the Standard edition. Results are cached for 60 seconds to stay within Lettermint's rate limits. The endpoint also works while the system is offline.

<details>
<summary>Example response</summary>

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

`date` shows when the checks ran. Match on the check `id`, not the `text` — the text is translated.

</details>

<details>
<summary>All checks</summary>

| Check                   | Needs                    | Fails with                                                    |
|-------------------------|--------------------------|---------------------------------------------------------------|
| `token`                 | —                        | `error`: token rejected or Lettermint unreachable             |
| `teamToken`             | Team API Token           | `warning`: token rejected or team unreadable                  |
| `usage`                 | Team API Token           | `warning`: 90% or more of the monthly volume used             |
| `project`               | Team API Token           | `warning`: project not in the team or couldn't be loaded      |
| `delivery`              | Team API Token + Project | `warning`: project in sandbox mode                            |
| `sender`                | Team API Token + Project | `error`: domain not in the project, `warning`: not verified   |
| `route`, `route.<site>` | Team API Token + Project | `error`: not an outgoing route of the project                 |

</details>

<details>
<summary>Can't send headers, or getting <code>401</code>?</summary>

* If your monitor can't send headers, use `?key=…` instead. Prefer the header when possible — query strings end up in server logs.
* Some Apache setups drop the `Authorization` header before it reaches PHP. If you get `401` with the right token, pass the header through or use `?key=…`.

</details>

### Health command

Run the same checks from the command line, e.g. during a deployment. No **Health Token** needed, and results are never cached.

```sh
php craft lettermint/health
```

```
ok      token      Lettermint accepts the project API token.
ok      teamToken  Lettermint accepts the team API token.
warning usage      The monthly volume of the plan is nearly used up, with 46000 of 50000 emails.

Status: warning
```

| Option     | Description                                 |
|------------|---------------------------------------------|
| `--json`   | Print the report as JSON, like the endpoint |
| `--strict` | Fail on `warning` too                       |

| Exit code | When                                                       |
|:---------:|------------------------------------------------------------|
|    `0`    | `ok` or `warning`                                          |
|   `69`    | `error`, or `warning` with `--strict`                      |
|   `78`    | Lettermint isn't the transport, or Standard edition        |

---

> [somehow digital.](https://somehow.digital)
