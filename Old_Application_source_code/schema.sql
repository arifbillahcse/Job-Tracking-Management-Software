-- Invoice Manager for Moving Company
-- MySQL Database Schema
-- Run this file once to set up your database:
--   mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS invoice_manager
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE invoice_manager;

-- ─────────────────────────────────────────────
-- Companies
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS companies (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    address    VARCHAR(255) DEFAULT '',
    city       VARCHAR(255) DEFAULT '',
    phone      VARCHAR(50)  DEFAULT '',
    dot_number VARCHAR(50)  DEFAULT '',
    mc_number  VARCHAR(50)  DEFAULT '',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────
-- Drivers
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS drivers (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name  VARCHAR(100) NOT NULL,
    phone      VARCHAR(50)  DEFAULT '',
    license    VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────
-- Company Invoices
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS company_invoices (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    company_id        INT            NOT NULL,
    driver_invoice_id INT            DEFAULT NULL,
    date              DATE           NOT NULL,
    subtotal          DECIMAL(12,2)  DEFAULT 0,
    carrier_fee       DECIMAL(12,2)  DEFAULT 0,
    labor_cost        DECIMAL(12,2)  DEFAULT 0,
    pads              DECIMAL(12,2)  DEFAULT 0,
    paid              DECIMAL(12,2)  DEFAULT 0,
    paid_date         DATE           DEFAULT NULL,
    invoice_remarks   TEXT           DEFAULT '',
    total             DECIMAL(12,2)  DEFAULT 0,
    created_at        TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id)        REFERENCES companies(id)       ON DELETE CASCADE,
    FOREIGN KEY (driver_invoice_id) REFERENCES driver_invoices(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS company_invoice_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id    INT            NOT NULL,
    sort_order    INT            DEFAULT 0,
    job_number    VARCHAR(100)   DEFAULT '',
    driver_id     INT            DEFAULT NULL,
    customer_name VARCHAR(255)   DEFAULT '',
    phone         VARCHAR(30)    DEFAULT '',
    from_location VARCHAR(255)   DEFAULT '',
    to_location   VARCHAR(255)   DEFAULT '',
    cubic_feet    DECIMAL(10,2)  DEFAULT 0,
    rate          DECIMAL(10,4)  DEFAULT 0,
    balance_due   DECIMAL(10,2)  DEFAULT 0,
    new_balance   DECIMAL(10,2)  DEFAULT 0,
    remarks       TEXT,
    FOREIGN KEY (invoice_id) REFERENCES company_invoices(id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────
-- Driver Invoices
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS driver_invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    driver_id       INT            NOT NULL,
    date            DATE           NOT NULL,
    subtotal        DECIMAL(12,2)  DEFAULT 0,
    carrier_fee     DECIMAL(12,2)  DEFAULT 0,
    labor_cost      DECIMAL(12,2)  DEFAULT 0,
    pads            DECIMAL(12,2)  DEFAULT 0,
    paid            DECIMAL(12,2)  DEFAULT 0,
    paid_date       DATE           DEFAULT NULL,
    invoice_remarks TEXT           DEFAULT '',
    total           DECIMAL(12,2)  DEFAULT 0,
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS driver_invoice_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id    INT            NOT NULL,
    sort_order    INT            DEFAULT 0,
    job_number    VARCHAR(100)   DEFAULT '',
    company_id    INT            DEFAULT NULL,
    customer_name VARCHAR(255)   DEFAULT '',
    phone         VARCHAR(30)    DEFAULT '',
    from_location VARCHAR(255)   DEFAULT '',
    to_location   VARCHAR(255)   DEFAULT '',
    cubic_feet    DECIMAL(10,2)  DEFAULT 0,
    rate          DECIMAL(10,4)  DEFAULT 0,
    balance_due   DECIMAL(10,2)  DEFAULT 0,
    new_balance   DECIMAL(10,2)  DEFAULT 0,
    remarks       TEXT,
    FOREIGN KEY (invoice_id) REFERENCES driver_invoices(id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────
-- Admin Users
-- Run this in phpMyAdmin SQL tab if you already imported the rest of schema.sql:
--   CREATE TABLE IF NOT EXISTS users ( ... ) -- (copy the block below)
-- Then visit create-admin.php to create your first account.
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────
-- Migration: Add labor_cost and pads to driver_invoices
-- Run these if you already have an existing database
-- (safe to run multiple times — IF NOT EXISTS guards it)
-- ─────────────────────────────────────────────
ALTER TABLE driver_invoices
    ADD COLUMN IF NOT EXISTS labor_cost DECIMAL(12,2) DEFAULT 0 AFTER carrier_fee,
    ADD COLUMN IF NOT EXISTS pads       DECIMAL(12,2) DEFAULT 0 AFTER labor_cost;

-- ─────────────────────────────────────────────
-- Migration: Add driver_invoice_id, labor_cost, pads to company_invoices
-- Step 1 – columns (IF NOT EXISTS supported in MariaDB 10.0.2+)
-- ─────────────────────────────────────────────
ALTER TABLE company_invoices
    ADD COLUMN IF NOT EXISTS driver_invoice_id INT           DEFAULT NULL AFTER company_id,
    ADD COLUMN IF NOT EXISTS labor_cost        DECIMAL(12,2) DEFAULT 0    AFTER carrier_fee,
    ADD COLUMN IF NOT EXISTS pads              DECIMAL(12,2) DEFAULT 0    AFTER labor_cost;

-- Step 2 – foreign key (run once; skip if constraint already exists)
ALTER TABLE company_invoices
    ADD CONSTRAINT fk_co_inv_driver_inv
        FOREIGN KEY (driver_invoice_id) REFERENCES driver_invoices(id) ON DELETE SET NULL;

-- Migration: Add paid and paid_date to both invoice tables
ALTER TABLE driver_invoices
    ADD COLUMN IF NOT EXISTS paid      DECIMAL(12,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS paid_date DATE          DEFAULT NULL;

ALTER TABLE company_invoices
    ADD COLUMN IF NOT EXISTS paid      DECIMAL(12,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS paid_date DATE          DEFAULT NULL;

-- Migration: Add invoice_remarks to both invoice tables
ALTER TABLE driver_invoices
    ADD COLUMN IF NOT EXISTS invoice_remarks TEXT DEFAULT '';

ALTER TABLE company_invoices
    ADD COLUMN IF NOT EXISTS invoice_remarks TEXT DEFAULT '';

-- Migration: Add phone to invoice item tables
ALTER TABLE driver_invoice_items
    ADD COLUMN IF NOT EXISTS phone VARCHAR(30) DEFAULT '' AFTER customer_name;

ALTER TABLE company_invoice_items
    ADD COLUMN IF NOT EXISTS phone VARCHAR(30) DEFAULT '' AFTER customer_name;
