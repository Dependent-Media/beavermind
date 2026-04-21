<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the official Anthropic PHP SDK.
 *
 * Centralizes API-key handling and provides a single place to add logging or
 * instrumentation later. Callers receive the bare SDK \Anthropic\Client and
 * use it directly — no leaky abstractions here.
 */
class ClaudeClient {

	private string $api_key;
	private ?\Anthropic\Client $client = null;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	public function is_configured(): bool {
		return '' !== trim( $this->api_key ) && class_exists( '\Anthropic\Client' );
	}

	public function client(): \Anthropic\Client {
		if ( null === $this->client ) {
			if ( ! class_exists( '\Anthropic\Client' ) ) {
				throw new \RuntimeException( 'Anthropic SDK is not loaded. Run `composer install` in the plugin directory.' );
			}
			if ( '' === trim( $this->api_key ) ) {
				throw new \RuntimeException( 'Claude API key is not set. Configure it in BeaverMind settings.' );
			}
			$this->client = new \Anthropic\Client( apiKey: $this->api_key );
		}
		return $this->client;
	}
}
