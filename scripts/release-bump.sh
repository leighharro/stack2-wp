#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <version> [--dry-run]"
  exit 1
fi

version="$1"
shift || true

dry_run=0
if [[ "${1:-}" == "--dry-run" ]]; then
  dry_run=1
fi

validate_semver() {
  local value="$1"
  if [[ "$value" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    return 0
  fi
  return 1
}

if ! validate_semver "$version"; then
  echo "Invalid version '$version'. Use semantic versioning like 1.2.3."
  exit 1
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "This script must be run from inside the git repository."
  exit 1
fi

current_branch="$(git branch --show-current)"
if [[ "$current_branch" != "main" ]]; then
  echo "Release bumps must be run from the main branch. Current branch: $current_branch"
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Working tree is not clean. Commit or stash your changes before creating a release."
  exit 1
fi

plugin_file="stack2-connector/stack2-connector.php"
readme_file="stack2-connector/readme.txt"

if [[ ! -f "$plugin_file" || ! -f "$readme_file" ]]; then
  echo "Expected release files were not found."
  exit 1
fi

python3 - "$plugin_file" "$readme_file" "$version" <<'PY'
from pathlib import Path
import re
import sys

plugin_path = Path(sys.argv[1])
readme_path = Path(sys.argv[2])
version = sys.argv[3]

plugin_content = plugin_path.read_text()
plugin_content = re.sub(r"^ \* Version: .*$", f" * Version: {version}", plugin_content, count=1, flags=re.M)
plugin_content = re.sub(
    r"define\('STACK2_CONNECTOR_VERSION', '.*?'\)",
    f"define('STACK2_CONNECTOR_VERSION', '{version}')",
    plugin_content,
    count=1,
)
plugin_path.write_text(plugin_content)

readme_content = readme_path.read_text()
readme_content = re.sub(r"^Stable tag: .*$", f"Stable tag: {version}", readme_content, count=1, flags=re.M)
if f"= {version} =" not in readme_content:
    readme_content = readme_content.replace(
        "== Changelog ==\n\n",
        f"== Changelog ==\n\n= {version} =\n- Release {version}.\n\n",
        1,
    )
readme_path.write_text(readme_content)
PY

echo "Updated plugin version to $version"

git diff --stat -- "$plugin_file" "$readme_file"

if [[ "$dry_run" -eq 1 ]]; then
  echo "Dry run complete. No commit, tag, or push was created."
  exit 0
fi

git add "$plugin_file" "$readme_file"
git commit -m "Release $version"

tag_name="v$version"
git tag "$tag_name"

echo "Pushing release branch and tag to origin..."
git push origin main
git push origin "$tag_name"

echo "Release $version has been pushed. GitHub Actions should start for tag $tag_name."
