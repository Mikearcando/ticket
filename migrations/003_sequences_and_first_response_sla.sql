CREATE TABLE IF NOT EXISTS ticket_sequences (
  year INT PRIMARY KEY,
  next_number INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tickets
  ADD COLUMN first_response_deadline DATETIME NULL AFTER sla_deadline,
  ADD COLUMN first_response_warning_sent_at DATETIME NULL AFTER first_response_deadline,
  ADD COLUMN first_response_breach_sent_at DATETIME NULL AFTER first_response_warning_sent_at;
