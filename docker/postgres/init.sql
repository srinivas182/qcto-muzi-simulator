-- The application connects as a normal role (not a superuser), exactly as in production,
-- so PostgreSQL row-level security isolates training providers in development too.
CREATE ROLE simuwork LOGIN PASSWORD 'simuwork' NOSUPERUSER CREATEDB;
CREATE DATABASE simuwork OWNER simuwork;
CREATE DATABASE simuwork_test OWNER simuwork;
