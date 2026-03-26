FROM webdevops/php-nginx:8.2
ENV WEB_DOCUMENT_ROOT=/app/public
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader
RUN mkdir -p storage bootstrap/cache
RUN chown -R application:application storage bootstrap/cache    