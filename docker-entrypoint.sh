#!/bin/bash

# Entrypoint script that configures database.php and then starts Apache

# Export environment variables to /etc/environment so they are available to cron jobs
printenv | grep -v "no_proxy" >> /etc/environment

# SSMTP configuration for sending emails from the container
cat <<EOF > /etc/ssmtp/ssmtp.conf
root=postmaster
mailhub=${SMTP_HOST:-localhost}:${SMTP_PORT:-25}
hostname=$(hostname)
FromLineOverride=YES
EOF

# Add SMTP authentication if credentials are provided
if [ -n "$SMTP_USER" ] && [ -n "$SMTP_PASSWORD" ]; then
    echo "AuthUser=${SMTP_USER}" >> /etc/ssmtp/ssmtp.conf
    echo "AuthPass=${SMTP_PASSWORD}" >> /etc/ssmtp/ssmtp.conf
    echo "UseSTARTTLS=${SMTP_TLS:-YES}" >> /etc/ssmtp/ssmtp.conf
else
    echo "No SMTP credentials provided, skipping authentication config."
fi

# Configure PHP to use ssmtp for sending emails
echo "sendmail_path = /usr/sbin/ssmtp -t" > $PHP_INI_DIR/conf.d/sendmail.ini

# Run database configuration to modify database.php
/usr/local/bin/configure-db.sh

# Start cron in the background
service cron start

# Start Apache
apache2-foreground
