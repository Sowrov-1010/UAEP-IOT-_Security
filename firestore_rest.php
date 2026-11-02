<?php
declare(strict_types=1);

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

final class FirestoreRest
{
    private Client $client;
    private ServiceAccountCredentials $credentials;
    private string $projectId;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(string $serviceAccountPath)
    {
        $serviceAccount = json_decode((string)file_get_contents($serviceAccountPath), true);
        if (!is_array($serviceAccount) || empty($serviceAccount['project_id'])) {
            throw new RuntimeException('The Firebase service-account file is invalid or has no project_id.');
        }
        $this->projectId = (string)$serviceAccount['project_id'];
        $this->credentials = new ServiceAccountCredentials(
            ['https://www.googleapis.com/auth/datastore'],
            $serviceAccount
        );
        $this->client = new Client([
            'base_uri' => 'https://firestore.googleapis.com/v1/',
            'timeout' => 20,
        ]);
    }

    public function listCollection(string $collection): array
    {
        $documents = [];
        $pageToken = null;
        do {
            $query = ['pageSize' => 1000];
            if ($pageToken !== null) $query['pageToken'] = $pageToken;
            $result = $this->request('GET', $this->documentsPath() . '/' . rawurlencode($collection), ['query' => $query]);
            foreach ($result['documents'] ?? [] as $document) $documents[] = $this->decodeDocument($document);
            $pageToken = $result['nextPageToken'] ?? null;
        } while ($pageToken !== null);
        return $documents;
    }

    public function query(string $collection, ?string $field = null, bool $descending = true, int $limit = 0, string $deviceId = '', ?string $filterField = null, $filterValue = null): array
    {
        $structuredQuery = ['from' => [['collectionId' => $collection]]];
        if ($deviceId !== '') {
            $structuredQuery['where'] = ['fieldFilter' => [
                'field' => ['fieldPath' => 'device_id'],
                'op' => 'EQUAL',
                'value' => $this->encodeValue($deviceId),
            ]];
        } elseif ($filterField !== null) {
            $structuredQuery['where'] = ['fieldFilter' => [
                'field' => ['fieldPath' => $filterField],
                'op' => 'EQUAL',
                'value' => $this->encodeValue($filterValue),
            ]];
        }
        if ($field !== null) {
            $structuredQuery['orderBy'] = [[
                'field' => ['fieldPath' => $field],
                'direction' => $descending ? 'DESCENDING' : 'ASCENDING',
            ]];
        }
        if ($limit > 0) $structuredQuery['limit'] = $limit;
        $results = $this->request('POST', $this->documentsPath() . ':runQuery', [
            'json' => ['structuredQuery' => $structuredQuery],
        ]);
        $documents = [];
        foreach ($results as $result) {
            if (isset($result['document'])) $documents[] = $this->decodeDocument($result['document']);
        }
        return $documents;
    }

    public function count(string $collection): int
    {
        $result = $this->request('POST', $this->documentsPath() . ':runAggregationQuery', [
            'json' => ['structuredAggregationQuery' => [
                'structuredQuery' => ['from' => [['collectionId' => $collection]]],
                'aggregations' => [['count' => (object)[], 'alias' => 'total']],
            ]],
        ]);
        return (int)($result[0]['result']['aggregateFields']['total']['integerValue'] ?? 0);
    }

    public function getDocument(string $collection, string $id): ?array
    {
        $document = $this->request('GET', $this->documentPath($collection, $id), [], true);
        return $document === null ? null : $this->decodeDocument($document);
    }

    public function createDocument(string $collection, array $data): string
    {
        $document = $this->request('POST', $this->documentsPath() . '/' . rawurlencode($collection), [
            'json' => ['fields' => $this->encodeFields($data)],
        ]);
        return $this->documentId($document['name'] ?? '');
    }

    public function setDocument(string $collection, string $id, array $data, bool $merge = false): void
    {
        if ($merge) $data = array_replace($this->getDocument($collection, $id) ?? [], $data);
        $document = ['fields' => $this->encodeFields($data)];
        if ($this->getDocument($collection, $id) === null) {
            $this->request('POST', $this->documentsPath() . '/' . rawurlencode($collection), [
                'query' => ['documentId' => $id],
                'json' => $document,
            ]);
            return;
        }
        $this->request('PATCH', $this->documentPath($collection, $id), ['json' => $document]);
    }

