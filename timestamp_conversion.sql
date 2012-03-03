ALTER TABLE jobs
  ADD COLUMN created_at_ts INT NOT NULL,
  ADD COLUMN locked_at_ts INT,
  ADD COLUMN failed_at_ts INT,
  ADD COLUMN run_at_ts INT;

UPDATE jobs SET
  created_at_ts = UNIX_TIMESTAMP(created_at),
  locked_at_ts = UNIX_TIMESTAMP(locked_at),
  failed_at_ts = UNIX_TIMESTAMP(failed_at),
  run_at_ts = UNIX_TIMESTAMP(run_at);

ALTER TABLE jobs
  DROP COLUMN created_at,
  DROP COLUMN locked_at,
  DROP COLUMN failed_at,
  DROP COLUMN run_at;

ALTER TABLE jobs
  CHANGE created_at_ts created_at INT NOT NULL,
  CHANGE locked_at_ts locked_at INT,
  CHANGE failed_at_ts failed_at INT,
  CHANGE run_at_ts run_at INT;