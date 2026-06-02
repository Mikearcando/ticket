ALTER TABLE tickets
  ADD COLUMN sla_warning_sent_at DATETIME NULL AFTER sla_deadline,
  ADD COLUMN sla_breach_sent_at DATETIME NULL AFTER sla_warning_sent_at;
