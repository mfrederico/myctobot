<?php
/**
 * Knowledge Bases Schema
 *
 * Knowledge bases and RAG documents for AI context retrieval.
 * Keeps parent alive until children are trashed.
 */

// Create knowledgebases table (parent)
$kb = \RedBeanPHP\R::dispense('knowledgebases');
$kb->name = 'Schema Placeholder KB';
$kb->slug = 'schema-placeholder-kb';
$kb->description = 'Schema placeholder knowledge base';
$kb->agent_profile_id = null;
$kb->member_id = null;
$kb->created_at = date('Y-m-d H:i:s');
$kb->updated_at = date('Y-m-d H:i:s');
$parentId = \RedBeanPHP\R::store($kb);

// Create ragdocuments table (child with FK to knowledgebases)
$doc = \RedBeanPHP\R::dispense('ragdocuments');
$doc->knowledgebase_id = $parentId;
$doc->member_id = null;
$doc->filename = 'schema_placeholder_doc.pdf';
$doc->original_filename = 'schema_placeholder_doc.pdf';
$doc->file_size = 0;
$doc->mime_type = 'application/pdf';
$doc->file_extension = 'pdf';
$doc->status = 'pending';
$doc->vision_model = null;
$doc->created_at = date('Y-m-d H:i:s');
$doc->error_message = null;
$doc->chunk_count = 0;
$doc->processed_at = null;
\RedBeanPHP\R::store($doc);
\RedBeanPHP\R::trash($doc);

// Fix columns
\RedBeanPHP\R::exec('ALTER TABLE `ragdocuments` MODIFY COLUMN `file_size` INT DEFAULT 0');
\RedBeanPHP\R::exec('ALTER TABLE `ragdocuments` MODIFY COLUMN `chunk_count` TINYINT DEFAULT 0');

// Trash parent after children are cleaned up
\RedBeanPHP\R::trash($kb);
