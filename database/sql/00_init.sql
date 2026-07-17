-- =====================================================================
-- Multi-Vendor Omnichannel Commerce Platform
-- MySQL DDL  |  Database: test  |  Engine: InnoDB  |  Charset: utf8mb4
-- Generated from: docs/database/* (design baseline v1.0)
-- File 00: initialization
-- ---------------------------------------------------------------------
-- Run order: 00_init -> 01_master -> 02_configuration -> 03_gst ->
--            04_transaction -> 05_payment -> 06_sync ->
--            07_notification -> 08_audit -> 09_finalize
-- (Or simply: SOURCE run_all.sql;)
-- FOREIGN_KEY_CHECKS is disabled across the load so cross-file FK
-- references resolve regardless of physical create order; 09_finalize
-- re-enables it.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
SET UNIQUE_CHECKS = 0;
SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS `test`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `test`;

-- Connection: user `root`, no password (local/dev) per project spec.
-- All monetary columns DECIMAL(15,4); quantities DECIMAL(15,3); rates DECIMAL(5,2).
-- created_at/updated_at are UTC; defaults provided for convenience (app overrides).
