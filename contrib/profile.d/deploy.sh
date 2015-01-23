# Deploys app root and creates the DB if necessary
/app/.heroku/php/bin/php /app/deploy.php

# This is not needed after deployment is complete
rm -f /app/deploy.php

# These are removed for security reasons
unset PW_DB_ADMIN_USER
unset PW_DB_ADMIN_PASS
unset PW_ADMIN_USER
unset PW_ADMIN_PASS

