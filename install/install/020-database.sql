CREATE TABLE `aiEvents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created` int(10) unsigned NOT NULL,
  `uuid` varchar(128) DEFAULT NULL,
  `uuidHash` binary(20) NOT NULL,
  `aiEvent` mediumtext NOT NULL,
  `priority` float NOT NULL DEFAULT 0.5,
  `status` varchar(12) NOT NULL DEFAULT 'queued',
  `start` int(10) unsigned DEFAULT NULL,
  `end` int(10) unsigned DEFAULT NULL,
  `response` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuidHash_UNIQUE` (`uuidHash`),
  KEY `idx_status_priority` (`status`, `priority` DESC, `id` DESC)
) ENGINE=InnoDB AUTO_INCREMENT=919 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `aiTexts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(128) NOT NULL,
  `uuidHash` binary(20) NOT NULL,
  `contents` text DEFAULT NULL,
  `title` varchar(2048) DEFAULT NULL,
  `description` varchar(2048) DEFAULT NULL,
  `tldr` text DEFAULT NULL,
  `lang` varchar(5) NOT NULL DEFAULT 'en-US',
  `triggerURL` varchar(4096) DEFAULT NULL,
  `tag` varchar(45) DEFAULT NULL,
  `updated` int(10) unsigned NOT NULL DEFAULT unix_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`),
  UNIQUE KEY `uuid_UNIQUE` (`uuid`),
  UNIQUE KEY `uuidHash_UNIQUE` (`uuidHash`)
) ENGINE=InnoDB AUTO_INCREMENT=5864 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
