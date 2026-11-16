-- Runs once, only when the pgdata volume is first initialised.
-- Separate database for the automated test suite so tests never touch dev data.
CREATE DATABASE marketplace_test;
