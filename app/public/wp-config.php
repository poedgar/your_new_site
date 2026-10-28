<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'local' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          'LZ_!Xy5P+S%l:Mr]e??xPfA!RRem|7#h=rxQM[TxOO8B}+HpDG#X:FBJRnH**!oh' );
define( 'SECURE_AUTH_KEY',   '^=;)Yoaxir]B~ZM}hD;1 D*50](N2sy>2?5~TBW3;PJJ/5QDN0dB3t;sOYJcIkxi' );
define( 'LOGGED_IN_KEY',     'E=LJO>X~Kib=RTOnksJ`ua/e-K{Nnf6@U%.mz:N7X0=(7gBOC+k9tuUsvG!Y;`iS' );
define( 'NONCE_KEY',         '| ?Q!0IqZ_WT$^1lpPW;}IV6yyrE.@ueGAI0ilJg?Nan.V}p=2S&}2E.`q_DI4Mj' );
define( 'AUTH_SALT',         '#8JSwW!TbI~2d:)XxO&[?M@KsyCCk}`7vC`JrH5UEo`sfnDoW`k1wDlWX|fa;^D*' );
define( 'SECURE_AUTH_SALT',  'N-Oh,#&7|wAEWe7_T?zUNzdvbWa*%?|Ko0^pcD/:8Zy@+4J?rT<oI`0~@&>V~Ody' );
define( 'LOGGED_IN_SALT',    'AzvA@jAv>rI`)ADXeD[`4)i0=o>VNxfD]~acINI`g2Q~0D}zG:z5m.}49zZ)b;t ' );
define( 'NONCE_SALT',        'Dqm@yy^j`9g@v-9xkm-=4H<$a&Cca)D.Mn1GtJo* 1}u:N;a[K[h>oJRCjpHh76>' );
define( 'WP_CACHE_KEY_SALT', 'Y{@)3lT(*nSp{AS!ccANm3lJgVpvP$mj *>N!rk`g-Vu%Dk6F?c^R-?#jMJ$Ax|/' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define( 'WP_ENVIRONMENT_TYPE', 'local' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
