<img src="src/icon.svg" width="96" height="96" alt="Lettermint icon" />

# `Lettermint` for Craft CMS
> Provides a [Lettermint](https://www.lettermint.co/) integration for [Craft CMS](https://craftcms.com/).

## Requirements

* Craft CMS 5.8.0 or later.
* PHP 8.2 or later.

## Installation

Install this plugin from the Plugin Store or via Composer.

#### Plugin Store

Go to the “Plugin Store” in your project’s Control Panel, search for
“Lettermint” and click on the “Install” button in its modal window.

#### Composer

```sh
composer require somehow-digital/craft-lettermint
./craft plugin/install lettermint
```

## Configuration

1. Go to **Settings** → **Email**.
2. Change the **Transport Type** setting to **`Lettermint`**. Also, ensure that your System Email Address in your Craft Email Settings uses a configured [domain](https://dash.lettermint.co/domains).
3. Enter your **Project API Token** (which you can get from your [projects](https://dash.lettermint.co/projects) page under “API Token”).
4. If you wish to send mail using a custom [route](https://docs.lettermint.co/platform/projects-and-routes/routes), set the **Route Slug**. (The default transactional route will be used if this is left blank.)
5. If you wish to [tag](https://lettermint.co/docs/platform/emails/tags) all emails, set the **Tags** as comma-separated `name=value` pairs, e.g. `environment=production, campaign=welcome`. Names and values may only contain letters, digits, `_` and `-`.
6. Click **Save**.

> **Tip:** The `Project API Token`, `Route Slug` and `Tags` settings can be set using environment variables. See [Environmental Configuration](https://craftcms.com/docs/5.x/configure.html#env) in the Craft CMS docs to learn more about that.

## Pro Edition

### Team API Token

Enter a **Team API Token** (which team owners can create in the [dashboard](https://dash.lettermint.co/) under **Manage team** → “API Tokens”) for features that use the [Team API](https://lettermint.co/docs/platform/teams/team-api) of Lettermint. It can be set using an environment variable. The [utility](#utility) shows whether Lettermint accepts the token.

### Project

Set the **Project** to the ID of the Lettermint project of the **Project API Token**, so the utility can show it and check the settings against it. Lettermint does not tell which project a token belongs to, so it is set here; once the **Team API Token** is accepted, the projects of the team are suggested. Leave it blank when the team has a single project, which is then taken automatically. It can be set using an environment variable, e.g. for a different project per environment.

### Utility

Go to **Utilities** → **Lettermint** for an overview of the team of the **Team API Token**:

* The team, with its plan and the number of its projects, domains and members.
* The usage of the current billing period, compared with the emails included in the plan.
* Whether Lettermint accepts the **Project API Token** and the **Team API Token**. The status of the **Project API Token** is also shown without a **Team API Token**.
* The project of the site, with its default route, emails and deliverability of the last 28 days, and checks whether it is live, whether the domain of the System Email Address belongs to it and is verified, and whether the route slugs of the adapter and the sites are outgoing routes of it.
* The sent, delivered, hard bounced, spam complaint, open and click numbers of the last 28 days, with a chart of the emails sent per day.
* The projects, with their delivery mode, routes, domains, emails and deliverability of the last 28 days.
* The domains, with their verification status.

The page is loaded from Lettermint each time it is opened. Sections the token is not allowed to read are left out with a notice. Access to the utility is granted with the **Utilities** → **Lettermint** user permission.

### Webhooks

Lettermint can [notify Craft](https://lettermint.co/docs/platform/webhooks/introduction) about the events of its emails, such as deliveries, bounces and spam complaints, which the plugin fires as events in Craft:

1. Create a webhook in the [dashboard](https://dash.lettermint.co/) with the endpoint URL that is shown under the **Webhook Secret** setting, e.g. `https://example.com/actions/lettermint/webhook`, and choose its events.
2. Set the **Webhook Secret** to the signing secret of the webhook (`whsec_…`). It can be set using an environment variable. Leave it blank to turn the endpoint off.

The endpoint verifies the signature of each delivery and rejects the ones that are not signed with the secret or signed more than 5 minutes ago with a `401`, which Lettermint does not deliver again. It then fires `Webhooks::EVENT_RECEIVE_WEBHOOK` for every delivery, with the Lettermint event as its type:

```php
use SomehowDigital\Craft\Lettermint\Webhooks;
use SomehowDigital\Craft\Lettermint\events\WebhookEvent;
use yii\base\Event;

Event::on(Webhooks::class, Webhooks::EVENT_RECEIVE_WEBHOOK, function (WebhookEvent $event) {
    if ($event->type === Webhooks::TYPE_MESSAGE_HARD_BOUNCED) {
        $event->payload->id; // the ID of the delivery
        $event->payload->data; // the data of the event, e.g. its `recipient`
    }
});
```

There is a constant for each type Lettermint documents, e.g. `TYPE_MESSAGE_DELIVERED`, `TYPE_MESSAGE_SPAM_COMPLAINT` or `TYPE_SUPPRESSION_ADDED`. Types that Lettermint adds later fire the event as well. The test delivery of the dashboard has the type `TYPE_WEBHOOK_TEST`.

When a handler throws an exception, the delivery fails with a `500` and Lettermint [delivers it again](https://lettermint.co/docs/platform/webhooks/introduction) later, for up to about 14 hours, so a delivery can arrive more than once; its ID (`$event->payload->id`) stays the same. Lettermint waits 30 seconds for a response, which also counts as failed otherwise, so keep handlers quick, e.g. by pushing longer work to the queue. Deliveries are also received while the system is offline.

### Route per Site

Set a **Route Slug** for the emails of each site in the **Sites** table. Emails use the route slug of the site they are sent for, which Craft determines from the message (e.g. the affiliated site of a user) or the current request. Sites without a route slug use the **Route Slug** of the adapter.

Route slugs can be set using environment variables. A route set on a message itself, using the `X-LM-Options` header, takes precedence over the one of its site.

### Tags per Site

Set **Tags** for the emails of each site in the **Sites** table. They are added to the **Tags** of the adapter, replacing the ones with the same name. For example, with `environment=production, site=default` for the adapter and `site=shop` for a site, the emails of that site are tagged with `environment=production, site=shop`. Together, the tags of the adapter and a site can be at most 20.

Tags can be set using environment variables. Tags set on a message itself, using the `X-LM-Options` header, replace the ones of its site and the adapter.

---

> [somehow digital.](https://somehow.digital)
