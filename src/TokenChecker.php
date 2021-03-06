<?php
namespace TahaKhram\LaravelAdb2cOpenid;

use TahaKhram\LaravelAdb2cOpenid\EndPointHandler;

// Turn on error reporting, for debugging
error_reporting(E_ALL);
require_once base_path('vendor') . '/autoload.php'; 
use phpseclib\Crypt\RSA;

// A class to verify an id_token, following either:
// 1. Implicit Flow (response from authorization endpoint is an ID token)
// 2. Confidential Client Flow (response from authorization is a code)
class TokenChecker {

	// Class variables
	private $id_token_array = array();
	private $head = "";
	private $payload = "";
	private $clientID = "";
	private $client_secret = "";
	private $endpointHandler;

	function __construct($resp, $resp_type, $clientID, $client_secret, $policy_name) {

		$this->clientID = $clientID;
		$this->client_secret = $client_secret;
		$this->endpointHandler = new EndpointHandler($policy_name);

		if ($resp_type == "id_token") $id_token = $resp;
		else $id_token = $this->getTokenFromCode($resp);

		$this->splitIdToken($id_token);
	}

	// Given an authorization code, fetches the id_token from the token endpoint
	private function getTokenFromCode($code) {

		$post_fields = array(
                            'client_id' => urlencode($this->clientID),
                            'client_secret' => urlencode($this->client_secret),
                            'code' => urlencode($code),
                            'scope' => urlencode(env('AZ_SCOPE')),
                            'redirect_uri' => urlencode(env('AZ_REDIRECT_URI')),
                            'grant_type' => urlencode("authorization_code")
                        );

		// Get Token Endpoint
		$token_endpoint = $this->endpointHandler->getTokenEndpoint();


		// Execute post and get id token
		$result = $this->endpointHandler->postEndpointData($token_endpoint, $post_fields);
		$id_token = getClaim("id_token", $result);
		return $id_token;

	}

	// Converts base64url encoded string into base64 encoded string
	// Also adds the necessary padding to the base64 encoded string
	private function convert_base64url_to_base64($input) {

		$padding = strlen($input) % 4;
		if ($padding > 0) {
			$input .= str_repeat("=", 4 - $padding);
		}
		return strtr($input, '-_', '+/');
	}

	// Splits the id token into an array of header, payload, and signature
	private function splitIdToken($id_token) {

		// Split the token into Header, Payload, and Signature, and decode
		$this->id_token_array = explode('.', $id_token);
		$this->head = json_decode(base64_decode($this->id_token_array[0]),true);
		$this->payload = json_decode(base64_decode($this->id_token_array[1]),true);
	}

	// Validates the RSA signature on the token
	private function validateSignature() {

		// Get kid from header
		$kid = $this->head["kid"];
		// Get public key
		$key_data = $this->endpointHandler->getJwksUriData();

		// Extract e and n from the public key
		$e_regex = '/"kid":\W*"' . $kid . '.*"e":\W*"([^"]+)/';
		$e_array = array();
		preg_match($e_regex, $key_data, $e_array);

		$n_regex = '/"kid":\W*"' . $kid . '.*"n":\W*"([^"]+)/';
		$n_array = array();
		preg_match($n_regex, $key_data, $n_array);

		// 'e' and 'n' are base64 URL encoded, change to just base64 encoding
		$e = $this->convert_base64url_to_base64($e_array[1]);
		$n = $this->convert_base64url_to_base64($n_array[1]);

		// Convert RSA(e,n) format to PEM format
		
		$rsa = new RSA();

		$key = "<RSAKeyPair>"
		. "<Modulus>" . $n . "</Modulus>"
		. "<Exponent>" . $e . "</Exponent>"
		. "</RSAKeyPair>";
		$rsa->loadKey($key, RSA::PUBLIC_FORMAT_XML);

		// Verify Signature
		$to_verify_data = $this->id_token_array[0] . "." . $this->id_token_array[1];
		$to_verify_sig = base64_decode($this->convert_base64url_to_base64(($this->id_token_array[2])));
		$verified = openssl_verify($to_verify_data, $to_verify_sig, $rsa, OPENSSL_ALGO_SHA256);

		return $verified;
	}

	// Validate audience, not_before, expiration_time, and issuer claims
	private function validateClaims() {

		$audience = $this->payload["aud"]; // Should be app's clientID
		if ($audience != $this->clientID) return false;

		$cur_time = time();
		$not_before = $this->payload["nbf"]; // epoch time, time after which token is valid (so basically nbf < cur time < exp)
		$expiration = $this->payload["exp"]; // epoch time, check that the token is still valid

		if ($not_before > $cur_time) return false;
		if ($cur_time > $expiration) return false;

		// The Issuer Identifier for the OpenID Provider MUST exactly match the value of the iss (issuer) Claim.
		$iss_token = $this->payload["iss"];
		$iss_metadata = $this->endpointHandler->getIssuer();
		if ($iss_token != $iss_metadata) return false;

		return true;
	}

	// Verifies both the signature and claims of the ID token
	public function authenticate() {

		if ($this->validateSignature() == false) return false;
		if ($this->validateClaims() == false) return false;
		return true;
	}

    public function getPayload() {
		return $this->payload;
	}


	// Returns the end session (aka logout) url
	public function getEndSessionEndpoint() {
		return $this->endpointHandler->getEndSessionEndpoint();
	}
}
