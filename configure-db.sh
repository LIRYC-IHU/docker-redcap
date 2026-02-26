#!/bin/bash

# Configure database.php to use environment variables
# This script modifies database.php to use getenv() for database configuration

DB_FILE="/var/www/html/database.php"

# Check if the file exists
if [ ! -f "$DB_FILE" ]; then
    echo "Error: $DB_FILE not found"
    exit 1
fi

# Check if already configured by looking for getenv pattern
if grep -q "getenv('MYSQL_HOST')" "$DB_FILE"; then
    echo "Database configuration already applied"
    exit 0
fi

# Create a temporary file
TEMP_FILE="$DB_FILE.tmp"

# Use sed to replace the database configuration lines with getenv() calls
# Using more flexible patterns to handle whitespace variations
sed -e "s/\\\$hostname   = '';/\$hostname   = getenv('MYSQL_HOST') ?: 'localhost';/" \
    -e "s/\\\$db     \t= '';/\$db     \t= getenv('MYSQL_DATABASE') ?: 'redcap_db';/" \
    -e "s/\\\$username   = '';/\$username   = getenv('MYSQL_USER') ?: 'redcap_user';/" \
    -e "s/\\\$password   = '';/\$password   = getenv('MYSQL_PASSWORD') ?: 'redcap_password';/" \
    -e "s/\\\$salt = '';/\$salt = getenv('REDCAP_SALT') ?: '';/" \
    "$DB_FILE" > "$TEMP_FILE"

# Check if sed was successful
if [ $? -eq 0 ]; then
    # Replace the original file
    mv "$TEMP_FILE" "$DB_FILE"
    echo "Database configuration modified successfully to use environment variables"
else
    echo "Error: Failed to configure database.php"
    rm -f "$TEMP_FILE"
    exit 1
fi
