<?php

namespace Preprio;

use GuzzleHttp\Client;
use Cache;
use Artisan;
use lastguest\Murmur;
use Session;

class Prepr
{
    protected $baseUrl;
    protected $path;
    protected $query;
    protected $rawQuery;
    protected $method;
    protected $params = [];
    protected $response;
    protected $rawResponse;
    protected $request;
    protected $authorization;
    protected $file = null;
    protected $statusCode;
    protected $userId;

    private $chunkSize = 26214400;

    public function __construct($authorization = null, $userId = null, $baseUrl = 'https://api.eu1.prepr.io/')
    {
        $this->baseUrl = $baseUrl;
        $this->authorization = $authorization;

        if($userId) {
            $this->userId = $this->hashUserId($userId);
        }
    }

    protected function client()
    {
        return new Client([
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $this->authorization,
                'Prepr-ABTesting' => $this->userId
            ]
        ]);
    }

    protected function request($options = [])
    {
        $url = $this->baseUrl . $this->path;

        $this->client = $this->client();

        $data = [
            'form_params' => $this->params,
        ];

        if ($this->method == 'post') {
            $data = [
                'multipart' => $this->nestedArrayToMultipart($this->params),
            ];
        }

        $this->request = $this->client->request($this->method, $url . $this->query, $data);

        $this->rawResponse = $this->request->getBody()->getContents();
        $this->response = json_decode($this->rawResponse, true);

        // Files larger then 25 MB (upload chunked)
        if ($this->data_get($this->file, 'chunks') > 1 && ($this->getStatusCode() === 201 || $this->getStatusCode() === 200)) {
            return $this->processFileUpload();
        }

        return $this;
    }

    public function authorization($authorization)
    {
        $this->authorization = $authorization;

        return $this;
    }

    public function url($url)
    {
        $this->baseUrl = $url;

        return $this;
    }

    public function get()
    {
        $this->method = 'get';

        return $this->request();
    }

    public function post()
    {
        $this->method = 'post';

        return $this->request();
    }

    public function put()
    {
        $this->method = 'put';

        return $this->request();
    }

    public function delete()
    {
        $this->method = 'delete';

        return $this->request();
    }

    public function path($path = null, array $array = [])
    {
        foreach ($array as $key => $value) {
            $path = str_replace('{' . $key . '}', $value, $path);
        }

        $this->path = $path;

        return $this;
    }

    public function method($method = null)
    {
        $this->method = $method;

        return $this;
    }

    public function query(array $array)
    {
        $this->rawQuery = $array;
        $this->query = '?' . http_build_query($array);

        return $this;
    }

    public function params(array $array)
    {
        $this->params = $array;

        return $this;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getRawResponse()
    {
        return $this->rawResponse;
    }

    public function getStatusCode()
    {
        if($this->statusCode) {
            return $this->statusCode;
        }
        return $this->request->getStatusCode();
    }

    public function file($filepath)
    {
        $fileSize = filesize($filepath);
        $file = fopen($filepath, 'r');

        $this->file = [
            'path' => $filepath,
            'size' => $fileSize,
            'file' => $file,
            'chunks' => ($fileSize / $this->chunkSize),
            'original_name' => basename($filepath),
        ];

        if ($this->file) {

            // Files larger then 25 MB (upload chunked)
            if ($this->data_get($this->file, 'chunks') > 1) {

                $this->params['upload_phase'] = 'start';
                $this->params['file_size'] = $this->data_get($this->file, 'size');

                // Files smaller then 25 MB (upload directly)
            } else {
                $this->params['source'] = $this->data_get($this->file, 'file');
            }
        }

        return $this;
    }

    private function processFileUpload()
    {
        $id = $this->data_get($this->response, 'id');
        $fileSize = $this->data_get($this->file, 'size');

        for ($i = 0; $i <= $this->data_get($this->file, 'chunks'); $i++) {

            $offset = ($this->chunkSize * $i);
            $endOfFile = (($offset + $this->chunkSize) > $fileSize ? true : false);

            $original = \GuzzleHttp\Psr7\stream_for($this->data_get($this->file, 'file'));
            $stream = new \GuzzleHttp\Psr7\LimitStream($original, ($endOfFile ? ($fileSize - $offset) : $this->chunkSize), $offset);

            $this->params['upload_phase'] = 'transfer';
            $this->params['file_chunk'] = $stream;

            $prepr = (new Prepr($this->authorization,$this->userId,$this->baseUrl))
                ->path('assets/{id}/multipart', [
                    'id' => $id,
                ])
                ->params($this->params)
                ->post();

            if ($prepr->getStatusCode() !== 200) {
                return $prepr;
            }
        }

        $this->params['upload_phase'] = 'finish';

        return (new Prepr($this->authorization,$this->userId,$this->baseUrl))
            ->path('assets/{id}/multipart', [
                'id' => $id,
            ])
            ->params($this->params)
            ->post();
    }

    public function autoPaging()
    {
        $this->method = 'get';

        $perPage = 100;
        $page = 0;
        $queryLimit = $this->data_get($this->rawQuery, 'limit');

        $arrayItems = [];

        while(true) {

            $query = $this->rawQuery;

            $query['limit'] = $perPage;
            $query['offset'] = $page*$perPage;

            $result = (new Prepr($this->authorization,$this->userId,$this->baseUrl))
                ->path($this->path)
                ->query($query)
                ->get();

            if($result->getStatusCode() == 200) {

                $items = $this->data_get($result->getResponse(),'items');
                if($items) {

                    foreach($items as $item) {
                        $arrayItems[] = $item;

                        if (count($arrayItems) == $queryLimit) {
                            break;
                        }
                    }

                    if(count($items) == $perPage) {
                        $page++;
                        continue;
                    } else {
                        break;
                    }

                } else {
                    break;
                }
            } else {
                return $result;
            }
        }

        $this->response = [
            'items' => $arrayItems,
            'total' => count($arrayItems)
        ];
        $this->statusCode = 200;

        return $this;
    }

    public function hashUserId($userId)
    {
        $hashValue = Murmur::hash3_int($userId, 1);
        $ratio = $hashValue / pow(2, 32);
        return intval($ratio*10000);
    }

    public function userId($userId)
    {
        $this->userId = $this->hashUserId($userId);

        return $this;
    }

    public function nestedArrayToMultipart($array)
    {
        $flatten = function ($array, $original_key = '') use (&$flatten) {
            $output = [];
            foreach ($array as $key => $value) {
                $new_key = $original_key;
                if (empty($original_key)) {
                    $new_key .= $key;
                } else {
                    $new_key .= '[' . $key . ']';
                }

                if (is_array($value)) {
                    $output = array_merge($output, $flatten($value, $new_key));
                } else {
                    $output[$new_key] = $value;
                }
            }

            return $output;
        };

        $flat_array = $flatten($array);

        $multipart = [];
        foreach ($flat_array as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => $value,
            ];
        }

        return $multipart;
    }

    public function data_get($array, $variable)
    {
        if(isset($array[$variable])) {
            return $array[$variable];
        }
    }
}