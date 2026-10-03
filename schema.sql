-- The Black Couch — Proof-of-Payment Store demo schema (MySQL 8.0+)

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    auth_version INT NOT NULL DEFAULT 0,
    email_verified TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verification_codes (
    user_id INT PRIMARY KEY,
    code_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verification_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_verify_attempt_email_time (email_hash, created_at),
    INDEX idx_verify_attempt_ip_time (ip_hash, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user (user_id, used_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_attempt_email_time (email_hash, created_at),
    INDEX idx_reset_attempt_ip_time (ip_hash, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type VARCHAR(60) NOT NULL DEFAULT 'Merch',
    description TEXT,
    price_cents INT NOT NULL,              -- price is ALWAYS read server-side from here
    image VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS episodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    episode_number INT NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    guest_name VARCHAR(150) NOT NULL,
    category VARCHAR(60) NOT NULL,
    description VARCHAR(1000) NOT NULL,
    youtube_url VARCHAR(255) NOT NULL,
    cover_image_url VARCHAR(500) NOT NULL,
    publish_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- One row per size/variant so stock can be locked at variant granularity.
CREATE TABLE IF NOT EXISTS product_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    size VARCHAR(20) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT chk_stock_non_negative CHECK (stock >= 0),   -- DB-level guard: stock can never go negative
    UNIQUE KEY uq_product_size (product_id, size)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_ref VARCHAR(20) NOT NULL UNIQUE,     -- e.g. TBC1048, shown to customer
    user_id INT NOT NULL,
    total_cents INT NOT NULL,                  -- always computed server-side at checkout, never trusted from client
    delivery_cents INT NOT NULL DEFAULT 0,
    status ENUM('pending_payment','approved','rejected') NOT NULL DEFAULT 'pending_payment',
    verified_by INT NULL,                      -- admin user id who actioned it
    verified_at TIMESTAMP NULL,
    stock_released TINYINT(1) NOT NULL DEFAULT 0, -- guards against double-releasing stock
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_order_verifier FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_addresses (
    order_id INT PRIMARY KEY,
    recipient_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address_line_1 VARCHAR(190) NOT NULL,
    address_line_2 VARCHAR(190) NULL,
    suburb VARCHAR(120) NOT NULL,
    city VARCHAR(120) NOT NULL,
    province VARCHAR(120) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'South Africa',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_address_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    variant_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price_cents INT NOT NULL,             -- snapshot of price at time of order (from DB, not client)
    CONSTRAINT fk_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_proofs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    stored_filename VARCHAR(100) NOT NULL,     -- server-generated random name; NEVER the customer's original filename
    original_mime VARCHAR(100) NOT NULL,       -- detected from file bytes, not the client-supplied Content-Type
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_proof_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed an admin (change the password immediately via the register flow + manual role update in real use)
-- Seed a couple of demo products
INSERT INTO products (name, description, price_cents) VALUES
  ('The Black Couch Classic Tee', 'Premium cotton T-shirt featuring The Black Couch branding.', 39900),
  ('The Black Couch Cap', 'Embroidered cap, one size fits most.', 24900)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO product_variants (product_id, size, stock) VALUES
  (1, 'S', 4), (1, 'M', 12), (1, 'L', 18), (1, 'XL', 9), (1, 'XXL', 2),
  (2, 'One Size', 30)
ON DUPLICATE KEY UPDATE stock = VALUES(stock);
