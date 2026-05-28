-- 2026-05-25: Databaze jidel a jidelnicku

CREATE TABLE IF NOT EXISTS `coach_meals` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`    INT NOT NULL,
    `name`        VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `grams`       INT NULL,
    `meal_type`   ENUM('breakfast','snack','lunch','dinner','second_dinner','post_workout','cheat_day') NOT NULL,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_coach_meals_coach_type` (`coach_id`, `meal_type`),
    KEY `idx_coach_meals_coach_name` (`coach_id`, `name`),
    CONSTRAINT `fk_coach_meals_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coach_meal_plans` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`   INT NOT NULL,
    `name`       VARCHAR(200) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_coach_meal_plans_coach` (`coach_id`, `created_at`),
    CONSTRAINT `fk_coach_meal_plans_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coach_meal_plan_items` (
    `id`                 INT AUTO_INCREMENT PRIMARY KEY,
    `meal_plan_id`       INT NOT NULL,
    `day_of_week`        ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
    `meal_type`          ENUM('breakfast','snack','lunch','dinner','second_dinner','post_workout','cheat_day') NOT NULL,
    `meal_id`            INT NULL,
    `meal_name_snapshot` VARCHAR(200) NOT NULL,
    `note`               TEXT NULL,
    `position`           INT NOT NULL DEFAULT 1,
    `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_meal_plan_items_plan_day_pos` (`meal_plan_id`, `day_of_week`, `position`),
    KEY `idx_meal_plan_items_meal` (`meal_id`),
    CONSTRAINT `fk_meal_plan_items_plan` FOREIGN KEY (`meal_plan_id`) REFERENCES `coach_meal_plans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_meal_plan_items_meal` FOREIGN KEY (`meal_id`) REFERENCES `coach_meals`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `athlete_meal_plans` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`            INT NOT NULL,
    `athlete_id`          INT NOT NULL,
    `meal_plan_id`        INT NOT NULL,
    `assigned_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `removed_at`          DATETIME NULL,
    `removed_by_coach_id` INT NULL,
    KEY `idx_athlete_meal_plans_athlete_active` (`athlete_id`, `removed_at`),
    KEY `idx_athlete_meal_plans_plan` (`meal_plan_id`),
    KEY `idx_athlete_meal_plans_coach` (`coach_id`),
    CONSTRAINT `fk_athlete_meal_plans_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_athlete_meal_plans_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_athlete_meal_plans_plan` FOREIGN KEY (`meal_plan_id`) REFERENCES `coach_meal_plans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_athlete_meal_plans_removed_by` FOREIGN KEY (`removed_by_coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
