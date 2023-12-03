<?php

namespace RefinedDigital\ShopifyHydrogen\Module\FlySystem;

use GuzzleHttp\Client;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Util\MimeType;
use League\Flysystem\Config;
use RefinedDigital\ShopifyHydrogen\Module\Enums\ResourceType;
use Shopify\ApiVersion;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;
use Shopify\Context;

/**
 * based on: https://gist.github.com/celsowhite/2e890966620bc781829b5be442bea159
 * additional references: https://github.com/RoyVoetman/flysystem-gitlab-storage/blob/v1.1.0/src/GitlabAdapter.php
 *
 */

class ShopifyHydrogenAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    protected $client;
    protected $gClient;

    public function __construct($config)
    {
        // setup the context to load
        Context::initialize(
            config('shopify-hydrogen.api_key'),
            config('shopify-hydrogen.token'),
            config('shopify-hydrogen.scopes'),
            config('shopify-hydrogen.domain'),
            new FileSessionStorage('/tmp/php_sessions'),
            ApiVersion::LATEST,
            false,
            true,
        );

        // create the client
        $this->client = new Graphql(config('shopify-hydrogen.domain'), config('shopify-hydrogen.token'));

        // create the guzzle client
        $this->gClient = new Client();

    }

    public function write($path, $contents, Config $config)
    {
        $pathBits = explode('/', $path);
        $filename = end($pathBits);

        $staged = $this->createStagedUpload($filename);
        $this->sendToTempTarget($staged, $contents);

        $upload = $this->upload($staged->resourceUrl, $filename);

        // $file = $this->getFileUrl('shopify/MediaImage/24228864688193');

        return $upload->data->fileCreate->files[0]->id;
    }

    public function writeStream($path, $resource, Config $config)
    {
        $url = $this->write($path, $resource, $config);
        // todo: don't use sessions for this
        session()->flash('shopify_hydrogen', $url);

        return $url;
    }

    public function update($path, $contents, Config $config)
    {
        \Log::info('update');
        // TODO: Implement update() method.
    }

    public function updateStream($path, $resource, Config $config)
    {
        \Log::info('update stream');
        // TODO: Implement updateStream() method.
    }

    public function rename($path, $newpath)
    {
        \Log::info('rename');
        // TODO: Implement rename() method.
    }

    public function copy($path, $newpath)
    {
        \Log::info('copy');
        // TODO: Implement copy() method.
    }

    public function delete($path)
    {
        \Log::info('delete');
        // TODO: Implement delete() method.
    }

    public function deleteDir($dirname)
    {
        \Log::info('delete dir');
        // TODO: Implement deleteDir() method.
    }

    public function createDir($dirname, Config $config)
    {
        \Log::info('create dir');
        // TODO: Implement createDir() method.
    }

    public function has($path)
    {
        // TODO: Implement has() method.
    }

    public function read($path)
    {
        \Log::info('read');
        // TODO: Implement read() method.
    }

    public function readStream($path)
    {
        \Log::info('read stream');
        // TODO: Implement readStream() method.
    }

    public function listContents($directory = '', $recursive = false)
    {
        \Log::info('list contents');
        // TODO: Implement listContents() method.
    }

    public function getMetadata($path)
    {
        // TODO: Implement getMetadata() method.
    }

    public function getSize($path)
    {
        \Log::info('get size');
        // TODO: Implement getSize() method.
    }

    public function getMimetype($path)
    {
        return MimeType::detectByFilename($path);
    }

    public function getTimestamp($path)
    {
        \Log::info('get timestamp');
        // TODO: Implement getTimestamp() method.
    }


    /**
     * Create staged upload.
     *
     * Shopify sets up temporary file targets in aws s3 buckets so we can host file data (images, videos, etc).
     *
     * @return false|\stdClass
     */
    private function createStagedUpload($filename)
    {
        // create the staged upload
        $query = <<<QUERY
mutation stagedUploadsCreate(\$input: [StagedUploadInput!]!) {
  stagedUploadsCreate(input: \$input) {
    stagedTargets {
      resourceUrl
      url
      parameters {
        name
        value
      }
    }
    userErrors {
      field
      message
    }
  }
}
QUERY;

        $variables = [
            'input' => [
                [
                    'filename' => $filename,
                    'mimeType' => $this->getMimetype($filename),
                    'httpMethod' => 'POST',
                    // Important to set this as FILE and not IMAGE. Or else when you try and create the file via Shopify's api there will be an error;
                    'resource' => ResourceType::FILE->value,
                ]
            ]
        ];

        $response = $this->client->query([
            'query' => $query,
            'variables' => $variables
        ]);

        $body = json_decode($response->getBody()->getContents());
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $returnData = new \stdClass();
        $target = $body->data->stagedUploadsCreate->stagedTargets[0];
        $returnData->target = $target;
        // Parameters contain all the sensitive info we'll need to interact with the aws bucket.
        $returnData->params = $target->parameters;
        // This is the url you'll use to post data to aws or google. It's a generic s3 url that when combined with the params sends your data to the right place.
        $returnData->url = $target->url;
        // This is the specific url that will contain your image data after you've uploaded the file to the aws staged target.
        $returnData->resourceUrl = $target->resourceUrl;

        return $returnData;
    }

    /**
     * Post to a temp target
     *
     * A temp target is a url hosted on Shopify's AWS servers.
     *
     * @return void
     */
    private function sendToTempTarget($target, $contents)
    {

        // Generate a form, add the necessary params and append the file.
        // Must use the FormData library to create form data via the server.
        $formData = [];
        foreach($target->params as $value) {
            $formData[$value->name] = $value->value;
        }

        $multipart = [];
        foreach ($formData as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }

        // add the file
        $multipart[] = ['name' => 'file', 'contents' => $contents];

        try {
            // Send the request
            $response = $this->gClient->request('POST', $target->url, ['multipart' => $multipart]);

        } catch (RequestException $e) {
            // echo 'Error uploading file: ' . $e->getMessage();
            return false;
        }


    }

    /**
     * Create the file
     *
     * Now that the file is prepared and accessible on the staged target, use the resource url from aws to create the file.
     *
     * @return void
     */
    private function upload($resourceUrl, $filename)
    {
        $query = <<<QUERY
mutation fileCreate(\$files: [FileCreateInput!]!) {
  fileCreate(files: \$files) {
    files {
      alt
      fileStatus
      createdAt
      ... on GenericFile {
        id
      }
      ... on MediaImage {
        id
      }
      ... on Video {
        id
      }
    }
    userErrors {
      field
      message
    }
  }
}
QUERY;

        $type = is_numeric(strpos($this->getMimetype($filename), 'image/'))
            ? ResourceType::IMAGE->value
            : ResourceType::FILE->value;

        $variables = [
            'files' => [
                'contentType' => $type,
                'originalSource' => $resourceUrl
            ]
        ];

        $response = $this->client->query([
            'query' => $query,
            'variables' => $variables
        ]);

        return json_decode($response->getBody()->getContents());
    }

}