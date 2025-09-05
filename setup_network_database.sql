-- MySQL Network Setup Script for ABDELMOULA CAMP
-- Run this script in MySQL to enable network access

-- Create a user for network access (optional but recommended)
-- Replace 'your_secure_password' with a strong password
CREATE USER IF NOT EXISTS 'camp_network_user'@'%' IDENTIFIED BY 'your_secure_password';

-- Grant necessary permissions to the network user
GRANT SELECT, INSERT, UPDATE, DELETE ON abdelmoula_camp.* TO 'camp_network_user'@'%';

-- Grant permissions for local access as well
GRANT SELECT, INSERT, UPDATE, DELETE ON abdelmoula_camp.* TO 'camp_network_user'@'localhost';

-- Flush privileges to apply changes
FLUSH PRIVILEGES;

-- Show current users (for verification)
SELECT User, Host FROM mysql.user WHERE User = 'camp_network_user';

-- Show grants for the network user
SHOW GRANTS FOR 'camp_network_user'@'%';
SHOW GRANTS FOR 'camp_network_user'@'localhost';
