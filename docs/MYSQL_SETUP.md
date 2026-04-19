# MySQL Setup for MyCTOBot

This guide covers MySQL user configuration for new MyCTOBot installations.

## Required MySQL User

MyCTOBot requires a MySQL user that can provision new workspaces. This user needs privileges to:

1. Create databases (`myctobot_*`)
2. Create users (`mctb_*`)
3. Grant privileges to workspace users
4. Flush privileges

## Setup Commands

Connect to MySQL as root and run:

```sql
-- Create the myctobot admin user
CREATE USER 'myctobot'@'%' IDENTIFIED BY 'your_secure_password_here';

-- Grant all required privileges
GRANT ALL PRIVILEGES ON *.* TO 'myctobot'@'%' WITH GRANT OPTION;

FLUSH PRIVILEGES;
```

### Verify Grants

```sql
SHOW GRANTS FOR 'myctobot'@'%';
```

Expected output:
```
+-------------------------------------------------------------------------+
| GRANT ALL PRIVILEGES ON *.* TO `myctobot`@`%` WITH GRANT OPTION         |
+-------------------------------------------------------------------------+
```

## More Restrictive Alternative

If you prefer not to grant full global privileges, the minimum required grants are:

```sql
-- Global privileges needed for provisioning
GRANT CREATE, CREATE USER, RELOAD ON *.* TO 'myctobot'@'%';

-- Full access to all myctobot workspace databases
GRANT ALL PRIVILEGES ON `myctobot_%`.* TO 'myctobot'@'%' WITH GRANT OPTION;

FLUSH PRIVILEGES;
```

**Note:** The `WITH GRANT OPTION` is required because the provisioner creates a dedicated MySQL user for each workspace and grants that user access to their database.

## Configuration

Add the MySQL credentials to your config file (`conf/config.ini` or `conf/config.{workspace}.ini`):

```ini
[database]
host = localhost
name = myctobot_public
user = myctobot
pass = your_secure_password_here

[provisioning]
db_host = localhost
db_admin_user = myctobot
db_admin_pass = your_secure_password_here
```

## Workspace Provisioning Flow

When a new workspace is created, the provisioner:

1. Creates database: `myctobot_{subdomain}`
2. Creates user: `mctb_{subdomain}@localhost`
3. Grants the new user full access to their database only
4. Runs the schema migrations
5. Creates the workspace config file
6. Creates the admin user in the workspace

Each workspace is fully isolated with its own database and credentials.

## Troubleshooting

### "Access denied for user 'myctobot'@'%' to database 'myctobot_xxx'"

The user is missing CREATE privilege or the wildcard grant isn't working. Grant full privileges:

```sql
GRANT ALL PRIVILEGES ON *.* TO 'myctobot'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

### "Access denied; you need the CREATE USER privilege(s)"

```sql
GRANT CREATE USER ON *.* TO 'myctobot'@'%';
FLUSH PRIVILEGES;
```

### "Access denied; you need the RELOAD privilege(s)"

```sql
GRANT RELOAD ON *.* TO 'myctobot'@'%';
FLUSH PRIVILEGES;
```

### "Access denied; you need the GRANT privilege"

Add `WITH GRANT OPTION`:

```sql
GRANT ALL PRIVILEGES ON `myctobot_%`.* TO 'myctobot'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```
