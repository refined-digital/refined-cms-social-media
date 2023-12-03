<?php

namespace RefinedDigital\ShopifyHydrogen\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use DB;
use File;
use RefinedDigital\CMS\Modules\Media\Models\Media;
use RefinedDigital\CMS\Modules\Media\Events\MediaFileUpdated;
use Shopify\ApiVersion;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;
use Shopify\Context;
use Str;

class SyncShopifyHydrogenMedia extends Command
{
    protected $client;

    public function __construct()
    {
        if (config('shopify-hydrogen.api_key')) {
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
        }

        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'refinedCMS:sync-shopify-hydrogen-media';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs the Shopify media url with the db record';

     /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $media = Media::whereNull('external_url')->whereNotNull('external_id')->get();
        $this->info('Fetching '.$media->count().' file urls');
        if ($media->count()) {
            foreach ($media as $item) {
                $this->line('Fetching image for: '. $item->external_id);
                $response = $this->getFileUrl($item->external_id);

                if (isset($response->url) && $response->url) {
                    $item->external_url = $response->url;
                    $item->save();
                    $this->dispatchEvent($item);
                }

                if (isset($response->image->url) && $response->image->url) {
                    $item->external_url = $response->image->url;
                    $item->save();
                    $this->dispatchEvent($item);
                }
            }
        }
    }

    /**
     * Fetch the file details
     *
     * Shopify doesn't supply the file url once the file has been uploaded
     * So we must re-fetch it
     *
     * @return void
     */
    private function getFileUrl($resourceUrl)
    {

        $idSegments = explode('/', $resourceUrl);
        $id = (string) end($idSegments);
        $query = <<<QUERY
query {
    files(first: 1, query: "id:$id") {
      edges {
        node {
          ... on GenericFile {
            id,
            url
          }
          ... on MediaImage {
            id,
            image {
              url
            }
          }
        }
      }
    }
}
QUERY;


        $response = $this->client->query([
            'query' => $query,
        ]);

        $body = json_decode($response->getBody()->getContents());

        return $body->data->files->edges[0]->node;
    }

    private function dispatchEvent (Media $media)
    {
        $this->info('Dispatching event');
        MediaFileUpdated::dispatch($media);
    }

}
