# syntax=docker/dockerfile:1
# Production web image. Build context: src/. Dev uses the stock image + bind mounts instead.
# nginx-unprivileged: master runs as non-root `nginx`, listens on 8080.
FROM nginxinc/nginx-unprivileged:1.30.6-alpine

COPY .docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
# Static files + index.php (nginx only checks it exists; PHP-FPM executes its own copy).
COPY public /var/www/html/public

EXPOSE 8080
