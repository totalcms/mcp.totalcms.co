#!/bin/bash
#
# Deploy mcp.totalcms.co. Run on the server (via webhook or manually).
#
# Usage: bin/deploy.sh
#
# Steps:
#   1. composer install --no-dev
#   2. Rebuild the documentation index from the latest totalcms/cms on Packagist
#   3. Ensure data/sessions exists
#
# For local development, run bin/build.sh /path/to/local/totalcms directly to
# rebuild the index against a checkout in progress.
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

echo "=== Deploying mcp.totalcms.co ==="

echo "Installing dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "Rebuilding documentation index from Packagist..."
bin/build.sh

mkdir -p data/sessions

echo ""
echo "=== Deploy complete ==="
