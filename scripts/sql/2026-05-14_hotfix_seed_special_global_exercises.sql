-- Hotfix: doplneni vychozich globalnich cviku pro specialni sporty
-- Vlozi je pouze pokud pro dany sport_type zadny globalni cvik neexistuje.

SET NAMES utf8mb4;

INSERT INTO `exercises` (`coach_id`, `name`, `photo`, `sport_type`, `is_global`)
SELECT NULL, 'Golf', NULL, 'golf', 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `exercises` WHERE `is_global` = 1 AND `sport_type` = 'golf'
);

INSERT INTO `exercises` (`coach_id`, `name`, `photo`, `sport_type`, `is_global`)
SELECT NULL, 'Beh venku', NULL, 'run_outdoor', 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `exercises` WHERE `is_global` = 1 AND `sport_type` = 'run_outdoor'
);

INSERT INTO `exercises` (`coach_id`, `name`, `photo`, `sport_type`, `is_global`)
SELECT NULL, 'Beh na pase', NULL, 'run_treadmill', 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `exercises` WHERE `is_global` = 1 AND `sport_type` = 'run_treadmill'
);
