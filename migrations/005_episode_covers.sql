ALTER TABLE episodes ADD COLUMN cover_image_url VARCHAR(500) NULL AFTER youtube_url;
UPDATE episodes SET cover_image_url = '' WHERE cover_image_url IS NULL;
ALTER TABLE episodes MODIFY cover_image_url VARCHAR(500) NOT NULL;