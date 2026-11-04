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
3. Enter your **API Token** (which you can get from your [projects](https://dash.lettermint.co/projects) page under “API Token”).
4. If you wish to send mail using a custom [route](https://docs.lettermint.co/platform/projects-and-routes/routes), set the **Route Slug**. (The default transactional route will be used if this is left blank.)
5. If you wish to [tag](https://lettermint.co/docs/platform/emails/tags) all emails, set the **Tags** as comma-separated `name=value` pairs, e.g. `environment=production, campaign=welcome`. Names and values may only contain letters, digits, `_` and `-`.
6. Click **Save**.

> **Tip:** The `API Token`, `Route Slug` and `Tags` settings can be set using environment variables. See [Environmental Configuration](https://craftcms.com/docs/5.x/configure.html#env) in the Craft CMS docs to learn more about that.

## Pro Edition

### Team API Token

Enter a **Team API Token** (which team owners can create in the [dashboard](https://dash.lettermint.co/) under **Manage team** → “API Tokens”) for features that use the [Team API](https://lettermint.co/docs/platform/teams/team-api) of Lettermint. It can be set using an environment variable. The settings page shows whether Lettermint accepts the token.

### Route per Site

Set a **Route Slug** for the emails of each site in the **Sites** table. Emails use the route slug of the site they are sent for, which Craft determines from the message (e.g. the affiliated site of a user) or the current request. Sites without a route slug use the **Route Slug** of the adapter.

Route slugs can be set using environment variables. A route set on a message itself, using the `X-LM-Options` header, takes precedence over the one of its site.

### Tags per Site

Set **Tags** for the emails of each site in the **Sites** table. They are added to the **Tags** of the adapter, replacing the ones with the same name. For example, with `environment=production, site=default` for the adapter and `site=shop` for a site, the emails of that site are tagged with `environment=production, site=shop`. Together, the tags of the adapter and a site can be at most 20.

Tags can be set using environment variables. Tags set on a message itself, using the `X-LM-Options` header, replace the ones of its site and the adapter.

---

> [somehow digital.](https://somehow.digital)
