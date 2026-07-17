-- Extend the banners table for direct image URL storage and subtitle text.
-- The existing media_id FK to media_assets is preserved for future S3 use;
-- image_url is the fast path used by the admin panel upload endpoint.

ALTER TABLE `banners`
    ADD COLUMN `image_url` VARCHAR(500) NULL DEFAULT NULL AFTER `media_id`,
    ADD COLUMN `subtitle`  VARCHAR(255) NULL DEFAULT NULL AFTER `title`;
