<?php
/**
 * Knowledge Vectors Schema
 *
 * MariaDB-native vector storage for RAG knowledge entries and document chunks.
 * Replaces the per-workspace SQLite AssistKnowledgeStore with unified MariaDB tables.
 *
 * Uses MariaDB 11.8+ VECTOR(768) columns with VEC_DISTANCE_COSINE() for similarity search
 * and VECTOR INDEX for accelerated nearest-neighbor queries.
 *
 * Tables:
 *   kbentries       - Knowledge entries (page definitions, FAQs, workflows, etc.)
 *   kbchunks        - Document chunks with embeddings (uploaded files + crawled content)
 *   kbinteractions  - User interaction log for learning
 */

use \RedBeanPHP\R;

// ── kbentries ───────────────────────────────────────────────────────────
// VECTOR columns can't be created via RedBeanPHP dispense, use raw DDL

R::exec('CREATE TABLE IF NOT EXISTS `kbentries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `knowledgebases_id` INT DEFAULT NULL,
    `entry_type` VARCHAR(50) NOT NULL DEFAULT \'page_definition\',
    `page` VARCHAR(500) DEFAULT NULL,
    `element` VARCHAR(255) DEFAULT NULL,
    `title` VARCHAR(500) DEFAULT NULL,
    `content` TEXT NOT NULL,
    `embedding` VECTOR(768) NOT NULL,
    `metadata` JSON DEFAULT NULL,
    `access_count` INT NOT NULL DEFAULT 0,
    `relevance_score` FLOAT NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    VECTOR INDEX `vec_kbentries_embedding` (`embedding`),
    INDEX `idx_kbentries_kb` (`knowledgebases_id`),
    INDEX `idx_kbentries_type` (`entry_type`),
    INDEX `idx_kbentries_page` (`page`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// ── kbchunks ────────────────────────────────────────────────────────────

R::exec('CREATE TABLE IF NOT EXISTS `kbchunks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `knowledgebases_id` INT DEFAULT NULL,
    `ragdocuments_id` INT DEFAULT NULL,
    `filename` VARCHAR(500) NOT NULL,
    `chunk_index` INT NOT NULL DEFAULT 0,
    `content` TEXT NOT NULL,
    `embedding` VECTOR(768) NOT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    VECTOR INDEX `vec_kbchunks_embedding` (`embedding`),
    INDEX `idx_kbchunks_kb` (`knowledgebases_id`),
    INDEX `idx_kbchunks_doc` (`ragdocuments_id`),
    INDEX `idx_kbchunks_filename` (`filename`(191)),
    UNIQUE INDEX `idx_kbchunks_file_chunk` (`filename`(191), `chunk_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// ── kbinteractions ──────────────────────────────────────────────────────

R::exec('CREATE TABLE IF NOT EXISTS `kbinteractions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `page` VARCHAR(500) DEFAULT NULL,
    `user_message` TEXT DEFAULT NULL,
    `assistant_response` TEXT DEFAULT NULL,
    `actions_taken` JSON DEFAULT NULL,
    `feedback` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_kbinteractions_page` (`page`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
