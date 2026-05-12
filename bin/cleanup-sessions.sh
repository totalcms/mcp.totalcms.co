#!/bin/bash
#
# Clean up expired MCP session files.
#
# Sessions older than 60 minutes are removed (matches the SDK's default TTL).
# The SDK also runs probabilistic GC on every ~100th request, but high traffic
# can outpace that — run this from cron every 15 minutes for a hard floor:
#
#   */15 * * * * /path/to/mcp.totalcms.co/bin/cleanup-sessions.sh >/dev/null
#
# Usage: bin/cleanup-sessions.sh
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SESSIONS_DIR="$(dirname "$SCRIPT_DIR")/data/sessions"

if [ ! -d "$SESSIONS_DIR" ]; then
	echo "Sessions directory not found: $SESSIONS_DIR"
	exit 0
fi

COUNT=$(find "$SESSIONS_DIR" -type f -mmin +60 | wc -l | tr -d ' ')
find "$SESSIONS_DIR" -type f -mmin +60 -delete

echo "Cleaned up $COUNT expired session(s)."
