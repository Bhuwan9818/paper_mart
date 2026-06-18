-- ============================================================
-- MIGRATION: Populate options_list for attribute_definitions
-- Gives vendors predefined dropdown values for common attributes
-- Run AFTER database.sql
-- ============================================================
USE product_enquiry;

-- BF (Bursting Factor) — typical paper board values
UPDATE attribute_definitions
SET options_list = '14,16,18,20,22,24,26,28,30,32,34,36,38,40,42,44,46,48,50,55,60,65,70,75,80'
WHERE attribute_name LIKE '%BF%' OR attribute_name LIKE '%Bursting%';

-- GSM
UPDATE attribute_definitions
SET options_list = '80,90,100,110,120,130,140,150,160,175,200,220,250,280,300,350,400,450,500'
WHERE attribute_name = 'GSM';

-- Moisture (%)
UPDATE attribute_definitions
SET options_list = '6,7,8,9,10,11,12,13,14,15'
WHERE attribute_name LIKE '%Moisture%';

-- Brightness (%)
UPDATE attribute_definitions
SET options_list = '60,65,70,75,80,82,84,85,86,87,88,89,90,92,94,95,96'
WHERE attribute_name LIKE '%Brightness%';

-- Caliper / Thickness (micron)
UPDATE attribute_definitions
SET options_list = '100,120,150,175,200,225,250,275,300,350,400,450,500,600,700,800,900,1000'
WHERE attribute_name LIKE '%Caliper%' OR attribute_name LIKE '%Thickness%';

-- Ply / Layers
UPDATE attribute_definitions
SET options_list = '2 Ply,3 Ply,5 Ply,7 Ply,9 Ply'
WHERE attribute_name LIKE '%Ply%' OR attribute_name LIKE '%Layer%';

-- Flute Type (already set but ensure it's there)
UPDATE attribute_definitions
SET options_list = 'A Flute,B Flute,C Flute,E Flute,F Flute,BC Flute,EB Flute'
WHERE attribute_name LIKE '%Flute%';

-- Width (mm) — common reel widths
UPDATE attribute_definitions
SET options_list = '500,600,700,750,800,850,900,950,1000,1050,1100,1200,1300,1400,1500,1600,1800,2000,2100,2400,2700,3000'
WHERE attribute_name LIKE '%Width%';

-- Color / Shade
UPDATE attribute_definitions
SET options_list = 'Brown,White,Grey,Cream,Yellow,Bleached,Unbleached,Natural'
WHERE attribute_name LIKE '%Color%' OR attribute_name LIKE '%Colour%' OR attribute_name LIKE '%Shade%';

SELECT CONCAT(
  'Updated options_list for ', COUNT(*), ' attribute definitions.'
) AS status
FROM attribute_definitions
WHERE options_list IS NOT NULL AND options_list != '';
