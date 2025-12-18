# Scratch

## AWS RDS Command

Create RDS database instances with replicas. The command automates creation of primary and replica instances, handling subnet groups, VPCs, and KMS keys automatically.

### Prerequisites

- AWS account with permissions for RDS, EC2, and KMS
- PHP 8.4+ and Composer installed

### Authentication

Set credentials via environment variables in `.env`:

```env
AWS_ACCESS_KEY_ID=your-access-key-id
AWS_SECRET_ACCESS_KEY=your-secret-access-key
AWS_DEFAULT_REGION=us-east-2
```

Or use AWS credentials file (`~/.aws/credentials`). The command uses the default AWS credential provider chain if environment variables are not set.

### Examples

#### Same-Region Replica

```bash
php artisan aws:rds:create-with-replica \
  --db-instance-identifier=test-same-region \
  --master-username=admin \
  --master-user-password=TestPassword123! \
  --primary-region=us-east-2 \
  --replica-region=us-east-2
```

#### Cross-Region Replica

```bash
php artisan aws:rds:create-with-replica \
  --db-instance-identifier=test-cross-region \
  --master-username=admin \
  --master-user-password=TestPassword123! \
  --primary-region=us-east-2 \
  --replica-region=us-west-1
```

### Command Options

**Database:**
- `--db-instance-identifier`: Instance identifier (default: `db-1`)
- `--master-username`: Master username (default: `admin`)
- `--master-user-password`: Master password (default: `password`)
- `--engine`: Database engine (default: `mysql`)
- `--engine-version`: Engine version (default: `8.0.43`)
- `--db-instance-class`: Instance class (default: `db.t4g.micro`)
- `--allocated-storage`: Storage in GB (default: `20`)

**Network:**
- `--db-subnet-group-name`: DB subnet group (auto-detected/created if not provided)
- `--vpc-security-group-ids`: Security group IDs for primary instance
- `--replica-vpc-security-group-ids`: Security group IDs for replica instance
- `--publicly-accessible`: Make instance publicly accessible

**Encryption:**
- `--kms-key-id`: KMS key ID for primary instance
- `--replica-kms-key-id`: KMS key ID for replica instance

**Regions:**
- `--primary-region`: Primary instance region (default: from config)
- `--replica-region`: Replica instance region (default: same as primary)

### Automatic Features

- **Subnet Groups:** Automatically finds or creates DB subnet groups. If multiple exist, prompts for selection. If none exist, creates one from available VPCs.
- **Cross-Region Replicas:**
  - Automatically encrypts primary instance (creates KMS key if needed)
  - Creates KMS key in replica region if not specified
  - Finds or creates subnet group in replica region
  - Security groups are region-specific (specify `--replica-vpc-security-group-ids` or leave unset)
- **Same-Region Replicas:** Reuses subnet group and security groups from primary instance.

### Important Notes

- Replica identifier is automatically set to `{primary-identifier}-replica`
- Command waits for both instances to be available (takes several minutes)
- RDS requires at least 2 subnets in different availability zones (automatically handled)
- Cross-region replicas require encryption on the primary instance
- IAM permissions needed: RDS (`CreateDBInstance`, `CreateDBInstanceReadReplica`, `DescribeDBInstances`, `CreateDBSubnetGroup`, `DescribeDBSubnetGroups`), EC2 (`DescribeVpcs`, `DescribeSubnets`), KMS (`CreateKey`, `DescribeKey`)
