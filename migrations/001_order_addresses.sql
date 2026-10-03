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
