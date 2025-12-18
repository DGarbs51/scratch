# Scratch

## Getting Started with AWS RDS Command

This application includes a command to create RDS database instances with replicas. The command automates the creation of both primary and replica instances, handling subnet groups, VPCs, and KMS keys automatically when needed.

### Prerequisites

- AWS account with appropriate permissions for RDS, EC2, and KMS
- PHP 8.4+ and Composer installed
- Laravel application dependencies installed (`composer install`)

### AWS Authentication Setup

You have two options for authenticating with AWS:

#### Option 1: Environment Variables (Recommended for Development)

Set the following environment variables in your `.env` file:

```env
AWS_ACCESS_KEY_ID=your-access-key-id
AWS_SECRET_ACCESS_KEY=your-secret-access-key
AWS_DEFAULT_REGION=us-east-2
```

The command will automatically use these credentials when they are set.

#### Option 2: AWS Credentials File (Recommended for Production)

Configure AWS credentials using the AWS CLI or by manually creating `~/.aws/credentials`:

```ini
[default]
aws_access_key_id = your-access-key-id
aws_secret_access_key = your-secret-access-key
```

And set the region in `~/.aws/config`:

```ini
[default]
region = us-east-2
```

If no environment variables are set, the command will automatically use the default AWS credential provider chain, which reads from `~/.aws/credentials`.

### Command Usage

#### Basic Example: Create RDS Instance with In-Region Replica

This creates a primary RDS instance and a replica in the same region:

```bash
php artisan aws:rds:create-with-replica \
  --db-instance-identifier=test-db-1 \
  --master-username=admin \
  --master-user-password=YourSecurePassword123! \
  --db-instance-class=db.t4g.micro \
  --allocated-storage=20 \
  --primary-region=us-east-2 \
  --replica-region=us-east-2
```

**What happens:**
- Creates a primary RDS instance in `us-east-2` with identifier `test-db-1`
- Creates a replica instance with identifier `test-db-1-replica` in the same region
- Automatically finds or creates a DB subnet group if not specified
- Uses the same subnet group for both instances
- Waits for both instances to be available before completing
- Shows progress spinners during instance creation

#### Cross-Region Replica Example

This creates a primary RDS instance and a replica in a different region:

```bash
php artisan aws:rds:create-with-replica \
  --db-instance-identifier=test-db-2 \
  --master-username=admin \
  --master-user-password=YourSecurePassword123! \
  --db-instance-class=db.t4g.micro \
  --allocated-storage=20 \
  --primary-region=us-east-2 \
  --replica-region=us-west-2 \
  --vpc-security-group-ids=sg-12345678 \
  --replica-vpc-security-group-ids=sg-87654321
```

**Note:** If you don't specify `--replica-vpc-security-group-ids`, the command will warn you that security groups from the primary region won't work in the replica region. You can either:
- Specify replica security groups with `--replica-vpc-security-group-ids`
- Leave security groups unset for the replica (it will be created without explicit security groups)

**What happens:**
- Creates a primary RDS instance in `us-east-2`
- Creates a replica instance in a different region (`us-west-2`)
- **Automatically encrypts the primary instance** (creates KMS key if `--kms-key-id` not specified) - required for cross-region replicas
- Automatically finds or creates DB subnet groups for both regions (region-specific)
- Automatically creates a new KMS key in the replica region if `--replica-kms-key-id` is not specified (required for cross-region replicas)
- **Important:** VPC security groups are region-specific. You must specify `--replica-vpc-security-group-ids` for the replica region, or leave security groups unset for the replica

#### Minimal Example (Using Defaults)

You can run the command with minimal options, using all defaults:

```bash
php artisan aws:rds:create-with-replica
```

This will create:
- Primary instance: `db-1` (MySQL 8.0.43, `db.t4g.micro`, 20GB storage)
- Replica instance: `db-1-replica` (same region)
- Both instances will use auto-detected or auto-created subnet groups

### Command Options

The command supports the following options (all are optional with sensible defaults):

**Database Configuration:**
- `--engine`: Database engine (default: `mysql`)
- `--engine-version`: Engine version (default: `8.0.43`)
- `--db-instance-identifier`: Unique identifier for the DB instance (default: `db-1`)
  - The replica will automatically be named `{identifier}-replica`
