---
title: Metadata
description: See which site, user and system message an email belongs to.
sidebar:
  badge:
    text: Pro
    variant: tip
---

Every email is sent with Craft context as Lettermint metadata. You can see it with each message in Lettermint.

| Key                 | Value                                                                                                  |
| ------------------- | ------------------------------------------------------------------------------------------------------ |
| `craft_email`       | Key of the [system message](https://craftcms.com/docs/5.x/system/mail.html), e.g. `forgot_password`    |
| `craft_site`        | Handle of the site                                                                                     |
| `craft_user`        | ID of the recipient user, e.g. with `setTo($user)`                                                     |
| `craft_language`    | Language of the email, e.g. `de-AT`                                                                    |
| `craft_environment` | Craft [environment](https://craftcms.com/docs/5.x/configure.html#craft-environment), e.g. `production` |

Empty keys are skipped. For example, `craft_email` is missing for emails that aren't system messages.

## Add your own metadata

Metadata set on the message wins over the Craft metadata:

```php
use Symfony\Component\Mailer\Header\MetadataHeader;

$message->getSymfonyEmail()->getHeaders()->add(new MetadataHeader('order_id', '1234'));
```
