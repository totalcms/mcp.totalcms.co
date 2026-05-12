#!/bin/bash
#
# Build the documentation index for mcp.totalcms.co.
#
# Usage:
#   bin/build.sh                    # Composer-install T3 into a temp dir and build from there
#   bin/build.sh /path/to/totalcms  # Build from an existing local T3 checkout
#
# Called by bin/deploy.sh on production; called directly with a path during development.
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

TOTALCMS_PATH="${1:-}"
TEMP_BUILD_DIR=""

cleanup() {
	if [ -n "$TEMP_BUILD_DIR" ] && [ -d "$TEMP_BUILD_DIR" ]; then
		echo "Cleaning up temporary T3 install at $TEMP_BUILD_DIR"
		rm -rf "$TEMP_BUILD_DIR"
	fi
}
trap cleanup EXIT

if [ -z "$TOTALCMS_PATH" ]; then
	echo "No T3 path provided — installing totalcms/cms via Composer..."
	TEMP_BUILD_DIR="$(mktemp -d -t mcp-t3-build-XXXXXX)"
	cd "$TEMP_BUILD_DIR"
	composer init --name=mcp-build/t3 --no-interaction --quiet
	composer require totalcms/cms --no-interaction --no-progress --quiet
	TOTALCMS_PATH="$TEMP_BUILD_DIR/vendor/totalcms/cms"
	cd "$PROJECT_DIR"
fi

if [ ! -d "$TOTALCMS_PATH/resources/docs" ]; then
	echo "Error: $TOTALCMS_PATH/resources/docs not found." >&2
	exit 1
fi

echo "Building documentation index from $TOTALCMS_PATH..."
php bin/build-index.php "$TOTALCMS_PATH"

# Bump mtime to invalidate the APCu cache
touch data/index.json

echo "Build complete."
