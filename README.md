<img src="./src/icon.svg" width="100" height="100" alt="Lettermint icon">

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
composer require somehowdigital/craft-lettermint
./craft plugin/install lettermint
```

## Configuration

1. Go to **Settings** → **Email**.
2. Change the **Transport Type** setting to **`Lettermint`**. Also, ensure that your System Email Address in your Craft Email Settings uses a configured [domain](https://dash.lettermint.co/domains).
3. Enter your **API Token** (which you can get from your [projects](https://dash.lettermint.co/projects) page under “API Token”).
4. If you wish to send mail using a custom [route](https://docs.lettermint.co/platform/projects-and-routes/routes), set the **Route Slug**. (The default transactional route will be used if this is left blank.)
5. Click **Save**.

> **Tip:** The `API Token` and `Route Slug` settings can be set using environment variables. See [Environmental Configuration](https://craftcms.com/docs/5.x/configure.html#env) in the Craft CMS docs to learn more about that.
