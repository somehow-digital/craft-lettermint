<img src="src/icon.svg" width="96" height="96" alt="Lettermint icon" />

# `Lettermint` for Craft CMS
> Provides a [Lettermint](https://www.lettermint.co/) integration for [Craft CMS](https://craftcms.com/).

| Feature                                   | Standard | Pro |
|-------------------------------------------|:--------:|:---:|
| Mail transport with route and tags        |  ✓   |  ✓  |
| [Utility](#utility) with usage and checks |      |  ✓  |
| [Dashboard widget](#dashboard-widget)     |      |  ✓  |
| [Webhooks](#webhooks) as Craft events     |      |  ✓  |
| [Health endpoint](#health-endpoint) for monitoring |      |  ✓  |
| [Route and tags per site](#per-site-routes-and-tags) |      |  ✓  |

## Requirements

* Craft CMS 5.8.0+
* PHP 8.2+

## Installation

**Plugin Store:** open the **Plugin Store** in the Control Panel, search for “Lettermint” and click **Install**.

**Composer:**

```sh
composer require somehow-digital/craft-lettermint
./craft plugin/install lettermint
```

## Setup

1. Go to **Settings** → **Email**.
2. Set **Transport Type** to **Lettermint**.
3. Fill in the settings below and click **Save**.

| Setting               | Required | Description                                                                                                                                |
|-----------------------|:--------:|--------------------------------------------------------------------------------------------------------------------------------------------|
| **Project API Token** |    ✓     | From your [projects](https://app.lettermint.co/projects) page under “API Token”.                                                          |
| **Route Slug**        |          | A custom [route](https://docs.lettermint.co/platform/projects-and-routes/routes). Blank uses the default transactional route.              |
| **Tags**              |          | [Tags](https://lettermint.co/docs/platform/emails/tags) for all emails, e.g. `environment=production, campaign=welcome`.                   |

> [!IMPORTANT]
> The **System Email Address** must use a [domain](https://app.lettermint.co/domains) configured in Lettermint.

> [!TIP]
> All settings can use [environment variables](https://craftcms.com/docs/5.x/configure.html#env).

Tag names and values may only contain letters, digits, `_` and `-`.

## Pro Edition

### Pro settings

| Setting              | Description                                                                                                                                                                         |
|----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Team API Token**   | Enables features using the [Team API](https://lettermint.co/docs/platform/teams/team-api). Team owners create it in the [dashboard](https://app.lettermint.co/) under **Manage team** → “API Tokens”. |
| **Project**          | ID of the project the **Project API Token** belongs to (Lettermint can't tell). Suggested once the team token is accepted. Leave blank if the team has only one project. |
| **Webhook Secret**   | Signing secret (`whsec_…`) of a [webhook](#webhooks). Blank turns the endpoint off.                                                                                                 |
| **Health Token**     | Token of the [health endpoint](#health-endpoint), at least 16 characters without spaces. Blank turns the endpoint off.                                                              |
| **Sites**            | [Route slug and tags per site](#per-site-routes-and-tags).                                                                                                                          |

### Utility

Open **Utilities** → **Lettermint** for an overview of your team.

| Section      | Shows                                                                                                   |
|--------------|---------------------------------------------------------------------------------------------------------|
| Team         | Plan and number of projects, domains and members                                                        |
| Usage        | Emails sent this billing period vs. plan                                                                |
| Tokens       | Whether the Project and Team API Tokens are accepted                                                    |
| Project      | Default route, emails and deliverability (28 days), plus [checks](#project-checks)                      |
| Statistics   | Sent, delivered, hard bounces, spam complaints, opens and clicks (28 days), with a daily chart          |
| Projects     | Delivery mode, routes, domains, emails and deliverability of each project                               |
| Domains      | Verification status                                                                                     |

<a id="project-checks"></a>The project checks verify that:

* the project is live,
* the System Email Address domain belongs to it and is verified,
* the route slugs of the adapter and all sites are outgoing routes of it.

Good to know:

* The token status of the **Project API Token** works without a Team API Token.
* Sections the token can't read are hidden with a notice.
* The data is cached for 5 minutes and shared with the [dashboard widget](#dashboard-widget). **Refresh** loads it again, as does changing the settings.
* Access is controlled by the **Utilities** → **Lettermint** user permission.

### Dashboard widget

Add the **Lettermint** widget to the dashboard for a compact version of the [utility](#utility): team and plan, usage this billing period, sent, delivered, hard bounces and spam complaints (28 days) with a daily chart, and any failing [project checks](#project-checks).

Good to know:

* It shares its data with the utility.
* Only users with the **Utilities** → **Lettermint** permission can add or see the widget.

### Webhooks

Receive Lettermint [webhook](https://lettermint.co/docs/platform/webhooks/introduction) events (deliveries, bounces, complaints, …) as Craft events.

**Setup**

1. In the [dashboard](https://app.lettermint.co/), create a webhook with the endpoint URL shown below the **Webhook Secret** setting (e.g. `https://example.com/actions/lettermint/webhook`) and choose its events.
2. Paste its signing secret into **Webhook Secret**.

**Usage**

```php
use SomehowDigital\Craft\Lettermint\Webhooks;
use SomehowDigital\Craft\Lettermint\events\WebhookEvent;
use yii\base\Event;

Event::on(Webhooks::class, Webhooks::EVENT_RECEIVE_WEBHOOK, function (WebhookEvent $event) {
    if ($event->type === Webhooks::TYPE_MESSAGE_HARD_BOUNCED) {
        $event->payload->id;   // delivery ID
        $event->payload->data; // event data, e.g. `recipient`
    }
});
```

Each documented type has a `TYPE_*` constant, e.g. `TYPE_MESSAGE_DELIVERED`, `TYPE_MESSAGE_SPAM_COMPLAINT`, `TYPE_SUPPRESSION_ADDED`. The dashboard's test delivery is `TYPE_WEBHOOK_TEST`. Types Lettermint adds later fire the event too.

**Behavior**

| Situation                                   | Response | Lettermint retries?      |
|---------------------------------------------|:--------:|:------------------------:|
| Valid signature, handlers succeed           |  `200`   | —                        |
| Invalid signature or older than 5 minutes   |  `401`   | No                       |
| A handler throws an exception               |  `500`   | Yes, for up to ~14 hours |
| No response within 30 seconds               |    —     | Yes                      |

> [!TIP]
> * Deliveries may arrive more than once — deduplicate using `$event->payload->id`, which stays the same across retries.
> * Keep handlers fast; push longer work to the queue.
> * Deliveries are received even while the system is offline.

### Health endpoint

Check whether emails can be sent, e.g. from an uptime monitor.

**Setup**

1. Generate a token, e.g. `openssl rand -hex 32`, and store it in an environment variable such as `LETTERMINT_HEALTH_TOKEN`. A token entered directly is saved in the project config, so it ends up in version control.
2. Set **Health Token** to `$LETTERMINT_HEALTH_TOKEN`.

**Usage**

```sh
curl -H "Authorization: Bearer $LETTERMINT_HEALTH_TOKEN" https://example.com/actions/lettermint/health
```

Monitors that can't send headers can use `?key=…` instead. Prefer the header, as query strings end up in server logs. Some Apache setups drop the `Authorization` header before it reaches PHP. If you get `401` with the correct token, pass the header through or use `?key=…`.

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

`status` is the worst status of the checks: `ok`, `warning` or `error`. Match on the check `id`s, as the `text` is translated.

| Check        | Needs Team API Token | Fails with                                                     |
|--------------|:--------------------:|----------------------------------------------------------------|
| `token`      |                      | `error`: token rejected or Lettermint unreachable              |
| `teamToken`  |          ✓           | `warning`: token rejected or team unreadable                   |
| `usage`      |          ✓           | `warning`: 90% or more of the plan's monthly volume used       |
| `project`    |          ✓           | `warning`: project not in the team or could not be loaded      |
| `delivery`   |     ✓ + project      | `warning`: project in sandbox mode                             |
| `sender`     |     ✓ + project      | `error`: not a domain of the project, `warning`: not verified  |
| `route`, `route.<site>` | ✓ + project | `error`: not an outgoing route of the project               |

**Behavior**

| Situation                              | Response |
|----------------------------------------|:--------:|
| Status `ok` or `warning`               |  `200`   |
| Status `error`                         |  `503`   |
| Missing or wrong token                 |  `401`   |
| No **Health Token** or Standard edition |  `404`   |

> [!TIP]
> * Results are cached for 60 seconds, so frequent checks don't hit Lettermint's rate limits. `date` shows when they were taken.
> * The endpoint also answers while the system is offline.

### Per-site routes and tags

Set a **Route Slug** and **Tags** for each site in the **Sites** table. The site is determined from the message (e.g. a user's affiliated site) or the current request. Both can use environment variables.

| | Route Slug | Tags |
|---|---|---|
| **Site value** | Replaces the adapter's route | Merged with the adapter's tags; same names are replaced |
| **Not set**    | Adapter's route is used      | Adapter's tags are used |
| **`X-LM-Options` header on the message** | Takes precedence | Replaces site and adapter tags |
| **Limit** | — | Max. 20 tags combined |

**Example:** adapter `environment=production, site=default` + site `site=shop` → `environment=production, site=shop`.

---

> [somehow digital.](https://somehow.digital)
