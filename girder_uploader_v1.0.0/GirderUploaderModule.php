<?php

namespace ExternalModules\GirderUploaderModule;

use ExternalModules\AbstractExternalModule;

class GirderUploaderModule extends AbstractExternalModule
{
    public function redcap_module_link_check_display($project_id, $link)
    {
        $settings = $this->loadSettings();

        if (is_array($link) && ($link['name'] ?? '') === 'Girder Collection') {
            if (!empty($settings['girderFrontchannelBaseUrl']) && !empty($settings['rootCollectionId'])) {
                $base = rtrim((string) $settings['girderFrontchannelBaseUrl'], '/');
                $id = rawurlencode((string) $settings['rootCollectionId']);

                // trailing ? makes appended params become hash-query suffix
                // e.g. #collection/<id>?&pid=16
                $link['url'] = "{$base}/#collection/{$id}?";
            } else {
                $link['url'] = '#';
            }
        }

        return $link;
    }

    public function redcap_data_entry_form ( int $project_id, string $record, string $instrument, int $event_id, int $group_id = NULL, int $repeat_instance = 1 )
    {
        $this->consolePrint('Hello from data entry form top!');
        $fields = $this->getGirderUploadFields($project_id, $instrument);
        if (empty($fields)) {
            return;
        }

        $settings = $this->loadSettings();
        if (empty($settings['girderUrl']) || empty($settings['girderFrontchannelBaseUrl']) || empty($settings['apiKey']) || empty($settings['rootCollectionId'])) {
            return;
        }

        $this->consolePrint('Getting context');
        $context = [
            'projectId' => $project_id,
            'projectTitle' => $this->getProjectTitle($project_id),
            'instrument' => $instrument,
            'recordId' => $record,
            'eventId' => $event_id,
            'instanceId' => $repeat_instance,
            'dagName' => $this->getDagName($group_id),
            'fields' => array_values($fields),
            'settings' => [
                'chunkSize' => (int) $settings['chunkSize'],
                'maxRetries' => (int) $settings['maxRetries'],
                'retryDelay' => (int) $settings['retryDelay'],
                'deidentifyBeforeSend' => (bool) $settings['deidentifyBeforeSend'],
                'preserveUploadFolderArchitecture' => (bool) $settings['preserveUploadFolderArchitecture'],
                'deidentifyWorkerUrl' => $this->getUrl('js/deidentify-worker.js', true),
            ],
        ];

        $this->consolePrint('Initializing Girder Uploader...');

        print($this->initializeJavascriptModuleObject());

        $this->consolePrint('Javascript module object initialized: ' . $this->getJavascriptModuleObjectName());
        print('<script>'. 'window.GirderUploaderConfig=' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';' . 'window.GirderUploaderModule=' . $this->getJavascriptModuleObjectName() . ';' . '</script>');
        if (file_exists(__DIR__ . '/js/vendor/jszip.min.js')) {
            $this->includeJs('./js/vendor/jszip.min.js');
        } else {
            // Fallback if vendor file is not deployed.
            echo '<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>';
        }
        $this->includeJs('./js/girder-uploader.js');
        $this->includeCSS('./css/style.css');
        $this->consolePrint('Girder Uploader initialized');
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        $this->debugAjax('Incoming AJAX action', [
            'action' => (string) $action,
            'project_id' => (int) $project_id,
            'record' => (string) $record,
            'instrument' => (string) $instrument,
            'event_id' => (int) $event_id,
            'repeat_instance' => (int) $repeat_instance,
            'user_id' => (string) $user_id,
            'group_id' => (int) $group_id,
        ]);

        if ($action === 'init-batch') {
            return $this->handleInitBatch($payload, (int) $project_id, (string) $instrument, $group_id, (string) $record, (int) $repeat_instance);
        }

        if ($action === 'upload-file') {
            return $this->handleUploadFile($payload, (int) $project_id, (string) $instrument);
        }

        $this->debugAjax('Unknown AJAX action', [
            'action' => (string) $action,
        ]);

        return [
            'ok' => false,
            'error' => 'Unknown action.',
        ];
    }

