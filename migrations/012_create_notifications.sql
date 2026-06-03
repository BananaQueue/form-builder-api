CREATE TABLE IF NOT EXISTS notifications (
  id INT(11) NOT NULL AUTO_INCREMENT,
  recipient_user_id INT(11) NOT NULL,
  type ENUM('FORM_EDITED', 'FORM_DELETED') NOT NULL,
  form_id INT(11) DEFAULT NULL,
  form_title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  deletion_reason TEXT DEFAULT NULL,
  admin_id INT(11) DEFAULT NULL,
  admin_name VARCHAR(100) DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  acknowledged TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recipient_pending (recipient_user_id, acknowledged, created_at),
  KEY idx_recipient_created (recipient_user_id, created_at),
  CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
