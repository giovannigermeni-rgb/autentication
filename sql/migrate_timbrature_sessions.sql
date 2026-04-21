START TRANSACTION;

DROP TABLE IF EXISTS `timbrature_nuove`;

CREATE TABLE `timbrature_nuove` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `utente_id` int unsigned NOT NULL,
  `entrata_il` datetime NOT NULL,
  `uscita_il` datetime DEFAULT NULL,
  `durata` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `utente_id` (`utente_id`),
  CONSTRAINT `timbrature_nuove_ibfk_1`
    FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `timbrature_nuove` (`utente_id`, `entrata_il`, `uscita_il`, `durata`)
SELECT
  e.utente_id,
  e.data_ora AS entrata_il,
  u.data_ora AS uscita_il,
  COALESCE(u.durata, CASE
    WHEN u.data_ora IS NOT NULL THEN TIMESTAMPDIFF(SECOND, e.data_ora, u.data_ora)
    ELSE NULL
  END) AS durata
FROM `timbrature` e
LEFT JOIN `timbrature` u
  ON u.id = (
    SELECT u2.id
    FROM `timbrature` u2
    WHERE u2.utente_id = e.utente_id
      AND u2.tipo = 'uscita'
      AND u2.data_ora > e.data_ora
    ORDER BY u2.data_ora ASC
    LIMIT 1
  )
WHERE e.tipo = 'entrata'
ORDER BY e.utente_id, e.data_ora;

RENAME TABLE `timbrature` TO `timbrature_eventi_backup`,
             `timbrature_nuove` TO `timbrature`;

COMMIT;
