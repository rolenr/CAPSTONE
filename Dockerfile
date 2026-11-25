FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev && docker-php-ext-install pdo_sqlite && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . .
RUN mv backend/database /opt/parking-seed && ln -s /var/data/database backend/database && cp deploy/apache.conf /etc/apache2/sites-available/000-default.conf
CMD ["sh", "deploy/start.sh"]
