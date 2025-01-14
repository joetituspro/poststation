<?php

namespace PostStation\Admin\Works;

use PostStation\Models\PostBlock;
use PostStation\Models\Webhook;
use WP_List_Table;

if (!class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class PostWorksTable extends WP_List_Table
{
	public function __construct()
	{
		parent::__construct([
			'singular' => 'postwork',
			'plural'   => 'postworks',
			'ajax'     => false
		]);

		// Add hook for custom CSS
		$this->add_custom_styles();
	}

	public function add_custom_styles()
	{
?>
		<style type="text/css">
			.wp-list-table .column-title {
				width: 25%;
			}

			/* .wp-list-table .column-blocks,
.wp-list-table .column-block_status,
.wp-list-table .column-post_type,
.wp-list-table .column-webhook,
.wp-list-table .column-author,
.wp-list-table .column-created_at {
	width: 15%;
} */

			.wp-list-table .status-count {
				display: inline-block;
				padding: 2px 5px;
				border-radius: 3px;
				font-size: 12px;
			}

			.wp-list-table .status-count.pending {
				background: #f0f0f1;
			}

			.wp-list-table .status-count.completed {
				background: #d1e4dd;
			}

			.wp-list-table .status-count.failed {
				background: #f0c5c5;
			}
		</style>
<?php
	}

	public function prepare_items()
	{
		$columns = $this->get_columns();
		$hidden = [];
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = [$columns, $hidden, $sortable];

		// Get all postworks
		global $wpdb;
		$table_name = $wpdb->prefix . 'poststation_postworks';

		// Handle sorting
		$orderby = isset($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'created_at';
		$order = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'DESC';

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} ORDER BY %s %s",
				$orderby,
				$order
			),
			ARRAY_A
		);
	}

	public function get_columns()
	{
		return [
			'cb'         => '<input type="checkbox" />',
			'title'      => __('Title', 'poststation'),
			'blocks'     => __('Blocks', 'poststation'),
			'block_status' => __('Block Status', 'poststation'),
			'post_type'  => __('Post Type', 'poststation'),
			'webhook'    => __('Webhook', 'poststation'),
			'author'     => __('Author', 'poststation'),
			'created_at' => __('Created', 'poststation')
		];
	}

	public function get_sortable_columns()
	{
		return [
			'title'      => ['title', true],
			'post_type'  => ['post_type', false],
			'author'     => ['author_id', false],
			'created_at' => ['created_at', true],
		];
	}

	protected function column_default($item, $column_name)
	{
		return esc_html($item[$column_name] ?? '');
	}

	protected function column_cb($item)
	{
		return sprintf(
			'<input type="checkbox" name="postwork[]" value="%s" />',
			$item['id']
		);
	}

	protected function column_title($item)
	{
		$edit_link = add_query_arg(
			[
				'action' => 'edit',
				'id'     => $item['id']
			]
		);

		$actions = [
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url($edit_link),
				__('Open Postwork', 'poststation')
			),
			'delete' => sprintf(
				'<a href="#" class="delete-postwork" data-id="%d">%s</a>',
				$item['id'],
				__('Delete', 'poststation')
			),
			'export' => sprintf(
				'<a href="#" class="export-postwork" data-id="%d">%s</a>',
				$item['id'],
				__('Export', 'poststation')
			),
		];

		return sprintf(
			'<a href="%1$s" style="text-decoration: none; font-weight: 600;">%2$s</a> %3$s',
			esc_url($edit_link),
			esc_html($item['title']),
			$this->row_actions($actions)
		);
	}

	protected function column_blocks($item)
	{
		$blocks = PostBlock::get_by_postwork($item['id']);
		return sprintf(
			'<span class="blocks-count">%d</span>',
			count($blocks)
		);
	}

	protected function column_block_status($item)
	{
		$blocks = PostBlock::get_by_postwork($item['id']);
		$pending = 0;
		$completed = 0;
		$failed = 0;

		foreach ($blocks as $block) {
			switch ($block['status']) {
				case 'pending':
					$pending++;
					break;
				case 'completed':
					$completed++;
					break;
				case 'failed':
					$failed++;
					break;
			}
		}

		return sprintf(
			'<span class="status-count pending" title="%s">P %d</span> / ' .
				'<span class="status-count completed" title="%s">C %d</span> / ' .
				'<span class="status-count failed" title="%s">F %d</span>',
			__('Pending', 'poststation'),
			$pending,
			__('Completed', 'poststation'),
			$completed,
			__('Failed', 'poststation'),
			$failed
		);
	}

	protected function column_post_type($item)
	{
		$post_type_object = get_post_type_object($item['post_type']);
		return $post_type_object ? esc_html($post_type_object->labels->singular_name) : esc_html($item['post_type']);
	}

	protected function column_webhook($item)
	{
		if (empty($item['webhook_id'])) {
			return '—';
		}

		$webhook = Webhook::get_by_id($item['webhook_id']);
		if (!$webhook) {
			return '—';
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url(add_query_arg(
				['page' => 'poststation-webhooks', 'action' => 'edit', 'id' => $webhook['id']],
				admin_url('admin.php')
			)),
			esc_html($webhook['name'])
		);
	}

	protected function column_author($item)
	{
		$author = get_user_by('id', $item['author_id']);
		return $author ? esc_html($author->display_name) : '';
	}

	protected function column_created_at($item)
	{
		return esc_html(get_date_from_gmt($item['created_at']));
	}

	protected function get_bulk_actions()
	{
		return [
			'delete' => __('Delete', 'poststation'),
			'export' => __('Export', 'poststation'),
		];
	}

	public function no_items()
	{
		_e('No post works found.', 'poststation');
	}
}
