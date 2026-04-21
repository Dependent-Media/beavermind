<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const MENU_SLUG  = 'beavermind';
	const GROUP_SLUG = 'beavermind_settings';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'BeaverMind', 'beavermind' ),
			__( 'BeaverMind', 'beavermind' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-superhero',
			58
		);
	}

	public function register_fields(): void {
		register_setting(
			self::GROUP_SLUG,
			Plugin::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(
					'api_key' => '',
					'model'   => 'claude-opus-4-7',
				),
			)
		);

		add_settings_section(
			'beavermind_api',
			__( 'Claude API', 'beavermind' ),
			function () {
				echo '<p>' . esc_html__( 'BeaverMind calls the Anthropic Claude API to plan and fill Beaver Builder layouts.', 'beavermind' ) . '</p>';
			},
			self::MENU_SLUG
		);

		add_settings_field(
			'api_key',
			__( 'API Key', 'beavermind' ),
			array( $this, 'render_api_key_field' ),
			self::MENU_SLUG,
			'beavermind_api'
		);

		add_settings_field(
			'model',
			__( 'Model', 'beavermind' ),
			array( $this, 'render_model_field' ),
			self::MENU_SLUG,
			'beavermind_api'
		);

		add_settings_section(
			'beavermind_integrations',
			__( 'Integrations', 'beavermind' ),
			function () {
				echo '<p>' . esc_html__( 'Optional third-party credentials BeaverMind uses for additional input modalities.', 'beavermind' ) . '</p>';
			},
			self::MENU_SLUG
		);

		add_settings_field(
			'figma_token',
			__( 'Figma PAT', 'beavermind' ),
			array( $this, 'render_figma_token_field' ),
			self::MENU_SLUG,
			'beavermind_integrations'
		);
	}

	public function render_figma_token_field(): void {
		$value  = (string) Plugin::instance()->get_option( 'figma_token', '' );
		$masked = $value ? str_repeat( '•', max( 0, strlen( $value ) - 4 ) ) . substr( $value, -4 ) : '';
		printf(
			'<input type="password" id="beavermind_figma_token" name="%s[figma_token]" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( Plugin::OPTION_KEY ),
			esc_attr( $value )
		);
		$link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://www.figma.com/developers/api#access-tokens' ),
			esc_html__( 'Figma personal access tokens', 'beavermind' )
		);
		if ( $masked ) {
			echo '<p class="description">';
			echo esc_html( sprintf( __( 'Currently set: %s', 'beavermind' ), $masked ) );
			echo ' &middot; ';
			printf( wp_kses_post( __( 'Manage tokens at %s. Needs file_read scope.', 'beavermind' ) ), $link );
			echo '</p>';
		} else {
			echo '<p class="description">';
			printf( wp_kses_post( __( 'Required for the From Figma input. Generate one at %s with file_read scope.', 'beavermind' ) ), $link );
			echo '</p>';
		}
	}

	public function sanitize( $input ): array {
		$out = array();
		$out['api_key']     = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		$out['figma_token'] = isset( $input['figma_token'] ) ? trim( sanitize_text_field( $input['figma_token'] ) ) : '';
		$allowed_models = array( 'claude-opus-4-7', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001' );
		$model = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : 'claude-opus-4-7';
		$out['model'] = in_array( $model, $allowed_models, true ) ? $model : 'claude-opus-4-7';
		return $out;
	}

	public function render_api_key_field(): void {
		$value = (string) Plugin::instance()->get_option( 'api_key', '' );
		$masked = $value ? str_repeat( '•', max( 0, strlen( $value ) - 4 ) ) . substr( $value, -4 ) : '';
		printf(
			'<input type="password" id="beavermind_api_key" name="%s[api_key]" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( Plugin::OPTION_KEY ),
			esc_attr( $value )
		);

		$console_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://console.anthropic.com/settings/keys' ),
			esc_html__( 'Anthropic Console', 'beavermind' )
		);

		if ( $masked ) {
			echo '<p class="description">';
			echo esc_html( sprintf( __( 'Currently set: %s', 'beavermind' ), $masked ) );
			echo ' &middot; ';
			printf(
				/* translators: %s: link to the Anthropic Console */
				wp_kses_post( __( 'Manage or rotate keys at the %s.', 'beavermind' ) ),
				$console_link
			);
			echo '</p>';
		} else {
			echo '<p class="description">';
			printf(
				/* translators: %s: link to the Anthropic Console */
				wp_kses_post( __( 'Get an API key at the %s (starts with %s).', 'beavermind' ) ),
				$console_link,
				'<code>sk-ant-</code>'
			);
			echo '</p>';
		}
	}

	public function render_model_field(): void {
		$value = (string) Plugin::instance()->get_option( 'model', 'claude-opus-4-7' );
		$models = array(
			'claude-opus-4-7'           => 'Claude Opus 4.7 (highest quality)',
			'claude-sonnet-4-6'         => 'Claude Sonnet 4.6 (balanced)',
			'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (fastest, cheapest)',
		);
		printf( '<select name="%s[model]">', esc_attr( Plugin::OPTION_KEY ) );
		foreach ( $models as $slug => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $slug ),
				selected( $value, $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind', 'beavermind' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP_SLUG );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>
			<hr />
			<?php $this->render_environment_check(); ?>
		</div>
		<?php
	}

	private function render_environment_check(): void {
		echo '<h2>' . esc_html__( 'Environment', 'beavermind' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		$this->render_check_row( 'Beaver Builder', class_exists( 'FLBuilder' ) );
		$this->render_check_row( 'FLBuilderModel', class_exists( 'FLBuilderModel' ) );
		$this->render_check_row( 'Beaver Themer', class_exists( 'FLThemeBuilderLoader' ) );
		$this->render_check_row( 'Ultimate Addons (UABB)', class_exists( 'BB_Ultimate_Addon' ) || defined( 'BB_ULTIMATE_ADDON_VER' ) );
		$this->render_check_row( 'PowerPack', class_exists( 'BB_PowerPack' ) || defined( 'BB_POWERPACK_VER' ) );
		echo '</tbody></table>';
	}

	private function render_check_row( string $label, bool $ok ): void {
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html( $label ),
			$ok
				? '<span style="color:#1a7f37">✓ ' . esc_html__( 'Detected', 'beavermind' ) . '</span>'
				: '<span style="color:#b91c1c">✗ ' . esc_html__( 'Not detected', 'beavermind' ) . '</span>'
		);
	}
}
