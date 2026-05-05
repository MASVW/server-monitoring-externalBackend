-- MySQL dump 10.13  Distrib 8.4.8, for macos26.3 (arm64)
--
-- Host: 127.0.0.1    Database: indra_server_monitoring
-- ------------------------------------------------------
-- Server version	8.4.8

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `heartbeat_events`
--

DROP TABLE IF EXISTS `heartbeat_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `heartbeat_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `node_id` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `received_at` timestamp NOT NULL,
  `node_timestamp` timestamp NOT NULL,
  `status` enum('ok','degraded','down','unknown') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json DEFAULT NULL,
  `signature_valid` tinyint(1) NOT NULL DEFAULT '0',
  `ip_address` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_heartbeat_events_node_received` (`node_id`,`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `heartbeat_events`
--

LOCK TABLES `heartbeat_events` WRITE;
/*!40000 ALTER TABLE `heartbeat_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `heartbeat_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `incident_events`
--

DROP TABLE IF EXISTS `incident_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `incident_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `node_id` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` enum('ok','degraded','down','unknown') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` enum('ok','degraded','down','unknown') COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` enum('degraded','down','recovered','heartbeat_received','timeout_detected') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata_json` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_incident_events_node_occurred` (`node_id`,`occurred_at`),
  KEY `idx_incident_events_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `incident_events`
--

LOCK TABLES `incident_events` WRITE;
/*!40000 ALTER TABLE `incident_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `incident_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2026_05_04_095340_create_heartbeat_events_table',1),(2,'2026_05_04_095340_create_incident_events_table',1),(3,'2026_05_04_095340_create_monitored_nodes_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `monitored_nodes`
--

DROP TABLE IF EXISTS `monitored_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitored_nodes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `node_id` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unnamed Node',
  `heartbeat_interval_seconds` int unsigned NOT NULL DEFAULT '60',
  `timeout_threshold_seconds` int unsigned NOT NULL DEFAULT '180',
  `secret_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_status` enum('ok','degraded','down','unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `last_heartbeat_at` timestamp NULL DEFAULT NULL,
  `last_payload_json` json DEFAULT NULL,
  `last_summary_json` json DEFAULT NULL,
  `last_alert_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `monitored_nodes_node_id_unique` (`node_id`),
  KEY `idx_monitored_nodes_node_id` (`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `monitored_nodes`
--

LOCK TABLES `monitored_nodes` WRITE;
/*!40000 ALTER TABLE `monitored_nodes` DISABLE KEYS */;
/*!40000 ALTER TABLE `monitored_nodes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'indra_server_monitoring'
--

--
-- Dumping routines for database 'indra_server_monitoring'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-05 17:39:46
