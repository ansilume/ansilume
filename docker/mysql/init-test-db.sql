-- Create the test database and grant access to the app user.
-- MariaDB runs files in /docker-entrypoint-initdb.d/ on first init only,
-- but this volume mount ensures it is always available for rebuilds.
CREATE DATABASE IF NOT EXISTS `ansilume_test`;
GRANT ALL PRIVILEGES ON `ansilume_test`.* TO 'ansilume'@'%';
FLUSH PRIVILEGES;
