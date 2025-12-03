# REDCap Docker Setup

This Docker Compose configuration sets up REDCap with MariaDB and CloudBeaver for database management.

## Services

### 1. PHP/Apache (REDCap)
- **Container:** `redcap-php`
- **Port:** 80
- **Description:** Runs the REDCap application with PHP 8.1 and Apache
- **Database Connection:** Configured to connect to MariaDB container

### 2. MariaDB (Database)
- **Container:** `redcap-mariadb`
- **Port:** 3306
- **Description:** MariaDB database server for REDCap data storage
- **Data Persistence:** Stored in `mariadb_data` volume

### 3. CloudBeaver (Database Management Tool)
- **Container:** `redcap-cloudbeaver`
- **Port:** 8978
- **URL:** `http://localhost:8978`
- **Description:** Web-based database management tool for manual database modifications

## Quick Start

1. **Clone or navigate to the workspace:**
   ```bash
   cd /Users/josselin.duchateau/Code/docker-redcap
   ```

2. **Start all services:**
   ```bash
   docker-compose up -d
   ```

3. **Access the services:**
   - **REDCap:** http://localhost
   - **CloudBeaver:** http://localhost:8978

4. **Stop all services:**
   ```bash
   docker-compose down
   ```

5. **View logs:**
   ```bash
   docker-compose logs -f [service_name]
   # Example: docker-compose logs -f php
   ```

## Database Configuration

Default credentials (configured in `docker-compose.yml`):
- **MySQL Root User:** root
- **Root Password:** root_password
- **REDCap User:** redcap_user
- **REDCap Password:** redcap_password
- **Database Name:** redcap_db

## CloudBeaver Setup

1. Access CloudBeaver at http://localhost:8978
2. Create a new connection to MariaDB:
   - **Host:** mariadb
   - **Port:** 3306
   - **Username:** redcap_user
   - **Password:** redcap_password
   - **Database:** redcap_db

## Customization

To modify environment variables, edit the `docker-compose.yml` file or create a `.env` file based on `.env.example`.

## Important Notes

- The REDCap folder is mounted as a volume, allowing for live editing of files
- All data is persisted in Docker volumes
- Ensure Docker and Docker Compose are installed on your system
- For production use, change all default passwords in the configuration

## Troubleshooting

If you encounter connection issues:
- Ensure all containers are running: `docker-compose ps`
- Check container logs: `docker-compose logs [service_name]`
- Verify network connectivity: `docker network ls`
- Restart services: `docker-compose restart`
