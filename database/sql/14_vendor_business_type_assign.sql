-- =====================================================================
-- 14_vendor_business_type_assign.sql
-- Idempotent: only touches vendors where business_type_id IS NULL.
-- Phase 1: name-pattern REGEXP → direct business type assignment.
-- Phase 2: majority-vote fallback for any vendor still untyped.
-- =====================================================================

-- Phase 1: Name-based assignment
UPDATE vendors v
JOIN business_types bt
  ON bt.code = CASE
    WHEN v.display_name REGEXP '(Foods?|Kirana|Grocery|Fresh|Daily|Bazaar|Mart|Sabji|Veggie|Produce)' THEN 'grocery'
    WHEN v.display_name REGEXP '(Footwear|Shoes?|Sandal|Heels?|Boot|Sneaker|Chappal)'                THEN 'footwear'
    WHEN v.display_name REGEXP '(Electronics?|Gadget|Tech|Mobile|Digital|Computer|Laptop)'            THEN 'electronics'
    WHEN v.display_name REGEXP '(Fashion|Cloth|Apparel|Wear|Dress|Saree|Textile|Boutique)'           THEN 'fashion'
    WHEN v.display_name REGEXP '(Cafe|Restaurant|Bakery|Snack|Sweets?|Mithai|Juice|Coffee)'          THEN 'fnb'
    WHEN v.display_name REGEXP '(Home|Kitchen|Furniture|Decor|Interior|Household)'                   THEN 'home_kitchen'
    WHEN v.display_name REGEXP '([[:<:]](Health|Beauty|Salon|Spa|Wellness|Cosme|Skin|Hair)[[:>:]])'   THEN 'health_beauty'
    WHEN v.display_name REGEXP '(Sports?|Fitness|Gym|Athletic|Cricket|Football)'                     THEN 'sports_fitness'
    WHEN v.display_name REGEXP '(Books?|Library|Stationery|Games|Media|Publishing)'                  THEN 'books_digital'
    WHEN v.display_name REGEXP '(Hotel|Hospitality|Resort|Lodge|Stay|Hostel)'                        THEN 'hotels'
    WHEN v.display_name REGEXP '(Medical|Hospital|Clinic|Pharma|Drug|Medicine|Lab)'                  THEN 'medical'
    ELSE NULL
  END
  AND bt.deleted_at IS NULL
SET v.business_type_id = bt.id,
    v.updated_at       = NOW()
WHERE v.deleted_at IS NULL
  AND v.business_type_id IS NULL;

-- Phase 2: Majority-vote fallback for any vendors still untyped
UPDATE vendors v
JOIN (
  SELECT p.vendor_id,
         (SELECT m.business_type_id
          FROM products p2
          JOIN business_type_category_map m ON m.category_id = p2.category_id
          WHERE p2.vendor_id = p.vendor_id AND p2.deleted_at IS NULL
          GROUP BY m.business_type_id
          ORDER BY COUNT(*) DESC
          LIMIT 1
         ) AS majority_type_id
  FROM products p
  WHERE p.deleted_at IS NULL
  GROUP BY p.vendor_id
) derived ON derived.vendor_id = v.id AND derived.majority_type_id IS NOT NULL
SET v.business_type_id = derived.majority_type_id,
    v.updated_at       = NOW()
WHERE v.deleted_at IS NULL
  AND v.business_type_id IS NULL;
