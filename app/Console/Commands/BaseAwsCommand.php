<?php

namespace App\Console\Commands;

use Aws\Credentials\CredentialProvider;
use Aws\Sdk;
use Illuminate\Console\Command;

class BaseAwsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aws:base';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Base AWS command';

    /**
     * Get AWS SDK configuration.
     */
    protected function getAwsConfig(): array
    {
        $accessKeyId = config('services.aws.access_key_id');
        $secretAccessKey = config('services.aws.secret_access_key');
        $region = config('services.aws.region');

        $config = [
            'region' => $region,
        ];

        // If credentials are explicitly set, use them
        if (! empty($accessKeyId) && ! empty($secretAccessKey)) {
            $config['credentials'] = [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ];
        } else {
            // Otherwise, use the default credential provider chain
            // This will automatically use the default profile from ~/.aws/credentials
            $config['credentials'] = CredentialProvider::defaultProvider();
        }

        return $config;
    }

    /**
     * Create an AWS SDK instance.
     */
    protected function getAwsSdk(): Sdk
    {
        return new Sdk($this->getAwsConfig());
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Base AWS command');

        $accessKeyId = config('services.aws.access_key_id');
        $secretAccessKey = config('services.aws.secret_access_key');

        if (! empty($accessKeyId) && ! empty($secretAccessKey)) {
            $this->info('Using explicit credentials from config');
            $this->info('AWS_ACCESS_KEY_ID: '.$accessKeyId);
            $this->info('AWS_SECRET_ACCESS_KEY: '.str_repeat('*', strlen($secretAccessKey)));
        } else {
            $this->info('Using default AWS credential provider chain (default profile)');
        }

        $this->info('AWS_DEFAULT_REGION: '.config('services.aws.region'));

        // Example: Create an AWS SDK instance
        $sdk = $this->getAwsSdk();
        $this->info('AWS SDK client created successfully');
    }
}
