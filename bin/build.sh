#!/bin/bash
#
# Deploy script for mcp.totalcms.co
#
# Usage: bin/deploy.sh [/path/to/totalcms]
#
# Steps:
# 1. Install dependencies
# 2. Rebuild documentation index
# 3. Ensure sessions directory exists
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
TOTALCMS_PATH="${1:?Usage: bin/build.sh /path/to/totalcms}"

cd "$PROJECT_DIR"

echo "=== Building mcp.totalcms.co ==="

# Install dependencies
echo "Installing dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# Rebuild index
echo "Building documentation index..."
php bin/build-index.php "$TOTALCMS_PATH"

# Ensure sessions directory
mkdir -p data/sessions

echo ""
echo "=== Build complete ==="
