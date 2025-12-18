<?php

namespace App\Console\Commands;

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;
use Aws\Rds\RdsClient;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

class RDSCreateWithReplicaCommand extends BaseAwsCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aws:rds:create-with-replica
    {--engine= : The engine to use}
    {--engine-version= : The version of the engine to use}
    {--db-instance-identifier= : The identifier of the database instance}
    {--master-username= : The username of the master user}
    {--master-user-password= : The password of the master user}
    {--db-instance-class= : The class of the database instance}
    {--allocated-storage= : The allocated storage in GB}
    {--db-subnet-group-name= : The name of the DB subnet group}
    {--vpc-security-group-ids=* : The VPC security group IDs (comma-separated)}
    {--replica-vpc-security-group-ids=* : The VPC security group IDs for the replica (region-specific)}
    {--publicly-accessible : Make the DB instance publicly accessible}
    {--kms-key-id= : The ID of the KMS key to use}
    {--backup-retention-period= : The retention period of the backup}
    {--primary-region= : The region of the primary instance}
    {--replica-region= : The region of the replica instance}
    {--replica-kms-key-id= : The ID of the KMS key to use for the replica instance}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new RDS instance with a replica';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $engine = $this->option('engine') ?? 'mysql';
        $engineVersion = $this->option('engine-version') ?? '8.0.43';
        $dbInstanceIdentifier = $this->option('db-instance-identifier') ?? 'db-2';
        $masterUsername = $this->option('master-username') ?? 'admin';
        $masterUserPassword = $this->option('master-user-password') ?? 'password';
        $dbInstanceClass = $this->option('db-instance-class') ?? 'db.t4g.micro';
        $allocatedStorage = (int) ($this->option('allocated-storage') ?? 20);
        $backupRetentionPeriod = (int) ($this->option('backup-retention-period') ?? 1);
        $primaryRegion = $this->option('primary-region') ?? config('services.aws.region');
        $replicaRegion = $this->option('replica-region') ?? config('services.aws.region');
        $dbSubnetGroupName = $this->option('db-subnet-group-name');
        $vpcSecurityGroupIds = $this->option('vpc-security-group-ids');
        $replicaVpcSecurityGroupIds = $this->option('replica-vpc-security-group-ids');
        $publiclyAccessible = $this->option('publicly-accessible');
        $kmsKeyId = $this->option('kms-key-id');
        $replicaKmsKeyId = $this->option('replica-kms-key-id');

        // Create RDS client for primary region
        // Configured with the primary region to ensure all operations target the correct region
        $rdsClient = new RdsClient($this->getAwsConfig($primaryRegion));

        // Auto-create KMS key for primary if needed for cross-region replicas
        // AWS Requirement: Cross-region encrypted replicas require the primary to be encrypted
        // If user didn't specify a KMS key, we automatically create one in the primary region
        if ($replicaRegion !== $primaryRegion && ! $kmsKeyId) {
            $this->info('Cross-region replicas require encryption. Creating new KMS key for primary instance...');
            // Create KMS client for primary region to create the encryption key
            $kmsClient = new KmsClient($this->getAwsConfig($primaryRegion));
            $kmsKey = $kmsClient->createKey([
                'Description' => 'KMS key for the primary RDS instance (required for cross-region replica)',
                // Required: Symmetric key specification (AES-256)
                'KeySpec' => 'SYMMETRIC_DEFAULT',
                // Required: Key usage for encryption/decryption operations
                'KeyUsage' => 'ENCRYPT_DECRYPT',
            ]);
            // Extract the key ID from the created key metadata
            $kmsKeyId = $kmsKey['KeyMetadata']['KeyId'];
            $this->info('New KMS key created for primary: '.$kmsKeyId);
        }

        // If no subnet group is specified, try to get or create one
        if (! $dbSubnetGroupName) {
            $this->info('No DB subnet group specified. Attempting to find or create one...');
            $dbSubnetGroupName = $this->getOrCreateDbSubnetGroup($primaryRegion);
            if (! $dbSubnetGroupName) {
                $this->error('Failed to get or create a DB subnet group. Please specify one with --db-subnet-group-name.');

                return Command::FAILURE;
            }
        }

        $this->info('Creating primary instance...');

        // Build primary instance configuration
        $primaryInstanceArgs = [
            // Required: Unique identifier for the DB instance
            'DBInstanceIdentifier' => $dbInstanceIdentifier,
            // Required: Instance class (e.g., db.t4g.micro, db.r5.large)
            'DBInstanceClass' => $dbInstanceClass,
            // Required: Database engine (mysql, postgres, mariadb, etc.)
            'Engine' => $engine,
            // Required: Engine version (must be compatible with the engine)
            'EngineVersion' => $engineVersion,
            // Required: Master username for database access
            'MasterUsername' => $masterUsername,
            // Required: Master password for database access
            'MasterUserPassword' => $masterUserPassword,
            // Required: Allocated storage in GB (minimum varies by engine)
            'AllocatedStorage' => $allocatedStorage,
            // Required: Number of days to retain automated backups (0-35)
            'BackupRetentionPeriod' => $backupRetentionPeriod,
        ];

        // Optional: DB Subnet Group - Required if instance is in a VPC
        // Specifies which subnets the DB instance can use (must span at least 2 AZs)
        if ($dbSubnetGroupName) {
            $primaryInstanceArgs['DBSubnetGroupName'] = $dbSubnetGroupName;
            $this->info('Using DB subnet group: '.$dbSubnetGroupName);
        }

        // Optional: VPC Security Group IDs - Controls network access to the DB instance
        // If not specified, RDS will use the default security group for the VPC
        if ($vpcSecurityGroupIds && count($vpcSecurityGroupIds) > 0) {
            $primaryInstanceArgs['VpcSecurityGroupIds'] = $vpcSecurityGroupIds;
            $this->info('Using VPC security groups: '.implode(', ', $vpcSecurityGroupIds));
        }

        // Optional: Publicly Accessible - Whether the DB instance has a public IP
        // Default is false (only accessible within VPC)
        if ($publiclyAccessible) {
            $primaryInstanceArgs['PubliclyAccessible'] = true;
            $this->info('DB instance will be publicly accessible');
        }

        // Optional: Encryption Configuration
        // StorageEncrypted must be true when KmsKeyId is specified (AWS requirement)
        // Required for cross-region replicas (automatically set earlier if needed)
        if ($kmsKeyId) {
            // Required: Must be true when KmsKeyId is specified
            $primaryInstanceArgs['StorageEncrypted'] = true;
            // Optional: KMS key ID for encryption (if not specified, AWS uses default key)
            $primaryInstanceArgs['KmsKeyId'] = $kmsKeyId;
            $this->info('Using KMS key for primary: '.$kmsKeyId);
        }

        $primaryInstance = $rdsClient->createDBInstance($primaryInstanceArgs);

        $this->info('Waiting for primary instance to be available...');

        // wait for the primary instance to be available showing a spinner from prompts
        spin(
            message: 'Creating primary instance',
            callback: function () use ($rdsClient, $dbInstanceIdentifier) {
                return $rdsClient->waitUntil('DBInstanceAvailable', [
                    'DBInstanceIdentifier' => $dbInstanceIdentifier,
                ]);
            },
        );

        $primaryInstanceArn = $primaryInstance['DBInstance']['DBInstanceArn'];
        $this->info('Primary instance created: '.$primaryInstanceArn);

        // Build replica instance configuration
        $replicaInstanceIdentifier = $dbInstanceIdentifier.'-replica';
        $replicaInstanceArgs = [
            // Required: Unique identifier for the replica instance
            'DBInstanceIdentifier' => $replicaInstanceIdentifier,
            // Required: Source instance identifier (will be updated for cross-region)
            // For same-region: uses DB instance identifier
            // For cross-region: will be replaced with ARN
            'SourceDBInstanceIdentifier' => $dbInstanceIdentifier,
        ];

        // Optional: DB Instance Class - Can be different from primary for cost optimization
        // If not specified, replica uses the same instance class as primary
        if ($dbInstanceClass) {
            $replicaInstanceArgs['DBInstanceClass'] = $dbInstanceClass;
        }

        // Handle cross-region vs same-region replica configuration
        if ($replicaRegion !== $primaryRegion) {
            $this->info('Creating cross-region read replica in: '.$replicaRegion);

            // Required for cross-region: Use ARN instead of identifier
            // Cross-region replicas require the full ARN to identify the source instance
            $replicaInstanceArgs['SourceDBInstanceIdentifier'] = $primaryInstanceArn;
            // Required for cross-region: Source region where primary instance exists
            // AWS needs this to locate the source instance in a different region
            $replicaInstanceArgs['SourceRegion'] = $primaryRegion;

            // Required for cross-region: DB Subnet Group in replica region
            // Subnet groups are region-specific and cannot be shared across regions
            // Must be explicitly specified for cross-region replicas (AWS requirement)
            $this->info('Finding or creating DB subnet group in replica region...');
            $replicaSubnetGroupName = $this->getOrCreateDbSubnetGroup($replicaRegion);
            if (! $replicaSubnetGroupName) {
                $this->error('Failed to get or create a DB subnet group in the replica region. Please create one manually.');

                return Command::FAILURE;
            }
            $replicaInstanceArgs['DBSubnetGroupName'] = $replicaSubnetGroupName;
            $this->info('Using DB subnet group in replica region: '.$replicaSubnetGroupName);

            // Required for cross-region: KMS Key in replica region
            // Cross-region encrypted replicas require a KMS key in the destination region
            // If not provided, we automatically create one
            if (! $replicaKmsKeyId) {
                $this->info('Creating new KMS key for replica instance...');
                $kmsClient = new KmsClient($this->getAwsConfig($replicaRegion));
                $kmsKey = $kmsClient->createKey([
                    'Description' => 'KMS key for the replica instance',
                    'KeySpec' => 'SYMMETRIC_DEFAULT',
                    'KeyUsage' => 'ENCRYPT_DECRYPT',
                ]);
                $replicaKmsKeyId = $kmsKey['KeyMetadata']['KeyId'];
                $this->info('New KMS key created: '.$replicaKmsKeyId);
            }
            // Required for cross-region encrypted replicas
            // StorageEncrypted must be true when KmsKeyId is specified (AWS requirement)
            if ($replicaKmsKeyId) {
                // Required: Must be true when KmsKeyId is specified
                $replicaInstanceArgs['StorageEncrypted'] = true;
                // Required: KMS key ID in the replica region for encryption
                $replicaInstanceArgs['KmsKeyId'] = $replicaKmsKeyId;
            }

            // Optional: VPC Security Groups in replica region
            // Security groups are region-specific - cannot reuse primary region security groups
            if ($replicaVpcSecurityGroupIds && count($replicaVpcSecurityGroupIds) > 0) {
                // Use security groups from the replica region
                $replicaInstanceArgs['VpcSecurityGroupIds'] = $replicaVpcSecurityGroupIds;
                $this->info('Using VPC security groups for replica: '.implode(', ', $replicaVpcSecurityGroupIds));
            } elseif ($vpcSecurityGroupIds && count($vpcSecurityGroupIds) > 0) {
                // Warn user that primary region security groups won't work
                $this->warn('VPC Security Groups are region-specific. The specified security groups from the primary region will not work in the replica region.');
                $this->warn('Please specify replica security groups with --replica-vpc-security-group-ids, or leave them unset for the replica.');
            }
        } else {
            $this->info('Creating read replica in same region: '.$primaryRegion);

            // For same-region replicas: DBSubnetGroupName is NOT specified
            // AWS automatically inherits the subnet group from the primary instance
            // Explicitly setting it would cause an error (AWS requirement)

            // Optional: VPC Security Groups - Can reuse primary region security groups
            // Since we're in the same region, security groups can be shared
            if ($vpcSecurityGroupIds && count($vpcSecurityGroupIds) > 0) {
                $replicaInstanceArgs['VpcSecurityGroupIds'] = $vpcSecurityGroupIds;
            }
        }

        // Optional: Publicly Accessible - Applies to both same-region and cross-region replicas
        // If primary is publicly accessible, replica can also be (independent setting)
        if ($publiclyAccessible) {
            $replicaInstanceArgs['PubliclyAccessible'] = true;
        }

        $this->info('Creating read replica...');

        // Create RDS client for replica region
        // For cross-region replicas, we need a separate client configured for the replica region
        // For same-region replicas, we can reuse the primary region client
        $replicaRdsClient = $rdsClient;
        if ($replicaRegion !== $primaryRegion) {
            // Required for cross-region: Create client configured for replica region
            // AWS SDK requires region-specific clients for cross-region operations
            $replicaRdsClient = new RdsClient($this->getAwsConfig($replicaRegion));
        }

        $replicaInstance = $replicaRdsClient->createDBInstanceReadReplica($replicaInstanceArgs);

        $this->info('Waiting for replica instance to be available...');

        // wait for the replica instance to be available showing a spinner from prompts

        spin(
            message: 'Creating replica instance',
            callback: function () use ($replicaRdsClient, $replicaInstanceIdentifier) {
                return $replicaRdsClient->waitUntil('DBInstanceAvailable', [
                    'DBInstanceIdentifier' => $replicaInstanceIdentifier,
                ]);
            },
        );

        $this->info('Replica instance created: '.$replicaInstance['DBInstance']['DBInstanceArn']);
    }

    /**
     * Get available DB subnet groups for the current region.
     */
    protected function getDbSubnetGroups(string $region): array
    {
        $rdsClient = new RdsClient($this->getAwsConfig($region));

        try {
            $result = $rdsClient->describeDBSubnetGroups();
            $subnetGroups = $result['DBSubnetGroups'] ?? [];

            return array_map(function ($group) {
                return [
                    'name' => $group['DBSubnetGroupName'],
                    'description' => $group['DBSubnetGroupDescription'] ?? '',
                    'vpc_id' => $group['VpcId'] ?? '',
                    'subnets' => array_map(function ($subnet) {
                        return [
                            'id' => $subnet['SubnetIdentifier'],
                            'az' => $subnet['SubnetAvailabilityZone']['Name'] ?? '',
                        ];
                    }, $group['Subnets'] ?? []),
                ];
            }, $subnetGroups);
        } catch (AwsException $e) {
            $this->warn('Error fetching DB subnet groups: '.$e->getAwsErrorMessage());

            return [];
        }
    }

    /**
     * Get the default VPC for the current region.
     */
    protected function getDefaultVpc(string $region): ?array
    {
        $ec2Client = new Ec2Client($this->getAwsConfig($region));

        try {
            $result = $ec2Client->describeVpcs([
                'Filters' => [
                    [
                        'Name' => 'isDefault',
                        'Values' => ['true'],
                    ],
                ],
            ]);

            $vpcs = $result['Vpcs'] ?? [];
            if (empty($vpcs)) {
                return null;
            }

            return [
                'vpc_id' => $vpcs[0]['VpcId'],
                'cidr_block' => $vpcs[0]['CidrBlock'] ?? '',
            ];
        } catch (AwsException $e) {
            $this->warn('Error fetching default VPC: '.$e->getAwsErrorMessage());

            return null;
        }
    }

    /**
     * Get all VPCs for the current region.
     */
    protected function getAllVpcs(string $region): array
    {
        $ec2Client = new Ec2Client($this->getAwsConfig($region));

        try {
            $result = $ec2Client->describeVpcs();
            $vpcs = $result['Vpcs'] ?? [];

            return array_map(function ($vpc) {
                return [
                    'vpc_id' => $vpc['VpcId'],
                    'cidr_block' => $vpc['CidrBlock'] ?? '',
                    'is_default' => $vpc['IsDefault'] ?? false,
                    'tags' => array_column($vpc['Tags'] ?? [], 'Value', 'Key'),
                ];
            }, $vpcs);
        } catch (AwsException $e) {
            $this->warn('Error fetching VPCs: '.$e->getAwsErrorMessage());

            return [];
        }
    }

    /**
     * Get subnets for a VPC.
     */
    protected function getSubnetsForVpc(string $vpcId, string $region): array
    {
        $ec2Client = new Ec2Client($this->getAwsConfig($region));

        try {
            $result = $ec2Client->describeSubnets([
                'Filters' => [
                    [
                        'Name' => 'vpc-id',
                        'Values' => [$vpcId],
                    ],
                ],
            ]);

            $subnets = $result['Subnets'] ?? [];

            return array_map(function ($subnet) {
                return [
                    'id' => $subnet['SubnetId'],
                    'az' => $subnet['AvailabilityZone'] ?? '',
                    'cidr' => $subnet['CidrBlock'] ?? '',
                ];
            }, $subnets);
        } catch (AwsException $e) {
            $this->warn('Error fetching subnets: '.$e->getAwsErrorMessage());

            return [];
        }
    }

    /**
     * Create a DB subnet group if it doesn't exist.
     */
    protected function createDbSubnetGroup(string $vpcId, array $subnets, string $region, ?string $name = null): ?string
    {
        if (count($subnets) < 2) {
            $this->error('At least 2 subnets in different availability zones are required for RDS.');

            return null;
        }

        $rdsClient = new RdsClient($this->getAwsConfig($region));

        $subnetGroupName = $name ?? 'default-'.str_replace(' ', '-', strtolower($vpcId)).'-'.time();

        try {
            // Group subnets by availability zone
            $subnetsByAz = [];
            foreach ($subnets as $subnet) {
                $az = $subnet['az'];
                if (! isset($subnetsByAz[$az])) {
                    $subnetsByAz[$az] = [];
                }
                $subnetsByAz[$az][] = $subnet['id'];
            }

            // Use one subnet from each AZ (RDS requires at least 2 AZs)
            $subnetIds = [];
            foreach ($subnetsByAz as $az => $azSubnets) {
                $subnetIds[] = $azSubnets[0];
            }

            if (count($subnetIds) < 2) {
                $this->error('Subnets must span at least 2 availability zones.');

                return null;
            }

            $rdsClient->createDBSubnetGroup([
                'DBSubnetGroupName' => $subnetGroupName,
                'DBSubnetGroupDescription' => "Auto-created DB subnet group for VPC {$vpcId}",
                'SubnetIds' => $subnetIds,
                'Tags' => [
                    [
                        'Key' => 'CreatedBy',
                        'Value' => 'Laravel RDS Command',
                    ],
                ],
            ]);

            $this->info("Created DB subnet group: {$subnetGroupName}");

            return $subnetGroupName;
        } catch (AwsException $e) {
            $this->error('Error creating DB subnet group: '.$e->getAwsErrorMessage());

            return null;
        }
    }

    /**
     * Get or create a DB subnet group for the region.
     */
    protected function getOrCreateDbSubnetGroup(string $region): ?string
    {
        // First, try to find existing subnet groups
        $subnetGroups = $this->getDbSubnetGroups($region);
        if (! empty($subnetGroups)) {
            if (count($subnetGroups) === 1) {
                $selectedGroup = $subnetGroups[0];
                $this->info("Using existing DB subnet group: {$selectedGroup['name']}");

                return $selectedGroup['name'];
            }

            // Multiple subnet groups found - prompt user to select
            $options = [];
            foreach ($subnetGroups as $group) {
                $subnetCount = count($group['subnets']);
                $label = "{$group['name']} (VPC: {$group['vpc_id']}, {$subnetCount} subnets)";
                if (! empty($group['description'])) {
                    $label .= " - {$group['description']}";
                }
                $options[$group['name']] = $label;
            }

            $selectedName = select(
                label: 'Multiple DB subnet groups found. Please select one:',
                options: $options,
                default: array_key_first($options),
            );

            $this->info("Using DB subnet group: {$selectedName}");

            return $selectedName;
        }

        // If no subnet groups exist, try to create one from a VPC
        $this->info('No DB subnet groups found. Attempting to create one from a VPC...');
        $defaultVpc = $this->getDefaultVpc($region);

        $selectedVpc = null;
        if ($defaultVpc) {
            // Check if there are other VPCs available
            $allVpcs = $this->getAllVpcs($region);
            if (count($allVpcs) === 1) {
                $selectedVpc = $defaultVpc;
            } else {
                // Multiple VPCs - prompt user to select
                $options = [];
                foreach ($allVpcs as $vpc) {
                    $label = $vpc['vpc_id'];
                    if ($vpc['is_default']) {
                        $label .= ' (Default)';
                    }
                    if (! empty($vpc['cidr_block'])) {
                        $label .= " - {$vpc['cidr_block']}";
                    }
                    if (isset($vpc['tags']['Name'])) {
                        $label .= " - {$vpc['tags']['Name']}";
                    }
                    $options[$vpc['vpc_id']] = $label;
                }

                $selectedVpcId = select(
                    label: 'Multiple VPCs found. Please select one to create a DB subnet group:',
                    options: $options,
                    default: $defaultVpc['vpc_id'],
                );

                // Find the selected VPC
                foreach ($allVpcs as $vpc) {
                    if ($vpc['vpc_id'] === $selectedVpcId) {
                        $selectedVpc = [
                            'vpc_id' => $vpc['vpc_id'],
                            'cidr_block' => $vpc['cidr_block'],
                        ];
                        break;
                    }
                }
            }
        } else {
            // No default VPC, but check if there are any VPCs
            $allVpcs = $this->getAllVpcs($region);
            if (empty($allVpcs)) {
                $this->error('No VPCs found. Please create a DB subnet group manually or specify one with --db-subnet-group-name.');

                return null;
            }

            if (count($allVpcs) === 1) {
                $selectedVpc = [
                    'vpc_id' => $allVpcs[0]['vpc_id'],
                    'cidr_block' => $allVpcs[0]['cidr_block'],
                ];
            } else {
                // Multiple VPCs - prompt user to select
                $options = [];
                foreach ($allVpcs as $vpc) {
                    $label = $vpc['vpc_id'];
                    if (! empty($vpc['cidr_block'])) {
                        $label .= " - {$vpc['cidr_block']}";
                    }
                    if (isset($vpc['tags']['Name'])) {
                        $label .= " - {$vpc['tags']['Name']}";
                    }
                    $options[$vpc['vpc_id']] = $label;
                }

                $selectedVpcId = select(
                    label: 'Multiple VPCs found. Please select one to create a DB subnet group:',
                    options: $options,
                    default: array_key_first($options),
                );

                // Find the selected VPC
                foreach ($allVpcs as $vpc) {
                    if ($vpc['vpc_id'] === $selectedVpcId) {
                        $selectedVpc = [
                            'vpc_id' => $vpc['vpc_id'],
                            'cidr_block' => $vpc['cidr_block'],
                        ];
                        break;
                    }
                }
            }
        }

        if (! $selectedVpc) {
            $this->error('No VPC selected. Please create a DB subnet group manually or specify one with --db-subnet-group-name.');

            return null;
        }

        $subnets = $this->getSubnetsForVpc($selectedVpc['vpc_id'], $region);
        if (empty($subnets)) {
            $this->error('No subnets found in selected VPC. Please create a DB subnet group manually.');

            return null;
        }

        return $this->createDbSubnetGroup($selectedVpc['vpc_id'], $subnets, $region);
    }
}
