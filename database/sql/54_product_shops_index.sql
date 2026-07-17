-- Add composite index on product_shops(product_id, status) to speed up the
-- EXISTS correlated subquery in StoreCatalogRepository::applyFilters():
--   EXISTS (SELECT 1 FROM product_shops ps
--           WHERE ps.product_id = p.id AND ps.status = 'active' AND ps.shop_id IN (...))
-- Without this index, MySQL performs a full product_shops scan for every product row.

ALTER TABLE product_shops
    ADD INDEX IF NOT EXISTS idx_ps_product_status (product_id, status);
