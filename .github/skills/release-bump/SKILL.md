---
name: release-bump
description: "Automate Stack2 Connector plugin release bumping. Use when: performing a version bump, preparing a release, updating version files, creating a release branch. Handles updating all version fields (PHP file, readme.txt, changelog), commits, and tags."
---

# Release Bump Skill

Automates the Stack2 Connector plugin release process by updating all necessary version files, changelog, and optionally creating commits and tags.

## What This Skill Does

1. **Validates** semantic versioning format (X.Y.Z)
2. **Updates** all version references:
   - `stack2-connector/stack2-connector.php` — Version header and STACK2_CONNECTOR_VERSION constant
   - `stack2-connector/readme.txt` — Stable tag
3. **Updates** changelog in readme.txt with the new version
4. **Creates** a commit with the version bump
5. **Creates** a Git tag (vX.Y.Z format)
6. **Pushes** to remote (optional)

## Version Format

Must be semantic versioning: `X.Y.Z` where X, Y, Z are integers.

Examples:
- `1.0.0` → `1.0.1` (patch)
- `1.0.0` → `1.1.0` (minor)
- `1.0.0` → `2.0.0` (major)

## Release Checklist

Before using this skill, ensure:

- [ ] You're on the `main` branch with latest changes pulled
- [ ] All changes you want in the release are committed
- [ ] You have proper Git credentials configured
- [ ] You're ready to push to origin

## How to Use

Request the skill with the new version:

> "Bump the release to 1.0.1" or "Release 1.1.0"

Or ask to prepare a release branch:

> "Create a release branch for 1.0.1"

The skill will:
1. Ask you to confirm the new version
2. Update all version files
3. Ask if you want to commit and tag
4. Ask if you want to push to remote
5. Confirm completion

## After Running This Skill

Once the skill completes:

1. Review the changes: `git log -n 1 -p`
2. Verify the tag: `git tag -l v*.*.* --sort -version:refname | head -5`
3. Push the tag: `git push origin v<version>`
4. Visit GitHub Actions to monitor the workflow

The release workflow will automatically:
- Validate version consistency
- Lint PHP files
- Build the WordPress-ready zip package
- Create a GitHub Release with assets

## Files Modified

- `stack2-connector/stack2-connector.php`
- `stack2-connector/readme.txt`

## Related

- [RELEASING.md](/RELEASING.md) — Complete release workflow documentation
- `.github/workflows/release-wordpress.yml` — Automated build and publish workflow
