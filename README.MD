# docker-redcap

Docker Compose deployment of [REDCap](https://projectredcap.org/) with a custom External Module for chunked file upload to a [Girder](https://girder.readthedocs.io) server.

> **Author:** Josselin Duchateau — IHU Liryc, University of Bordeaux

---

## Stack

| Service | Image | Notes |
|---|---|---|
| `php` | Custom (`php:8.1-apache`) | REDCap application + External Modules |
| `mariadb` | `mariadb:latest` | REDCap database |

REDCap is served on host port **8081** (mapped to container port 80).

---

## Prerequisites

- Docker & Docker Compose
- A licensed REDCap zip placed and extracted under `redcap/`
- A running Girder instance reachable from the Docker network (e.g. `http://host.docker.internal:<port>/api/v1`)
- A Girder API key with write access to the target collection

---

## Configuration

Copy `example.env` to `.env` and fill in the values:

```env
MYSQL_USER=redcap
MYSQL_PASSWORD=<password>
MYSQL_DATABASE=redcap
MYSQL_ROOT_PASSWORD=<root_password>
SMTP_HOST=<smtp_host>
SMTP_PORT=25
SMTP_USER=<smtp_user>
SMTP_PASSWORD=<smtp_password>
REDCAP_SALT=<random_32_char_string>
TLS_CERT=<path_to_directory_containing_rootCA.pem>
```

PHP limits are configured in `redcap-overrides.ini`:

| Setting | Value |
|---|---|
| `upload_max_filesize` | 32 MB |
| `post_max_size` | 32 MB |
| `memory_limit` | 512 MB |
| `max_input_vars` | 100 000 |

The MariaDB container is started with `--max-allowed-packet=1G` to support large data imports.

---

## Running

```bash
# First run — initialises the database and configures REDCap
docker compose --env-file .env up --build -d

# Subsequent runs
docker compose --env-file .env up -d

# Logs
docker compose logs -f php
```

Access REDCap at: **http://localhost:8081**

---

## Girder Uploader External Module

The module is located in `girder_uploader_v1.0.0/` and is mounted directly into the container at `/var/www/html/modules/girder_uploader_v1.0.0`, so changes on the host are reflected immediately without rebuilding.

### How it works

All Girder API calls are made **server-side** (PHP cURL). The Girder API key is never sent to the browser. The flow is:

```
Browser                    REDCap PHP (backend)              Girder API
  |                               |                               |
  |-- init-upload (module.ajax) ->|-- POST /api_key/token ------->|
  |                               |<-- { authToken } -------------|
  |                               |-- POST /folder (DAG) -------->|
  |                               |-- POST /folder (record) ----->|
  |                               |-- POST /folder (instance) --->|
  |                               |-- POST /item ----------------->|
  |                               |-- POST /file (init upload) -->|
  |<-- { uploadId, itemId } ------|                               |
  |                               |                               |
  |-- upload-chunk (module.ajax)->|-- POST /file/chunk ---------->|
  |<-- { fileId } ----------------|                               |
```

Files are stored in Girder under:

```
<root collection> / <DAG> / <record_id> / <instance> / <fieldName_fileName>
```

### Enabling the module in REDCap

1. In REDCap Control Center → **External Modules**, enable **Girder Uploader** system-wide.
2. In your project → **External Modules**, enable and configure it with:

| Setting | Description |
|---|---|
| **Girder Backchannel API URL** | URL used by PHP backend, e.g. `http://host.docker.internal:8080/api/v1` |
| **Girder Frontchannel Base URL** | URL used for UI links, e.g. `https://girder.myserver.com` |
| **Girder API Key** | API key generated in Girder for your user |
| **Root Collection ID** | Girder collection ID where uploads are stored |
| **Chunk Size** | Bytes per chunk (default: 10 MB) |
| **Max Retries** | Retry count per chunk (default: 3) |
| **Retry Delay** | Milliseconds between retries (default: 1000) |

### Annotating fields

Add `@GIRDER_UPLOAD` to the **Field Annotation** of any `textarea` field (no validation type). The field will be replaced by an upload widget on the data entry form.

After a successful upload, the field stores a JSON payload recording the Girder item ID, folder path, file ID, and upload metadata.

### Advanced features

- **DICOM deidentification** — enable `deidentify-before-send` to run DICOM files through a Rust/WASM deidentification pipeline in a Web Worker before upload. Non-DICOM files are skipped.
- **ZIP expansion** — a single ZIP file is expanded client-side and uploaded as individual files preserving subfolder hierarchy.
- **Folder drag-and-drop** — recursive upload preserving subfolder structure.
- **Retry** — failed uploads can be retried from the widget without re-selecting files.

---

## Project structure

```
docker-redcap/
├── docker-compose.yml            # Main compose file
├── docker-compose-phpmyadmin.yml # Optional phpMyAdmin service
├── Dockerfile                    # PHP/Apache image with REDCap
├── docker-entrypoint.sh          # Init script (DB setup, REDCap config)
├── configure-db.sh               # Database configuration helper
├── redcap-overrides.ini          # PHP tuning (memory, upload limits)
├── ihu.env                       # Environment variable template
├── redcap/                       # REDCap source files (not committed)
└── girder_uploader_v1.0.0/       # Girder Uploader External Module
    ├── config.json               # EM manifest, settings and action tags
    ├── GirderUploaderModule.php  # Hook implementations and backend AJAX handlers
    ├── js/
    │   └── girder-uploader.js    # Frontend upload widget
    └── css/
        └── style.css             # Widget styles
```

---

## Debugging

### PHP / AJAX logs

Backend AJAX events are logged via the EM framework:  
**REDCap project → External Modules → View Logs**

Log messages are prefixed with `[AJAX]` and include action, field, upload IDs, byte counts, and errors.

### Browser console

All frontend steps emit `console.debug` messages prefixed with `[GirderUploader]`, including AJAX request/response, chunk progress, retries, and failures.

### Container connectivity

```bash
# Open a shell in the PHP container
docker exec -it redcap-php bash

# Test Girder reachability from inside the container
curl -v http://host.docker.internal:<girder-port>/api/v1/system/check
```

# Funding
This work was sponsored by the French Agence Nationale de la Recherche ANR-23-RHUS-0015
