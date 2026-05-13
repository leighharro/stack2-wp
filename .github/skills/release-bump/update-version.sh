#!/bin/bash

# Release bump helper script for Stack2 Connector
# Updates all version files to match the new version
#
# Usage: ./update-version.sh <new_version>
#   Example: ./update-version.sh 1.0.1

set -euo pipefail

# Color codes for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Validate arguments
if [[ $# -ne 1 ]]; then
    echo -e "${RED}Error: Version argument required${NC}"
    echo "Usage: $0 <new_version>"
    echo "Example: $0 1.0.1"
    exit 1
fi

NEW_VERSION="$1"

# Validate semantic versioning format
if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo -e "${RED}Error: Invalid version format '${NEW_VERSION}'${NC}"
    echo "Must be semantic versioning: X.Y.Z"
    exit 1
fi

PLUGIN_FILE="stack2-connector/stack2-connector.php"
README_FILE="stack2-connector/readme.txt"

# Check files exist
if [[ ! -f "$PLUGIN_FILE" ]]; then
    echo -e "${RED}Error: Plugin file not found: $PLUGIN_FILE${NC}"
    exit 1
fi

if [[ ! -f "$README_FILE" ]]; then
    echo -e "${RED}Error: Readme file not found: $README_FILE${NC}"
    exit 1
fi

# Get current version from plugin file
CURRENT_VERSION=$(grep -E '^ \* Version:' "$PLUGIN_FILE" | head -n1 | sed -E 's/^ \* Version:[[:space:]]*//')

if [[ -z "$CURRENT_VERSION" ]]; then
    echo -e "${RED}Error: Could not find current version in $PLUGIN_FILE${NC}"
    exit 1
fi

echo -e "${YELLOW}Current version: ${CURRENT_VERSION}${NC}"
echo -e "${YELLOW}New version: ${NEW_VERSION}${NC}"

# Update plugin file - Version header
sed -i.bak "s/^ \* Version: .*$/ * Version: ${NEW_VERSION}/" "$PLUGIN_FILE"
rm -f "${PLUGIN_FILE}.bak"
echo -e "${GREEN}✓ Updated plugin header version${NC}"

# Update plugin file - STACK2_CONNECTOR_VERSION constant
sed -i.bak "s/define('STACK2_CONNECTOR_VERSION', '[^']*')/define('STACK2_CONNECTOR_VERSION', '${NEW_VERSION}')/" "$PLUGIN_FILE"
rm -f "${PLUGIN_FILE}.bak"
echo -e "${GREEN}✓ Updated STACK2_CONNECTOR_VERSION constant${NC}"

# Update readme.txt - Stable tag
sed -i.bak "s/^Stable tag: .*$/Stable tag: ${NEW_VERSION}/" "$README_FILE"
rm -f "${README_FILE}.bak"
echo -e "${GREEN}✓ Updated readme.txt Stable tag${NC}"

echo -e "${GREEN}✓ All version files updated successfully${NC}"
echo ""
echo "Next steps:"
echo "  1. Review changes: git diff"
echo "  2. Update changelog in $README_FILE"
echo "  3. Commit: git add $PLUGIN_FILE $README_FILE"
echo "  4. Commit: git commit -m \"Release ${NEW_VERSION}\""
echo "  5. Tag: git tag v${NEW_VERSION}"
echo "  6. Push: git push origin main v${NEW_VERSION}"
