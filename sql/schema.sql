SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email          VARCHAR(255) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  display_name   VARCHAR(120) NOT NULL,
  timezone       VARCHAR(64)  NOT NULL DEFAULT 'UTC',
  email_verified_at DATETIME NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL,
  deleted_at     DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_prefs (
  user_id          BIGINT UNSIGNED NOT NULL,
  shift_minutes    INT UNSIGNED NOT NULL DEFAULT 540,
  break_minutes    INT UNSIGNED NOT NULL DEFAULT 60,
  break_paid       TINYINT(1)   NOT NULL DEFAULT 0,
  reminder_offsets JSON         NOT NULL,
  email_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  push_enabled     TINYINT(1)   NOT NULL DEFAULT 1,
  sound_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  theme            VARCHAR(8)   NOT NULL DEFAULT 'auto',
  PRIMARY KEY (user_id),
  CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE schedule_templates (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  minutes       INT UNSIGNED NOT NULL,
  break_minutes INT UNSIGNED NOT NULL,
  days_json     JSON NOT NULL,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_tpl_user (user_id),
  CONSTRAINT fk_tpl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clients (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  email        VARCHAR(255) NOT NULL,
  alt_emails   VARCHAR(500) NOT NULL DEFAULT '',
  subject_tpl  VARCHAR(255) NOT NULL DEFAULT 'Upcoming End of Shift Reminder',
  body_tpl     TEXT NOT NULL,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_clients_user (user_id, active),
  CONSTRAINT fk_clients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shifts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            BIGINT UNSIGNED NOT NULL,
  client_id          BIGINT UNSIGNED NULL,
  started_at         DATETIME NOT NULL,
  scheduled_end      DATETIME NOT NULL,
  ended_at           DATETIME NULL,
  scheduled_minutes  INT UNSIGNED NOT NULL,
  break_minutes      INT UNSIGNED NOT NULL,
  break_paid         TINYINT(1) NOT NULL,
  overtime_ack_at    DATETIME NULL,
  status             ENUM('active','completed','adjusted') NOT NULL DEFAULT 'active',
  notes              VARCHAR(500) NOT NULL DEFAULT '',
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_shifts_user_status (user_id, status),
  KEY ix_shifts_user_started (user_id, started_at),
  CONSTRAINT fk_shifts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_shifts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE breaks (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shift_id   BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at   DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_breaks_shift (shift_id),
  CONSTRAINT fk_breaks_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
  CONSTRAINT fk_breaks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notification_jobs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  shift_id    BIGINT UNSIGNED NOT NULL,
  channel     ENUM('email','push') NOT NULL,
  fire_at     DATETIME NOT NULL,
  status      ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts    INT UNSIGNED NOT NULL DEFAULT 0,
  last_error  TEXT NULL,
  dedupe_key  VARCHAR(191) NOT NULL,
  payload     JSON NOT NULL,
  sent_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_job_dedupe (dedupe_key),
  KEY ix_jobs_due (status, fire_at),
  KEY ix_jobs_shift (shift_id, status),
  CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_jobs_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id       BIGINT UNSIGNED NOT NULL,
  attempted_at DATETIME NOT NULL,
  ok           TINYINT(1) NOT NULL,
  info         VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY ix_attempts_job (job_id),
  CONSTRAINT fk_attempts_job FOREIGN KEY (job_id) REFERENCES notification_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE push_subscriptions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  endpoint    VARCHAR(500) NOT NULL,
  p256dh      VARCHAR(255) NOT NULL,
  auth        VARCHAR(255) NOT NULL,
  user_agent  VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint(191)),
  KEY ix_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE in_app_notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  kind        VARCHAR(40) NOT NULL,
  title       VARCHAR(200) NOT NULL,
  body        VARCHAR(500) NOT NULL DEFAULT '',
  ref_type    VARCHAR(40) NULL,
  ref_id      BIGINT UNSIGNED NULL,
  read_at     DATETIME NULL,
  dismissed_at DATETIME NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_notif_user (user_id, dismissed_at, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  entity     VARCHAR(40) NOT NULL,
  entity_id  BIGINT UNSIGNED NOT NULL,
  action     VARCHAR(40) NOT NULL,
  before_json JSON NULL,
  after_json  JSON NULL,
  reason     VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_user (user_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reset_hash (token_hash),
  KEY ix_reset_user (user_id),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_verifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_verify_hash (token_hash),
  CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email        VARCHAR(255) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  success      TINYINT(1) NOT NULL,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_login_email_time (email, attempted_at),
  KEY ix_login_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;