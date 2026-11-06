-- Allow coordinator records before their real email addresses are available.
-- NULL keeps unknown contact details distinct from invented or blank addresses.
ALTER TABLE users MODIFY COLUMN email VARCHAR(150) NULL DEFAULT NULL;
