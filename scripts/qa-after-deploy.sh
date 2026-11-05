#!/bin/sh
# Invoke from the verified deployed backend root, before promotion; preserve the exit code.
set -eu
: "${APP_RELEASE_SHA:?Set APP_RELEASE_SHA to the exact deployed commit}"
exec php artisan shopbonus:qa-staging-run after_deploy --sha="$APP_RELEASE_SHA"
