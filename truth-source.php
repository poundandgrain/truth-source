<?php
/**
 * Truth Source
 *
 * Plugin Name: Truth Source
 *
 * Write something here
 */


final class Truth_Source {

	private static $settings;

	/**
	 * Holds the class instance.
	 *
	 * @var Truth_Source
	 */
	private static $instance;
	private static $errors;

	/**
	 * Get the Sentry admin page instance.
	 *
	 * @return \Truth_Source
	 */
	public static function get_instance(): Truth_Source {
		return self::$instance ?: self::$instance = new self;
	}

	protected function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action('admin_init',  [ $this, 'on_admin_init' ] );

		self::$errors[] = [];
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
	}

	/**
	 * Setup the admin menu page.
	 */
	public function admin_menu(): void {
		add_management_page(
			'Source of Truth',
			'Source of Truth',
			'activate_plugins',
			'truth-source',
			[ $this, 'render_admin_page' ]
		);
	}


	public static function on_admin_init() {
		$settings = self::get_settings();

		add_filter( 'plugin_action_links', array( __CLASS__, 'add_settings_link' ), 10, 2 );
		add_filter( 'network_admin_plugin_action_links', array( __CLASS__, 'add_settings_link' ), 10, 2 );

		if( ! empty($_POST) )
		{
			self::check_form_submissions();
		}
	}

	private static function check_form_submissions()
	{
		$settings = self::get_settings();

		if( ! empty($_POST['new_source']) )
		{
			$newSourcePost = strtolower($_POST['new_source']);
			$newSource = parse_url($newSourcePost);

			if(($newSource['scheme'] == 'http' || $newSource['scheme'] == 'https') && !empty($newSource['host']))
			{
				$cleanedSource = $newSource['scheme'] . '://'.$newSource['host'];

				if( !in_array($cleanedSource, $settings['sources'] )) {
					$settings['sources'][] = $cleanedSource;
					update_option('truth-source', $settings);

					self::update_remotes();
				}
				else
				{
					self::$errors[] = "'${cleanedSource}' already exists as environment.";
				}

			} else {
				self::$errors[] = "'${newSourcePost}' is not a proper url.";
			}
		}
		if( ! empty($_POST['remove']) )
		{
			$removeSource = strtolower($_POST['remove']);
			if( $settings['sot'] != $removeSource)
			{
				array_splice($settings['sources'], (int)$_POST['remove']-1, 1);
				update_option('truth-source', $settings);

				self::update_remotes();
			}
			else
			{
				self::$errors[] = "'${removeSource}' is the source of truth. Please select another source before removing.";
			}
		}
		if( ! empty($_POST['make_source']) )
		{
			$settings['sot'] = $_POST['make_source'];
			//dd($settings['sources']);
			update_option('truth-source', $settings);

			self::update_remotes();
		}
	}

	private static function get_settings( $refresh = 'no' ) {

		if ( ! empty( self::$settings ) && $refresh === 'no' ) {
			return self::$settings;
		}

		$defaults = [
			'sources' => [],
			'sot' => false,
			'status' => [],
			'token' => '',
		];

		if ( is_multisite() ) {
			$options = get_network_option( 'truth-source' );
		} else {
			$options = get_option( 'truth-source' );
		}
		if( ! empty( $options ) ) {
			$defaults = $options;
		}

		self::$settings = $defaults;

		return self::$settings;
	}


	/**
	 * Add a link to the settings on the Plugins screen.
	 */
	public static function add_settings_link( $links, $file ) {
		if ( $file === 'truth-source/truth-source.php' && current_user_can( 'manage_options' ) ) {
			$url = admin_url( 'tools.php?page=truth-source&api=1' );
			// Prevent warnings in PHP 7.0+ when a plugin uses this filter incorrectly.
			$links = (array) $links;
			$links[] = sprintf( '<a href="%s">%s</a>', $url, __( 'Settings', 'classic-editor' ) );
		}

		return $links;
	}

	/**
	 * Set defaults on activation.
	 */
	public static function activate() {
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );

		$options = [];
		$options[] = [
			'sources' => home_url(),
			'sot' => true,
			'status' => [],
			'token' => '',
		];
		if ( is_multisite() ) {
			add_network_option( null, 'truth-source', $options );
		}

		add_option( 'truth-source', $options );
	}

	/**
	 * Delete the options on uninstall.
	 */
	public static function uninstall() {
		if ( is_multisite() ) {
			delete_network_option( null, 'truth-source' );
		}

		delete_option( 'truth-source' );
	}

	// public static function add_edit_php_inline_style() {
	// 	$css = "
	// 	.url-sot {
	// 		background-color: red;
	// 	}
	// 	";
	// 	wp_enqueue_style('truth-source', get_template_directory_uri() . '/css/custom.css', array(), '1.0.0', 'all' );
	// 	wp_add_inline_style('truth-source', $css);
	// }

	public static function update_remotes() {
		$settings = self::get_settings('refresh');
		$admin_path = str_replace(home_url(), '', admin_url('admin-ajax.php'));
		$admin_path .= '?action=truth_source';

		foreach($settings['sources'] as $source):
			// don't curl yo'self dummy!
			if($source == home_url()) {
				$settings['status'][$source] = true;
			}
			else
			{
				$curl = curl_init();
				// set url
				curl_setopt($curl, CURLOPT_URL, $source . $admin_path);
				//return the transfer as a string
				curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($settings));
				curl_setopt($curl, CURLOPT_HEADER, 0);
				curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
				curl_setopt($curl, CURLOPT_POST, 1);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
				// $output contains the output string
				$data = json_decode(curl_exec($curl));

				curl_close($curl);

				if(empty($data)) {
					self::$errors[] = "Host `${source}` unreachable";
				} else {
					$settings['status'][$source] = $data->success;

					// combine remote sources with these sources
					$settings['sources'] = array_unique(array_merge($data->sources,$settings['sources']), SORT_REGULAR);

					if(!empty($data->message)) {
						self::$errors[] = $data->message;
					}
				}
			}
			// close curl resource to free up system resources
		endforeach;

		update_option('truth-source', $settings);
	}

	/**
	 * Render the API settings page.
	 */
	public function api_settings_page() {
		$settings = self::get_settings();
		$token = $settings['token'];
		//self::$errors[] = '';
		if( !empty($_POST['token']) )
		{
			$settings['token'] = $token = $_POST['token'];
			update_option('truth-source', $settings);
		}
		elseif(empty($token))
		{
			$bytes = random_bytes(20);
			$token = bin2hex($bytes);
		}
		?>
		<style>

		</style>
        <div class="wrap">
            <h1>Source of Truth API Settings</h1>
			<a href="?page=truth-source">Admin settings</a>
			<?php self::show_errors(); ?>
			<form method="POST">
				<p>
					<label>Token</label>
					<input type="text" class="code regular-text" value="<?php echo $token; ?>" name="token">
				</p>
				<?php submit_button(); ?>
			</form>
        </div>
		<?php
	}

	/**
	 * Render the admin page.
	 */
	public static function admin_page() {
		$settings = self::get_settings('refresh');
		//self::$errors[] = '';
		?>
		<style>
		.url-sot-2 {
			background-color: #ffaaaa !important;
		}
		.sot-circle {
			border-radius: 50%;
			width: 12px;
			height: 12px;
			background-color: #888;
			margin: auto;
			border: 1px solid #000;
		}
		.sot-circle--green {
			background-color: #0F0;
		}
		.sot-circle--red {
			background-color: #F00;
		}
		.sot-col-sm {
			width: 4em;
		}
		</style>
        <div class="wrap">
            <h1>Source of Truth Settings</h1>
			<a href="?page=truth-source&api=1">API settings</a>
			<?php self::show_errors(); ?>
			<form method="POST">
				<input type="hidden" class="code" value="" name="recheck">
				<?php submit_button('Re-check sources'); ?>
			</form>
			<form method="POST">
				<table class="fixed wp-list-table widefat striped table-view-list pages">
				<thead>
					<tr>
						<th scope="col" class="column-primary column-url">URL</th>
						<th scope="col" class="column-actions">Actions</th>
						<th scope="col" class="column-status sot-col-sm">Status</th>
					</tr>
					</thead>
					<?php
					$i = 0;
					foreach($settings['sources'] as $source):
						$i++;
						//$data = self::check_remote($source);
					?>
					<tr class="<?php echo($source === $settings['sot'] ? "url-sot" : "url-not-sot") ?>">
						<td>
							<?php //echo('<input type="text" class="code" name="source_' . $i . '" value="'. $source .'" />'); ?>
							<?php echo("<a href=\"${source}\" target=\"_blank\">${source}</a>"); ?>
						</td>
						<td>
						<?php echo('<button value="' . $i . '" class="button-link editinline" name="remove">Remove</button>'); ?>
						<?php
							if( $source === $settings['sot']):
								echo(' | This is current Source');
							elseif($settings['status'][$source] !== true):
								echo(' | Error connecting');
							else:
								echo(' | <button value="' . $source . '" class="button-link editinline" name="make_source">Make this source</button>');
							endif;
						?>

						</td>
						<td>
							<?php
								if( $source === $settings['sot']):
									echo("<div class=\"sot-circle sot-circle--green\"></div>");
								elseif($settings['status'][$source] !== true):
									echo("<div class=\"sot-circle sot-circle--red\"></div>");
								else:
									echo("<div class=\"sot-circle\"></div>");
								endif;
							?>
						</td>
					</tr>
					<?php
					endforeach;
					?>
				</table>
			</form>
			<br><br>
			<form method="POST" class="acf-field">
				<label>Add New Source</label>
				<input type="text" class="code regular-text" value="" name="new_source">
				<?php submit_button('Add Source'); ?>
			</form>
        </div>
	<?php
	}

	/**
	 * Form pages.
	 */
	public static function render_admin_page() {
		if($_GET['api']) {
			self::api_settings_page();
		} else {
			self::admin_page();
		}

	}

	function show_errors() {
		foreach(self::$errors as $error):
			if(!empty($error)):
				?>
				<div class="notice notice-error notice-big-error" style="background: #f88;color: white;">
					<p><?php echo($error) ?></p>
				</div>
				<?php
			endif;
		endforeach;
	}
}



