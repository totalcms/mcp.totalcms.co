#!/bin/bash
#
# Build the documentation index for mcp.totalcms.co.
#
# Usage:
#   bin/build.sh                    # Shallow-clone T3 from GitHub and build from there
#   bin/build.sh /path/to/totalcms  # Build from an existing local T3 checkout
#
# Called by bin/deploy.sh on production; called directly with a path during development.
#
# The git-clone path uses TOTALCMS_BRANCH (default: develop) because Composer
# can't install totalcms/cms from Packagist — it requires a VCS fork
# (joeworkman-forks/couleur) that Composer ignores from non-root packages.
# The MCP server only needs resources/docs/ from T3, so a shallow clone is
# faster and simpler than a full composer install anyway.
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

TOTALCMS_PATH="${1:-}"
TOTALCMS_REPO="${TOTALCMS_REPO:-https://github.com/totalcms/cms.git}"
TOTALCMS_BRANCH="${TOTALCMS_BRANCH:-develop}"
TEMP_BUILD_DIR=""

cleanup() {
	if [ -n "$TEMP_BUILD_DIR" ] && [ -d "$TEMP_BUILD_DIR" ]; then
		echo "Cleaning up temporary T3 checkout at $TEMP_BUILD_DIR"
		rm -rf "$TEMP_BUILD_DIR"
	fi
}
trap cleanup EXIT

if [ -z "$TOTALCMS_PATH" ]; then
	echo "No T3 path provided — shallow-cloning $TOTALCMS_REPO ($TOTALCMS_BRANCH)..."
	TEMP_BUILD_DIR="$(mktemp -d -t mcp-t3-build-XXXXXX)"
	git clone --depth=1 --branch="$TOTALCMS_BRANCH" --quiet "$TOTALCMS_REPO" "$TEMP_BUILD_DIR/totalcms"
	TOTALCMS_PATH="$TEMP_BUILD_DIR/totalcms"
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
