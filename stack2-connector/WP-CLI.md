# Installing Stack2 Connector via WP-CLI

Stack2 Connector can be installed, activated, and fully configured from the
command line using [WP-CLI](https://wp-cli.org/) — no admin dashboard
required. This is useful for scripted deployments and provisioning.

## Prerequisites

- WP-CLI installed and available on the target server (`wp --info` to check).
- Run commands from the WordPress install directory, or pass `--path=<wp-root>`.
- A Stack2 account with a **Site ID** and **API Key** for the site you're
  connecting.

## 1. Install and activate the plugin

Stack2 Connector isn't distributed on wordpress.org, so install it directly
from the latest GitHub Release:

```bash
wp plugin install https://github.com/leighharro/stack2-wp/releases/latest/download/stack2-connector.zip --activate
```

This downloads the stable-named release asset, which always points at the
current release without pinning a version.

To install a specific version instead, use the versioned asset from that
release, e.g.:

```bash
wp plugin install https://github.com/leighharro/stack2-wp/releases/download/v1.1.4/stack2-connector-1.1.4.zip --activate
```

## 2. Configure Stack2 credentials

Once activated, the plugin registers a `wp stack2 configure` command that
saves your Stack2 Base URL, Site ID, and API Key, and queues an inventory
sync. Saving the **Settings > Stack2 Connector** page stores the same
values but runs inventory sync immediately in that request (the admin
notice reports success or failure).

```bash
wp stack2 configure \
    --server=https://app.stack2.au \
    --site-id=abc123 \
    --token=secret
```

| Flag | Description |
| --- | --- |
| `--server` | Stack2 control server base URL. |
| `--site-id` | Site ID issued by Stack2 for this WordPress install. |
| `--token` | API key issued by Stack2 for this site. |

All three flags are required. On success, WP-CLI prints a confirmation and
an inventory sync is queued for a few seconds later — it runs via WP-Cron,
not immediately in-process.

WordPress's default "pseudo-cron" only fires on an incoming site request,
so on a headless or low-traffic install (e.g. right after a scripted
provisioning run with no page views yet) the queued sync may sit pending
indefinitely. Force it to run immediately with:

```bash
wp cron event run --due-now
```

Or skip the queue entirely with the `sync` command below, which pushes
inventory synchronously and reports the result directly.

## 3. Force a sync on demand

To push inventory to Stack2 immediately, without waiting on WP-Cron:

```bash
wp stack2 sync
```

This runs the same inventory push used by the **Sync Now** button and
scheduled sync, but in-process — it reports success or failure right away
instead of queuing a cron event. Requires the plugin to already be
configured (`wp stack2 configure`).

## 4. Verify

```bash
wp plugin list --name=stack2-connector
```

Confirm the plugin shows as `active`, then check **Settings > Stack2
Connector** in wp-admin (or the Stack2 dashboard) to confirm the sync
completed.

## Updating

Stack2 Connector checks GitHub Releases for new versions and integrates
with WordPress's normal update mechanism, so standard WP-CLI update
commands work:

```bash
wp plugin update stack2-connector
```

## One-liner (scripted install)

```bash
wp plugin install https://github.com/leighharro/stack2-wp/releases/latest/download/stack2-connector.zip --activate \
  && wp stack2 configure --server=https://app.stack2.au --site-id=abc123 --token=secret \
  && wp stack2 sync
```

## Troubleshooting

- **`'stack2' is not a registered wp command`** — the plugin isn't active,
  or WP-CLI is running against a different WordPress install. Check
  `wp plugin list` and `--path`.
- **`--server, --site-id, and --token are all required.`** — one of the
  three flags was missing or empty.
- Sync doesn't appear to run — confirm WP-Cron is active; manual sync is
  also available from **Settings > Stack2 Connector**.