    public function deleteDocument(string $collection, string $id): void
    {
        $this->request('DELETE', $this->documentPath($collection, $id));
    }

    private function request(string $method, string $path, array $options = [], bool $notFoundIsNull = false): ?array
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Accept' => 'application/json',
        ]);
        try {
            $response = $this->client->request($method, $path, $options);
        } catch (RequestException $error) {
            $status = $error->getResponse()?->getStatusCode() ?? 0;
            if ($notFoundIsNull && $status === 404) return null;
            $body = $error->getResponse()?->getBody()->getContents() ?? '';
            $details = json_decode($body, true);
            $message = $details['error']['message'] ?? 'Firestore REST request failed.';
            throw new RuntimeException($message, $status, $error);
        }
        $body = (string)$response->getBody();
        if ($body === '') return [];
        $result = json_decode($body, true);
        if (!is_array($result)) throw new RuntimeException('Firestore returned an invalid JSON response.');
        return $result;
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt) return $this->accessToken;
        $token = $this->credentials->fetchAuthToken();
        if (empty($token['access_token'])) throw new RuntimeException('Google OAuth did not return an access token.');
        $this->accessToken = (string)$token['access_token'];
        $this->tokenExpiresAt = time() + max(60, (int)($token['expires_in'] ?? 3600) - 60);
        return $this->accessToken;
    }

    private function documentsPath(): string
    {
        return 'projects/' . rawurlencode($this->projectId) . '/databases/(default)/documents';
    }

    private function documentPath(string $collection, string $id): string
    {
        return $this->documentsPath() . '/' . rawurlencode($collection) . '/' . rawurlencode($id);
    }

    private function documentId(string $name): string
    {
        return rawurldecode((string)substr($name, strrpos($name, '/') + 1));
    }

    private function decodeDocument(array $document): array
    {
        $data = [];
        foreach ($document['fields'] ?? [] as $field => $value) $data[$field] = $this->decodeValue($value);
        $data['id'] = $this->documentId($document['name'] ?? '');
        return $data;
    }

    private function encodeFields(array $data): array
    {
        $fields = [];
        foreach ($data as $field => $value) $fields[$field] = $this->encodeValue($value);
        return $fields;
    }

    private function encodeValue($value): array
    {
        if ($value === null) return ['nullValue' => null];
        if (is_bool($value)) return ['booleanValue' => $value];
        if (is_int($value)) return ['integerValue' => (string)$value];
        if (is_float($value)) return ['doubleValue' => $value];
        if (is_string($value)) return ['stringValue' => $value];
        if (is_array($value)) {
            if (array_is_list($value)) {
                return ['arrayValue' => ['values' => array_map(fn($item) => $this->encodeValue($item), $value)]];
            }
            return ['mapValue' => ['fields' => $this->encodeFields($value)]];
        }
        throw new InvalidArgumentException('Unsupported Firestore field value type.');
    }

    private function decodeValue(array $value)
    {
        if (array_key_exists('nullValue', $value)) return null;
        if (array_key_exists('booleanValue', $value)) return (bool)$value['booleanValue'];
        if (array_key_exists('integerValue', $value)) return (int)$value['integerValue'];
        if (array_key_exists('doubleValue', $value)) return (float)$value['doubleValue'];
        if (array_key_exists('stringValue', $value)) return $value['stringValue'];
        if (array_key_exists('timestampValue', $value)) return $value['timestampValue'];
        if (array_key_exists('referenceValue', $value)) return $value['referenceValue'];
        if (array_key_exists('bytesValue', $value)) return $value['bytesValue'];
        if (array_key_exists('mapValue', $value)) {
            $map = [];
            foreach ($value['mapValue']['fields'] ?? [] as $field => $nested) $map[$field] = $this->decodeValue($nested);
            return $map;
        }
        if (array_key_exists('arrayValue', $value)) {
            return array_map(fn($item) => $this->decodeValue($item), $value['arrayValue']['values'] ?? []);
        }
        return null;
    }
}