    private function handleInitBatch($payload, $projectId, $instrument, $groupId, $record, $repeatInstance)
    {
        $data = is_array($payload) ? $payload : [];
        $fieldName = isset($data['fieldName']) ? (string) $data['fieldName'] : '';
        $files = isset($data['files']) && is_array($data['files']) ? $data['files'] : [];

        $this->debugAjax('init-batch payload parsed', [
            'fieldName' => $fieldName,
            'fileCount' => count($files),
            'projectId' => (int) $projectId,
            'instrument' => (string) $instrument,
            'repeatInstance' => (int) $repeatInstance,
        ]);

        if ($fieldName === '' || empty($files)) {
            $this->debugAjax('init-batch rejected: invalid payload');
            return [
                'ok' => false,
                'error' => 'Invalid upload batch initialization payload.',
            ];
        }

        $allowedFields = $this->getGirderUploadFields($projectId, $instrument);
        if (!in_array($fieldName, $allowedFields, true)) {
            $this->debugAjax('init-batch rejected: unauthorized field', [
                'fieldName' => $fieldName,
            ]);
            return [
                'ok' => false,
                'error' => 'Field is not allowed for Girder upload.',
            ];
        }

        $settings = $this->loadSettings();
        if (empty($settings['girderUrl']) || empty($settings['girderFrontchannelBaseUrl']) || empty($settings['apiKey']) || empty($settings['rootCollectionId'])) {
            $this->debugAjax('init-batch rejected: incomplete settings');
            return [
                'ok' => false,
                'error' => 'Girder Uploader module settings are incomplete.',
            ];
        }

        try {
            $dagName = $this->sanitizePathPart($this->getDagName($groupId), 'NO_DAG');
            $recordId = $this->sanitizePathPart($record, 'UNASSIGNED_RECORD');
            $fieldFolderName = $this->sanitizePathPart($fieldName, 'upload');
            if ($repeatInstance > 1) {
                $fieldFolderName .= ' -' . $this->sanitizePathPart((string) $repeatInstance, '1');
            }

            $dagFolder = $this->ensureFolder($settings, 'collection', (string) $settings['rootCollectionId'], $dagName);
            $recordFolder = $this->ensureFolder($settings, 'folder', (string) $dagFolder['_id'], $recordId);
            $fieldFolder = $this->ensureFolder($settings, 'folder', (string) $recordFolder['_id'], $fieldFolderName);
            $dicomItemCacheByFolderId = [];
            $dicomFlatItemCacheBySourceFolder = [];
            $preserveUploadFolderArchitecture = !empty($settings['preserveUploadFolderArchitecture']);

            $uploads = [];
            foreach ($files as $index => $fileInfo) {
                if (!is_array($fileInfo)) {
                    throw new \Exception('Invalid file entry at index ' . $index . '.');
                }

                $relativePath = isset($fileInfo['relativePath']) ? (string) $fileInfo['relativePath'] : '';
                $fileName = isset($fileInfo['fileName']) ? (string) $fileInfo['fileName'] : '';
                $fileSize = isset($fileInfo['fileSize']) ? (int) $fileInfo['fileSize'] : 0;
                $mimeType = isset($fileInfo['mimeType']) ? (string) $fileInfo['mimeType'] : 'application/octet-stream';

                if ($relativePath === '' || $fileName === '' || $fileSize <= 0) {
                    throw new \Exception('Invalid file payload for batch item ' . $index . '.');
                }

                $isDicom = null;
                if (array_key_exists('isDicom', $fileInfo)) {
                    $rawIsDicom = $fileInfo['isDicom'];
                    if (is_bool($rawIsDicom)) {
                        $isDicom = $rawIsDicom;
                    } else {
                        $normalized = strtolower(trim((string) $rawIsDicom));
                        $isDicom = in_array($normalized, ['1', 'true', 'yes', 'on'], true);
                    }
                }

                $uploads[] = $this->createUploadForRelativePath(
                    $settings,
                    $fieldFolder,
                    $relativePath,
                    $fileName,
                    $fileSize,
                    $mimeType,
                    $dagName,
                    $recordId,
                    $repeatInstance,
                    $dicomItemCacheByFolderId,
                    $isDicom,
                    $preserveUploadFolderArchitecture,
                    $dicomFlatItemCacheBySourceFolder
                );
            }

            $this->debugAjax('init-batch completed', [
                'fieldName' => $fieldName,
                'uploadCount' => count($uploads),
                'parentFolderId' => (string) $fieldFolder['_id'],
            ]);

            return [
                'ok' => true,
                'batch' => [
                    'parentFolderId' => (string) $fieldFolder['_id'],
                    'dagName' => $dagName,
                    'recordId' => $recordId,
                    'instanceId' => $repeatInstance > 0 ? (string) $repeatInstance : '1',
                    'girderUrl' => (string) $settings['girderUrl'],
                    'girderFrontchannelBaseUrl' => (string) $settings['girderFrontchannelBaseUrl'],
                    'rootCollectionId' => (string) $settings['rootCollectionId'],
                    'uploads' => $uploads,
                ],
            ];
        } catch (\Throwable $exception) {
            $this->debugAjax('init-batch failed', [
                'fieldName' => $fieldName,
                'error' => $exception->getMessage(),
            ]);
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function handleUploadFile($payload, $projectId, $instrument)
    {
        $data = is_array($payload) ? $payload : [];
        $fieldName = isset($data['fieldName']) ? (string) $data['fieldName'] : '';
        $uploadId = isset($data['uploadId']) ? (string) $data['uploadId'] : '';
        $fileBase64 = isset($data['fileBase64']) ? (string) $data['fileBase64'] : '';

        $this->debugAjax('upload-file payload parsed', [
            'fieldName' => $fieldName,
            'uploadId' => $uploadId,
            'fileBase64Length' => strlen($fileBase64),
            'projectId' => (int) $projectId,
            'instrument' => (string) $instrument,
        ]);

        if ($fieldName === '' || $uploadId === '' || $fileBase64 === '') {
            $this->debugAjax('upload-file rejected: invalid payload');
            return [
                'ok' => false,
                'error' => 'Invalid file upload payload.',
            ];
        }

        $allowedFields = $this->getGirderUploadFields($projectId, $instrument);
        if (!in_array($fieldName, $allowedFields, true)) {
            $this->debugAjax('upload-file rejected: unauthorized field', [
                'fieldName' => $fieldName,
            ]);
            return [
                'ok' => false,
                'error' => 'Field is not allowed for Girder upload.',
            ];
        }

        $binaryFile = base64_decode($fileBase64, true);
        if ($binaryFile === false) {
            $this->debugAjax('upload-file rejected: base64 decode failed', [
                'uploadId' => $uploadId,
            ]);
            return [
                'ok' => false,
                'error' => 'File decoding failed.',
            ];
        }

        $this->debugAjax('upload-file decoded', [
            'uploadId' => $uploadId,
            'fileBytes' => strlen($binaryFile),
        ]);

        $settings = $this->loadSettings();
        if (empty($settings['girderUrl']) || empty($settings['girderFrontchannelBaseUrl']) || empty($settings['apiKey']) || empty($settings['rootCollectionId'])) {
            $this->debugAjax('upload-file rejected: incomplete settings');
            return [
                'ok' => false,
                'error' => 'Girder Uploader module settings are incomplete.',
            ];
        }

        try {
            $fileEntity = $this->uploadBinaryFileInChunks($settings, $uploadId, $binaryFile);
            $this->debugAjax('upload-file completed', [
                'uploadId' => $uploadId,
                'fileId' => isset($fileEntity['_id']) ? (string) $fileEntity['_id'] : null,
            ]);
            return [
                'ok' => true,
                'file' => $fileEntity,
            ];
        } catch (\Throwable $exception) {
            $this->debugAjax('upload-file failed', [
                'uploadId' => $uploadId,
                'error' => $exception->getMessage(),
            ]);
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function loadSettings()
    {
        $backchannelApiUrl = $this->normalizeApiUrl((string) $this->getProjectSetting('girder-backchannel-api-url'));
        $frontchannelBaseUrl = $this->normalizeBaseUrl((string) $this->getProjectSetting('girder-frontchannel-base-url'));

        // Backward compatibility fallback for older setting keys.
        if ($backchannelApiUrl === '') {
            $backchannelApiUrl = $this->normalizeApiUrl((string) $this->getProjectSetting('girder-url'));
        }
        if ($frontchannelBaseUrl === '') {
            $frontchannelBaseUrl = $this->normalizeBaseUrl((string) $this->getProjectSetting('girder-base-url'));
        }
        if ($frontchannelBaseUrl === '' && $backchannelApiUrl !== '') {
            $frontchannelBaseUrl = $this->deriveBaseUrlFromLegacyApiUrl($backchannelApiUrl);
        }

        $settings = [
            'girderUrl' => $backchannelApiUrl,
            'girderFrontchannelBaseUrl' => $frontchannelBaseUrl,
            'apiKey' => (string) $this->getProjectSetting('api-key'),
            'rootCollectionId' => (string) $this->getProjectSetting('root-collection-id'),
            'chunkSize' => $this->readIntSetting('chunk-size', 10485760),
            'maxRetries' => $this->readIntSetting('max-retries', 3),
            'retryDelay' => $this->readIntSetting('retry-delay', 1000),
            'deidentifyBeforeSend' => $this->readBoolSetting('deidentify-before-send', false),
            'preserveUploadFolderArchitecture' => $this->readBoolSettingTreatEmptyAsFalse('preserve-upload-folder-architecture', true),
        ];
        return $settings;
    }

    private function getGirderUploadFields($projectId, $instrument)
    {
        if (!class_exists('REDCap')) {
            return [];
        }

        $metadata = \REDCap::getDataDictionary($projectId, 'array');
        if (!is_array($metadata)) {
            return [];
        }

        $fields = [];
        foreach ($metadata as $fieldName => $field) {
            if (!is_array($field)) {
                continue;
            }

            $formName = isset($field['form_name']) ? (string) $field['form_name'] : '';
            if ($formName !== $instrument) {
                continue;
            }

            $annotation = isset($field['field_annotation']) ? strtoupper((string) $field['field_annotation']) : '';
            if ($annotation === '' || strpos($annotation, '@GIRDER_UPLOAD') === false) {
                continue;
            }

            $validation = isset($field['text_validation_type_or_show_slider_number'])
                ? strtolower((string) $field['text_validation_type_or_show_slider_number'])
                : '';

            if ($validation !== '' && $validation !== 'none') {
                continue;
            }

            $fields[] = (string) $fieldName;
        }

        return $fields;
    }

    private function getDagName($group_id)
	{
		if (empty($group_id)) {
			return null;
		}

		$group_name = \REDCap::getGroupNames(FALSE, $group_id);
		return $group_name ? $group_name : null;
	}

    private function sanitizePathPart($value, $fallback)
    {
        $text = trim((string) $value);
        if ($text === '') {
            return (string) $fallback;
        }

        $text = preg_replace('/[\\\\\/:*?"<>|]/', '_', $text);
        $text = preg_replace('/\s+/', ' ', (string) $text);
        $text = trim((string) $text);

        return $text !== '' ? $text : (string) $fallback;
    }

    private function splitRelativePath($relativePath)
    {
        $relativePath = str_replace('\\', '/', (string) $relativePath);
        $relativePath = trim($relativePath);
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '') {
            return ['uploaded-file'];
        }

        $rawParts = explode('/', $relativePath);
        $parts = [];
        foreach ($rawParts as $part) {
            $part = $this->sanitizePathPart($part, '');
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        if (empty($parts)) {
            return ['uploaded-file'];
        }

        return $parts;
    }

    private function ensureFolder($settings, $parentType, $parentId, $name)
    {
        $query = http_build_query([
            'parentType' => $parentType,
            'parentId' => $parentId,
            'name' => $name,
            'reuseExisting' => 'true',
        ]);

        return $this->girderRequestJson($settings, 'POST', '/folder?' . $query, null, [
            'Content-Type: text/plain',
        ]);
    }

    private function ensureItem($settings, $folderId, $name)
    {
        $query = http_build_query([
            'folderId' => $folderId,
            'name' => $name,
            'reuseExisting' => 'true',
        ]);

        return $this->girderRequestJson($settings, 'POST', '/item?' . $query, null, [
            'Content-Type: text/plain',
        ]);
    }

    private function initUpload($settings, $itemId, $fileName, $fileSize, $mimeType)
    {
        $query = http_build_query([
            'parentType' => 'item',
            'parentId' => $itemId,
            'name' => $fileName,
            'size' => (int) $fileSize,
            'mimeType' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
        ]);

        return $this->girderRequestJson($settings, 'POST', '/file?' . $query, null, [
            'Content-Type: text/plain',
        ]);
    }

    private function uploadChunk($settings, $uploadId, $offset, $binaryChunk)
    {
        $query = http_build_query([
            'uploadId' => $uploadId,
            'offset' => (int) $offset,
        ]);

        return $this->girderRequestJson($settings, 'POST', '/file/chunk?' . $query, $binaryChunk, [
            'Content-Type: application/octet-stream',
        ]);
    }

    private function uploadBinaryFileInChunks($settings, $uploadId, $binaryFile)
    {
        $chunkSize = isset($settings['chunkSize']) ? (int) $settings['chunkSize'] : 10485760;
        if ($chunkSize <= 0) {
            $chunkSize = 10485760;
        }

        $offset = 0;
        $fileLength = strlen($binaryFile);
        $lastFileEntity = null;

        while ($offset < $fileLength) {
            $chunk = substr($binaryFile, $offset, $chunkSize);
            if ($chunk === '' || $chunk === false) {
                break;
            }

            $lastFileEntity = $this->uploadChunk($settings, $uploadId, $offset, $chunk);
            $offset += strlen($chunk);
        }

        if (!is_array($lastFileEntity)) {
            throw new \Exception('No file response was returned by Girder after chunked upload.');
        }

        return $lastFileEntity;
    }

    private function createUploadForRelativePath(
        $settings,
        $fieldFolder,
        $relativePath,
        $fileName,
        $fileSize,
        $mimeType,
        $dagName,
        $recordId,
        $repeatInstance,
        &$dicomItemCacheByFolderId = [],
        $isDicomHint = null,
        $preserveUploadFolderArchitecture = true,
        &$dicomFlatItemCacheBySourceFolder = []
    )
    {
        $pathParts = $this->splitRelativePath($relativePath);
        $leafFileName = $this->sanitizePathPart((string) end($pathParts), $fileName);
        $subfolders = array_slice($pathParts, 0, max(0, count($pathParts) - 1));
        $sourceFolderKey = empty($subfolders) ? '.' : implode('/', $subfolders);
        $isDicom = is_bool($isDicomHint) ? $isDicomHint : $this->isDicomFile($leafFileName, $mimeType);

        $targetFolder = $fieldFolder;
        if ($preserveUploadFolderArchitecture || !$isDicom) {
            foreach ($subfolders as $segment) {
                $targetFolder = $this->ensureFolder($settings, 'folder', (string) $targetFolder['_id'], $segment);
            }
        }

        $targetFolderId = (string) $targetFolder['_id'];

        if ($isDicom) {
            if (!is_array($dicomItemCacheByFolderId)) {
                $dicomItemCacheByFolderId = [];
            }
            if (!is_array($dicomFlatItemCacheBySourceFolder)) {
                $dicomFlatItemCacheBySourceFolder = [];
            }

            if ($preserveUploadFolderArchitecture) {
                if (!isset($dicomItemCacheByFolderId[$targetFolderId]) || !is_array($dicomItemCacheByFolderId[$targetFolderId])) {
                    $dicomItemCacheByFolderId[$targetFolderId] = $this->ensureItem($settings, $targetFolderId, 'DICOM_DATA');
                }
                $item = $dicomItemCacheByFolderId[$targetFolderId];
            } else {
                $flatTargetFolderId = (string) $fieldFolder['_id'];
                if (!isset($dicomFlatItemCacheBySourceFolder[$sourceFolderKey]) || !is_array($dicomFlatItemCacheBySourceFolder[$sourceFolderKey])) {
                    $nextIndex = count($dicomFlatItemCacheBySourceFolder) + 1;
                    $dicomFlatItemCacheBySourceFolder[$sourceFolderKey] = $this->ensureItem($settings, $flatTargetFolderId, 'DICOM_DATA_' . $nextIndex);
                }
                $targetFolderId = $flatTargetFolderId;
                $targetFolder = $fieldFolder;
                $item = $dicomFlatItemCacheBySourceFolder[$sourceFolderKey];
            }
        } else {
            $item = $this->ensureItem($settings, $targetFolderId, $leafFileName);
        }

        $upload = $this->initUpload($settings, (string) $item['_id'], $leafFileName, $fileSize, $mimeType);

        return [
            'relativePath' => (string) $relativePath,
            'uploadId' => (string) $upload['_id'],
            'itemId' => (string) $item['_id'],
            'folderId' => (string) $targetFolder['_id'],
            'parentFolderId' => (string) $fieldFolder['_id'],
            'dagName' => $dagName,
            'recordId' => $recordId,
            'instanceId' => $repeatInstance > 0 ? (string) $repeatInstance : '1',
            'girderUrl' => (string) $settings['girderUrl'],
            'girderFrontchannelBaseUrl' => (string) $settings['girderFrontchannelBaseUrl'],
            'rootCollectionId' => (string) $settings['rootCollectionId'],
        ];
    }

    private function isDicomFile($fileName, $mimeType)
    {
        $fileName = strtolower(trim((string) $fileName));
        $mimeType = strtolower(trim((string) $mimeType));

        if ($mimeType !== '' && (strpos($mimeType, 'dicom') !== false || $mimeType === 'application/dicom')) {
            return true;
        }

        if ($fileName === '') {
            return false;
        }

        if (substr($fileName, -4) === '.dcm') {
            return true;
        }

        return false;
    }

    private function girderRequestJson($settings, $method, $pathWithQuery, $body, $extraHeaders = [])
    {
        $token = $this->getGirderAuthToken($settings, false);
        $response = $this->girderRequestWithToken($settings, $method, $pathWithQuery, $body, $extraHeaders, $token);

        if ((int) $response['statusCode'] === 401) {
            $this->clearCachedGirderAuthToken($settings);
            $token = $this->getGirderAuthToken($settings, true);
            $response = $this->girderRequestWithToken($settings, $method, $pathWithQuery, $body, $extraHeaders, $token);
        }

        $responseBody = (string) $response['body'];
        $statusCode = (int) $response['statusCode'];

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \Exception('Girder request failed (' . $statusCode . '): ' . $responseBody);
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new \Exception('Girder returned an invalid JSON response.');
        }

        return $decoded;
    }

    private function girderRequestWithToken($settings, $method, $pathWithQuery, $body, $extraHeaders, $authToken)
    {
        $headers = array_merge([
            'Girder-Token: ' . (string) $authToken,
            'Accept: application/json',
        ], is_array($extraHeaders) ? $extraHeaders : []);

        return $this->executeCurlRequest(
            rtrim((string) $settings['girderUrl'], '/') . (string) $pathWithQuery,
            $method,
            $headers,
            $body
        );
    }

    private function getGirderAuthToken($settings, $forceRefresh = false)
    {
        if (!$forceRefresh) {
            $cachedToken = $this->getCachedGirderAuthToken($settings);
            if ($cachedToken !== null && $cachedToken !== '') {
                return $cachedToken;
            }
        }

        $apiKey = (string) $settings['apiKey'];
        if ($apiKey === '') {
            throw new \Exception('Girder API key is missing.');
        }

        $tokenResponse = $this->requestGirderTokenByApiKey((string) $settings['girderUrl'], $apiKey);
        $authToken = $this->extractAuthTokenValue($tokenResponse);

        if ($authToken === '') {
            throw new \Exception('Girder token response does not contain authToken.');
        }

        $expiresAt = $this->extractTokenExpiryTimestamp($tokenResponse);
        $this->cacheGirderAuthToken($settings, $authToken, $expiresAt);
        return $authToken;
    }

    private function requestGirderTokenByApiKey($girderUrl, $apiKey)
    {
        $baseUrl = rtrim((string) $girderUrl, '/');
        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $response = $this->executeCurlRequest(
            $baseUrl . '/api_key/token',
            'POST',
            $headers,
            http_build_query(['key' => $apiKey])
        );

        if ((int) $response['statusCode'] < 200 || (int) $response['statusCode'] >= 300) {
            $fallback = $this->executeCurlRequest(
                $baseUrl . '/api_key/token',
                'POST',
                $headers,
                http_build_query(['apiKey' => $apiKey])
            );
            $response = $fallback;
        }

        if ((int) $response['statusCode'] < 200 || (int) $response['statusCode'] >= 300) {
            throw new \Exception('Girder token request failed (' . (int) $response['statusCode'] . '): ' . (string) $response['body']);
        }

        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            throw new \Exception('Girder token endpoint returned invalid JSON.');
        }

        return $decoded;
    }

    private function executeCurlRequest($url, $method, $headers, $body = null)
    {
        $methodUpper = strtoupper((string) $method);
        $ch = curl_init((string) $url);
        if ($ch === false) {
            throw new \Exception('Failed to initialize cURL.');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, (string) $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, is_array($headers) ? $headers : []);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif (in_array($methodUpper, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new \Exception('Girder request failed: ' . $curlError);
        }

        return [
            'statusCode' => $statusCode,
            'body' => (string) $responseBody,
        ];
    }

    private function tokenCacheKey($settings)
    {
        $raw = rtrim((string) $settings['girderUrl'], '/') . '|' . (string) $settings['apiKey'];
        return hash('sha256', $raw);
    }

    private function getCachedGirderAuthToken($settings)
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }

        if (!isset($_SESSION['girderUploaderAuthTokens']) || !is_array($_SESSION['girderUploaderAuthTokens'])) {
            return null;
        }

        $key = $this->tokenCacheKey($settings);
        $entry = isset($_SESSION['girderUploaderAuthTokens'][$key]) ? $_SESSION['girderUploaderAuthTokens'][$key] : null;
        if (!is_array($entry)) {
            return null;
        }

        $token = isset($entry['token']) ? trim((string) $entry['token']) : '';
        if ($token === '') {
            return null;
        }

        $expiresAt = isset($entry['expiresAt']) ? (int) $entry['expiresAt'] : 0;
        if ($expiresAt > 0 && time() >= max(0, $expiresAt - 30)) {
            unset($_SESSION['girderUploaderAuthTokens'][$key]);
            return null;
        }

        return $token;
    }

    private function cacheGirderAuthToken($settings, $token, $expiresAt = 0)
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }

