-- ============================================================
-- TrainerApp - Kalendář trenéra (události + uzamčené časy)
-- Datum: 2026-05-15
-- ============================================================

CREATE TABLE IF NOT EXISTS `coach_calendar_events` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`     INT NOT NULL,
    `athlete_id`   INT NULL,
    `series_id`    CHAR(36) NULL,
    `color_key`    VARCHAR(20) NOT NULL DEFAULT 'blue',
    `custom_title` VARCHAR(140) NULL,
    `location`     VARCHAR(255) NULL,
    `starts_at`    DATETIME NOT NULL,
    `ends_at`      DATETIME NOT NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_calendar_events_series_start` (`coach_id`, `series_id`, `starts_at`),
    KEY `idx_calendar_events_coach_start` (`coach_id`, `starts_at`),
    KEY `idx_calendar_events_coach_end` (`coach_id`, `ends_at`),
    CONSTRAINT `fk_calendar_events_coach`
        FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_calendar_events_athlete`
        FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coach_calendar_locks` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`   INT NOT NULL,
    `note`       VARCHAR(255) NULL,
    `starts_at`  DATETIME NOT NULL,
    `ends_at`    DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_calendar_locks_coach_start` (`coach_id`, `starts_at`),
    KEY `idx_calendar_locks_coach_end` (`coach_id`, `ends_at`),
    CONSTRAINT `fk_calendar_locks_coach`
        FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coach_calendar_digest_notifications` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`    INT NOT NULL,
    `digest_type` ENUM('daily_tomorrow','weekly_next_week') NOT NULL,
    `digest_date` DATE NOT NULL,
    `sent_at`     DATETIME NOT NULL,
    UNIQUE KEY `uq_calendar_digest` (`coach_id`, `digest_type`, `digest_date`),
    KEY `idx_calendar_digest_type_date` (`digest_type`, `digest_date`),
    CONSTRAINT `fk_calendar_digest_coach`
        FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
