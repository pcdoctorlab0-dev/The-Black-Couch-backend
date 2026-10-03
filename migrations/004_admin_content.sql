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
