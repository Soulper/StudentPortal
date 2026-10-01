#!/data/data/com.termux/files/usr/bin/bash
# Start the Student Portal on Linux / Termux (Android) / macOS.
#
# On a tablet or phone this is the only thing you need to run. Keep the
# terminal open (or run `termux-wake-lock` first) so Android doesn't kill it.
set -e
cd "$(dirname "$0")"

PORT="${PORT:-8000}"

command -v php >/dev/null 2>&1 || {
    echo "PHP is not installed."
    echo "  Termux (Android):  pkg install php"
    echo "  Debian/Ubuntu:     sudo apt install php-cli php-sqlite3 php-zip php-mbstring"
    exit 1
}

# The gradebook needs these; fail loudly rather than crashing later.
for ext in pdo_sqlite sqlite3 mbstring zip; do
    php -m | grep -qix "$ext" || echo "warning: PHP extension '$ext' is missing"
done

# SQLite cannot be opened from Android's shared storage (/sdcard), because
# that filesystem does not support the file locking SQLite requires. So keep
# the project inside Termux's private directory. If you copied it to
# Downloads or /storage/emulated/0, move it back:
#     cp -r ~/storage/downloads/StudentPortal ~/www/StudentPortal

DB="${DB_PATH:-$PWD/school_portal.db}"
UP="${UPLOAD_DIR:-$PWD/uploads}"
mkdir -p "$(dirname "$DB")" "$UP"

echo "Student Portal"
echo "  database: $DB"
echo "  uploads:  $UP"
echo
echo "  Open this on the same device:   http://localhost:$PORT"
echo "  Stop with Ctrl+C"
echo

# -S binds localhost only, which is all you need when the browser is on the
# same device as the server.
exec php \
    -d upload_max_filesize=25M \
    -d post_max_size=26M \
    -S "localhost:$PORT"
