<?php
if (!defined('ABSPATH')) {
	die('Access denied.');
}

if (class_exists('Adi_Synchronization_Ui_SyncToWordPressPage')) {
	return;
}

/**
 * Controller for manual synchronizing of Active Directory users into the current WordPress instance
 *
 * @author Tobias Hellmann <the@neos-it.de>
 * @author Sebastian Weinert <swe@neos-it.de>
 * @author Danny Meißner <dme@neos-it.de>
 *
 * @access public
 */
class Adi_Synchronization_Ui_SyncToWordPressPage extends Multisite_View_Page_Abstract
{
	const TITLE = 'Sync to WordPress';
	const SLUG = 'sync_to_wordpress';
	const AJAX_SLUG = null;
	const CAPABILITY = 'manage_options';
	const TEMPLATE = 'sync-to-wordpress.twig';
	const NONCE = 'Active Directory Integration Sync to WordPress Nonce';


	/* @var Adi_Synchronization_WordPress $syncToWordPress*/
	private $syncToWordPress;
	/** @var Multisite_Configuration_Service $configuration */
	private $configuration;

	private $result;
	private $log;

	/**
	 * @param Multisite_View_TwigContainer            $twigContainer
	 * @param Adi_Synchronization_WordPress        $syncToWordPress
	 * @param Multisite_Configuration_Service $configuration
	 */
	public function __construct(Multisite_View_TwigContainer $twigContainer,
								Adi_Synchronization_WordPress $syncToWordPress,
								Multisite_Configuration_Service $configuration)
	{
		parent::__construct($twigContainer);

		$this->syncToWordPress = $syncToWordPress;
		$this->configuration = $configuration;
	}

	/**
	 * Get the page title.
	 *
	 * @return string
	 */
	public function getTitle()
	{
		return esc_html__(self::TITLE, ADI_I18N);
	}

	/**
	 * Render the page for an admin.
	 */
	public function renderAdmin()
	{
		$this->checkCapability();

		$params = $this->processData($_POST);
		// add nonce for security
		$params['nonce'] = wp_create_nonce(self::NONCE);
		$params['authCode'] = $this->configuration->getOptionValue(Adi_Configuration_Options::SYNC_TO_WORDPRESS_AUTHCODE);
		$params['blogUrl'] = get_site_url(get_current_blog_id());
		$params['message'] = $this->result;
		$params['log'] = $this->log;

		$this->display(self::TEMPLATE, $params);
	}

	/**
	 * This method reads the $_POST array and triggers Sync to Wordpress (if the authentication code from $_POST is correct)
	 *
	 * @param array $post
	 * @return array
	 */
	public function processData($post)
	{
		if (!isset($post['syncToWordpress'])) {	// TODO bulkImport darf nicht in POST stehen
			return array();
		}

		$security = Core_Util_ArrayUtil::get('security', $post, '');
		if (!wp_verify_nonce($security, self::NONCE)) {
			$message = esc_html__('You do not have sufficient permissions to access this page.', ADI_I18N);
			wp_die($message);
		}

		ob_start();
		Core_Logger::displayAndLogMessages();
		$status = $this->syncToWordPress->synchronize();
		Core_Logger::logMessages();
		$this->log = ob_get_contents();
		ob_end_clean();

		//Split the String and put the single log messages into an array
		$this->log = explode("<br />",$this->log);


		if ($status) {
			$this->result = esc_html__('Sync to WordPress succeeded.', ADI_I18N);
		} else {
			$this->result = esc_html__('Sync to WordPress failed.', ADI_I18N);
		}

		return array(
			'status' => $status
		);
	}

	/**
	 * Include JavaScript und CSS Files into WordPress.
	 *
	 * @param string $hook
	 */
	public function loadAdminScriptsAndStyle($hook)
	{
		if (strpos($hook, self::getSlug()) === false) {
			return;
		}

		wp_enqueue_style('adi2', ADI_URL . '/css/adi2.css', array(), Multisite_Ui::VERSION_CSS);
	}

	/**
	 * Get the menu slug of the page.
	 *
	 * @return string
	 */
	public function getSlug()
	{
		return ADI_PREFIX . self::SLUG;
	}

	/**
	 * Get the slug for post requests.
	 *
	 * @return mixed
	 */
	public function wpAjaxSlug()
	{
		return self::AJAX_SLUG;
	}

	/**
	 * Get the current capability to check if the user has permission to view this page.
	 *
	 * @return string
	 */
	protected function getCapability()
	{
		return self::CAPABILITY;
	}

	/**
	 * Get the current nonce value.
	 *
	 * @return mixed
	 */
	protected function getNonce()
	{
		return self::NONCE;
	}
}