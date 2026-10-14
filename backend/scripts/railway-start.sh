#!/bin/sh
# Railway start command (see railway.json). Run from the backend/ root.
set -e

# Everything under storage/ is meant to live on a Railway volume mounted at
# /app/storage, so uploads and logs survive redeploys. A fresh volume is
# empty, so recreate the folders Laravel and the app expect.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
  storage/logs storage/app/private/bill-documents storage/app/uploads

# Admin-uploaded catalog images (and bill documents from before 10/12) are
# written under public/uploads, which every deploy rebuilds from git. Move it
# onto the volume: seed the volume with the images that ship in git (never
# overwriting), then point public/uploads at it.
if [ ! -L public/uploads ]; then
  cp -Rn public/uploads/. storage/app/uploads/ 2>/dev/null || true
  rm -rf public/uploads
  ln -s ../storage/app/uploads public/uploads
fi

# Log to Railway's log viewer as well as the file on the volume, unless the
# dashboard already chose something else.
export LOG_STACK="${LOG_STACK:-single,stderr}"

php artisan migrate --force

# php artisan serve handles one request at a time unless it runs several
# workers, and the app polls every 1-2 seconds per open tab. Workers require
# --no-reload. Raise PHP_CLI_SERVER_WORKERS in the dashboard if needed.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php artisan serve --no-reload --host=0.0.0.0 --port="$PORT"
