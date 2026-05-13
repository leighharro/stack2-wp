# Release Bump Skill

Automated release version management for Stack2 Connector WordPress plugin.

## Overview

This skill provides tools and guidance for performing semantic version bumps of the Stack2 Connector plugin. It automates updating all version references and can handle the full release workflow including commits, tags, and pushes.

## Quick Start

### Using the Skill (Recommended)

In VS Code Chat, invoke the skill:

```
/release-bump
```

Or ask directly:
- "Bump the release to 1.0.1"
- "Release 1.1.0"  
- "Create a release branch for 2.0.0"

The skill will:
1. Confirm the version number
2. Update all version files
3. Guide you through updating the changelog
4. Create a commit and tag
5. Offer to push to remote

### Using the Helper Scripts

If you prefer manual control, use the included scripts:

#### Option A: Update Versions Only

```bash
./.github/skills/release-bump/update-version.sh 1.0.1
```

This updates version numbers in:
- `stack2-connector/stack2-connector.php` (Version header and constant)
- `stack2-connector/readme.txt` (Stable tag)

You then manually:
1. Update the changelog in readme.txt
2. Commit and push

#### Option B: Full Automated Release

```bash
./.github/skills/release-bump/release.sh 1.0.1
```

This:
1. Updates all version files
2. Prompts you to update changelog
3. Creates a commit
4. Creates a Git tag

To also push the tag automatically:

```bash
./.github/skills/release-bump/release.sh 1.0.1 --push
```

## Files Modified

The skill updates these files:

### stack2-connector/stack2-connector.php

```php
/**
 * Plugin Name: Stack2 Connector
 * Description: ...
 * Version: 1.0.1        ← Updated here
 * ...
 */

define('STACK2_CONNECTOR_VERSION', '1.0.1');  ← And here
```

### stack2-connector/readme.txt

```
Stable tag: 1.0.1        ← Updated here

== Changelog ==
= 1.0.1 =                ← You add this
* Bug fixes and improvements
* ... your notes here
```

## Workflow

```
1. Checkout main → git checkout main; git pull
   ↓
2. Request skill → /release-bump
   ↓
3. Confirm version → e.g., "1.0.1"
   ↓
4. Skill updates files
   ↓
5. Review changes → git diff
   ↓
6. Update changelog → Edit readme.txt
   ↓
7. Skill commits → git commit -m "Release 1.0.1"
   ↓
8. Skill tags → git tag v1.0.1
   ↓
9. Push? → Choose to push or do it manually
   ↓
10. GitHub Actions builds release
    ↓
11. Release assets appear on GitHub
```

## Validation

The skill validates:
- Semantic versioning format (X.Y.Z)
- No uncommitted changes in repo
- Current branch (warns if not on main)
- File existence and readability
- Version consistency after update

The GitHub Actions workflow also validates:
- Plugin header Version matches constant
- Tag matches plugin version
- PHP linting passes
- Zip package builds successfully

## Recovery

If something goes wrong:

### Undo uncommitted changes
```bash
git restore stack2-connector/stack2-connector.php stack2-connector/readme.txt
```

### Delete mistaken tag (before pushing)
```bash
git tag -d v1.0.1
```

### Delete pushed tag
```bash
git push origin --delete v1.0.1
```

### Undo last commit (before pushing)
```bash
git reset HEAD~1
```

## Examples

### Patch Release (1.0.0 → 1.0.1)
```
Use when: Bug fixes, minor patches
Skill: /release-bump → Enter "1.0.1"
```

### Minor Release (1.0.0 → 1.1.0)  
```
Use when: New features (backward compatible)
Skill: /release-bump → Enter "1.1.0"
```

### Major Release (1.0.0 → 2.0.0)
```
Use when: Breaking changes
Skill: /release-bump → Enter "2.0.0"
```

## Integration with CI/CD

Once you push the tag, GitHub Actions automatically:

1. **Validates** version consistency
2. **Lints** all PHP files
3. **Builds** WordPress package
4. **Generates** SHA256 checksum
5. **Creates** GitHub Release
6. **Uploads** build artifacts (zip + checksum)

Monitor at: https://github.com/leighharro/stack2-wp/actions

## Related Documentation

- [RELEASING.md](/RELEASING.md) — Complete release workflow
- [.github/workflows/release-wordpress.yml](.github/workflows/release-wordpress.yml) — Automated build/publish
