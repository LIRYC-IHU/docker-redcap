# Girder Uploader External Module for REDCap

This module turns `textarea` fields tagged with `@GIRDER_UPLOAD` into a Girder uploader widget.

It supports file upload, folder drag-and-drop, optional ZIP expansion, optional DICOM deidentification, and stores upload metadata directly in the REDCap field.

## Usage

1. Add `@GIRDER_UPLOAD` to a `textarea` field in your instrument.
2. Configure the module in Project Settings.
3. Open the data entry form and upload files from the widget.

## Project settings

- `girder-backchannel-api-url`: Girder API URL used by backend calls (must include `/api/v1`).
- `girder-frontchannel-base-url`: Girder base URL used for UI links (`#folder`, `#collection`).
- `api-key`: Girder API key used to obtain auth tokens.
- `root-collection-id`: Root Girder collection where uploads are stored.
- `chunk-size`: Backend chunk size for Girder file/chunk uploads.
- `max-retries`, `retry-delay`: Retry policy for backend upload attempts.
- `deidentify-before-send`: If enabled, only DICOM files are processed and uploaded after deidentification.

## Upload behavior

- Target path format:
  - `{DAG}/{record_id}/{field_name}`
  - if repeating instance > 1: `{DAG}/{record_id}/{field_name} -{instance_id}`
- Folder uploads preserve subfolder hierarchy in Girder.
- Hidden/temp files are filtered out (e.g. `.DS_Store`, dotfiles, `~*`).
- Single ZIP upload is expanded client-side and uploaded as file contents.
- ZIP files found in subfolders or during multi-file normal uploads are discarded.

## Metadata behavior

- Metadata is written in JSON format into the tagged REDCap field.
- Includes uploaded files list and Girder folder/item references.
- Includes direct frontchannel folder links for navigation.
- Failed uploads are persisted with error state and can be retried.

## Collection link

The module exposes a project menu link to the configured root Girder collection.  
The URL is resolved dynamically from project settings using the module link hook.

## Deidentification (DICOM)

When `deidentify-before-send` is enabled:

- Files are processed in a Web Worker through Rust/WASM.
- Non-DICOM files are skipped and not uploaded.
- DICOM values are anonymized with:
  - `PatientID` -> REDCap `record_id`
  - `PatientName` -> `{REDCAP_PROJECT_TITLE}^{record_id}`
  - date fields are preserved.

Build the WASM package before using deidentification:

```bash
wasm-pack build wasm/dicom_deid --target web --out-dir pkg
```
