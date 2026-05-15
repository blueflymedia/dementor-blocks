<?php
/**
 * Shared post meta keys.
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

namespace DementorBlocks;

final class MetaKeys {
	public const AUDIT_RESULT      = '_dementor_blocks_audit_result';
	public const CONVERSION_RESULT = '_dementor_blocks_conversion_result';
	public const SOURCE_POST_ID    = '_dementor_blocks_source_post_id';
	public const GENERATED_CSS     = '_dementor_blocks_generated_css';
	public const PRE_REPLACE_BACKUP = '_dementor_blocks_pre_replace_backup';
	public const ELEMENTOR_DATA    = '_elementor_data';
	public const ELEMENTOR_CSS     = '_elementor_css';
}
