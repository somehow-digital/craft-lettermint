---
title: Health checks
description: All checks of the health endpoint, the health command and the utility.
sidebar:
  badge:
    text: Pro
    variant: tip
---

The [health endpoint](/features/health-endpoint/), the [health command](/features/health-command/) and the [utility](/features/utility/) run these checks.

| Check                     | Needs                    | Fails with                                                  |
| ------------------------- | ------------------------ | ----------------------------------------------------------- |
| `token`                   |                          | `error`: token rejected or Lettermint unreachable           |
| `teamToken`               | Team API Token           | `warning`: token rejected or team unreadable                |
| `usage`                   | Team API Token           | `warning`: 90% or more of the monthly volume used           |
| `project`                 | Team API Token           | `warning`: project not in the team or couldn't be loaded    |
| `delivery`                | Team API Token + Project | `warning`: project in sandbox mode                          |
| `sender`, `sender.<site>` | Team API Token + Project | `error`: domain not in the project, `warning`: not verified |
| `route`, `route.<site>`   | Team API Token + Project | `error`: not an outgoing route of the project               |
