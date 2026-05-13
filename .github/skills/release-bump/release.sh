#!/bin/bash

# Full release automation script for Stack2 Connector
# Handles version bumping, changelog update, commit, tag, and push
#
# Usage: ./release.sh <new_version> [--push]
#   Example: ./release.sh 1.0.1
#   Example: ./release.sh 1.0.1 --push

set -euo pipefail

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Parse arguments
NEW_VERSION="${1:-}"
SHOULD_PUSH="${2:-}"

if [[ -z "$NEW_VERSION" ]]; then
    echo -e "${RED}Error: Version required${NC}"
    echo "Usage: $0 <new_version> [--push]"
    exit 1
fi

# Validate version format
if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo -e "${RED}Error: Invalid version format '${NEW_VERSION}'${NC}"
    echo "Must be semantic versioning: X.Y.Z"
    exit 1
fi

PLUGIN_FILE="stack2-connector/stack2-connector.php"
README_FILE="stack2-connector/readme.txt"
RELEASE_TAG="v${NEW_VERSION}"

# Check we're on main
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ "$CURRENT_BRANCH" != "main" ]]; then
    echo -e "${YELLOW}Warning: You're on branch '$CURRENT_BRANCH', not 'main'${NC}"
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Check for uncommitted changes
if ! git diff-index --quiet HEAD --; then
    echo -e "${RED}Error: Uncommitted changes exist${NC}"
    echo "Please commit or stash changes before releasing"
    exit 1
fi

# Get current version
CURRENT_VERSION=$(grep -E '^ \* Version:' "$PLUGIN_FILE" | head -n1 | sed -E 's/^ \* Version:[[:space:]]*//')

echo -e "${BLUE}Release Process${NC}"
echo "================="
echo "Current version: $CURRENT_VERSION"
echo "New version: $NEW_VERSION"
echo "Release tag: $RELEASE_TAG"
echo ""

# Step 1: Update versions
echo -e "${BLUE}Step 1: Updating version files...${NC}"

sed -i.bak "s/^ \* Version: .*$/ * Version: ${NEW_VERSION}/" "$PLUGIN_FILE"
rm -f "${PLUGIN_FILE}.bak"

sed -i.bak "s/define('STACK2_CONNECTOR_VERSION', '[^']*')/define('STACK2_CONNECTOR_VERSION', '${NEW_VERSION}')/" "$PLUGIN_FILE"
rm -f "${PLUGIN_FILE}.bak"

sed -i.bak "s/^Stable tag: .*$/Stable tag: ${NEW_VERSION}/" "$README_FILE"
rm -f "${README_FILE}.bak"

echo -e "${GREEN}✓ Version files updated${NC}"

# Step 2: Show diff
echo ""
echo -e "${BLUE}Step 2: Review changes${NC}"
git diff "$PLUGIN_FILE" "$README_FILE"

# Step 3: Prompt to update changelog
echo ""
echo -e "${YELLOW}Please update the Changelog section in $README_FILE${NC}"
echo "Add entry: == Version $NEW_VERSION =="
echo ""
read -p "Press enter when changelog is updated, or Ctrl+C to cancel: "

# Step 4: Commit
echo ""
echo -e "${BLUE}Step 3: Creating commit...${NC}"
git add "$PLUGIN_FILE" "$README_FILE"
git commit -m "Release ${NEW_VERSION}" --no-verify || {
    echo -e "${RED}Commit failed${NC}"
    exit 1
}
echo -e "${GREEN}✓ Commit created${NC}"

# Step 5: Create tag
echo ""
echo -e "${BLUE}Step 4: Creating tag...${NC}"
git tag "$RELEASE_TAG" || {
    echo -e "${RED}Tag creation failed${NC}"
    git reset HEAD~1
    exit 1
}
echo -e "${GREEN}✓ Tag created: $RELEASE_TAG${NC}"

# Step 6: Push (optional)
echo ""
if [[ "$SHOULD_PUSH" == "--push" ]]; then
    echo -e "${BLUE}Step 5: Pushing to remote...${NC}"
    git push origin main "$RELEASE_TAG" || {
        echo -e "${RED}Push failed${NC}"
        echo "To rollback: git reset HEAD~1 && git tag -d $RELEASE_TAG"
        exit 1
    }
    echo -e "${GREEN}✓ Pushed to origin${NC}"
else
    echo -e "${YELLOW}Not pushing (use --push to auto-push)${NC}"
    echo ""
    echo "To push when ready:"
    echo "  git push origin main $RELEASE_TAG"
fi

echo ""
echo -e "${GREEN}✓ Release ${NEW_VERSION} complete!${NC}"
echo ""
echo "Next:"
echo "  1. Monitor GitHub Actions: https://github.com/leighharro/stack2-wp/actions"
echo "  2. Verify release assets are created"
echo "  3. Download and test the release zip if needed"
