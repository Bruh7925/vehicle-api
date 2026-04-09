# Dockerfile
# This uses a pre-built image with PHP-FPM and Nginx, perfect for Laravel on Render
FROM richarvey/nginx-php-fpm:latest

# Switch to root user to install packages
USER root

# Install any additional packages your app might need
# (e.g., Node.js if you use Laravel Mix or Vite)
RUN apk update && \
    apk add --no-cache curl nodejs npm && \
    npm install -g npm@latest

# Copy your application code into the container
COPY . .

# Image configuration
ENV SKIP_COMPOSER 1
ENV WEBROOT /var/www/html/public
ENV PHP_ERRORS_STDERR 1
ENV RUN_SCRIPTS 1
ENV REAL_IP_HEADER 1

# Laravel configuration
ENV APP_ENV production
ENV APP_DEBUG false
ENV LOG_CHANNEL stderr

# Allow Composer to run as root/superuser
ENV COMPOSER_ALLOW_SUPERUSER 1

# Start the Nginx and PHP-FPM processes
CMD ["/start.sh"]
