CREATE DATABASE IF NOT EXISTS stitch_system
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

-- Optional local user for Laravel connections.
CREATE USER IF NOT EXISTS 'stitch_user'@'localhost' IDENTIFIED BY 'change_me';
GRANT ALL PRIVILEGES ON stitch_system.* TO 'stitch_user'@'localhost';
FLUSH PRIVILEGES;