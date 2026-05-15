-- ============================================================
-- TrainerApp - KompletnĂ­ SQL import pro reservio.online
-- DatabĂˇze: d391857_tplan
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- Volitelne (na hostingu vetsinou bez prav vytvorit DB):
-- CREATE DATABASE IF NOT EXISTS d391857_tplan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `d391857_tplan`;

-- ------------------------------------------------------------
-- coaches (trenĂ©Ĺ™i)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coaches` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `username`   VARCHAR(100) NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `name`       VARCHAR(200) NULL,
    `email`      VARCHAR(255) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- superadmins (sprĂˇva trenĂ©rĹŻ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `superadmins` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `username`   VARCHAR(100) NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `name`       VARCHAR(200) NULL,
    `email`      VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- athletes (sportovci)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `athletes` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`   INT NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name`  VARCHAR(100) NOT NULL,
    `birth_date` DATE NULL,
    `email`      VARCHAR(255) NULL,
    `photo`      VARCHAR(255) NULL,
    `notes`      TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_athletes_coach` (`coach_id`),
    CONSTRAINT `fk_athletes_coach`
        FOREIGN KEY (`coach_id`) REFERENCES `coaches` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- exercises (cviky)
-- coach_id = NULL u globĂˇlnĂ­ch cvikĹŻ
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exercises` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`   INT NULL,
    `name`       VARCHAR(200) NOT NULL,
    `photo`      VARCHAR(255) NULL,
    `is_global`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_exercises_coach` (`coach_id`),
    INDEX `idx_exercises_global` (`is_global`),
    CONSTRAINT `fk_exercises_coach`
        FOREIGN KEY (`coach_id`) REFERENCES `coaches` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- workout_sets (Ĺˇablony trĂ©ninkĹŻ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `workout_sets` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`   INT NOT NULL,
    `name`       VARCHAR(200) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_workout_sets_coach` (`coach_id`),
    CONSTRAINT `fk_workout_sets_coach`
        FOREIGN KEY (`coach_id`) REFERENCES `coaches` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- workout_set_exercises (cviky v sadÄ›)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `workout_set_exercises` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `workout_set_id` INT NOT NULL,
    `exercise_id`    INT NOT NULL,
    `exercise_order` INT NOT NULL DEFAULT 1,
    INDEX `idx_wse_workout_set` (`workout_set_id`),
    INDEX `idx_wse_exercise` (`exercise_id`),
    UNIQUE KEY `uniq_wse_order` (`workout_set_id`, `exercise_order`),
    CONSTRAINT `fk_wse_workout_set`
        FOREIGN KEY (`workout_set_id`) REFERENCES `workout_sets` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_wse_exercise`
        FOREIGN KEY (`exercise_id`) REFERENCES `exercises` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- training_sessions (trĂ©ninky)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `training_sessions` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `athlete_id`     INT NOT NULL,
    `workout_set_id` INT NOT NULL,
    `location`       VARCHAR(300) NULL,
    `notes`          TEXT NULL,
    `training_photo` VARCHAR(255) NULL,
    `started_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at`   TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_ts_athlete` (`athlete_id`),
    INDEX `idx_ts_workout_set` (`workout_set_id`),
    INDEX `idx_ts_completed_at` (`completed_at`),
    CONSTRAINT `fk_ts_athlete`
        FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ts_workout_set`
        FOREIGN KEY (`workout_set_id`) REFERENCES `workout_sets` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- session_series (sĂ©rie v trĂ©ninku)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `session_series` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `session_id`      INT NOT NULL,
    `exercise_id`     INT NOT NULL,
    `series_order`    INT NOT NULL,
    `weight`          DECIMAL(7,2) NOT NULL DEFAULT 0,
    `reps`            INT NOT NULL DEFAULT 0,
    `assistance_reps` INT NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ss_session` (`session_id`),
    INDEX `idx_ss_exercise` (`exercise_id`),
    UNIQUE KEY `uniq_ss_order` (`session_id`, `exercise_id`, `series_order`),
    CONSTRAINT `fk_ss_session`
        FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ss_exercise`
        FOREIGN KEY (`exercise_id`) REFERENCES `exercises` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- POZNĂMKA:
-- Superadmin ĂşÄŤet zaloĹľte pĹ™es:
--   https://reservio.online/setup_admin.php
-- a nĂˇslednÄ› soubor setup_admin.php ze serveru smaĹľte.
-- ============================================================
