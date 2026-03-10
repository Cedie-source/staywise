-- Add admin_role to users if it does not exist
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `admin_role` VARCHAR(50) NULL AFTER `role`;

-- Optional: seed super admin for convenience if none exist
UPDATE `users`
SET `admin_role` = 'super_admin'
WHERE `role` = 'admin' AND `username` = 'admin' AND (`admin_role` IS NULL OR `admin_role` = '');
