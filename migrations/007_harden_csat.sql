CREATE TEMPORARY TABLE IF NOT EXISTS csat_keep AS
SELECT cs.id
FROM csat_surveys cs
JOIN (
  SELECT ticket_id, MAX(CASE WHEN submitted_at IS NOT NULL THEN id ELSE 0 END) AS submitted_id, MIN(id) AS first_id
  FROM csat_surveys
  GROUP BY ticket_id
) chosen ON chosen.ticket_id = cs.ticket_id
  AND cs.id = CASE WHEN chosen.submitted_id > 0 THEN chosen.submitted_id ELSE chosen.first_id END;

DELETE cs
FROM csat_surveys cs
LEFT JOIN csat_keep keep_rows ON keep_rows.id = cs.id
WHERE keep_rows.id IS NULL;

DROP TEMPORARY TABLE IF EXISTS csat_keep;

ALTER TABLE csat_surveys
  ADD UNIQUE KEY uq_csat_ticket (ticket_id);
