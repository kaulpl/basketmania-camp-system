<?php
/**
 * Plugin Name: Basketmania Camp System
 * Description: Niezależny system zapisów, CRM, umów potwierdzanych kodem SMS, płatności Stripe i dokumentów dla Basketmania Camp.
 * Version: 0.25.4
 * Author: Basketmania Camp
 * Text Domain: basketmania-camp
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) exit;

define('BCS_VERSION', '0.25.4');
define('BCS_FILE', __FILE__);
define('BCS_DIR', plugin_dir_path(__FILE__));
define('BCS_URL', plugin_dir_url(__FILE__));

require_once BCS_DIR . 'includes/class-bcs-updater.php';
BCS_Updater::init();

require_once BCS_DIR . 'includes/class-bcs-db.php';
require_once BCS_DIR . 'includes/class-bcs-utils.php';
require_once BCS_DIR . 'includes/class-bcs-locks.php';
require_once BCS_DIR . 'includes/class-bcs-sms.php';
require_once BCS_DIR . 'includes/class-bcs-mailer.php';
require_once BCS_DIR . 'includes/class-bcs-mailbox.php';
require_once BCS_DIR . 'includes/class-bcs-agreements.php';
require_once BCS_DIR . 'includes/class-bcs-payments.php';
require_once BCS_DIR . 'includes/class-bcs-admin.php';
require_once BCS_DIR . 'includes/class-bcs-feedback.php';
require_once BCS_DIR . 'includes/class-bcs-frontend.php';
require_once BCS_DIR . 'includes/class-bcs-documents.php';
require_once BCS_DIR . 'includes/class-bcs-invoices.php';
require_once BCS_DIR . 'includes/class-bcs-invoice-batches.php';
require_once BCS_DIR . 'includes/class-bcs-crm.php';
require_once BCS_DIR . 'includes/class-bcs-communications.php';
require_once BCS_DIR . 'includes/class-bcs-workflow.php';
require_once BCS_DIR . 'includes/class-bcs-templates.php';

register_activation_hook(__FILE__, ['BCS_DB', 'activate']);
add_action('plugins_loaded', static function(): void {
    BCS_DB::maybe_upgrade();
    BCS_Admin::init();
    BCS_Agreements::init();
    BCS_Frontend::init();
    BCS_Communications::init();
    BCS_Workflow_Engine::init();
    BCS_Templates::init();
});
