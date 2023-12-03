<?php

namespace RefinedDigital\ShopifyHydrogen\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Validator;
use Artisan;
use DB;
use RuntimeException;

class Install extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'refinedCMS:install-shopify-hydrogen';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Installs the shopify hydrogen module';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->askQuestions();
        $this->publishConfig();
        $this->enableBroadcaster();

        $this->line('Make sure to run the following commands:');
        $this->line(' - php artisan websockets:serve');
        $this->line(' - php artisan schedule:work');
        $this->info('Shopify Hydrogen has been successfully installed');
    }

    protected function publishConfig()
    {
        $this->output->writeln('<info>Publishing the configs</info>');

        Artisan::call('vendor:publish', [
            '--tag' => 'shopify-hydrogen'
        ]);

        Artisan::call('vendor:publish', [
            '--provider' => 'BeyondCode\LaravelWebSockets\WebSocketsServiceProvider',
            '--tag' => 'config'
        ]);
    }

    protected function askQuestions()
    {
        $helper = $this->getHelper('question');

        $env = [];

        $question = new Question('Api Key:', false);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(function ($answer) {
            if(strlen(trim($answer)) < 1) {
                throw new RuntimeException('Api key is required');
            }
            return $answer;
        });
        $question->setMaxAttempts(3);
        $env['SHOPIFY_API_KEY'] = $helper->ask($this->input, $this->output, $question);

        $question = new Question('Access Token (admin api token):', false);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(function ($answer) {
            if(strlen(trim($answer)) < 1) {
                throw new RuntimeException('Api token is required');
            }
            return $answer;
        });
        $question->setMaxAttempts(3);
        $env['SHOPIFY_ACCESS_TOKEN'] = $helper->ask($this->input, $this->output, $question);

        $question = new Question('Scopes (write_files,read_files): ', 'write_files,read_files');
        $question->setValidator(function ($answer) {
            if(strlen(trim($answer)) < 1) {
                throw new RuntimeException('Scopes are required');
            }
            return $answer;
        });
        $question->setMaxAttempts(3);
        $env['SHOPIFY_APP_SCOPES'] = $helper->ask($this->input, $this->output, $question);

        $question = new Question('Shopify domain (https://myshop.myshopify.com): ', false);
        $question->setValidator(function ($answer) {
            if(strlen(trim($answer)) < 1) {
                throw new RuntimeException('Domain is required');
            }
            return $answer;
        });
        $question->setMaxAttempts(3);
        $env['SHOPIFY_DOMAIN'] = $helper->ask($this->input, $this->output, $question);

        // ask for the url
        $question = new Question('Public Url? (http://127.0.0.1:3000): ', 'http://127.0.0.1:3000');
        $question->setValidator(function($answer) {
            if (strlen($answer) < 1) {
                throw new RuntimeException('Site URL is required');
            }

            $siteBits = explode('.', $answer);
            if(count($siteBits) < 3) {
                throw new RuntimeException("Public url must contain a sub domain, domain and tdl, ie: www.domain.com\nYou supplied: ".$answer);
            }

            return $answer;
        });
        $question->setMaxAttempts(3);
        $publicUrl = $helper->ask($this->input, $this->output, $question);

        $question = new ConfirmationQuestion('Enable noindex, nofollow: ', false);
        $enableNoIndex = $helper->ask($this->input, $this->output, $question);

        $this->output->writeln('<info>Writing config</info>');
        // now do the search and replace on file strings
        $file = file_get_contents(app()->environmentFilePath());

        $file .= "\n";
        foreach ($env as $key => $value) {
            $file .= "\n".$key.'='.$value;
        }

        file_put_contents(app()->environmentFilePath(), $file);

        // update the env file
        // now do the search and replace on file strings
        $envSearch = [
            '(FILESYSTEM_DRIVER=(.*?)\n)',
            '(APP_URL=(.*?)\n)',
            '(BROADCAST_DRIVER=(.*?)\n)',
            '(PUSHER_APP_ID=(.*?)\n)',
            '(PUSHER_APP_KEY=(.*?)\n)',
            '(PUSHER_APP_SECRET=(.*?)\n)',
        ];

        $envReplace = [
            "FILESYSTEM_DRIVER=shopify_hydrogen\n",
            "APP_URL=".env('APP_URL')."\nPUBLIC_URL=$publicUrl\n",
            "BROADCAST_DRIVER=pusher\n",
            "PUSHER_APP_ID=refinedcms\n",
            "PUSHER_APP_KEY=refinedkey\n",
            "PUSHER_APP_SECRET=refinedsecret\n",
        ];
        $envFile = file_get_contents(app()->environmentFilePath());
        $envFile = preg_replace($envSearch, $envReplace, $envFile);
        file_put_contents(app()->environmentFilePath(), $envFile);

        $this->writeToFilesystemConfigFile();
        $this->writeToBroadcastConfigFile();

        if ($enableNoIndex) {
            $this->writeToHtAccess();
        }


        $this->output->writeln('<info>Finished writing config</info>');
    }

    protected function writeToFilesystemConfigFile()
    {
        $configFilePath = config_path('filesystems.php');
        $content = file_get_contents($configFilePath);
        $search = "'local' => [";
        $replace = "'shopify_hydrogen' => [
            'driver' => 'shopify_hydrogen'
        ],

        'local' => [";

        $content = str_replace($search, $replace, $content);
        file_put_contents($configFilePath, $content);

    }

    // pusher already exists, so need to replace only what is needed
    protected function writeToBroadcastConfigFile()
    {
        $configFilePath = config_path('broadcasting.php');
        $content = file_get_contents($configFilePath);
        $search = "'useTLS' => true,";
        $replace = "'useTLS' => true,
                'encrypted' => true,
                'host' => '127.0.0.1',
                'port' => 6001,
                'scheme' => 'http',";

        $content = str_replace($search, $replace, $content);
        file_put_contents($configFilePath, $content);
    }

    protected function writeToHtAccess()
    {
        $configFilePath = public_path('.htaccess');
        $content = file_get_contents($configFilePath);
        $search = "Options -MultiViews -Indexes
    </IfModule>";
        $replace = "Options -MultiViews -Indexes
    </IfModule>
    
    <IfModule mod_headers.c>
        Header set X-Robots-Tag \"noindex, nofollow\"
    </IfModule>";

        $content = str_replace($search, $replace, $content);
        file_put_contents($configFilePath, $content);
    }

    protected function enableBroadcaster()
    {
        $search = '// App\Providers\BroadcastServiceProvider::class,';
        $replace = 'App\Providers\BroadcastServiceProvider::class,';
        $configFilePath = config_path('app.php');
        $content = file_get_contents($configFilePath);
        $content = str_replace($search, $replace, $content);
        file_put_contents($configFilePath, $content);
    }

}
