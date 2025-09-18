#!/bin/bash
# Wrapper script to run the standalone Matrix bot with environment loaded from project .env
set -euo pipefail

PROJECT_DIR="/var/www/matrix.example.com/html/laravel-flight-management"
cd "$PROJECT_DIR"

# If .env exists, export its variables for the script (simple loader, not a full dotenv parser)
if [ -f "$PROJECT_DIR/.env" ]; then
  set -o allexport
  # shellcheck disable=SC1090
  source "$PROJECT_DIR/.env"
  set +o allexport
fi

# Execute the PHP reader script (continuous mode)
exec /usr/bin/php "$PROJECT_DIR/standalone_matrix_bot/read_messages.php"
