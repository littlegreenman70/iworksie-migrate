const { execFileSync } = require('child_process');
const path = require('path');

const WP_PATH = process.env.IWORKS_WP_PATH || '/opt/homebrew/var/www/www.iworks.new';
const BASE_URL = process.env.IWORKS_BASE_URL || 'https://www.iworks.new';

/**
 * Create short-lived WordPress auth cookies via WP-CLI (no password typing).
 * @param {string} [login]
 * @returns {Array<{name:string,value:string,url:string,httpOnly:boolean,secure:boolean,sameSite:string}>}
 */
function createAuthCookies(login = 'localadmin') {
	const php = `
$user = get_user_by( 'login', ${JSON.stringify(login)} );
if ( ! $user ) { echo 'NO_USER'; exit; }
$expiration = time() + HOUR_IN_SECONDS;
// CLI is not HTTPS; force secure_auth cookies for https://www.iworks.new admin.
$auth = wp_generate_auth_cookie( $user->ID, $expiration, 'secure_auth' );
$logged = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in' );
echo wp_json_encode( array(
	'auth_name' => SECURE_AUTH_COOKIE,
	'auth' => $auth,
	'logged_in_name' => LOGGED_IN_COOKIE,
	'logged_in' => $logged,
	'ssl' => true,
) );
`;
	const out = execFileSync(
		'wp',
		['eval', php, `--path=${WP_PATH}`],
		{ encoding: 'utf8', maxBuffer: 1024 * 1024 }
	).trim();
	if (!out || out.indexOf('NO_USER') === 0) {
		throw new Error('Could not create auth cookie for ' + login + ': ' + out);
	}
	const data = JSON.parse(out);
	const common = {
		url: BASE_URL,
		httpOnly: true,
		secure: !!data.ssl,
		sameSite: 'Lax',
	};
	return [
		{ name: data.auth_name, value: data.auth, ...common },
		{ name: data.logged_in_name, value: data.logged_in, ...common },
	];
}

/**
 * Run a WP-CLI command against the local site.
 * @param {string[]} args
 * @returns {string}
 */
function wp(args) {
	return execFileSync('wp', args.concat([`--path=${WP_PATH}`]), {
		encoding: 'utf8',
		maxBuffer: 10 * 1024 * 1024,
	}).trim();
}

module.exports = {
	WP_PATH,
	BASE_URL,
	createAuthCookies,
	wp,
	screenshotHideCss: `
		#wpadminbar { display: none !important; }
		html.wp-toolbar { padding-top: 0 !important; }
		.notice:not(.iwt-notice):not(.inline) { display: none !important; }
		.elementor-message, .e-notice, .update-nag { display: none !important; }
	`,
	screenshotsDir: path.join(
		require('os').homedir(),
		'Downloads',
		'iworks-health-check-screens'
	),
	wporgScreenshotsDir: path.join(
		__dirname,
		'..',
		'..',
		'..',
		'.wordpress-org'
	),
};
