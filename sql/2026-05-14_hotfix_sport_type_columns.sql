-- Hotfix: doplneni sloupcu sport_type pro verzi 1.1.01
-- Varianta bez PREPARE/EXECUTE (lepsi kompatibilita s phpMyAdmin/WEDOS).

SET NAMES utf8mb4;

ALTER TABLE `exercises`
  ADD COLUMN IF NOT EXISTS `sport_type` ENUM('standard','golf','run_outdoor','run_treadmill') NOT NULL DEFAULT 'standard' AFTER `photo`;

ALTER TABLE `training_session_exercises`
  ADD COLUMN IF NOT EXISTS `sport_type` ENUM('standard','golf','run_outdoor','run_treadmill') NOT NULL DEFAULT 'standard' AFTER `exercise_name`;
