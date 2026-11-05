FROM agent-center-central:recovery8-final
COPY --chown=www-data:www-data app/public/agent-center.css app/public/agent-center.js /var/www/html/public/
COPY --chown=www-data:www-data app/resources/views/layout.blade.php /var/www/html/resources/views/layout.blade.php
COPY --chown=www-data:www-data app/resources/views/dashboard/content.blade.php /var/www/html/resources/views/dashboard/content.blade.php
