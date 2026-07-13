# Releasing Stack2 Connector

This document describes how to create a new plugin release package and publish it through GitHub Actions.

## Release Workflow Summary

The workflow at .github/workflows/release-wordpress.yml does the following:

1. Validates plugin version consistency.
2. Lints all PHP files.
3. Builds a WordPress-ready zip package from stack2-connector.
4. Produces a SHA256 checksum file.
5. Also publishes a stable-named `stack2-connector.zip` alias of the same
   package (plus its own `.sha256`), so `releases/latest/download/stack2-connector.zip`
   always resolves to the current release without pinning a version. The
   in-plugin self-updater ignores this alias and always fetches the
   version-specific asset.
6. Uploads build artifacts.
7. Creates a GitHub Release when triggered by a tag push.

## Versioning Rules

Use semantic versioning:

- Patch: 1.0.0 -> 1.0.1
- Minor: 1.0.0 -> 1.1.0
- Major: 1.0.0 -> 2.0.0

Keep all of these aligned before tagging:

1. stack2-connector/stack2-connector.php plugin header Version field.
2. stack2-connector/stack2-connector.php STACK2_CONNECTOR_VERSION constant.
3. stack2-connector/readme.txt Stable tag.
4. stack2-connector/readme.txt Changelog section.

Tag format can be either vX.Y.Z or X.Y.Z.

## Standard Release Steps

1. Create a release branch.

   git checkout -b release/1.0.1

2. Update version fields and changelog files listed above.
3. Commit changes.

   git add stack2-connector/stack2-connector.php stack2-connector/readme.txt
   git commit -m "Release 1.0.1"

4. Merge to main (via PR or direct, depending on team policy).
5. Pull latest main locally.

   git checkout main
   git pull

6. Create and push a release tag that matches the plugin version.

   git tag v1.0.1
   git push origin v1.0.1

7. Wait for the Build WordPress Release workflow to complete in GitHub Actions.
8. Verify GitHub Release assets contain:

- stack2-connector-1.0.1.zip
- stack2-connector-1.0.1.zip.sha256
- stack2-connector.zip (stable-named alias of the same package, for `wp plugin install .../releases/latest/download/stack2-connector.zip --activate`)
- stack2-connector.zip.sha256

## Manual Build (No Release Publish)

You can run the workflow manually from Actions using workflow_dispatch.

Notes:

- Manual runs validate and package the plugin.
- GitHub Release publishing only happens on tag pushes.

## Troubleshooting

### Version mismatch failure

If the workflow says tag and plugin version do not match:

1. Confirm plugin header Version matches STACK2_CONNECTOR_VERSION.
2. Confirm pushed tag version equals plugin version.
3. Re-tag with the correct value if needed.

### Missing files in zip

If expected files are absent, review:

- stack2-connector/.distignore

That file controls what is excluded from the distributable package.

### PHP lint failure

Run local lint checks before tagging:

find stack2-connector -type f -name '*.php' -print0 | xargs -0 -n1 php -l

## Release Checklist

- Versions updated in plugin file and readme
- Changelog updated
- Changes merged to main
- Tag created and pushed
- Workflow completed successfully
- Release zip downloaded and smoke tested in a clean WordPress install
