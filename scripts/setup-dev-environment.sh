#!/bin/bash

# Sets up a fresh macOS machine for stack2-wp local development.
#
# Installs/verifies: Homebrew, Node.js 20+, PHP 8.3+, a Docker CLI + daemon
# (Docker Desktop or OrbStack), then installs npm dependencies so wp-env and
# Docker Compose (see LOCAL_DEV.md) are ready to use.
#
# Usage: ./scripts/setup-dev-environment.sh

set -euo pipefail

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

info()  { echo -e "${BLUE}==>${NC} $1"; }
ok()    { echo -e "${GREEN}✓${NC} $1"; }
warn()  { echo -e "${YELLOW}!${NC} $1"; }
fail()  { echo -e "${RED}✗${NC} $1"; }

if [[ "$(uname -s)" != "Darwin" ]]; then
    fail "This script currently only supports macOS."
    exit 1
fi

# 1. Homebrew
info "Checking Homebrew..."
if ! command -v brew >/dev/null 2>&1; then
    warn "Homebrew not found. Installing..."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    eval "$(/opt/homebrew/bin/brew shellenv)"
else
    ok "Homebrew found ($(brew --version | head -n1))"
fi

# 2. Node.js 20+
info "Checking Node.js..."
NODE_OK=0
if command -v node >/dev/null 2>&1; then
    NODE_MAJOR="$(node -v | sed -E 's/^v([0-9]+).*/\1/')"
    if [[ "$NODE_MAJOR" -ge 20 ]]; then
        NODE_OK=1
        ok "Node.js $(node -v) found"
    else
        warn "Node.js $(node -v) found, but 20+ is required"
    fi
else
    warn "Node.js not found"
fi

if [[ "$NODE_OK" -eq 0 ]]; then
    info "Installing Node.js (LTS) via Homebrew..."
    brew install node
    ok "Node.js $(node -v) installed"
fi

# 3. PHP 8.3+ (matches .wp-env.json phpVersion, used for local `php -l` linting)
info "Checking PHP..."
PHP_OK=0
if command -v php >/dev/null 2>&1; then
    PHP_MAJOR_MINOR="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    if awk -v v="$PHP_MAJOR_MINOR" 'BEGIN { exit !(v >= 8.3) }'; then
        PHP_OK=1
        ok "PHP $(php -v | head -n1 | awk '{print $2}') found"
    else
        warn "PHP $PHP_MAJOR_MINOR found, but 8.3+ is recommended (matches .wp-env.json)"
    fi
else
    warn "PHP not found"
fi

if [[ "$PHP_OK" -eq 0 ]]; then
    info "Installing PHP via Homebrew..."
    brew install php
    ok "PHP $(php -v | head -n1 | awk '{print $2}') installed"
fi

# 4. Docker CLI + daemon (Docker Desktop or OrbStack both work)
info "Checking Docker..."
if ! command -v docker >/dev/null 2>&1; then
    warn "Docker CLI not found. Installing Docker Desktop via Homebrew..."
    brew install --cask docker
    warn "Open Docker Desktop once from /Applications to finish setup and start the daemon."
else
    ok "Docker CLI found ($(docker --version))"
fi

if command -v docker >/dev/null 2>&1; then
    if docker info >/dev/null 2>&1; then
        ok "Docker daemon is running"
    else
        warn "Docker CLI is installed but the daemon isn't running. Start Docker Desktop/OrbStack before running wp-env or docker compose."
    fi
fi

# 5. Project npm dependencies (@wordpress/env, etc.)
info "Installing npm dependencies..."
npm install
ok "npm dependencies installed"

echo ""
ok "Dev environment setup complete."
echo ""
echo "Next steps (see LOCAL_DEV.md):"
echo "  npm run wp-env:start     # start WordPress via wp-env, http://localhost:8888"
echo "  docker compose up -d     # or start WordPress via Docker Compose, http://localhost:8080"
