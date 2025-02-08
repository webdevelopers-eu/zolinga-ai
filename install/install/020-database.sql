CREATE TABLE `aiTexts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(128) NOT NULL,
  `uuidHash` binary(20) NOT NULL,
  `contents` text DEFAULT 'null',
  `lang` varchar(5) NOT NULL DEFAULT 'en-US',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`),
  UNIQUE KEY `uuid_UNIQUE` (`uuid`),
  UNIQUE KEY `uuidHash_UNIQUE` (`uuidHash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `aiRequests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created` int(10) unsigned NOT NULL,
  `uuid` varchar(128) DEFAULT NULL,
  `uuidHash` binary(20) NOT NULL,
  `promptEvent` text NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'queued',
  `reqStart` int(10) unsigned DEFAULT NULL,
  `reqEnd` int(10) unsigned DEFAULT NULL,
  `response` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuidHash_UNIQUE` (`uuidHash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
