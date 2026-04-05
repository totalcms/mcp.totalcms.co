#!/bin/bash
#
# Deploy script for mcp.totalcms.co
#
# Usage: bin/deploy.sh [/path/to/totalcms]
#
# Steps:
# 1. Install dependencies
# 2. Ensure sessions directory exists
# TODO: install T3 with composer when that is ready and remove index.json from the repo
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

echo "=== Deploying mcp.totalcms.co ==="

# Install dependencies
echo "Installing dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# Rebuild index
# echo "Building documentation index..."
# php bin/build-index.php "$TOTALCMS_PATH"

# Ensure sessions directory
mkdir -p data/sessions

echo ""
echo "=== Deploy complete ==="
