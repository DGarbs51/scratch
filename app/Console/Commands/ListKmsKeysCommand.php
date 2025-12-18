<?php

namespace App\Console\Commands;

use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;
use Illuminate\Console\Command;

class ListKmsKeysCommand extends BaseAwsCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aws:kms:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List KMS keys for the configured region';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $kmsClient = new KmsClient($this->getAwsConfig());

            $this->info('Fetching KMS keys for region: '.config('services.aws.region'));
            $this->newLine();

            $result = $kmsClient->listKeys();
            $keys = $result['Keys'] ?? [];

            if (empty($keys)) {
                $this->warn('No KMS keys found in this region.');

                return Command::SUCCESS;
            }

            // Fetch detailed information for each key
            $tableData = [];
            foreach ($keys as $key) {
                $keyId = $key['KeyId'];
                try {
                    $keyDetails = $kmsClient->describeKey(['KeyId' => $keyId]);
                    $keyMetadata = $keyDetails['KeyMetadata'] ?? [];

                    $tableData[] = [
                        'Key ID' => $keyMetadata['KeyId'] ?? $keyId,
                        'ARN' => $keyMetadata['Arn'] ?? 'N/A',
                        'Description' => $keyMetadata['Description'] ?? 'N/A',
                        'State' => $keyMetadata['KeyState'] ?? 'N/A',
                        'Usage' => $keyMetadata['KeyUsage'] ?? 'N/A',
                        'Created' => isset($keyMetadata['CreationDate'])
                            ? $keyMetadata['CreationDate']->format('Y-m-d H:i:s')
                            : 'N/A',
                    ];
                } catch (AwsException $e) {
                    // If we can't describe the key, still show basic info
                    $tableData[] = [
                        'Key ID' => $keyId,
                        'ARN' => $key['KeyArn'] ?? 'N/A',
                        'Description' => 'N/A',
                        'State' => 'N/A',
                        'Usage' => 'N/A',
                        'Created' => 'N/A',
                    ];
                }
            }

            $this->table(
                ['Key ID', 'ARN', 'Description', 'State', 'Usage', 'Created'],
                $tableData
            );

            $this->newLine();
            $this->info('Total keys: '.count($keys));

            return Command::SUCCESS;
        } catch (AwsException $e) {
            $this->error('AWS Error: '.$e->getAwsErrorMessage());
            $this->error('Error Code: '.$e->getAwsErrorCode());

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
