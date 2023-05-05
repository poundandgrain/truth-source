<?php
/**
 * Truth Source Admin
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
     * @var bool
     */
    protected static $initialized = false;

	/**
	 * Get the Sentry admin page instance.
	 *
	 * @return Truth_Source
	 */
	public static function get_instance(): Truth_Source {
		return self::$instance ?: self::$instance = new self;
	}

	protected function __construct() {
		add_action( 'init', function () {

			self::$settings = self::get_settings();

            // setup plugin config
			add_filter( 'plugin_action_links', array( __CLASS__, 'add_settings_link' ), 10, 2 );
			add_filter( 'network_admin_plugin_action_links', array( __CLASS__, 'add_settings_link' ), 10, 2 );

            // setup side menu options
			if (is_multisite()) {
				add_action( 'network_admin_menu', [ __CLASS__, 'network_admin_menu' ] );
			} else {
				add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
			}

			if (false === self::$initialized) {
				add_action( 'admin_notices', [ __CLASS__, 'sot_admin_notice'] );
			}

			if( ! empty($_POST) )
			{
				self::check_form_submissions();
			}

			self::$errors[] = [];
			self::$initialized = true;
		} );
	}

	/**
	 * Setup the admin menu page.
	 */
	public function admin_menu(): void {
		add_management_page(
			'Source of Truth',
			'Source of Truth',
			'truth-source_plugins',
			'truth-source',
			[ __CLASS__, 'render_admin_page' ]
		);
	}

	/**
	 * Setup the network admin menu page.
	 */
	public function network_admin_menu(): void {
		global $submenu;

		// Network admin has no tools section so we add it ourselfs
		add_menu_page(
			'',
			'Source of Truth',
			'activate_plugins',
			'truth-source-menu',
			'',
			'dashicons-cloud-saved',
			22
		);

		add_submenu_page(
			'truth-source-menu',
			'Source of Truth',
			'Settings',
			'truth-source_plugins',
			'truth-source',
			[ __CLASS__, 'render_admin_page' ]
		);

		// Remove the submenu item crate by `add_menu_page` that links to `truth-source-menu` which does not exist
		if ( ! empty( $submenu['truth-source-menu'][0] ) && $submenu['truth-source-menu'][0][2] === 'truth-source-menu' ) {
			unset( $submenu['truth-source-menu'][0] );
		}
	}

	private static function check_form_submissions()
	{
		$settings = self::$settings;

		if( ! empty($_POST['new_source']) )
		{
			$newSourcePost = strtolower($_POST['new_source']);
			$newSource = parse_url($newSourcePost);

			if(array_key_exists('scheme', $newSource) && array_key_exists('host', $newSource)  && ($newSource['scheme'] == 'http' || $newSource['scheme'] == 'https') && !empty($newSource['host']))
			{
				$cleanedSource = $newSource['scheme'] . '://'.$newSource['host'];

				if(!in_array($cleanedSource, $settings['sources'] )) {
					$settings['sources'][] = $cleanedSource;
					self::set_settings($settings);
					self::update_remotes();
				}
				else
				{
					//dd(count($settings['sources']), $settings['sources'] );
					self::$errors[] = "'{$cleanedSource}' already exists as environment.";
				}

			} else {
				self::$errors[] = "'{$newSourcePost}' is not a proper url.";
			}
		}
		if( ! empty($_POST['remove']) )
		{
			$removeSource = strtolower($_POST['remove']);
			if( $settings['sot'] != $removeSource)
			{
				array_splice($settings['sources'], (int)$_POST['remove']-1, 1);
				self::set_settings($settings);
				self::update_remotes();
			}
			else
			{
				self::$errors[] = "'{$removeSource}' is the source of truth. Please select another source before removing.";
			}
		}
		if( ! empty($_POST['make_source']) )
		{
			$settings['sot'] = $_POST['make_source'];

			self::set_settings($settings);
			self::update_remotes();
		}

		if( ! empty($_POST['recheck']))
		{
			self::update_remotes();
		}
	}

	private static function get_settings() {
		if ( !empty( self::$settings ) && !empty(self::$settings['sources'])) {

			dd(self::$settings);
			return self::$settings;
		}

		if ( is_multisite() ) {
			$settings = get_network_option(null, 'truth-source' );
		} else {
			$settings = get_option( 'truth-source' );
		}
		if( ! empty( $settings ) ) {
			// make sure $options is valid
			if(!empty($settings['sources']) && gettype($settings['sources']) == 'array') {
				self::$settings = $settings;
				return $settings;
			}
		}

		$defaults = [
			'sources' => [],
			'sot' => true,
			'status' => [],
			'token' => '',
		];

		// add self as a source
		$defaults['sources'][] = home_url();

		return $defaults;
	}

	private static function set_settings($settings) {
		self::$settings = $settings;

		if ( is_multisite() ) {
			update_network_option(null, 'truth-source', $settings);
		} else {
			update_option('truth-source', $settings);
		}

		return self::$settings;
	}


	/**
	 * Add a link to the settings on the Plugins screen.
	 */
	public static function add_settings_link( $links, $file ) {
		if ( $file === 'truth-source/truth-source.php' && current_user_can( 'manage_options' ) ) {
			if ( is_multisite() ) {
				$url = network_admin_url( 'admin.php?page=truth-source&api=1' );
			} else {
				$url = admin_url( 'tools.php?page=truth-source&api=1' );
			}
			// Prevent warnings in PHP 7.0+ when a plugin uses this filter incorrectly.
			$links = (array) $links;
			$links[] = sprintf( '<a href="%s">%s</a>', $url, __( 'Settings', 'truth-source' ) );
		}

		return $links;
	}

	public static function update_remotes() {
		$settings = self::$settings;
		$admin_path = str_replace(home_url(), '', admin_url('admin-ajax.php'));
		$admin_path .= '?action=truth_source';
		$sourceHash = md5(json_encode($settings['sources']));
		$redosSources = [];
		$redo = false;


		if($settings['sources'] ) {
			foreach($settings['sources'] as $source):
				// don't curl yo'self dummy!
				if($source == home_url()) {
					$settings['status'][$source] = true;
				}
				elseif(self::update_remote_source($source, $admin_path, $settings, true))
				{
					$redo = true;
				}
			endforeach;

			// if there were any source differences, re-do them all once more to be safe
			foreach($settings['sources'] as $source):
				//echo ("RE DOING !!! ". $source);
				if($source != home_url()) {
					self::update_remote_source($source, $admin_path, $settings);
				}
			endforeach;
		}

		self::set_settings($settings);
	}

	public static function update_remote_source($source, $admin_path, &$settings, $recordErrors = false) {
		$redo = false;

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
		curl_setopt($curl, CURLOPT_TIMEOUT, 5);
		// $output contains the output string
		$data = json_decode(curl_exec($curl));

		// close curl resource to free up system resources
		curl_close($curl);

		// set status to false until reached
		$settings['status'][$source] = false;

		if(empty($data)) {
			if($recordErrors) self::$errors[] = "Host `{$source}` unreachable or truth-source not enabled.";
		} else {
			$settings['status'][$source] = $data->success;

			$remoteHash = md5(json_encode($data->sources));
			// combine remote sources with these sources
			$settings['sources'] = array_unique(array_merge($data->sources,$settings['sources']), SORT_REGULAR);
			$sourceHash = md5(json_encode($settings['sources']));
			if($sourceHash != $remoteHash) {
				$redo = true;
			};

			if(!empty($data->message) && $recordErrors) {
				self::$errors[] = $data->message;
			}
		}
		return $redo;
	}

	/**
	 * Render the navigation.
	 */
	public static function show_navigation() {
		echo '<a href="?page=truth-source">Sources</a>';
		echo ' | ';
		echo '<a href="?page=truth-source&api=1">Configuration</a>';
	}

	/**
	 * Render the API settings page.
	 */
	public static function api_settings_page() {
		$settings = self::$settings;
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
            <h1>Source of Truth Configuration</h1>
			<?php self::show_navigation(); ?>
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
		$settings = self::$settings;
		//self::$errors[] = '';
		?>
		<style>
		.url-sot-2 {
			background-color: #ff6666 !important;
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
			background-color: #ff9999;
		}
		.sot-col-sm {
			width: 4em;
		}
		</style>
        <div class="wrap">
            <h1>Source of Truth</h1>
			<?php self::show_navigation(); ?>
			<?php self::show_errors(); ?>
			<form method="POST">
				<input type="hidden" class="code" value="recheck" name="recheck">
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
					if(!empty($settings['sources'])):
						foreach($settings['sources'] as $source):
							$i++;
							//$data = self::check_remote($source);
						?>
						<tr class="<?php echo($source === $settings['sot'] ? "url-sot" : "url-not-sot") ?>">
							<td>
								<?php //echo('<input type="text" class="code" name="source_' . $i . '" value="'. $source .'" />'); ?>
								<?php echo("<a href=\"{$source}\" target=\"_blank\">{$source}</a>"); ?>
							</td>
							<td>
							<?php echo('<button value="' . $i . '" class="button-link editinline" name="remove">Remove</button>'); ?>
							<?php
								if( $source === $settings['sot']):
									echo(' | This is current Source');
								elseif(array_key_exists($source, $settings['status']) && $settings['status'][$source] !== true):
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
									elseif(array_key_exists($source, $settings['status']) && $settings['status'][$source] !== true):
										echo("<div class=\"sot-circle sot-circle--red\"></div>");
									else:
										echo("<div class=\"sot-circle\"></div>");
									endif;
								?>
							</td>
						</tr>
						<?php
						endforeach;
					endif;
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
		$api = false;
		if(isset($_GET['api'])) {
			$api = $_GET['api'];
		}
		if($api) {
			self::api_settings_page();
		} else {
			self::admin_page();
		}

	}

	public static function show_errors() {
		foreach(self::$errors as $error):
			if(!empty($error)):
				?>
				<div class="notice notice-error notice-big-error" style="background: #ff6666;color: white;">
					<p><?php echo($error) ?></p>
				</div>
				<?php
			endif;
		endforeach;
	}

	function sot_admin_notice() {
		$settings = self::get_sot_settings();
		if(!empty($settings)) {
			if(array_key_exists('sot', $settings) && $settings['sot'] == home_url()):
				?>
				<div class="notice notice-success">
					<p><?php _e( 'This is the current source of truth', 'sot' ); ?></p>
				</div>
				<?php
			else:
				?>
				<div class="notice notice-error notice-big-error" style="background: #ff6666;color: white;">
					<p>
						<?php _e( 'WARNING: This <strong>IS NOT</strong> the current source of truth (Any changes made here will be overwritten).' , 'sot' ); ?>
						<?php
						if(array_key_exists('sot', $settings)): ?>
							The current SOT is <a href="<?php echo($settings['sot']) ?>" target="_blank" style="color: #fff;"><?php echo($settings['sot']) ?></a>.
						<?php endif; ?>
					</p>
				</div>
				<?php
			endif;
		}
	}

}

/**
 * Get settings from options depending on if multisite or not
 */
function get_sot_settings() {
	if ( is_multisite() ) {
		$settings = get_network_option(null, 'truth-source' );
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

// setup ajax endpoints
add_action( 'wp_ajax_truth_source', __CLASS__ . '\\ajax_truth_source' );
add_action( 'wp_ajax_nopriv_truth_source', __CLASS__ . '\\ajax_truth_source' );
