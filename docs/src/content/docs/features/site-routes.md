---
title: Route and tags per site
description: Send each site through its own route, with its own tags.
---

Set a **Route Slug** and **Tags** for each site in the **Sites** table. Both support environment variables. This is a Pro feature.

The site comes from the message (e.g. a user's affiliated site) or the current request.

- **Route Slug**: the site's route replaces the adapter's route.
- **Tags**: the site's tags are merged with the adapter's tags. Tags with the same name are replaced.

**Example:** adapter `environment=production, site=default` plus site `site=shop` gives `environment=production, site=shop`.

|                                          | Route Slug                   | Tags                           |
| ---------------------------------------- | ---------------------------- | ------------------------------ |
| **Site value set**                       | Replaces the adapter's route | Merged with the adapter's tags |
| **Site value empty**                     | Adapter's route is used      | Adapter's tags are used        |
| **`X-LM-Options` header on the message** | Takes precedence             | Replaces site and adapter tags |
| **Limit**                                |                              | Max. 20 tags in total          |
