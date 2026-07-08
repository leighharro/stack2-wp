# Local WordPress Development

This repository includes two minimal local setups:

Release documentation:

- See RELEASING.md for the end-to-end release process and tagging workflow.

- wp-env (quickest path for plugin testing)
- Docker Compose (portable and explicit services)

## Quick Setup (New Machine)

Run the setup script to install/verify Homebrew, Node.js 20+, PHP 8.3+, Docker,
and project npm dependencies in one go:

    ./scripts/setup-dev-environment.sh

Or run it from VS Code via Command Palette > Tasks: Run Task > "Setup Dev Environment".

## Option 1: wp-env

Prerequisites:

- Docker Desktop running
- Node.js 20+

Commands:

1. Install dependencies:

   npm install

2. Start WordPress:

   npm run wp-env:start

3. Open the site:

   http://localhost:8888

4. Open wp-admin:

   http://localhost:8888/wp-admin

5. Stop environment:

   npm run wp-env:stop

Useful maintenance:

- Reset containers and data: npm run wp-env:clean
- Remove wp-env resources: npm run wp-env:destroy

Notes:

- Plugin is mounted from ./stack2-connector
- Debug logging is enabled in .wp-env.json

## Option 2: Docker Compose

Prerequisites:

- Docker Desktop running

Commands:

1. Start services:

   docker compose up -d

2. Open WordPress install flow:

   http://localhost:8080

3. Stop services:

   docker compose down

4. Stop and remove DB volume (full reset):

   docker compose down -v

Notes:

- Plugin is mounted into container path:
  /var/www/html/wp-content/plugins/stack2-connector
- Database credentials are local-dev defaults only

## Quick Plugin Smoke Test

1. Activate Stack2 Connector in wp-admin.
2. Open Settings > Stack2 Connector.
3. Enter Stack2 Base URL, Site ID, and API key.
4. Click Save Settings.
5. Click Sync Now.
6. Confirm last sync status and debug logs.

## Connecting to a Stack2 App Running on Your Mac

If WordPress is running in Docker, localhost inside WordPress is the container itself, not your Mac host.

Use this in Stack2 Base URL:

- http://host.docker.internal:YOUR_STACK2_PORT

Example:

- http://host.docker.internal:3000

If connection still fails:

1. Ensure your Stack2 app is actually listening on a port on the host.
2. Ensure your Stack2 app binds to 0.0.0.0, not only 127.0.0.1.
3. Ensure you use the correct protocol (http vs https).
4. If using https locally with a self-signed cert, prefer local http for this integration test.
5. From inside WordPress container, test connectivity:

   docker exec stack2_wp_app sh -lc "curl -i http://host.docker.internal:YOUR_STACK2_PORT"

## If wp-json Route Returns 404

In some local Docker setups, Apache rewrite rules are not enabled for pretty REST URLs.

If this URL returns 404:

- http://localhost:8080/wp-json/stack2/v1/command

Use this equivalent endpoint instead:

- http://localhost:8080/index.php?rest_route=/stack2/v1/command

Notes:

1. This endpoint is POST only.
2. A GET request will return route-not-found even when the route is registered.
3. A POST without required signature headers should return 401, which confirms the endpoint exists.