function get_sot_settings() {
	if ( is_multisite() ) {
		$settings = get_network_option( 'truth-source' );
	} else {
		$settings = get_option( 'truth-source' );
	}
	return $settings;
}

/**
 * Ajax path for negotiating source of truth
*/
function ajax_truth_source(){
	$payload = json_decode(file_get_contents('php://input'), true);
	$settings = get_sot_settings();

	if(!empty($settings)) {
		if(empty($settings['token']) && !empty($payload['token'])) {
			$settings['token'] = $payload['token'];
		}
		if($settings['token'] == $payload['token']) {
			echo(json_encode(['success' => true, 'sources' => $settings['sources']]));
			update_option('truth-source', $payload);
		} else {
			echo(json_encode(['success' => false, 'message' => 'Tokens don\'t match' . $payload['token'] . ' and ' . $settings['token'] ]));
		}
	} else {
		echo(json_encode(['success' => false, 'message' => 'Settings are empty.']));
	}

	die();
}

add_action( 'wp_ajax_truth_source', __NAMESPACE__ . '\\ajax_truth_source' );
add_action( 'wp_ajax_nopriv_truth_source', __NAMESPACE__ . '\\ajax_truth_source' );

function sot_admin_notice() {
	$settings = get_sot_settings();
    if($settings['sot'] == home_url()):
        ?>
        <div class="notice notice-success">
            <p><?php _e( 'This is the current source of truth', 'sample-text-domain' ); ?></p>
        </div>
        <?php
    else:
        ?>
        <div class="notice notice-error notice-big-error" style="background: #f88;color: white;">
            <p>
				<?php _e( 'WARNING: This <strong>IS NOT</strong> the current source of truth (Any changes made here will be overwritten).' , 'sample-text-domain' ); ?>
				The current SOT is <a href="<?php echo($settings['sot']) ?>" target="_blank"><?php echo($settings['sot']) ?></a>.
			</p>
        </div>
        <?php
    endif;

}
add_action( 'admin_notices', 'sot_admin_notice' );


if ( is_admin() ) {
	Truth_Source::get_instance();
}
//new Truth_Source();