ARG BASE_IMAGE
FROM ${BASE_IMAGE}
USER root
COPY overlay/ /var/www/html/
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction \
    && chown -R www-data:www-data bootstrap/cache
USER www-data
ENV DEVICE_READONLY_LAB_ENABLED=false DEVICE_ANALYSIS_LAB_ENABLED=false
