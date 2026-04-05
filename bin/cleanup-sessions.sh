#!/bin/bash
#
# Clean up expired MCP session files.
# Sessions older than 1 day are removed.
#
# Usage: bin/cleanup-sessions.sh
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SESSIONS_DIR="$(dirname "$SCRIPT_DIR")/data/sessions"

if [ ! -d "$SESSIONS_DIR" ]; then
	echo "Sessions directory not found: $SESSIONS_DIR"
	exit 0
fi

COUNT=$(find "$SESSIONS_DIR" -type f -mtime +1 | wc -l | tr -d ' ')
find "$SESSIONS_DIR" -type f -mtime +1 -delete

echo "Cleaned up $COUNT expired session(s)."
