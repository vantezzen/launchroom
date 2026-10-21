-- Dev only. Runs once, when the launchroom-dev_postgres volume is first created.
-- `make test-dashboard` runs the test suite against this database, so tests
-- never touch the dev database (`launchroom`).
CREATE DATABASE launchroom_test OWNER launchroom;
