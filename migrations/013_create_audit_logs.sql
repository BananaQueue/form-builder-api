CREATE TABLE IF NOT EXISTS audit_logs (
  id INT(11) NOT NULL AUTO_INCREMENT,
  actor_user_id INT(11) DEFAULT NULL,
  actor_username VARCHAR(100) DEFAULT NULL,
  actor_role VARCHAR(50) DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) DEFAULT NULL,
  entity_id INT(11) DEFAULT NULL,
  entity_label VARCHAR(255) DEFAULT NULL,
  metadata LONGTEXT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_actor_created (actor_user_id, created_at),
  KEY idx_audit_action_created (action, created_at),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
