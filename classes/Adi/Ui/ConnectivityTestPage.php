<?php
if (!defined('ABSPATH')) {
	die('Access denied.');
}

if (class_exists('Adi_Ui_ConnectivityTestPage')) {
	return;
}

/**
 * Controller for "Test Connection" plug-in view.
 *
 * It collects different system information and connects to the configured Active Directory based upon the provided credentials.
 *
 * @author Tobias Hellmann <the@neos-it.de>
 * @author Sebastian Weinert <swe@neos-it.de>
 * @author Danny Meißner <dme@neos-it.de>
 *
 * @access public
 */
class Adi_Ui_ConnectivityTestPage extends Multisite_View_Page_Abstract
{
	const CAPABILITY = 'manage_options';
	const TEMPLATE = 'test-connection.twig';
	const NONCE = 'Active Directory Integration Test Authentication Nonce';

	/* @var Multisite_Configuration_Service $configuration */
	private $configuration;

	/* @var Ldap_Attribute_Service $attributeService */
	private $attributeService;

	/** @var Adi_User_Manager $userManager */
	private $userManager;

	/* @var Logger $logger */
	private $logger;

	/** @var string $result */
	private $result;

	/** @var string $output */
	private $output;

	/** @var Adi_Role_Manager */
	private $roleManager;

	/** @var  Ldap_Connection */
	private $ldapConnection;

	/**
	 * @param Multisite_View_TwigContainer $twigContainer
	 * @param Multisite_Configuration_Service $configuration
	 * @param Ldap_Connection $ldapConnection
	 * @param Ldap_Attribute_Service $attributeService
	 * @param Adi_User_Manager $userManager
	 * @param Adi_Role_Manager $roleManager
	 */
	public function __construct(Multisite_View_TwigContainer $twigContainer,
								Multisite_Configuration_Service $configuration,
								Ldap_Connection $ldapConnection,
								Ldap_Attribute_Service $attributeService,
								Adi_User_Manager $userManager,
								Adi_Role_Manager $roleManager)
	{
		parent::__construct($twigContainer);

		$this->configuration = $configuration;
		$this->attributeService = $attributeService;
		$this->ldapConnection = $ldapConnection;
		$this->userManager = $userManager;
		$this->roleManager = $roleManager;

		$this->logger = Logger::getLogger(__CLASS__);
	}

	/**
	 * Get the page title.
	 *
	 * @return string
	 */
	public function getTitle()
	{
		return esc_html__('Test authentication', ADI_I18N);
	}

	/**
	 * Render the page for an admin.
	 */
	public function renderAdmin()
	{
		$this->checkCapability();

		// get data from $_POST
		$params = $this->processData();
		$params['nonce'] = wp_create_nonce(self::NONCE); // add nonce for security
		$params['message'] = $this->result;
		$params['log'] = $this->output;

		// render
		$this->display(self::TEMPLATE, $params);
	}

	/**
	 * This method reads the $_POST array and triggers the connection test (if the admin has pressed the button)
	 *
	 * @return array
	 */
	public function processData()
	{
		if (!isset($_POST['username']) || !isset($_POST['password'])) {
			return array();
		}

		// before test connection check nonce
		if (!wp_verify_nonce($_POST['security'], self::NONCE)) {
			$message = __('You do not have sufficient permissions.', ADI_I18N);
			wp_die($message);
		}

		$username = $_POST['username'];
		$password = $_POST['password'];

		$information = $this->collectInformation($username, $password);
		$this->output = explode("<br />", $information['output']);

		if ($information['authentication_result']) {
			$this->result = esc_html__('User logged on.', ADI_I18N);
		} else {
			$this->result = esc_html__('Logon failed.', ADI_I18N);
		}

		return array(
			'status' => $information['authentication_result'],
		);
	}

	/**
	 * Collect the information for the login process
	 *
	 * @param string $username
	 * @param string $password
	 *
	 * @return array with key 'output' and 'authentication_result'
	 */
	function collectInformation($username, $password)
	{
		ob_start();
		Core_Logger::displayAndLogMessages();

		// detect support-id
		$supportData = $this->detectSupportData();
		
		foreach ($supportData as $line) {
			$this->logger->info($line);
		}
		
		// detect system environment
		$env = $this->detectSystemEnvironment();
		$this->logger->info('System Information: ');

		foreach ($env as $info) {
			$this->logger->info(' - ' . $info[0] . ": " . $info[1]);
		}

		$this->logger->info('*** Establishing Active Directory connection ***');
		$authenticationResult = $this->connectToActiveDirectory($username, $password);

		Core_Logger::logMessages();
		$output = ob_get_contents();

		ob_end_clean();

		return array(
			'output' => $output,
			'authentication_result' => $authenticationResult,
		);
	}

	/**
	 * Detect relevant system environment information for debug purposes
	 * @return array of array [['PHP', '5.6'], ['WordPress', '3.5']]
	 */
	function detectSystemEnvironment()
	{
		global $wp_version;

		if (!class_exists('adLDAP')) {
			require_once(ADI_PATH . '/vendor/adLDAP/adLDAP.php');
		}

		return array(
			array('PHP', json_encode(phpversion())),
			array('WordPress', json_encode($wp_version)),
			array('Active Directory Integration', json_encode(ADI_PLUGIN_VERSION)),
			array('Operating System', json_encode(php_uname())),
			array('Web Server', json_encode(php_sapi_name())),
			array('adLDAP', json_encode(adLDAP::VERSION)),
		);
	}

	/**
	 * Detects the support data
	 * 
	 * @return array
	 */
	function detectSupportData()
	{
		$supportId = $this->configuration->getOptionValue(Adi_Configuration_Options::SUPPORT_LICENSE_KEY, get_current_blog_id());
		$siteUrl = get_site_url();
		$siteName = get_bloginfo('name');
		
		if ($supportId == '') {
			$supportId = 'unlicensed';
		}
		
		$supportString = 'Support for: ###' . $supportId . '###' . $siteUrl . '###' . $siteName . '###';
		$supportStringHashed = 'Support Hash: ' . hash('sha256', $supportString);		
		
		return array($supportString, $supportStringHashed);
	}

	/**
	 * Connect to the Active Directory with given username and password
	 *
	 * @param string $username
	 * @param string $password
	 *
	 * @return bool if authentication was successful
	 */
	function connectToActiveDirectory($username, $password)
	{
		// create login authenticator with custom logger
		$loginAuthenticator = new Adi_Authentication_LoginService(
			null,
			$this->configuration,
			$this->ldapConnection,
			$this->userManager,
			null,
			null,
			$this->attributeService,
			$this->roleManager
		);

		return $loginAuthenticator->authenticate(null, $username, $password);
	}

	public function getOutput()
	{
		return $this->output;
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
	 * Get the menu slug for the page.
	 *
	 * @return string
	 */
	public function getSlug()
	{
		return ADI_PREFIX . 'test_connection';
	}

	/**
	 * Get the slug for post requests.
	 *
	 * @return mixed
	 */
	public function wpAjaxSlug()
	{
		return null;
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