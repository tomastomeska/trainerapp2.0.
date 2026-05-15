-- Hotfix: chybejici tabulky pro specialni sporty (beh, golf)
-- Bezpecne pro existujici DB s daty (pouze CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `run_treadmill_sessions` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `session_id`       INT NOT NULL,
  `duration_seconds` INT NOT NULL DEFAULT 0,
  `distance_km`      DECIMAL(7,2) NOT NULL DEFAULT 0,
  `calories_burned`  INT NULL,
  `location`         VARCHAR(255) NULL,
  `feeling`          TEXT NULL,
  `started_at`       DATETIME NULL,
  `ended_at`         DATETIME NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_run_treadmill_session` (`session_id`),
  CONSTRAINT `fk_run_treadmill_session_training`
    FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `run_outdoor_sessions` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `session_id`        INT NOT NULL,
  `duration_seconds`  INT NOT NULL DEFAULT 0,
  `distance_km`       DECIMAL(7,2) NOT NULL DEFAULT 0,
  `run_type`          ENUM('free','interval','tempo','hill','long') NOT NULL DEFAULT 'free',
  `surface`           ENUM('asphalt','trail','track','treadmill','other') NOT NULL DEFAULT 'asphalt',
  `weather`           VARCHAR(120) NULL,
  `avg_pace`          VARCHAR(10) NULL,
  `max_speed`         DECIMAL(5,2) NULL,
  `calories_burned`   INT NULL,
  `step_count`        INT NULL,
  `rpe`               TINYINT NULL,
  `tempo_variability` DECIMAL(5,2) NULL,
  `feeling`           TEXT NULL,
  `started_at`        DATETIME NULL,
  `ended_at`          DATETIME NULL,
  `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_run_outdoor_session` (`session_id`),
  CONSTRAINT `fk_run_outdoor_session_training`
    FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `run_outdoor_splits` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `run_session_id`  INT NOT NULL,
  `km_marker`       DECIMAL(4,2) NOT NULL,
  `split_time`      VARCHAR(10) NOT NULL,
  `pace`            VARCHAR(10) NULL,
  `max_speed_at_km` DECIMAL(5,2) NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_run_outdoor_split` (`run_session_id`, `km_marker`),
  KEY `idx_run_outdoor_splits` (`run_session_id`),
  CONSTRAINT `fk_run_outdoor_splits_session`
    FOREIGN KEY (`run_session_id`) REFERENCES `run_outdoor_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `golf_sessions` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `session_id`         INT NOT NULL,
  `course_id`          INT NULL,
  `tee_id`             INT NULL,
  `tee_name`           VARCHAR(80) NULL,
  `course_name`        VARCHAR(255) NOT NULL,
  `num_holes`          INT NOT NULL DEFAULT 18,
  `game_type`          ENUM('training','stroke_play','stableford','match_play') NOT NULL DEFAULT 'training',
  `distance_km`        DECIMAL(7,2) NULL,
  `calories_burned`    INT NULL,
  `weather`            VARCHAR(120) NULL,
  `players`            VARCHAR(255) NULL,
  `handicap_before`    DECIMAL(5,1) NULL,
  `handicap_after`     DECIMAL(5,1) NULL,
  `count_for_handicap` TINYINT(1) NOT NULL DEFAULT 1,
  `course_rating`      DECIMAL(4,1) NULL,
  `slope_rating`       SMALLINT NULL,
  `score_total`        INT NULL,
  `score_differential` DECIMAL(5,1) NULL,
  `duration_minutes`   INT NULL,
  `feeling`            TEXT NULL,
  `started_at`         DATETIME NULL,
  `ended_at`           DATETIME NULL,
  `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_golf_session_training` (`session_id`),
  KEY `idx_golf_sessions_course` (`course_id`),
  KEY `idx_golf_sessions_tee` (`tee_id`),
  CONSTRAINT `fk_golf_session_training`
    FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `golf_holes` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `golf_session_id` INT NOT NULL,
  `hole_number`     TINYINT NOT NULL,
  `par`             TINYINT NOT NULL,
  `score`           TINYINT NULL,
  `notes`           TEXT NULL,
  UNIQUE KEY `uq_golf_hole` (`golf_session_id`, `hole_number`),
  KEY `idx_golf_holes_session` (`golf_session_id`),
  CONSTRAINT `fk_golf_holes_session`
    FOREIGN KEY (`golf_session_id`) REFERENCES `golf_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
