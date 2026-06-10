CREATE TEMPORARY TABLE category_keep AS
SELECT name, MIN(id) keep_id
FROM categories
GROUP BY name;

UPDATE tickets t
JOIN categories c ON c.id = t.category_id
JOIN category_keep ck ON ck.name = c.name
SET t.category_id = ck.keep_id
WHERE t.category_id <> ck.keep_id;

UPDATE knowledge_articles k
JOIN categories c ON c.id = k.category_id
JOIN category_keep ck ON ck.name = c.name
SET k.category_id = ck.keep_id
WHERE k.category_id <> ck.keep_id;

DELETE c
FROM categories c
JOIN category_keep ck ON ck.name = c.name
WHERE c.id <> ck.keep_id;

DROP TEMPORARY TABLE IF EXISTS category_keep;

ALTER TABLE categories
  ADD UNIQUE KEY uq_categories_name (name);
