# Deploys app root and creates the DB if necessary
/app/.heroku/php/bin/php /app/vendor/sforsman/dhelper/bin/deploy.php

# Check for custom deployment script and remove it
if [ -f /app/deploy.php ]; then
  /app/.heroku/php/bin/php /app/deploy.php
  rm -f /app/deploy.php
fi

# These are removed for security reasons
unset PW_DB_ADMIN_USER
unset PW_DB_ADMIN_PASS
unset PW_ADMIN_USER
unset PW_ADMIN_PASS

