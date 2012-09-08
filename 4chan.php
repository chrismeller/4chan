<?php

	class FourChan {

		const BASE_URL = 'https://www.4chan.org';
		const API_BASE_URL = 'https://api.4chan.org';
		const BOARDS_BASE_URL = 'https://boards.4chan.org';

		const VERSION = '0.1';

		public function posts ( $board, $thread, $if_modified_since = null ) {

			$url = self::API_BASE_URL . '/' . $board . '/res/' . $thread . '.json';

			$request = $this->new_request( $url, $if_modified_since )->execute();

			// parse out the body
			$request->response->body = json_decode( $request->response->body );

			return $request;

		}

		public function boards ( $if_modified_since = null ) {

			// get the HTML of the homepage so we can parse it out
			$request = $this->new_request( self::BASE_URL, $if_modified_since )->execute();

			$dom = new DOMDocument();
			@$dom->loadHTML( $request->response->body );

			$xpath = new DOMXPath( $dom );

			$board_links = $xpath->query( '//a[@class="boardlink"]' );

			$boards = array();
			foreach ( $board_links as $board_link ) {
				$href = $board_link->getAttribute( 'href' );

				if ( strpos( $href, 'http' ) !== 0 ) {
					$href = 'http:' . $href;
				}

				$host = parse_url( $href, PHP_URL_HOST );
				$path = parse_url( $href, PHP_URL_PATH );

				if ( strpos( $host, 'boards' ) === 0 ) {
					$abbr = basename( $path );
				}
				else {
					$abbr = $host;
				}

				$name = $board_link->getAttribute( 'title' );

				if ( $abbr != null ) {
					$boards[ $abbr ] = $name;
				}
				
			}

			$request->response->body = $boards;

			return $request;

		}

		/**
		 * Prepares a new 4chan_Request object with the given defaults.
		 *
		 * @param  string $url         The URL to request. Can be overridden later, or with CURLOPT_URL
		 * @param  int|string|DateTime $if_modified_since The Last-Modified time from your last request. We'll try to convert it to a DateTime, so it can be anything date() could reasonably parse.
		 * @return 4chan_Request              The new request object, ready to alter or run.
		 */
		protected function new_request ( $url, $if_modified_since = null ) {

			$request = new FourChan_Request();
			$request->options[ CURLOPT_URL ] = $url;
			$request->if_modified_since = $if_modified_since;

			return $request;

		}

	}

	class FourChan_Request {

		/**
		 * Headers to send with the request.
		 *
		 * @var array
		 */
		public $headers = array();

		/**
		 * The response we received.
		 *
		 * @var 4chan_Response
		 */
		public $response;

		public $options = array();

		public $if_modified_since;

		public function __construct ( ) {
			$this->response = new FourChan_Response();
		}

		public function execute ( ) {

			$default_options = array(
				CURLOPT_HEADERFUNCTION => array( $this->response, 'save_response_header' ),		// save the response headers
				CURLOPT_USERAGENT => 'FourChanPHP/' . FourChan::VERSION,
				CURLOPT_HTTPHEADER => $this->headers,		// HTTP headers to send
				CURLOPT_MAXREDIRS => 5,              		// don't get redirected too many times
				CURLOPT_SSL_VERIFYPEER => true,      		// these two verify ssl certificates
				CURLOPT_SSL_VERIFYHOST => true,
				CURLOPT_FAILONERROR => true,   		// if we get a status code > 400, consider it an error
				CURLOPT_ENCODING => '',        		// let curl decide what compression methods to use
				CURLOPT_TIMEOUT => 3,          		// seconds to wait for the response
				CURLOPT_CONNECTTIMEOUT => 2,   		// seconds to wait connecting to the server
				CURLOPT_FOLLOWLOCATION => true,		// follow redirects up to CURLOPT_MAXREDIRS
				CURLINFO_HEADER_OUT => true,   		// track the request headers we send -- note the CURLINFO prefix
				CURLOPT_RETURNTRANSFER => true,		// we need the response back as a string - you don't wan tto remove this one, people
			);

			// handle setting the If-Modified-Since header, if they provided a date
			if ( $this->if_modified_since != null ) {

				// do we need to convert it?
				if ( !$this->if_modified_since instanceof DateTime ) {

					// does it look like a unix timestamp?
					if ( is_numeric( $this->if_modified_since ) ) {
						$this->if_modified_since = new DateTime( '@' . $this->if_modified_since );
					}
					else {
						$this->if_modified_since = new DateTime( $this->if_modified_since );
					}

				}

				// the docs say TIMECOND_IFMODSINCE is the default, but surprise! it's not
				$default_options[ CURLOPT_TIMECONDITION ] = CURL_TIMECOND_IFMODSINCE;
				$default_options[ CURLOPT_TIMEVALUE ] = $this->if_modified_since->format('U');

			}

			// yes, we mean to do this - array_merge resets numeric indexes, which CURL constants are
			$options = $default_options + $this->options;
			print_r($options);

			$handle = curl_init();

			curl_setopt_array( $handle, $options );

			$this->response->body = curl_exec( $handle );

			// before we get rid of the curl handle, get all the info we can
			$this->response->info = curl_getinfo( $handle );

			// pull out some special values
			$this->response->status = curl_getinfo( $handle, CURLINFO_HTTP_CODE );

			if ( isset( $this->response->headers['Last-Modified'] ) ) {
				$this->response->last_modified = $this->response->headers['Last-Modified'];
				$this->response->last_modified = new DateTime( $this->response->last_modified );
			}

			// check for an error
			if ( curl_errno( $handle ) !== 0 ) {

				$errno = curl_errno( $handle );
				$error = curl_error( $handle );

				// close the handle before we throw an exception
				curl_close( $handle );

				throw new Exception( $error, $errno );

			}

			curl_close( $handle );

			return $this;

		}
	}

	class FourChan_Response {

		/**
		 * Headers received with the response.
		 *
		 * @var array
		 */
		public $headers = array();

		/**
		 * The response body.
		 *
		 * @var string
		 */
		public $body;

		/**
		 * The Last Modified header, extracted into a DateTime object for easy parsing and storage.
		 *
		 * @var DateTime
		 */
		public $last_modified;

		public function save_response_header ( $curl_handle, $header ) {

			$string = trim( $header );

			// don't save blank lines - there's usually one after the last header
			if ( $string != '' ) {

				if ( strpos( $string, ':' ) !== false ) {
					list( $key, $value ) = explode( ':', $string, 2 );
					$this->headers[ trim( $key ) ] = trim( $value );
				}
				else if ( strpos( $string, 'HTTP' ) !== false ) {
					// looks like the http status, set that too - it's keyed so we end up with the last one in the chain, should we be redirected or something
					list( $http_version, $status, $verb ) = explode( ' ', $string, 3 );
					$this->headers['Status'] = $status;

					// also save the whole thing
					$this->headers[] = $string;
				}
				else {
					// no key and not the status header, so let's just save it
					$this->headers[] = $string;
				}

			}

			// return the length of the *original* line we got, before we trimmed it, so curl can properly report the header and request sizes
			return strlen( $header );

		}
	}

?>