        if (!isset($_SESSION['girderUploaderAuthTokens']) || !is_array($_SESSION['girderUploaderAuthTokens'])) {
            $_SESSION['girderUploaderAuthTokens'] = [];
        }

        $_SESSION['girderUploaderAuthTokens'][$this->tokenCacheKey($settings)] = [
            'token' => (string) $token,
            'expiresAt' => (int) $expiresAt,
        ];
    }

    private function clearCachedGirderAuthToken($settings)
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        if (!isset($_SESSION['girderUploaderAuthTokens']) || !is_array($_SESSION['girderUploaderAuthTokens'])) {
            return;
        }

        $key = $this->tokenCacheKey($settings);
        unset($_SESSION['girderUploaderAuthTokens'][$key]);
    }

    private function extractTokenExpiryTimestamp($tokenResponse)
    {
        if (!is_array($tokenResponse)) {
            return time() + 3600;
        }

        if (isset($tokenResponse['authToken']) && is_array($tokenResponse['authToken'])) {
            $nestedAuthToken = $tokenResponse['authToken'];
            if (isset($nestedAuthToken['expires']) && is_string($nestedAuthToken['expires'])) {
                $parsed = strtotime($nestedAuthToken['expires']);
                if ($parsed !== false && $parsed > 0) {
                    return (int) $parsed;
                }
            }
        }

        $knownIntegerKeys = ['expiresAt', 'expires_at', 'expiry', 'expiration', 'expires'];
        foreach ($knownIntegerKeys as $key) {
            if (isset($tokenResponse[$key]) && is_numeric($tokenResponse[$key])) {
                $value = (int) $tokenResponse[$key];
                if ($value > 0) {
                    return $value > 2000000000 ? (int) floor($value / 1000) : $value;
                }
            }
        }

        $knownDurationKeys = ['duration', 'ttl', 'expiresIn', 'expires_in'];
        foreach ($knownDurationKeys as $key) {
            if (isset($tokenResponse[$key]) && is_numeric($tokenResponse[$key])) {
                $duration = (int) $tokenResponse[$key];
                if ($duration > 0) {
                    return time() + $duration;
                }
            }
        }

        return time() + 3600;
    }

    private function extractAuthTokenValue($tokenResponse)
    {
        if (!is_array($tokenResponse)) {
            return '';
        }

        if (isset($tokenResponse['authToken']) && is_string($tokenResponse['authToken'])) {
            return trim($tokenResponse['authToken']);
        }

        if (isset($tokenResponse['authToken']) && is_array($tokenResponse['authToken'])) {
            $authTokenObject = $tokenResponse['authToken'];
            if (isset($authTokenObject['token']) && is_string($authTokenObject['token'])) {
                return trim($authTokenObject['token']);
            }
        }

        if (isset($tokenResponse['token']) && is_string($tokenResponse['token'])) {
            return trim($tokenResponse['token']);
        }

        return '';
    }

    private function normalizeApiUrl($url)
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        return rtrim($url, '/');
    }

    private function normalizeBaseUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        return rtrim($url, '/');
    }

    private function deriveBaseUrlFromLegacyApiUrl($legacyApiUrl)
    {
        $legacyApiUrl = $this->normalizeApiUrl($legacyApiUrl);
        if ($legacyApiUrl === '') {
            return '';
        }

        $suffix = '/api/v1';
        if (substr($legacyApiUrl, -strlen($suffix)) === $suffix) {
            return substr($legacyApiUrl, 0, -strlen($suffix));
        }

        return $legacyApiUrl;
    }

    private function readIntSetting($key, $default)
    {
        $value = $this->getProjectSetting($key);
        if ($value === null || $value === '') {
            return (int) $default;
        }

        if (!is_numeric($value)) {
            return (int) $default;
        }

        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : (int) $default;
    }

    private function readBoolSetting($key, $default = false)
    {
        $value = $this->getProjectSetting($key);
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return (bool) $default;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return (bool) $default;
    }

    private function readBoolSettingTreatEmptyAsFalse($key, $default = false)
    {
        $value = $this->getProjectSetting($key);
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return (bool) $default;
        }
        if ($value === '') {
            return false;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        if ($normalized === '') {
            return false;
        }

        return (bool) $default;
    }

    private function getProjectTitle($projectId)
    {
        $projectId = (int) $projectId;
        if ($projectId <= 0) {
            return '';
        }

        $sql = "SELECT app_title FROM redcap_projects WHERE project_id = {$projectId} LIMIT 1";
        $result = db_query($sql);
        if ($result && ($row = db_fetch_assoc($result))) {
            return isset($row['app_title']) ? trim((string) $row['app_title']) : '';
        }

        return '';
    }

    private function consolePrint($message)
    // Print to javascript console if in web context, or to stdout if in CLI context
    {
        if (php_sapi_name() === 'cli') {
            echo '[GirderUploaderModule] ' . $message . PHP_EOL;
        } else {
            echo '<script>console.log("[GirderUploaderModule] ' . addslashes($message) . '");</script>';
        }
    }

    private function debugAjax($message, $context = [])
    {
        $parameters = [];
        if (is_array($context)) {
            foreach ($context as $key => $value) {
                $parameterKey = preg_replace('/[^A-Za-z0-9 _\-$]/', '_', (string) $key);
                if ($parameterKey === '') {
                    continue;
                }

                if (is_scalar($value) || $value === null) {
                    $parameters[$parameterKey] = $value;
                    continue;
                }

                $parameters[$parameterKey] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $this->log('[AJAX] ' . (string) $message, $parameters);
    }

    private function includeJs($path)
    {
        echo '<script src="' . $this->getUrl($path, true) . '"></script>';
    }

    private function includeCSS($path)
    {
        echo '<link rel="stylesheet" type="text/css" href="' . $this->getUrl($path, true) . '">';
    }
}
