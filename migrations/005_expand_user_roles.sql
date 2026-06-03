ALTER TABLE users
  MODIFY role ENUM('viewer','agent','manager','admin') NOT NULL DEFAULT 'agent';