- `--master-username`: Master username (default: `admin`)
- `--master-user-password`: Master password (default: `password`)
- `--db-instance-class`: Instance class (default: `db.t4g.micro`)
- `--allocated-storage`: Storage in GB (default: `20`)
- `--backup-retention-period`: Backup retention period in days (default: `1`)

**Network Configuration:**
- `--db-subnet-group-name`: DB subnet group name for the primary instance (auto-detected/created if not provided)
  - For cross-region replicas, a subnet group is automatically found/created in the replica region
- `--vpc-security-group-ids`: VPC security group IDs for the primary instance (can be specified multiple times or comma-separated)
- `--replica-vpc-security-group-ids`: VPC security group IDs for the replica instance (required for cross-region replicas, optional for same-region)
  - **Note:** Security groups are region-specific. For cross-region replicas, you must specify security groups from the replica region
- `--publicly-accessible`: Make the DB instance publicly accessible (flag, applies to both instances)

**Encryption:**
- `--kms-key-id`: KMS key ID for the primary instance
- `--replica-kms-key-id`: KMS key ID for the replica instance
  - For cross-region replicas, a new KMS key will be automatically created if not specified

**Region Configuration:**
- `--primary-region`: Region for the primary instance (default: from `config('services.aws.region')`)
- `--replica-region`: Region for the replica instance (default: same as primary)

### Automatic Subnet Group Management

The command automatically handles DB subnet groups:

1. **If `--db-subnet-group-name` is provided:** Uses the specified subnet group
2. **If no subnet group is specified:**
   - First, attempts to find existing DB subnet groups in the region
   - If exactly one exists, uses it automatically
   - If multiple exist, prompts you to select one interactively
   - If none exist, attempts to create one from available VPCs:
     - Prefers the default VPC if available
     - If multiple VPCs exist, prompts you to select one
     - Automatically selects subnets from at least 2 different availability zones
     - Creates a new subnet group with a descriptive name

**For Cross-Region Replicas:**
- The command automatically finds or creates a DB subnet group in the replica region
- Subnet groups are region-specific, so a separate subnet group is always used for the replica region
- The same interactive selection process applies if multiple subnet groups or VPCs exist in the replica region

**For Same-Region Replicas:**
- The replica uses the same subnet group as the primary instance (if one was specified or auto-detected)

### Interactive Prompts

The command uses Laravel Prompts for interactive selection when needed:

- **Multiple DB subnet groups:** If multiple subnet groups exist, you'll be prompted to select one
- **Multiple VPCs:** If no subnet groups exist and multiple VPCs are available, you'll be prompted to select a VPC
- All prompts include helpful descriptions showing VPC IDs, CIDR blocks, subnet counts, and tags

### Important Notes

- **Instance Creation Time:** The command waits for both the primary and replica instances to be available before completing. This can take several minutes.
- **Progress Indicators:** The command shows progress spinners during instance creation using Laravel Prompts.
- **IAM Permissions:** Ensure your AWS account has the necessary IAM permissions for:
  - RDS: `CreateDBInstance`, `CreateDBInstanceReadReplica`, `DescribeDBInstances`, `CreateDBSubnetGroup`, `DescribeDBSubnetGroups`
  - EC2: `DescribeVpcs`, `DescribeSubnets`
  - KMS: `CreateKey`, `DescribeKey` (for cross-region replicas)
- **Subnet Requirements:** RDS requires at least 2 subnets in different availability zones. The command automatically ensures this requirement is met.
- **Cross-Region Replicas:**
  - **Encryption is required:** The primary instance must be encrypted for cross-region replicas. If `--kms-key-id` is not specified, the command will automatically create a KMS key in the primary region and encrypt the primary instance.
  - A KMS key is required in the replica region. If `--replica-kms-key-id` is not specified, the command will automatically create one.
  - VPC security groups are region-specific. You must specify `--replica-vpc-security-group-ids` with security groups from the replica region, or leave them unset.
  - DB subnet groups are automatically found or created in the replica region (they cannot be shared across regions).
- **Same-Region Replicas:**
  - The replica can reuse the same subnet group and security groups as the primary instance.
  - KMS keys are optional (not required like cross-region replicas).
- **Replica Naming:** The replica instance identifier is automatically set to `{primary-identifier}-replica`.
