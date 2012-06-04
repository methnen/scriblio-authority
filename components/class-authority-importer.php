<?php

/**
 * Scriblio Authority Importer
 *
 * Modeled after wordpress-importer.
 *
 * @see http://plugins.svn.wordpress.org/wordpress-importer/trunk/wordpress-importer.php
 */


if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

class Authority_Importer
{
	protected $parser;

	public function __construct()
	{
	}

	public function dispatch()
	{
		$this->header();

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
		switch ( $step ) {
			case 0:
				$this->greet();
				break;
			case 1:
				check_admin_referer( 'import-upload' );
				if( $this->handle_upload() )
				{
					set_time_limit(0);
					$file = get_attached_file( $this->id );
					$this->parse( $file );
					$this->next();
				}
				break;
			case 2:
				check_admin_referer( 'import-scriblio-csv' );
				$this->id = (int) $_POST['import_id'];
				$file = get_attached_file( $this->id );
				$this->parse( $file );
				$position = isset( $_POST['position'] ) ? (int) $_POST['position'] : 0;
				$new_position = $this->import( compact( 'position' ) );
				$this->next( $new_position );
				break;
		}

		$this->footer();
	}

	public function handle_upload()
	{
		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) )
		{
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'scribauth-importer' ) . '</strong><br />';
			echo esc_html( $file['error'] ) . '</p>';
			return false;
		}
		elseif ( ! file_exists( $file['file'] ) )
		{
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'scribauth-importer' ) . '</strong><br />';
			printf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'wordpress-importer' ), esc_html( $file['file'] ) );
			echo '</p>';
			return false;
		}

		$this->id = (int) $file['id'];

		return true;
	}

	public function next( $position = 0 )
	{
		?>
		<form class="scrib-auth-importer" action="<?php echo admin_url( 'admin.php?import=scriblio_authority&amp;step=2' ); ?>" method="post">
			<?php wp_nonce_field( 'import-scriblio-csv' ); ?>
			<input type="hidden" name="import_id" value="<?php echo $this->id; ?>" />
			<input type="hidden" name="position" value="<?php echo $position; ?>" />
			<input type="submit" value="Load More Records">
		</form>
		<script type="text/javascript">
		(function($){
			$(function(){
				function doSubmit() {
					//$('.scrib-auth-importer').submit();
				}
				setTimeout( doSubmit, 3000 );
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * Run the import process
	 */
	public function import( $args = '' )
	{
		$defaults = array(
			'position' => 0,
			'limit' => 10,
		);
		$args = wp_parse_args( $args, $defaults );
		extract( $args, EXTR_SKIP );

		if ( $position )
		{
			$this->parser->seek( $position );
		}

		$count = 0;
		while ( false !== ( $line = $this->parser->next() ) )
		{
			$result = $this->import_one( $line );

			if ( is_wp_error( $result ) )
			{
				printf( '<li>%s (tax:%s, name:%s, corrected name: %s)</li>', $result->get_error_message(),
					$line['taxonomy'], $line['name'], $line['Corrected Name'] );
			}
			else
			{
				printf( '<li>Added "%s" to "%s" in %s.</li>', $line['name'],
					$line['Corrected Name'], $line['taxonomy'] );
			}

			if ( ++$count > $limit )
			{
				return $this->parser->tell();
			}
		}

		return false;
	}

	/**
	 * Import a single record from the CSV as an authority record.
	 */
	function import_one( $record )
	{
		global $scriblio_authority_posttype;

		if ( ! isset($record['taxonomy']) ||
			! isset($record['Corrected Name']) ||
			! isset($record['name'])
		) {
			return new WP_Error( 'missing-field', 'One or more fields were missing from the record' );
		}

		$taxonomy = $record['taxonomy'];

		$primary_term_name = $record['Corrected Name'];
		$alias_term_name = $record['name'];

		$primary_term = $this->get_or_insert_term( $primary_term_name, $taxonomy );
		$alias_term = $this->get_or_insert_term( $alias_term_name, $taxonomy );

		if ( false === ( $post_id = $scriblio_authority_posttype->get_term_authority( $primary_term ) ) )
		{
			$post_id = $scriblio_authority_posttype->create_authority_record( $primary_term, array( $alias_term ) );
		}
	}

	function get_or_insert_term( $term_name, $taxonomy )
	{
		if ( $term_id = get_term_by( 'name', $term_name, $taxonomy ) )
		{
			$term = get_term( $term_id, $taxonomy );
		}
		else
		{
			$term = wp_insert_term( $term_name, $taxonomy );

			if ( is_wp_error( $term ) )
				return $term;

			$term = get_term( $term['term_id'], $taxonomy );
		}

		return $term;
	}

	function import_options()
	{
		$j = 0;
?>
<form action="<?php echo admin_url( 'admin.php?import=scriblio_authority&amp;step=2' ); ?>" method="post">
	<?php wp_nonce_field( 'import-scriblio-csv' ); ?>
	<input type="hidden" name="import_id" value="<?php echo $this->id; ?>" />
	<p class="submit"><input type="submit" class="button" value="<?php esc_attr_e( 'Submit', 'wordpress-importer' ); ?>" /></p>
</form>
<?php
	}

	public function parse( $file )
	{
		$this->parser = new Authority_CSV_Parser;
		$this->parser->parse( $file );
	}

	public function header()
	{
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>' . __( 'Import Scriblio Authority CSV', 'scribauth-importer' ) . '</h2>';
	}

	public function footer()
	{
		echo '</div>';
	}

	public function hooks()
	{
	}

	public function greet()
	{
		wp_import_upload_form( 'admin.php?import=scriblio_authority&amp;step=1' );
	}
}

function scriblio_authority_importer_init()
{
	$GLOBALS['authority_import'] = new Authority_Importer();
	register_importer( 'scriblio_authority', 'Scriblio Authority CSV', 'Import Scriblio Authority records from CSV.', array( $GLOBALS['authority_import'], 'dispatch' ) );
}
add_action( 'admin_init', 'scriblio_authority_importer_init' );
