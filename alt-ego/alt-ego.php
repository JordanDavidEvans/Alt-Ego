<?php
/**
 * Plugin Name: Alt Ego
 * Description: Automatically generate alt text and meta descriptions using OpenAI.
 * Version: 0.1.0
 * Author: Alt-Ego Contributors
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Alt_Ego {
    const OPTION_KEY  = 'alt_ego_api_key';
    const PROMPT_KEY  = 'alt_ego_prompt';
    const QUEUE_OPTION = 'alt_ego_queue';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'add_attachment', [ $this, 'maybe_queue_image' ] );
        add_action( 'alt_ego_process_queue', [ $this, 'process_queue' ] );
    }

    public function add_settings_page() {
        add_options_page( 'Alt Ego', 'Alt Ego', 'manage_options', 'alt-ego', [ $this, 'settings_page' ] );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Alt Ego Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'alt_ego' ); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">OpenAI API Key</th>
                <td><input type="text" name="<?php echo self::OPTION_KEY; ?>" value="<?php echo esc_attr( get_option( self::OPTION_KEY ) ); ?>" size="40"></td>
            </tr>
            <tr valign="top">
                <th scope="row">Alt Text Prompt</th>
                <td>
                    <textarea name="<?php echo self::PROMPT_KEY; ?>" rows="3" cols="50"><?php echo esc_textarea( get_option( self::PROMPT_KEY, 'Provide a concise alt text for this image.' ) ); ?></textarea>
                    <p class="description">Prompt used when requesting alt text from OpenAI.</p>
                </td>
            </tr>
        </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting( 'alt_ego', self::OPTION_KEY );
        register_setting( 'alt_ego', self::PROMPT_KEY );
    }

    public function maybe_queue_image( $attachment_id ) {
        $queue = get_option( self::QUEUE_OPTION, [] );
        $queue[] = $attachment_id;
        update_option( self::QUEUE_OPTION, $queue );
        if ( ! wp_next_scheduled( 'alt_ego_process_queue' ) ) {
            wp_schedule_single_event( time() + 60, 'alt_ego_process_queue' );
        }
    }

    public function process_queue() {
        $queue = get_option( self::QUEUE_OPTION, [] );
        if ( empty( $queue ) ) {
            return;
        }
        $attachment_id = array_shift( $queue );
        update_option( self::QUEUE_OPTION, $queue );
        $this->generate_alt_text( $attachment_id );
        if ( ! empty( $queue ) ) {
            wp_schedule_single_event( time() + 60, 'alt_ego_process_queue' );
        }
    }

    private function generate_alt_text( $attachment_id ) {
        $api_key = get_option( self::OPTION_KEY );
        if ( ! $api_key ) {
            return;
        }
        $image_url = wp_get_attachment_url( $attachment_id );
        if ( ! $image_url ) {
            return;
        }
        $prompt_text = get_option( self::PROMPT_KEY, 'Provide a concise alt text for this image.' );
        $prompt = [
            [
                'role'    => 'user',
                'content' => [
                    [ 'type' => 'text', 'text' => $prompt_text ],
                    [ 'type' => 'image_url', 'image_url' => [ 'url' => $image_url ] ],
                ],
            ],
        ];
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'model'    => 'gpt-4-vision-preview',
                'messages' => $prompt,
                'max_tokens' => 50,
            ] ),
            'timeout' => 60,
        ] );
        if ( is_wp_error( $response ) ) {
            return;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['choices'][0]['message']['content'] ) ) {
            $alt = sanitize_text_field( $body['choices'][0]['message']['content'] );
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
        }
    }
}

new Alt_Ego();

